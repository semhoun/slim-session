import { afterEach, describe, expect, it, vi } from 'vitest';
import { createSessionClient, SessionClient } from '../src/index.js';
import type { SessionClientOptions } from '../src/index.js';

const clients: SessionClient[] = [];
afterEach(() => { clients.splice(0).forEach(client => client.destroy()); vi.useRealTimers(); vi.unstubAllGlobals(); });
const defaults: SessionClientOptions = {
  baseUrl: 'https://api.example.test/app/', application: 'example', transport: 'header', token: 'initial',
  endpoints: { status: 'session/status', refresh: 'session/refresh', logout: 'session/logout' }, autoRefresh: false,
};
function client(fetcher: typeof fetch, options: Partial<SessionClientOptions> = {}): SessionClient {
  const config = { ...defaults, ...options, fetch: fetcher };
  if (config.transport === 'cookie') delete config.token;
  const result = createSessionClient(config);
  clients.push(result);
  return result;
}
function deadlines() {
  const now = Math.floor(Date.now() / 1000);
  return { tokenExpiresAt: now + 7200, idleExpiresAt: now + 86400, absoluteExpiresAt: now + 604800 };
}
function status(transport = 'header', token?: string, csrfToken = 'csrf-one'): Response {
  return Response.json({ authenticated: true, transport, ...deadlines(), ...(transport === 'cookie' ? { csrfToken } : {}) }, { headers: token ? { 'X-Session-Token': token } : {} });
}
function metadataHeaders(token?: string, csrf?: string): Headers {
  const data = deadlines();
  const headers = new Headers({ 'X-Session-Token-Expires-At': String(data.tokenExpiresAt), 'X-Session-Idle-Expires-At': String(data.idleExpiresAt), 'X-Session-Absolute-Expires-At': String(data.absoluteExpiresAt) });
  if (token) headers.set('X-Session-Token', token);
  if (csrf) headers.set('X-CSRF-Token', csrf);
  return headers;
}
function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

describe('transport and scope', () => {
  it('does not access browser globals or fetch until used', () => {
    const fetcher = vi.fn<typeof fetch>();
    vi.stubGlobal('sessionStorage', undefined);
    expect(client(fetcher).state.status).toBe('uninitialized');
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('sends only configured header credentials and refuses redirect overrides', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(status());
    const instance = client(fetcher);
    await instance.initialize();
    const [url, init] = fetcher.mock.calls[0]!;
    expect(url).toBe('https://api.example.test/app/session/status');
    expect(new Headers(init?.headers).get('Authorization')).toBe('Bearer initial');
    expect(init).toMatchObject({ credentials: 'omit', redirect: 'error', cache: 'no-store', referrerPolicy: 'no-referrer' });
    await instance.request('data', { redirect: 'follow', credentials: 'include' });
    expect(fetcher.mock.lastCall?.[1]).toMatchObject({ redirect: 'error', credentials: 'omit' });
  });

  it('uses custom credential headers without accepting caller overrides', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(status());
    const instance = client(fetcher, { credentialHeader: 'X-Auth', credentialPrefix: '' });
    await instance.initialize();
    expect(new Headers(fetcher.mock.calls[0]?.[1]?.headers).get('X-Auth')).toBe('initial');
    await expect(instance.request('data', { headers: { 'x-auth': 'evil' } })).rejects.toThrow('managed');
  });

  it.each(['https://other.example/app/data', '/outside', '../outside', 'https://user:secret@api.example.test/app/data', '/app/%2foutside', '/app/%5coutside', '/app/%2e%2e/outside', 'data#fragment'])('refuses out of scope URL %s', async input => {
    const fetcher = vi.fn<typeof fetch>();
    await expect(client(fetcher).request(input)).rejects.toThrow();
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('refuses redirects, including a misbehaving injected fetch', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(null, { status: 302, headers: { Location: 'https://other.example/' } }));
    await expect(client(fetcher).request('data')).rejects.toThrow('redirect');
  });

  it('cookie transport never reads or exposes a JWT and requires initialized CSRF for mutations', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(status('cookie', 'must-ignore'));
    const instance = client(fetcher, { transport: 'cookie' });
    await expect(instance.request('data', { method: 'POST' })).rejects.toThrow('Initialize');
    await instance.initialize();
    await instance.request('data', { method: 'POST', headers: { Authorization: 'App credential' } });
    const init = fetcher.mock.lastCall?.[1];
    expect(init?.credentials).toBe('include');
    expect(new Headers(init?.headers).get('X-CSRF-Token')).toBe('csrf-one');
    expect(new Headers(init?.headers).get('Authorization')).toBe('App credential');
    expect(JSON.stringify(instance.state)).not.toContain('must-ignore');
    expect(JSON.stringify(instance.state)).not.toContain('csrf-one');
  });
});

