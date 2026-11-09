import { createSessionClient, type SessionClient, type Transport } from '@semhoun/slim-session';

/** Use cookie on the API's own origin; use header for the second-origin widget. */
export function exampleClient(apiOrigin: string, transport: Transport, token?: string): SessionClient {
  return createSessionClient({
    baseUrl: `${apiOrigin}/api/${transport}/`, application: `example-${transport}`, transport,
    endpoints: { status: 'session/status', refresh: 'session/refresh', logout: 'session/logout' },
    storage: 'memory', ...(transport === 'header' && token ? { token } : {}),
  });
}

export async function simulatedLogin(apiOrigin: string, transport: Transport): Promise<SessionClient> {
  const base = `${apiOrigin}/api/${transport}/`;
  const init: RequestInit = { method: 'POST', credentials: transport === 'cookie' ? 'same-origin' : 'omit', redirect: 'error' };
  const challenge = await fetch(`${base}login-challenge`, { ...init, headers: { 'X-Login-Bootstrap': '1' } });
  if (!challenge.ok) throw new Error(`Login challenge failed: ${challenge.status}`);
  const { proof } = await challenge.json() as { proof: string };
  const login = await fetch(`${base}login`, { ...init,
    headers: { 'Content-Type': 'application/json', 'X-Login-CSRF': proof }, body: JSON.stringify({ username: 'demo' }) });
  if (!login.ok) throw new Error(`Login failed: ${login.status}`);
  const token = transport === 'header' ? login.headers.get('X-Session-Token') ?? undefined : undefined;
  const client = exampleClient(apiOrigin, transport, token);
  await client.initialize();
  return client;
}

/** Identical lifecycle to public/demo.js, suitable for a typed application integration. */
export async function exercise(client: SessionClient): Promise<void> {
  const unsubscribe = client.subscribe(state => console.info(state.status, state.metadata));
  try {
    await client.request('data', { method: 'POST' });
    await client.request('flash', { method: 'POST' });
    await client.request('data');
    await client.refresh();
    await client.request('rotate', { method: 'POST' });
    const stream = await client.request('stream');
    await stream.text();
    const result = await client.logout();
    if (!result.revoked) throw new Error('Local cleanup completed, but server revocation is unconfirmed.');
  } finally {
    unsubscribe();
    client.destroy(); // Local teardown alone is never server logout.
  }
}
