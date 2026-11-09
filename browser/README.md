# @semhoun/slim-session

Framework-independent browser client for server-side sessions. Session data remains
on the server. This package never interprets a JWT as authentication proof, patches
global fetch, or starts requests at import time. Runtime dependencies: none.

## Package Usage

Install the locally built package from this directory or its package archive.
The ESM entry point includes TypeScript declarations and is safe to import during
SSR. Constructing a memory-backed client also needs no DOM. Requests require
native fetch or an explicitly injected compatible `fetch` implementation.

```ts
import { createSessionClient } from '@semhoun/slim-session';

const session = createSessionClient({
  application: 'my-app',
  baseUrl: 'https://example.test/api/',
  transport: 'cookie',
  csrfRequired: true,
  endpoints: {
    status: 'session/status',
    refresh: 'session/refresh',
    logout: 'session/logout',
  },
});

const unsubscribe = session.subscribe(state => {
  console.log(state.status, state.metadata, state.revocation);
});
await session.initialize();
const response = await session.request('profile', { method: 'GET' });
if (!response.ok) throw new Error(`Request failed: ${response.status}`);
const result = await session.logout();
if (!result.revoked) console.warn('Server-side revocation is unconfirmed');
unsubscribe();
session.destroy();
```

Application login is deliberately not built in. Perform your application's login
flow with its own login-CSRF protection. After login, initialize the cookie client.
For header transport, pass the login-issued credential as `token` when constructing
the client. The client must never receive session credentials in a URL.

```ts
const headerSession = createSessionClient({
  application: 'isolated-widget',
  baseUrl: 'https://api.example.test/widget/',
  transport: 'header',
  token: loginResponse.headers.get('X-Session-Token')!,
  storage: 'memory',
  endpoints: { status: 'status', refresh: 'refresh', logout: 'logout' },
});
await headerSession.initialize();
```

## Plain HTML

The same implementation ships as `dist/slim-session.js` and its minified variant
`dist/slim-session.min.js`. Copy a versioned asset to your site's static directory.
Classic scripts expose only `window.SlimSession`; no module loader, framework,
runtime dependency, npm installation, or consumer build step is needed. Load your
initialization code from a separate script for restrictive CSP deployments:

```html
<script src="/assets/slim-session-0.1.0.min.js" defer></script>
<script src="/assets/session-init.js" defer></script>
```

```js
// session-init.js
const session = SlimSession.createSessionClient({
  application: 'my-app',
  baseUrl: 'https://example.test/api/',
  transport: 'cookie',
  endpoints: { status: 'session/status', refresh: 'session/refresh', logout: 'session/logout' },
});
session.initialize().catch(error => console.error(error.message));
```

Serve pages through HTTP(S), not `file://`. Configure CSP `connect-src` for your
explicit API origin and `script-src` for your self-hosted external scripts.

## API

- `createSessionClient(options)` or `new SessionClient(options)` creates an independent client without sending requests.
- `initialize()` fetches status with GET and bootstraps cookie CSRF. Concurrent calls coalesce. Call it after login and after resuming a suspended page when needed.
- `request(url, init?)` sends exactly one request and returns the original Response. It never refreshes and retries application work, including GET. Caller cancellation through `init.signal` is supported. HTTP errors are returned, not automatically thrown.
- `refresh()` sends POST and coalesces concurrent refresh calls. Invalid or expired credentials cannot recover themselves.
- `logout()` sends POST, always clears in-memory credentials, and returns `{revoked:boolean}`. Concurrent calls coalesce. Until it settles, `initialize()` and `refresh()` throw `SessionError`, and `request()` rejects with it. Only a successful HTTP response confirms revocation; network, CSRF, and server failures report false. Browser storage cleanup failures do not change this server result.
- `destroy()` aborts client-owned requests, clears in-memory credentials and timers, attempts full tab sessionStorage clearing, notifies subscribers once, and removes listeners. It does not revoke the server session. Later operations throw.
- `subscribe(listener)` returns an unsubscribe function. Notifications contain immutable state without JWT or CSRF secrets. Observer exceptions cannot interrupt cleanup.
- `state` has `status` (`uninitialized`, `authenticated`, `unauthenticated`, `destroyed`), `metadata` (deadlines or null), and `revocation` (`none`, `confirmed`, `unconfirmed`). Authentication state describes the last server check, not a business permission.
- `persistentCleanupFailed` reports module-wide sessionStorage quarantine after a failed clear or write, including in cookie/memory mode. It is independent of `state.revocation` and remains readable after destroy; successful recovery resets it for all clients.