describe('lifecycle and concurrency', () => {
  it.each(['header', 'cookie'] as const)('does not regress %s deadlines or credentials from a late same-session response', async transport => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-01-01T00:00:00Z'));
    const jwt = (marker: number): string => `header.${btoa(JSON.stringify({ sub: 'same-session', marker }))}.signature`;
    const late = deferred<Response>();
    const data = deadlines();
    const newer = metadataHeaders(transport === 'header' ? jwt(2) : undefined, transport === 'cookie' ? 'csrf-one' : undefined);
    newer.set('X-Session-Token-Expires-At', String(data.tokenExpiresAt + 100));
    newer.set('X-Session-Idle-Expires-At', String(data.idleExpiresAt + 100));
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(status(transport)).mockReturnValueOnce(late.promise)
      .mockResolvedValueOnce(new Response(null, { headers: newer })).mockResolvedValue(new Response());
    const instance = client(fetcher, { transport, token: jwt(0) });
    await instance.initialize();
    const pending = instance.request('older');
    await instance.request('newer');
    const older = metadataHeaders(transport === 'header' ? jwt(1) : undefined, transport === 'cookie' ? 'csrf-one' : undefined);
    older.set('X-Session-Token-Expires-At', String(Math.floor(Date.now() / 1000) - 1));
    older.set('X-Session-Idle-Expires-At', String(Math.floor(Date.now() / 1000) - 1));
    late.resolve(new Response(null, { headers: older }));
    await pending;
    expect(instance.state.status).toBe('authenticated');
    expect(instance.state.metadata).toEqual({ ...data, tokenExpiresAt: data.tokenExpiresAt + 100, idleExpiresAt: data.idleExpiresAt + 100 });
    await instance.request('check');
    if (transport === 'header') expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).get('Authorization')).toBe(`Bearer ${jwt(2)}`);
  });

  it('does not let ordinary renewal hide a later concurrent session rotation', async () => {
    const jwt = (sub: string, marker: number): string => `header.${btoa(JSON.stringify({ sub, marker })).replace(/=/g, '')}.signature`;
    const initial = jwt('session-one', 0);
    const renewed = jwt('session-one', 1);
    const rotated = jwt('session-two', 2);
    const renewal = deferred<Response>();
    const rotation = deferred<Response>();
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(status()).mockReturnValueOnce(renewal.promise).mockReturnValueOnce(rotation.promise).mockResolvedValue(new Response());
    const instance = client(fetcher, { token: initial });
    await instance.initialize();
    const a = instance.request('ordinary');
    const b = instance.request('rotate', { method: 'POST' });
    renewal.resolve(new Response(null, { headers: metadataHeaders(renewed) }));
    await a;
    rotation.resolve(new Response(null, { headers: metadataHeaders(rotated) }));
    await b;
    await instance.request('check');
    expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).get('Authorization')).toBe(`Bearer ${rotated}`);
  });

  it('rejects a late same-session renewal after a concurrent rotation', async () => {
    const jwt = (sub: string, marker: number): string => `header.${btoa(JSON.stringify({ sub, marker }))}.signature`;
    const renewal = deferred<Response>();
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(status()).mockReturnValueOnce(renewal.promise).mockResolvedValueOnce(new Response(null, { headers: metadataHeaders(jwt('two', 1)) }));
    const instance = client(fetcher, { token: jwt('one', 0) });
    await instance.initialize();
    const pending = instance.request('ordinary');
    await instance.request('rotate', { method: 'POST' });
    renewal.resolve(new Response(null, { headers: metadataHeaders(jwt('one', 2)) }));
    await expect(pending).rejects.toThrow('Stale');
  });

  it('rejects a stale business payload after logout even if fetch ignores abort', async () => {
    const pending = deferred<Response>();
    const fetcher = vi.fn<typeof fetch>().mockReturnValueOnce(pending.promise).mockResolvedValueOnce(new Response(null, { status: 204 }));
    const instance = client(fetcher);
    const request = instance.request('private-data');
    await instance.logout();
    pending.resolve(Response.json({ private: 'old-session' }));
    await expect(request).rejects.toThrow('Stale');
  });

  it('coalesces initialize and refresh', async () => {
    const pending = deferred<Response>();
    const fetcher = vi.fn<typeof fetch>().mockReturnValueOnce(pending.promise).mockResolvedValue(status());
    const instance = client(fetcher);
    const first = instance.initialize();
    expect(instance.initialize()).toBe(first);
    pending.resolve(status());
    await first;
    const refresh = instance.refresh();
    expect(instance.refresh()).toBe(refresh);
    await refresh;
    expect(fetcher).toHaveBeenCalledTimes(2);
  });

  it('cancels outstanding requests and ignores late refresh after logout', async () => {
    const pending = deferred<Response>();
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(status()).mockReturnValueOnce(pending.promise).mockResolvedValueOnce(new Response(null, { status: 204 }));
    const instance = client(fetcher);
    await instance.initialize();
    const refresh = instance.refresh();
    const signal = fetcher.mock.calls[1]?.[1]?.signal;
    expect(await instance.logout()).toEqual({ revoked: true });
    expect(signal?.aborted).toBe(true);
    pending.resolve(status('header', 'late-token'));
    await refresh;
    expect(instance.state).toMatchObject({ status: 'unauthenticated', revocation: 'confirmed' });
  });

  it('ignores stale business responses after header rotation', async () => {
    const stale = deferred<Response>();
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(status()).mockReturnValueOnce(stale.promise).mockResolvedValueOnce(new Response(null, { headers: metadataHeaders('rotated') })).mockResolvedValue(new Response());
    const instance = client(fetcher);
    await instance.initialize();
    const old = instance.request('old');
    await instance.request('rotate', { method: 'POST' });
    stale.resolve(new Response(null, { headers: metadataHeaders('old') }));
    await expect(old).rejects.toThrow('Stale');
    await instance.request('check');
    expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).get('Authorization')).toBe('Bearer rotated');
  });

  it('ignores stale cookie CSRF after rotation', async () => {
    const stale = deferred<Response>();
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(status('cookie')).mockReturnValueOnce(stale.promise).mockResolvedValueOnce(new Response(null, { headers: metadataHeaders(undefined, 'csrf-two') })).mockResolvedValue(new Response());
    const instance = client(fetcher, { transport: 'cookie' });
    await instance.initialize();
    const old = instance.request('old');
    await instance.request('rotate', { method: 'POST' });
    stale.resolve(new Response(null, { headers: metadataHeaders(undefined, 'csrf-one') }));
    await expect(old).rejects.toThrow('Stale');
    await instance.request('check', { method: 'POST' });
    expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).get('X-CSRF-Token')).toBe('csrf-two');
  });

  it('accepts committed rotation metadata on a normal 422 response', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(new Response(null, { status: 422, headers: metadataHeaders('rotated') })).mockResolvedValue(new Response());
    const instance = client(fetcher);
    expect((await instance.request('mutate', { method: 'POST' })).status).toBe(422);
    await instance.request('check');
    expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).get('Authorization')).toBe('Bearer rotated');
  });

  it.each([401, 403, 409, 503])('never replays a mutation after status %i', async code => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(null, { status: code }));
    const instance = client(fetcher);
    expect((await instance.request('data', { method: 'POST', body: 'change' })).status).toBe(code);
    expect(fetcher).toHaveBeenCalledTimes(1);
  });

  it('does not replay a failed network mutation', async () => {
    const fetcher = vi.fn<typeof fetch>().mockRejectedValue(new TypeError('Network failure'));
    await expect(client(fetcher).request('data', { method: 'POST' })).rejects.toThrow();
    expect(fetcher).toHaveBeenCalledTimes(1);
  });

  it.each(['network', 'storage'])('reports unconfirmed logout for %s failure', async failure => {
    const fetcher = vi.fn<typeof fetch>();
    if (failure === 'network') fetcher.mockRejectedValue(new TypeError('offline'));
    else fetcher.mockResolvedValue(new Response(null, { status: 503 }));
    const instance = client(fetcher);
    expect(await instance.logout()).toEqual({ revoked: false });
    expect(instance.state).toMatchObject({ status: 'unauthenticated', revocation: 'unconfirmed' });
  });

  it('destroy aborts, notifies, cleans timers/listeners, and never logs out remotely', async () => {
    const pending = deferred<Response>();
    const fetcher = vi.fn<typeof fetch>().mockReturnValue(pending.promise);
    const instance = client(fetcher);
    const listener = vi.fn();
    instance.subscribe(listener);
    const request = instance.request('data');
    const signal = fetcher.mock.lastCall?.[1]?.signal;
    instance.destroy();
    instance.destroy();
    expect(listener).toHaveBeenCalledTimes(1);
    expect(listener.mock.lastCall?.[0].status).toBe('destroyed');
    expect(signal?.aborted).toBe(true);
    pending.resolve(new Response(null, { headers: metadataHeaders('late') }));
    await expect(request).rejects.toThrow('Stale');
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(() => instance.initialize()).toThrow('destroyed');
  });

  it('propagates caller cancellation', async () => {
    const fetcher = vi.fn<typeof fetch>().mockImplementation((_url, init) => new Promise((_resolve, reject) => init?.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))));
    const controller = new AbortController();
    const request = client(fetcher).request('data', { signal: controller.signal });
    controller.abort();
    await expect(request).rejects.toMatchObject({ name: 'AbortError' });
  });
});

