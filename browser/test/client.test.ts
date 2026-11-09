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

describe('optional cookie CSRF', () => {
  it.each([undefined, true])('requires bootstrap for mutations unless explicitly disabled (csrfRequired=%s)', async csrfRequired => {
    const fetcher = vi.fn<typeof fetch>();
    const instance = client(fetcher, { transport: 'cookie', ...(csrfRequired === undefined ? {} : { csrfRequired }) });
    await expect(instance.request('data', { method: 'POST' })).rejects.toThrow('Initialize');
    await expect(instance.refresh()).rejects.toThrow('Initialize');
    expect(await instance.logout()).toEqual({ revoked: false });
    expect(instance.state.revocation).toBe('unconfirmed');
    expect(fetcher).not.toHaveBeenCalled();
  });

  it.each(['GET', 'HEAD', 'OPTIONS'])('preserves the default safe-method exemption for %s', async method => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response());
    await client(fetcher, { transport: 'cookie' }).request('data', { method });
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).has('X-CSRF-Token')).toBe(false);
  });

  it.each(['request', 'refresh', 'logout'] as const)('sends cookie %s without bootstrap when disabled', async operation => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(status('cookie'));
    const instance = client(fetcher, { transport: 'cookie', csrfRequired: false });
    expect(fetcher).not.toHaveBeenCalled();
    if (operation === 'request') await instance.request('data', { method: 'POST', credentials: 'omit', redirect: 'follow' });
    else if (operation === 'refresh') expect((await instance.refresh()).status).toBe('authenticated');
    else {
      expect(await instance.logout()).toEqual({ revoked: true });
      expect(instance.state).toMatchObject({ status: 'unauthenticated', revocation: 'confirmed' });
    }
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.lastCall?.[0]).toBe(`https://api.example.test/app/${operation === 'request' ? 'data' : `session/${operation}`}`);
    const init = fetcher.mock.lastCall?.[1];
    expect(init).toMatchObject({ method: 'POST', credentials: 'include', redirect: 'error', cache: 'no-store', referrerPolicy: 'no-referrer' });
    expect(new Headers(init?.headers).has('X-CSRF-Token')).toBe(false);
    expect(new Headers(init?.headers).has('Authorization')).toBe(false);
  });

  it('does not re-enable insertion after receiving CSRF in status, response headers, or refresh', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(status('cookie'))
      .mockResolvedValueOnce(new Response(null, { headers: metadataHeaders(undefined, 'csrf-two') }))
      .mockResolvedValueOnce(status('cookie', undefined, 'csrf-three'))
      .mockResolvedValueOnce(new Response(null, { status: 204 }));
    const instance = client(fetcher, { transport: 'cookie', csrfRequired: false });
    await instance.initialize();
    await instance.request('data', { method: 'PATCH' });
    await instance.refresh();
    expect(await instance.logout()).toEqual({ revoked: true });
    expect(fetcher).toHaveBeenCalledTimes(4);
    for (const [, init] of fetcher.mock.calls) {
      expect(init?.credentials).toBe('include');
      expect(new Headers(init?.headers).has('X-CSRF-Token')).toBe(false);
    }
  });

  it.each(['X-CSRF-Token', 'X-Custom-CSRF'])('still reserves the configured proof header %s when disabled', async csrfHeader => {
    const fetcher = vi.fn<typeof fetch>();
    const instance = client(fetcher, { transport: 'cookie', csrfRequired: false, csrfHeader });
    await expect(instance.request('data', { method: 'POST', headers: { [csrfHeader.toLowerCase()]: 'caller-proof' } })).rejects.toThrow('managed');
    expect(fetcher).not.toHaveBeenCalled();
  });

  it.each(['request', 'refresh', 'logout'] as const)('does not retry or negotiate after cookie %s receives 403', async operation => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(null, { status: 403 }));
    const instance = client(fetcher, { transport: 'cookie', csrfRequired: false });
    if (operation === 'request') expect((await instance.request('data', { method: 'POST', body: 'change' })).status).toBe(403);
    else if (operation === 'refresh') await expect(instance.refresh()).rejects.toMatchObject({ status: 403 });
    else {
      expect(await instance.logout()).toEqual({ revoked: false });
      expect(instance.state).toMatchObject({ status: 'unauthenticated', revocation: 'unconfirmed' });
    }
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).has('X-CSRF-Token')).toBe(false);
  });

  it('reports network logout failure as unconfirmed even without CSRF enforcement', async () => {
    const fetcher = vi.fn<typeof fetch>().mockRejectedValue(new TypeError('offline'));
    const instance = client(fetcher, { transport: 'cookie', csrfRequired: false });
    expect(await instance.logout()).toEqual({ revoked: false });
    expect(instance.state).toMatchObject({ status: 'unauthenticated', revocation: 'unconfirmed' });
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.lastCall?.[1]?.credentials).toBe('include');
  });

  it.each([undefined, true, false])('leaves Bearer request, refresh, and logout unchanged (csrfRequired=%s)', async csrfRequired => {
    const fetcher = vi.fn<typeof fetch>().mockImplementation(async () => status());
    const instance = client(fetcher, csrfRequired === undefined ? {} : { csrfRequired });
    await expect(instance.request('data', { method: 'POST', headers: { 'X-CSRF-Token': 'caller-proof' } })).rejects.toThrow('managed');
    await expect(instance.request('data', { headers: { Authorization: 'caller-token' } })).rejects.toThrow('managed');
    await instance.request('data', { method: 'POST', credentials: 'include' });
    await instance.refresh();
    expect(await instance.logout()).toEqual({ revoked: true });
    expect(fetcher).toHaveBeenCalledTimes(3);
    for (const [, init] of fetcher.mock.calls) {
      expect(init?.credentials).toBe('omit');
      expect(new Headers(init?.headers).get('Authorization')).toBe('Bearer initial');
      expect(new Headers(init?.headers).has('X-CSRF-Token')).toBe(false);
    }
  });
});

