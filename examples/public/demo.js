'use strict';

const mode = document.body.dataset.transport;
const baseUrl = `${window.ExampleConfig.apiOrigin}/api/${mode}/`;
const output = document.getElementById('output');
const state = document.getElementById('state');
let client;

function createClient(token) {
  client?.destroy();
  client = window.SlimSession.createSessionClient({
    baseUrl, application: `example-${mode}`, transport: mode,
    endpoints: { status: 'session/status', refresh: 'session/refresh', logout: 'session/logout' },
    storage: 'memory', ...(token ? { token } : {}),
  });
  client.subscribe(value => { state.textContent = `${value.status} / revocation: ${value.revocation}`; });
  return client;
}

async function login() {
  const init = { method: 'POST', credentials: mode === 'cookie' ? 'same-origin' : 'omit', redirect: 'error' };
  const challenge = await fetch(`${baseUrl}login-challenge`, { ...init, headers: { 'X-Login-Bootstrap': '1' } });
  if (!challenge.ok) throw new Error(`Login challenge failed: ${challenge.status}`);
  const { proof } = await challenge.json();
  const response = await fetch(`${baseUrl}login`, { ...init,
    headers: { 'Content-Type': 'application/json', 'X-Login-CSRF': proof }, body: JSON.stringify({ username: 'demo' }) });
  if (!response.ok) throw new Error(`Simulated login failed: ${response.status}`);
  // This header is read only in header mode. Cookie JWTs are never exposed to JavaScript.
  createClient(mode === 'header' ? response.headers.get('X-Session-Token') : undefined);
  return client.initialize();
}

async function act(action) {
  if (action === 'login') return login();
  if (action === 'status') return client.initialize();
  if (action === 'refresh') return client.refresh();
  if (action === 'logout') return client.logout();
  if (action === 'destroy') { client.destroy(); return { localOnly: true, serverRevocation: 'not attempted' }; }
  const paths = { data: ['data', 'GET'], mutate: ['data', 'POST'], flash: ['flash', 'POST'], rotate: ['rotate', 'POST'], stream: ['stream', 'GET'] };
  const [path, method] = paths[action];
  const response = await client.request(path, { method });
  if (!response.ok) throw new Error(`Request failed: ${response.status}; never automatically replayed`);
  if (action === 'stream') {
    output.textContent = '';
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      output.textContent += decoder.decode(value, { stream: true });
    }
    return 'Stream finished. Each tick used an independent storage validation.';
  }
  return response.json();
}

document.querySelectorAll('[data-action]').forEach(button => {
  button.addEventListener('click', async () => {
    button.disabled = true;
    try { output.textContent = JSON.stringify(await act(button.dataset.action), null, 2); }
    catch (error) { output.textContent = error.message; }
    finally { button.disabled = false; }
  });
});
const widget = document.getElementById('widget');
if (widget) widget.src = `${window.ExampleConfig.widgetOrigin}/widget.html`;
createClient().initialize().catch(error => { output.textContent = error.message; });
window.addEventListener('pagehide', () => client.destroy(), { once: true });
