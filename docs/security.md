# Security and Lifecycle Boundaries

## Authentication Is Server-Side

A session JWT is a signed reference, not a portable application identity. It
contains only `sub`, `iss`, `aud`, `iat`, `nbf`, `exp`, `sv`, and `svp`.
`sub` is a canonical lowercase cryptographic UUIDv4; `sv` is format version 2.
`svp` is an independently generated 256-bit per-session verification secret,
encoded as lowercase hex and persisted in the server record. Every authenticated
request loads live storage under a lease, then uses `hash_equals` to compare the
record secret before CSRF checks or handler execution. A valid signature and
known UUID alone cannot authenticate. Missing, revoked, idle-expired, and
absolute-expired records cannot be restored by a valid JWT. CSRF uses a separate
random secret; it is never a substitute for `svp`.

JWTs are signed, not encrypted: `svp` is a bearer secret visible to anyone holding
the JWT. This is not an additional factor against token theft or storage compromise.
Never expose it through status metadata, logs, or the application data bag.

Applications authenticate users and explicitly create sessions. User lookup,
authorization, SSO, all-devices logout, and business transactions are outside
this package. Do not accept session credentials in URLs. Use distinct
application namespaces, issuers, audiences, signing keys, and cookie names.
Existing application JWTs and remember credentials are intentionally unsupported.

Signing secrets are injected raw bytes and must contain at least 32 bytes;
generate them cryptographically, not from passwords. The HS256 keyring is a
closed local map selected by `kid`; JWT-supplied key URLs are never resolved.
Changing the active key does not require dropping older verification keys.
Keep an old key only for the intended verification overlap, then remove it;
removing a key immediately invalidates its JWTs regardless of stored records.

## Deadlines

Defaults are a 24-hour JWT, 24-hour idle timeout, seven-day absolute lifetime,
and renewal during the JWT's final hour. JWT expiration never exceeds the
absolute deadline. Activity cannot extend the absolute deadline. Status and
refresh are not activity; server-side policy can exclude other routes.
Unexcluded background polling can keep a session alive until its absolute
deadline.

An expired JWT cannot refresh itself, even if its record remains live. Closing
or suspending a browser before its renewal window can therefore require
reauthentication. A persistent cookie does not change this rule. JWT clock
tolerance never extends storage deadlines.