describe('lifecycle and concurrency', () => {
  it.each([
    ['header', 'before'], ['header', 'after'], ['cookie', 'before'], ['cookie', 'after'],
  ] as const)('blocks new %s operations during logout with stale status settling %s logout', async (transport, order) => {
    const body = deferred<unknown>();
    const logout = deferred<Response>();
    const stale = status(transport);
    vi.spyOn(stale, 'json').mockReturnValue(body.promise);
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(status(transport))
      .mockResolvedValueOnce(stale).mockReturnValueOnce(logout.promise);
    const instance = client(fetcher, { transport });
    await instance.initialize();
    const pending = instance.initialize();
    await vi.waitFor(() => expect(stale.json).toHaveBeenCalled());
    const observed: string[] = [];
    const reentrant: unknown[] = [];
    const unsubscribe = instance.subscribe(state => {
      observed.push(`${state.status}:${state.revocation}`);
      try { instance.initialize(); } catch (error) { reentrant.push(error); }
    });
    const first = instance.logout();
    expect(instance.logout()).toBe(first);
    expect(() => instance.initialize()).toThrow('logout');
    expect(() => instance.refresh()).toThrow('logout');
    await expect(instance.request('data')).rejects.toThrow('logout');
    expect(fetcher).toHaveBeenCalledTimes(3);
    const init = fetcher.mock.lastCall?.[1];
    expect(init?.signal?.aborted).toBe(false);
    expect(new Headers(init?.headers).get(transport === 'cookie' ? 'X-CSRF-Token' : 'Authorization'))
      .toBe(transport === 'cookie' ? 'csrf-one' : 'Bearer initial');
    const settleStatus = async () => {
      body.resolve({ authenticated: true, transport, ...deadlines(), csrfToken: 'stale' });
      await pending;
    };
    if (order === 'before') await settleStatus();
    logout.resolve(new Response(null, { status: 204 }));
    expect(await first).toEqual({ revoked: true });
    if (order === 'after') await settleStatus();
    expect(observed).toEqual(['unauthenticated:unconfirmed', 'unauthenticated:confirmed']);
    expect(reentrant).toHaveLength(2);
    expect(instance.state).toMatchObject({ status: 'unauthenticated', revocation: 'confirmed' });
    unsubscribe();
    fetcher.mockResolvedValueOnce(Response.json({ authenticated: false, transport }));
    await instance.initialize();
    expect(instance.state.status).toBe('unauthenticated');
  });

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
  it('allows peer observers to destroy clients during global cleanup without recursive notifications', () => {
    const clear = vi.fn();
    vi.stubGlobal('sessionStorage', { clear });
    const one = client(vi.fn<typeof fetch>());
    const two = client(vi.fn<typeof fetch>());
    const three = client(vi.fn<typeof fetch>());
    two.subscribe(() => { three.destroy(); two.destroy(); });
    one.destroy();
    expect(clear).toHaveBeenCalledTimes(1);
    expect(two.state.status).toBe('destroyed');
    expect(three.state.status).toBe('destroyed');
  });

  it.each(['header', 'cookie'] as const)('clears unrelated tab data on %s logout and blocks peer restoration', async transport => {
    const map = new Map<string, string>();
    const clear = vi.fn(() => map.clear());
    vi.stubGlobal('sessionStorage', { getItem: (key: string) => map.get(key) ?? null,
      setItem: (key: string, value: string) => map.set(key, value), clear });
    const pending = deferred<Response>();
    const peerFetch = vi.fn<typeof fetch>().mockReturnValueOnce(pending.promise).mockResolvedValue(new Response());
    const peer = client(peerFetch, { application: 'peer', storage: 'sessionStorage' });
    const inFlight = peer.request('pending');
    const fetcher = vi.fn<typeof fetch>().mockImplementation(async url => String(url).endsWith('/logout') ? new Response() : status(transport));
    const instance = client(fetcher, { transport });
    await instance.initialize();
    map.set('unrelated-draft', 'must disappear');
    const before = clear.mock.calls.length;
    await instance.logout();
    expect(clear).toHaveBeenCalledTimes(before + 1);
    expect(map.size).toBe(0);
    expect(instance.persistentCleanupFailed).toBe(false);
    expect(peer.state.status).toBe('unauthenticated');
    pending.resolve(new Response(null, { headers: metadataHeaders('stale') }));
    await expect(inFlight).rejects.toThrow('Stale');
    await peer.request('check');
    expect(new Headers(peerFetch.mock.lastCall?.[1]?.headers).has('Authorization')).toBe(false);
    expect(map.size).toBe(0);
  });

  it('does not clear unrelated storage during normal credential persistence', async () => {
    const map = new Map<string, string>();
    const clear = vi.fn(() => map.clear());
    vi.stubGlobal('sessionStorage', { getItem: (key: string) => map.get(key) ?? null,
      setItem: (key: string, value: string) => map.set(key, value), clear });
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(status('header', 'renewed'));
    const instance = client(fetcher, { storage: 'sessionStorage' });
    clear.mockClear();
    map.set('draft', 'keep');
    await instance.initialize();
    expect(clear).not.toHaveBeenCalled();
    expect(map.get('draft')).toBe('keep');
  });

  it('reports cookie cleanup failure globally and recovers with a full clear', async () => {
    const map = new Map([['unrelated', 'draft'], ['slim-session:other', 'stale']]);
    let fail = true;
    vi.stubGlobal('sessionStorage', { getItem: (key: string) => map.get(key) ?? null,
      setItem: (key: string, value: string) => map.set(key, value),
      clear: () => { if (fail) throw Error('blocked'); map.clear(); } });
    const cookie = client(vi.fn<typeof fetch>(), { transport: 'cookie' });
    cookie.destroy();
    expect(cookie.persistentCleanupFailed).toBe(true);
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response());
    const restored = client(fetcher, { application: 'other', storage: 'sessionStorage', token: '' });
    await restored.request('check');
    expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).has('Authorization')).toBe(false);
    expect(restored.persistentCleanupFailed).toBe(true);
    fail = false;
    client(fetcher, { storage: 'sessionStorage', token: 'fresh' });
    expect([...map.values()]).toEqual(['fresh']);
    expect(cookie.persistentCleanupFailed).toBe(false);
  });

  it.each(['throws', 'unavailable'] as const)('quarantines failed cleanup when storage %s until removal succeeds', async failure => {
    const map = new Map<string, string>();
    let fail = false;
    const storage = {
      getItem: (key: string) => map.get(key) ?? null,
      setItem: (key: string, value: string) => { map.set(key, value); },
      clear: () => { if (fail) throw Error('blocked'); map.clear(); },
    };
    vi.stubGlobal('sessionStorage', storage);
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(null, { status: 204 }));
    const options = { storage: 'sessionStorage' as const, application: `quarantine-${failure}` };
    const restore = () => {
      const config = { ...defaults, ...options, fetch: fetcher };
      delete config.token;
      const instance = createSessionClient(config);
      clients.push(instance);
      return instance;
    };
    const original = client(fetcher, options);
    fail = true;
    if (failure === 'unavailable') vi.stubGlobal('sessionStorage', undefined);
    expect(await original.logout()).toEqual({ revoked: true });
    expect(original.persistentCleanupFailed).toBe(true);
    expect(original.state.revocation).toBe('confirmed');
    vi.stubGlobal('sessionStorage', storage);
    const restored = restore();
    await restored.request('check');
    expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).has('Authorization')).toBe(false);
    expect(restored.persistentCleanupFailed).toBe(true);
    const explicit = client(fetcher, { ...options, token: 'new-login' });
    await explicit.request('check');
    expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).get('Authorization')).toBe('Bearer new-login');
    expect([...map.values()]).toEqual(['initial']);
    expect(explicit.persistentCleanupFailed).toBe(true);
    fail = false;
    const cleaned = restore();
    expect(cleaned.persistentCleanupFailed).toBe(false);
    expect(map.size).toBe(0);
    client(fetcher, { ...options, token: 'fresh' });
    const fresh = restore();
    await fresh.request('check');
    expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).get('Authorization')).toBe('Bearer fresh');
  });

  it.each(['tokenExpiresAt', 'idleExpiresAt', 'absoluteExpiresAt'] as const)('expires at %s while automatic refresh hangs and ignores late responses', async deadline => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-01-01T00:00:00Z'));
    const pending = deferred<Response>();
    const business = deferred<Response>();
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(Response.json({
      authenticated: true, transport: 'header', ...deadlines(), [deadline]: Date.now() / 1000 + 3,
    })).mockReturnValueOnce(business.promise).mockReturnValueOnce(pending.promise).mockResolvedValue(new Response());
    const instance = client(fetcher, { autoRefresh: true, refreshAheadSeconds: 7200 });
    await instance.initialize();
    const request = instance.request('pending');
    await vi.advanceTimersByTimeAsync(1000);
    const refresh = instance.refresh();
    expect(fetcher).toHaveBeenCalledTimes(3);
    await vi.advanceTimersByTimeAsync(2000);
    expect(instance.state.status).toBe('unauthenticated');
    expect(fetcher.mock.calls[2]?.[1]?.signal?.aborted).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
    pending.resolve(status('header', 'late'));
    business.resolve(new Response(null, { headers: metadataHeaders('late-business') }));
    await refresh;
    await expect(request).rejects.toThrow('Stale');
    expect(instance.state.status).toBe('unauthenticated');
    await instance.request('check');
    expect(new Headers(fetcher.mock.lastCall?.[1]?.headers).has('Authorization')).toBe(false);
  });

  it.each([false, true])('clamps long deadlines without early expiry or refresh (auto=%s)', async autoRefresh => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-01-01T00:00:00Z'));
    const expiresAt = Date.now() / 1000 + 3_000_000;
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(Response.json({ authenticated: true, transport: 'header',
      tokenExpiresAt: expiresAt, idleExpiresAt: expiresAt, absoluteExpiresAt: expiresAt }));
    const instance = client(fetcher, { autoRefresh, refreshAheadSeconds: 0 });
    await instance.initialize();
    await vi.advanceTimersByTimeAsync(2_147_483_647);
    expect(instance.state.status).toBe('authenticated');
    expect(fetcher).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(3_000_000_000 - 2_147_483_647);
    expect(instance.state.status).toBe('unauthenticated');
    expect(vi.getTimerCount()).toBe(0);
  });

  it('replaces expiry scheduling after successful refresh and clears both timers on logout', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-01-01T00:00:00Z'));
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(Response.json({
      authenticated: true, transport: 'cookie', ...deadlines(), tokenExpiresAt: Date.now() / 1000 + 3, csrfToken: 'csrf-one',
    })).mockImplementation(async url => String(url).endsWith('/logout') ? new Response() : status('cookie'));
    const instance = client(fetcher, { transport: 'cookie', autoRefresh: true, refreshAheadSeconds: 2 });
    await instance.initialize();
    await vi.advanceTimersByTimeAsync(3000);
    expect(instance.state.status).toBe('authenticated');
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(vi.getTimerCount()).toBe(2);
    await instance.logout();
    expect(vi.getTimerCount()).toBe(0);
  });

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

  it('uses isolated storage keys for writes but clears all tab data on destruction', async () => {
    const map = new Map<string, string>();
    vi.stubGlobal('sessionStorage', { getItem: (key: string) => map.get(key) ?? null, setItem: (key: string, value: string) => map.set(key, value), clear: () => map.clear() });
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(status());
    const one = client(fetcher, { storage: 'sessionStorage' });
    client(fetcher, { storage: 'sessionStorage', application: 'two', token: 'two' });
    client(fetcher, { storage: 'sessionStorage', baseUrl: 'https://api.example.test/other/', token: 'three' });
    expect(map.size).toBe(3);
    map.set('unrelated', 'draft');
    one.destroy();
    expect(map.size).toBe(0);
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