describe('metadata, storage and scheduling', () => {
  it('rejects malformed, mismatched and expired session metadata', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(Response.json({ authenticated: true, transport: 'cookie', ...deadlines() })).mockResolvedValueOnce(Response.json({ authenticated: true, transport: 'header', tokenExpiresAt: 'bad' })).mockResolvedValueOnce(Response.json({ authenticated: true, transport: 'header', ...deadlines(), tokenExpiresAt: 1 }));
    const instance = client(fetcher);
    await expect(instance.initialize()).rejects.toThrow('transport');
    await expect(instance.initialize()).rejects.toThrow('deadline');
    await instance.initialize();
    expect(instance.state.status).toBe('unauthenticated');
  });

  it('rejects replacement credentials without complete metadata', async () => {
    const instance = client(vi.fn<typeof fetch>().mockResolvedValue(new Response(null, { headers: { 'X-Session-Token': 'new' } })));
    await expect(instance.request('data')).rejects.toThrow('metadata');
  });

  it('uses isolated application and base path storage keys and removes only its own credential', async () => {
    const map = new Map<string, string>();
    vi.stubGlobal('sessionStorage', { getItem: (key: string) => map.get(key) ?? null, setItem: (key: string, value: string) => map.set(key, value), removeItem: (key: string) => map.delete(key) });
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(status());
    const one = client(fetcher, { storage: 'sessionStorage' });
    client(fetcher, { storage: 'sessionStorage', application: 'two', token: 'two' });
    client(fetcher, { storage: 'sessionStorage', baseUrl: 'https://api.example.test/other/', token: 'three' });
    expect(map.size).toBe(3);
    one.destroy();
    expect(map.size).toBe(2);
    expect([...map.values()].sort()).toEqual(['three', 'two']);
  });

  it('falls back to memory on all storage failures', async () => {
    vi.stubGlobal('sessionStorage', { getItem() { throw Error(); }, setItem() { throw Error(); }, removeItem() { throw Error(); } });
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(status('header', 'replacement')).mockResolvedValue(new Response());
    const instance = client(fetcher, { storage: 'sessionStorage' });
    await instance.initialize();
    await instance.request('data');
    expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).get('Authorization')).toBe('Bearer replacement');
    instance.destroy();
  });

  it('freezes public state and isolates observer failures', async () => {
    const instance = client(vi.fn<typeof fetch>().mockResolvedValue(status()));
    instance.subscribe(() => { throw Error('observer'); });
    const listener = vi.fn();
    const unsubscribe = instance.subscribe(listener);
    await instance.initialize();
    expect(Object.isFrozen(instance.state)).toBe(true);
    expect(Object.isFrozen(instance.state.metadata)).toBe(true);
    unsubscribe();
    instance.destroy();
    expect(listener).toHaveBeenCalledTimes(1);
  });

  it('expires an offline token even while the record deadlines remain live', async () => {
    vi.useFakeTimers();
    const now = Math.floor(Date.now() / 1000);
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(Response.json({ authenticated: true, transport: 'header', ...deadlines(), tokenExpiresAt: now + 2 }));
    const instance = client(fetcher);
    await instance.initialize();
    await vi.advanceTimersByTimeAsync(2100);
    expect(instance.state.status).toBe('unauthenticated');
    expect(fetcher).toHaveBeenCalledTimes(1);
  });

  it('bounds automatic refresh retries with backoff and clears scheduling on destroy', async () => {
    vi.useFakeTimers();
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(status()).mockRejectedValue(new Error('offline'));
    const instance = client(fetcher, { autoRefresh: true, refreshAheadSeconds: 7200 });
    await instance.initialize();
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetcher).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(2000);
    expect(fetcher).toHaveBeenCalledTimes(3);
    await vi.advanceTimersByTimeAsync(4000);
    expect(fetcher).toHaveBeenCalledTimes(4);
    await vi.advanceTimersByTimeAsync(60000);
    expect(fetcher).toHaveBeenCalledTimes(4);
    instance.destroy();
    expect(vi.getTimerCount()).toBe(0);
  });
});