Creation may also supply a server-calculated `absoluteExpiresAt` bound. It can
shorten, never extend, the configured lifetime and is checked with real time after
lease acquisition. See the [creation contract](contracts.md#bounded-creation-and-new-authentication).

## Exactly One Transport

Configure either cookies or headers for each integration, not automatic
credential detection. Header integrations ignore ambient session cookies.
Cookie integrations ignore unrelated authorization headers. Reject ambiguous
credentials within the selected source. Independently mount and configure
separate integrations if an application needs both.

Use an application-specific `__Host-` cookie with Secure, HttpOnly, Path=/,
no Domain, and SameSite=Lax. SameSite=None requires explicit authorization of
origins and Secure. Third-party cookie restrictions still apply; embedded
clients should use headers when appropriate. Cookie Max-Age follows the JWT
deadline. Deletion must use the same cookie name and path.

By default, cookie-authenticated unsafe requests require both an explicitly allowed Origin
and the session-bound CSRF token in `X-CSRF-Token`. Missing and `null` origins
fail closed. Bootstrap CSRF using the same-origin status endpoint. Do not
derive trusted origins from unvalidated Host or forwarded headers. Login and
session creation need application-level login-CSRF protection before a session
exists. Business mutations must not use GET, HEAD, or OPTIONS.

Cookie-mode JavaScript must never receive the JWT. In header mode, prefer
memory token storage; optional sessionStorage increases exposure to XSS.
Neither mode protects an application against malicious same-origin scripts.

## Optional Cookie Protection Controls

Use PHP/browser package versions supporting these switches before configuring
opt-outs. Defaults and existing configurations keep both checks.

PHP `HttpConfig` appends `bool $csrfRequired = true` and
`bool $originRequired = true` after `csrfHeader`. Existing argument positions and
secure defaults remain intact. Each flag independently controls one
check for authenticated cookie requests with methods other than GET, HEAD, OPTIONS:

| `csrfRequired` | `originRequired` | Enforcement |
| --- | --- | --- |
| `true` | `true` | Require both the session-bound CSRF token and an allowed Origin |
| `true` | `false` | Require CSRF only; ignore the request Origin |
| `false` | `true` | Require an allowed Origin only; ignore the request CSRF header |
| `false` | `false` | Require neither proof |

An enabled check retains its strict semantics. Missing, empty, duplicate, or
incorrect CSRF proofs fail; missing, empty, duplicate, `null`, or foreign Origins
fail. Failure returns 403 before application code runs and releases the session
lease. A disabled check ignores its corresponding request header, even when
malformed or duplicated. Safe methods remain exempt; never mutate on these methods.
Header/Bearer transport already bypasses both checks and remains unchanged.

Use the secure defaults, or explicitly retain both protections:

```php
use Semhoun\SlimSession\Http\HttpConfig;

$http = new HttpConfig(
    transport: 'cookie',
    canonicalOrigin: 'https://app.example.test',
    allowedOrigins: ['https://app.example.test'],
    csrfRequired: true,
    originRequired: true,
);
```

The switches apply to every eligible request handled by that middleware
instance, not individual routes or requests. Disabling either protection weakens
the defense against cross-site requests. Disabling both permits cookie-authenticated
mutations without either proof and is an explicit high-risk exception, not a
recommended configuration. CORS and SameSite cookies are not equivalent replacements.
Review the entire instance's exposed endpoints before opting out.

Configuration validation remains unchanged: canonical/allowed origins must be
valid explicit origins, header and cookie names must be valid, and SameSite rules
still apply. In particular, SameSite=None still requires an explicit nonempty
allowed-origin list and Secure cookies, even when Origin enforcement is disabled.
Disabling a request check does not make malformed configuration valid.

CSRF token generation, rotation, storage, response headers, and metadata remain
unchanged even when checking is disabled. JWT issuance and validation, live storage
authentication, deadlines, leases, cookies, credential extraction, and transport
isolation do not change. Missing credentials still do not create a session.
Applications remain responsible for initial login/session creation and login-CSRF
protection before an authenticated session exists.

The browser's `SessionClientOptions.csrfRequired?: boolean` defaults to true;
only explicit `false` disables its bootstrap-token requirement and managed CSRF
header insertion for cookie mutations, including refresh and logout. Tokens returned
by the server do not re-enable insertion. There is no browser Origin switch:
browsers control that header. Keep the browser default when only server Origin
checking is disabled. Set the browser option to false only where the corresponding
server CSRF check is disabled; the server may still require an allowed Origin.

A client opt-out never disables server enforcement. An enforcing server still
returns 403 for missing required proof, with no automatic negotiation or fallback.
The client never replays failed business mutations; failed logout does not confirm
server revocation. Deploy supporting packages before opting out, and restore client
proofs before re-enabling server enforcement. See the [browser options](../browser/README.md#optional-csrf-control).

## CORS and Response Handling

CORS belongs to the host application. Use exact origin allowlists, never `*`
with credentials, and send `Vary: Origin`. For header clients allow
`Authorization` and `Content-Type`; for cookie clients allow `X-CSRF-Token`
and enable credentials. Expose `X-Session-Token` only for header integrations,
plus the configured expiration metadata headers. An OPTIONS preflight should
not create a session or perform a business operation.

Credential and status responses must use `Cache-Control: no-store`. Do not
cache session responses in shared proxies. Do not log JWTs, cookies, secrets,
or complete session identifiers. Client-side token parsing is not validation.

## Locks and Side Effects

The default acquisition timeout is two seconds and the lease duration is
30 seconds. Mutable loading happens after acquiring a lease. Each write
atomically checks ownership, lease validity, record existence, revision, and
deadlines. A stale writer cannot upsert or resurrect a revoked session.
Redis and SQL lease checks use storage time, not individual worker clocks.

A session lease is not a transaction with application side effects. Business
work can succeed before an expired lease causes session persistence to fail.
Never automatically replay business requests. Applications need their own
transactional or idempotency design when coordinating external effects.

Call `saveAndClose()` before streaming or lengthy work. Mutation after close
throws. Finalize the middleware response before emitting headers or body bytes;
never call PHP's direct output/header functions inside the handler. Revalidate
long streams periodically without holding a session lease. Revocation blocks
subsequent checks, not previously authorized work or an unchecked open stream.

## Commit and Failure Semantics

Session states are absent, open, closed, and destroyed. Creation is explicit;
clearing application data does not destroy the session. Closed or destroyed
contexts do not silently reopen. Rotation requires an open context, creates a
fresh UUIDv4, invalidates the old ID atomically, and independently changes both
the verification secret and CSRF proof. Ordinary JWT renewal retains all three.

`startNew()` explicitly creates from absent or revokes and replaces an open
identity on the same context. It retains no old application data, flash, or
secrets and starts fresh creation deadlines. Unlike rotation, replacement is two
storage operations, not an atomic exchange. Failure after revocation does not
restore the old identity and must not issue a replacement credential.

Normal handler returns commit open sessions, including 4xx responses: a handler
may intentionally update attempt counters or flash messages. Uncaught handler
exceptions discard uncommitted changes and release the lease. An earlier
`saveAndClose()`, rotation, or destruction cannot be rolled back by a later
exception. Put error-rendering middleware outside session middleware if thrown
exceptions should discard pending session changes. If an inner error renderer
converts an exception to a response, that is a normal return and commits.

Response tokens are finalized only after successful persistence. Never turn a
storage error into JWT-only authorization or a successful token response.
Missing/invalid credentials and unavailable storage are different outcomes:

| Status | Meaning |
| --- | --- |
| 401 | Invalid credential or missing/revoked/expired session |
| 403 | Origin or CSRF proof rejected |
| 409 | Lease loss or conditional-write conflict; do not replay automatically |
| 503 | Storage unavailable or lock acquisition timed out |

A protected route with no session context is a configuration failure, not a
public route. Flash consumption persists under the session lease, but network
failure prevents any exactly-once browser-delivery guarantee.

## Browser Ordering and Cleanup

Client generations reject late JavaScript responses, but cannot stop the
browser applying an already received Set-Cookie header. A late cookie for a
rotated or destroyed ID may replace a newer browser cookie. The server must
still reject that old ID; this can cause reauthentication, never resurrection.
Applications should avoid overlapping login/rotation/logout transitions.

Local client destruction cancels work and clears local credentials; it is not
confirmed server revocation. Failed logout must report revocation as
unconfirmed. The browser client never retries a business mutation automatically.
Memory and cookie clients never access browser storage. Persistent header clients
remove only their application/base-URL credential key on logout, destruction,
401, unauthenticated status, or local expiration. They never clear the origin's
storage or invalidate independent clients. Failed removals/writes quarantine only
the affected key; recovery never touches another key. Identically configured
persistent clients share a slot, not a coordinated lifecycle, so explicitly
coordinate them when shared logout is required. `persistentCleanupFailed`
exposes failure independently of server revocation.
Inaccessible storage cannot be guaranteed erased; quarantine does not survive a
reload or span separate module copies/realms. Other tabs and localStorage are not
cleared, and cookie removal remains server-controlled.