Required options are `application`, `baseUrl`, `transport`, and `endpoints`.
`baseUrl` must be an absolute HTTP(S) URL without credentials, query, or fragment;
its path is normalized to a directory prefix. Relative endpoints resolve under
that prefix. Requests to other origins or outside the prefix, encoded path
separators, URL userinfo, and fragments are refused. All redirects are refused,
even same-origin redirects. Do not mount endpoints that redirect.

Header defaults are `credentialHeader: 'Authorization'`, `credentialPrefix:
'Bearer '`, `responseTokenHeader: 'X-Session-Token'`. Cookie mutations use
`csrfHeader: 'X-CSRF-Token'`. These header names are configurable. Client-managed
proof headers cannot be overridden per request. Header mode uses `credentials:
'omit'`; cookie mode uses `credentials: 'include'`, ignores response JWTs, and
does not override application Authorization headers. Cookie mode requires
initialize before mutations by default and forbids JavaScript token storage configuration.

### Optional CSRF Control

`SessionClientOptions.csrfRequired?: boolean` defaults to true. Install supporting
browser and PHP package versions before opting out. Usage examples retain the
secure default, either explicitly or by omitting this option.

Only explicit `false` disables the CSRF
bootstrap-token requirement and automatic managed CSRF header insertion for cookie
mutations. This applies instance-wide to `request()`, `refresh()`, and `logout()`,
not individual routes or requests. A returned CSRF token does not re-enable header
insertion. `initialize()` remains available to fetch authoritative session
status; an opt-out does not authenticate the client or create a session.

There is no browser `originRequired` option: browsers control Origin. PHP's
trailing `bool $csrfRequired = true` and `bool $originRequired = true` parameters
independently control server enforcement. Keep browser CSRF enabled when only
the server Origin check is disabled. Set browser `csrfRequired: false` only when
the corresponding server CSRF check is disabled. A client opt-out does not disable
server enforcement: an enforcing server still returns 403 for missing required
proof. There is no automatic negotiation, fallback, or replay of business requests.
A rejected logout still reports revocation as unconfirmed. Restore client proofs
before re-enabling server enforcement.

Trusted-origin/path restrictions, redirect refusal, generation/lifecycle checks,
configuration validation, and the prohibition on caller-supplied managed proof
headers remain unchanged. Cookie requests still use `credentials:
'include'`; header/Bearer transport remains unchanged with `credentials: 'omit'`.
Server CSRF token generation, rotation, storage, response headers, and metadata
continue even when enforcement is disabled. JWT issuance, authentication, and live
storage/deadline validation remain unchanged.

