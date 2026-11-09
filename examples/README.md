# Isolated Slim Examples

Two transports share a small Slim application and an isolated SQLite database.
`public/cookie.html` uses same-origin HttpOnly cookies. `public/widget.html` runs
on a second origin and uses header credentials in memory. Neither is a real login
system: the username `demo` simulates successful application authentication.

## Setup

Requirements: PHP 8.3+, Composer, PDO SQLite, and a built browser package.
Run these commands from `examples/`:

```bash
composer install
export EXAMPLE_SECRET_BASE64="$(php -r 'print base64_encode(random_bytes(32));')"
export EXAMPLE_API_ORIGIN=http://localhost:8080
export EXAMPLE_WIDGET_ORIGIN=http://localhost:8081
export EXAMPLE_ALLOW_INSECURE_LOCALHOST=1
php bin/setup.php
```

The secret is mandatory, generated locally, and never written by these examples.
Retain the same environment in both server terminals. Do not commit it. The CLI
creates `examples/var/session.sqlite`, with restrictive permissions, from
`../schemas/sqlite.sql`. HTTP requests never create tables. To use temporary
storage, set `EXAMPLE_SQLITE_PATH` to an absolute path under the system temporary
directory before setup and server startup. Other storage directories are rejected.
Setup recognizes an existing example schema without rewriting it and refuses an
unrecognized nonempty database. Select a fresh isolated path to start over.

Build the self-hosted standalone asset from `browser/` once:

```bash
npm ci
npm run build
```

Plain HTML consumers need no npm, bundler, framework, or runtime dependency. The
example router serves the built `browser/dist/slim-session.js` under `/assets/`.

Start two servers from `examples/`, in terminals with the same environment:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080 router.php
```

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8081 router.php
```

Open `http://localhost:8080/cookie.html`. Its iframe loads the header widget from
`http://localhost:8081/widget.html`. Each transport has an independent namespace,
issuer, and audience. Use separate keys too when deploying distinct applications.
Do not open the HTML with `file://`.

The loopback HTTP switch is an explicit development exception only. Core session
cookies remain `__Host-`, Secure, HttpOnly, Path=/, and SameSite=Lax; browsers must
support Secure cookies on localhost. Prefer HTTPS with a trusted local TLS proxy,
unset the exception, and configure both HTTPS origins. Defaults are
`https://localhost:8443` and `https://localhost:8444`. Never weaken cookies for a
remote HTTP deployment. The PHP development server is not a production server.
Multiple workers let independent requests proceed during the streaming demo.

## Demonstrations

| Action | Route under `/api/cookie/` or `/api/header/` |
| --- | --- |
| Login bootstrap | `POST login-challenge`, then `POST login` |
| Status, no activity extension | `GET session/status` |
| Refresh, no activity extension | `POST session/refresh` |
| Server data and consume flash | `GET data` |
| Increment and queue flash | `POST data` |
| Queue a flash message | `POST flash` |
| Atomic rotation, preserve data | `POST rotate` |
| Close then periodically revalidate | `GET stream` |
| Revoke one session | `POST session/logout` |

Missing credentials never create a session. Login explicitly calls `create()`
only after the demo check. Login proof issuance requires an allowlisted Origin
and custom bootstrap header. The short-lived HMAC proof is bound to transport
and origin; cookie login also checks a matching HttpOnly pre-login cookie. This
is login-CSRF defense, not real user authentication, rate limiting, or a full
single-use authentication challenge system. Logging in twice requires logout.

Cookie mutations use the library's session-bound `X-CSRF-Token` and exact Origin
validation. `initialize()` gets the CSRF bootstrap through status. Cookie JWTs
are never read by JavaScript, returned in JSON, or copied to response token
headers. Header login reads `X-Session-Token` only, and the browser package retains
it in memory. Reloading the widget loses its local credential, not the server
record; logout first to revoke it. Credentials never appear in URLs.

CORS is application-owned: the API origin and one configured widget origin are
allowlisted for header mode; cookie routes allow only the API origin. Preflight
checks method and header allowlists before touching session storage. Responses
expose only the explicitly listed session headers and use `Vary: Origin` and
`Cache-Control: no-store`. There is no wildcard and no cross-origin cookie
credential allowance. Header-mode fetch uses `credentials: omit`.

`RevalidatingStream.php` creates its body only after `saveAndClose()`. Middleware
finalizes pending cookies/headers before the emitter reads any bytes. Each tick
loads by the server-held identifier, saves/closes its own context, then releases
the lease before waiting. Revocation, rotation, or storage failure terminates the
stream at its next check. No identifier or JWT is exposed in events. Revalidation
does not extend activity. Business stream authorization and resource access
remain application responsibilities. Proxies can still buffer event delivery.

Normal handler responses, including application 4xx responses, persist session
mutations. Uncaught exceptions discard uncommitted changes because the error
renderer wraps session middleware. Already committed early close, rotation, and
revocation cannot be undone. No business mutation is automatically replayed.

## Browser Integration

Both pages use external scripts and an explicit CSP, not inline JavaScript or
eval. `public/demo.js` calls `SlimSession.createSessionClient()` from the
self-hosted standalone bundle. It exposes controls for the complete lifecycle.
Local Teardown calls `destroy()` only; it intentionally does not revoke anything.
Logout reports `{revoked:false}` when server revocation cannot be confirmed.

For a typed ESM integration, see [`typescript/client.ts`](typescript/client.ts).
It imports `createSessionClient` and public types from `@semhoun/slim-session`.
`exampleClient(origin, 'cookie')` is for same-origin use;
`simulatedLogin(origin, 'header')` is for an explicitly trusted widget origin.
`exercise()` demonstrates the same data, flash, refresh, rotation, stream, logout,
and teardown lifecycle. The independent typed consumer can be checked from
`examples/typescript/` with `npm install` followed by `npm run typecheck` after
the browser package build. It resolves the local browser package through a path
dependency. Compile integrations through your application's normal tooling.

## Checks

From `examples/`, `composer smoke` exercises both transports in process against
a temporary SQLite schema. It checks login proof rejection, explicit creation,
data/flash, refresh, rotation, old-token rejection, logout, CORS, and stream
revalidation. It does not replace a real browser's cookie or CORS enforcement.
Keep example processes and data isolated from application infrastructure.