Disabling either server check weakens cross-site request defenses. Disabling both
permits cookie-authenticated mutations without either proof; CORS and SameSite are
not equivalent replacements. Initial login/session creation and login-CSRF
protection remain application responsibilities. GET, HEAD, and OPTIONS remain
exempt from these checks and must never mutate business state. Review the
[four server combinations and risks](../docs/security.md#optional-cookie-protection-controls)
before configuring an exception.

### Storage and Refresh

Header credentials default to memory. Optional `storage: 'sessionStorage'`
persists only the credential, namespaced by application and base origin/path.
Unavailable or throwing storage falls back to memory. Normal writes to different
applications or base paths are isolated. Identically configured clients share the
same persisted slot; use separate application identifiers for independent scopes.
Storage is not an XSS defense. Do not persist credentials in localStorage.

**Cleanup is deliberately not namespaced.** Logout, destroy, 401, unauthenticated
status, and local expiration call `sessionStorage.clear()` in all modes, including
cookie and memory mode. This erases all data in the current page origin's tab
sessionStorage, including unrelated drafts and other applications' credentials.
The scope is the page origin, not the configured API origin. It does not erase
localStorage, other tabs, or HttpOnly cookies. Cookie cleanup still needs server
logout to confirm revocation.

Cleanup invalidates other live clients sharing this module, clears their in-memory
credentials and timers, aborts their requests, and rejects/ignores late responses.
Failed clearing (including inaccessible storage) quarantines all stored-token
restoration across the module. A new persistent client retries a full clear and
starts without a restored credential even if recovery succeeds. An explicitly
supplied new token remains usable in memory, but cannot persist until clear
succeeds. Failed writes also quarantine potentially stale storage. Normal token
writes never clear unrelated data unless recovering a quarantine.

These protections are not durable across reloads, separate realms, or separate
package copies; independent scripts can also write new data after clearing.
Storage cannot be guaranteed erased when inaccessible. Check
`persistentCleanupFailed` separately from server revocation. Confirmed server
revocation remains essential, and cleanup of other clients does not revoke their
server sessions. Reauthenticate affected clients explicitly.

`autoRefresh` defaults to true. `refreshAheadSeconds` defaults to 3600. Scheduling
uses server-provided Unix-second expiration metadata; it never parses the JWT.
Automatic refresh has at most two retries after a failure, using exponential
backoff. Failures do not replay business requests. Local expiration still clears
credentials if refresh fails or never settles, using an independent expiry timer.
Expiration aborts outstanding requests and invalidates late responses. Long timer
delays are clamped and rechecked rather than expiring or refreshing early.
Set `autoRefresh: false` to retain only expiry
scheduling and invoke refresh manually. Browser suspension may prevent renewal:
an expired JWT requires reauthentication, even if the server record still exists.

## HTTP Protocol

Status and refresh return no-store JSON:

```json
{
  "authenticated": true,
  "transport": "cookie",
  "tokenExpiresAt": 1794153600,
  "idleExpiresAt": 1794153600,
  "absoluteExpiresAt": 1794672000,
  "csrfToken": "session-bound-proof"
}
```

Unauthenticated JSON has `authenticated: false` and the configured `transport`.
Cookie responses include CSRF but never JWTs; header responses carry replacement
JWTs through the configured response header. Deadlines must be positive integral
Unix seconds. Successful status checks cannot authenticate a header client that
has no credential. A 401 clears local authentication.

Application responses, including committed 4xx responses, may update scheduling
and credentials using `X-Session-Token-Expires-At`, `X-Session-Idle-Expires-At`,
`X-Session-Absolute-Expires-At`, and optionally `X-CSRF-Token` / `X-Session-Token`.
A replacement header credential requires all three deadline headers. Late
responses cannot restore state after logout, destroy, or observed rotation.
Cookie network responses may still apply Set-Cookie outside JavaScript control;
server-side revocation and storage validation are essential.

Business responses from a stale authentication generation reject with
`SessionError` rather than returning an old session's payload. Same-session
responses do not roll back a newer header credential or newer local deadlines.
An unverified JWT `sub` is used only as an ordering hint to distinguish renewal
from rotation, never as authentication proof or a deadline source. Server
validation remains authoritative, including when the browser applies a late
Set-Cookie response outside the client's control.

The server must enforce deadlines and storage validation on every authenticated
request, plus the enabled Origin and CSRF checks on cookie mutations (both enabled
by default). Configure explicit CORS origins, permitted request headers,
exposed metadata headers, and `Vary: Origin`. Never use wildcard origins with
credentials. Third-party cookie restrictions remain browser policy; use explicit
header transport for affected widgets. The client is not a CORS middleware.

## Development

```sh
npm ci
npm test
npm run typecheck
npm run build
npm pack --dry-run
```

Builds generate ESM, declarations, and both standalone scripts from `src/index.ts`.
The repository's separate `e2e/` suite exercises the built distributions in real
browsers against isolated HTTP examples; unit tests use deterministic fake fetch
and timers. No packages are published by these commands.
