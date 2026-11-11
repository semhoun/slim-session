# Changelog

## [1.0.1] - 2026-11-11

### Added

- Bound a new session to a server-calculated expiration with the optional
  `absoluteExpiresAt` argument on `SessionContext::create()` and
  `SessionManager::create()`. The bound can shorten, never extend, the configured
  maximum lifetime and is checked after lease acquisition.
- Start a fresh authentication with `SessionContext::startNew()` without
  replacing the context tracked by the middleware. It revokes the previous
  session and generates fresh identity, secrets, and deadlines, without retaining
  old application data or flash messages.
- Read the authenticated incoming credential's transport and expiration through
  `SessionGuard::admission($request)`, without exposing its JWT or secrets.
  This metadata remains separate from any credential renewed in the response.

### Fixed

- Automatic JWT renewal now starts halfway through the actual token lifetime,
  including tokens shortened by the session's absolute deadline. Browser clients
  use the server-provided refresh timestamp so delayed initialization does not
  postpone renewal. Explicit fixed-window overrides remain available.
- Browser logout, teardown, and local expiration no longer erase unrelated
  same-origin tab data or invalidate independent clients. Persistent header
  clients remove only their own application/base-URL credential key; memory and
  cookie clients never access browser storage.
- Storage failures quarantine only the affected credential key. Recovery no
  longer clears storage belonging to other applications or clients.

### Security

- Failed session creation or replacement cannot issue a new credential after
  a caught save, lease-renewal, or rotation failure. Replacement failures after
  revocation never restore the old identity. Session replacement is not atomic;
  applications must not automatically replay business operations on failure.

## [1.0.0] - 2026-11-09

Initial release notes. The date above is proposed; no release tag or package
publication is implied.

### Added

- Server-backed sessions for PHP 8.3+ (`semhoun/slim-session`), using
  PSR-7/15/17/20 interfaces independently of Slim and application user models.
- Explicit session creation, JSON-compatible data and flash values, configurable
  idle and absolute deadlines, renewal, atomic rotation, single-session
  revocation, and explicit save/close operations for streaming or long work.
- Nested application-data paths such as `user.firstname` and `users.0.firstname`
  in `get`, `has`, `set`, `delete`, `assign`, and session creation. Values are
  stored as actual nested arrays. Bulk writes follow input order, replace leaves
  rather than deep-merging, and remain atomic on failure. Missing parents are
  created; existing non-array parents, including null, reject child writes.
  PHP references are detached, JSON limits apply to the normalized data plus
  flash, and flash keys remain literal.
- Atomic session stores for PostgreSQL, MySQL, MariaDB, and SQLite through PDO,
  and Redis through ext-redis. SQL schemas are installed explicitly; Redis
  Cluster is not supported. Separate PDO and Redis PSR-16 caches do not replace
  the atomic session-store contract.
- A local filesystem session store for one host on private POSIX storage, with
  JSON records, leases, atomic replacement, and recoverable rotation journaling.
  Network filesystems and distributed deployments are unsupported; power-loss
  durability is not guaranteed.
- Bounded, namespace-scoped expired-record and lease cleanup for PDO and
  filesystem stores. Probabilistic cleanup after newly created sessions close
  is optional and disabled by default. Expiration checks do not depend on
  cleanup; Redis uses TTLs.
- PSR-15 session middleware and status, refresh, and logout handlers with
  explicitly configured HttpOnly cookie or header transport, plus Slim examples.
- Independent, instance-wide cookie protection options in PHP `HttpConfig`:
  `csrfRequired = true` and `originRequired = true`. The browser client adds
  `csrfRequired?: boolean`; only explicit `false` skips the CSRF bootstrap-token
  requirement and managed header insertion for mutations, refresh, and logout.
- A framework-independent browser client (`@semhoun/slim-session`) with typed
  ESM and standalone scripts, no runtime dependencies, configurable endpoints,
  state subscriptions, coalesced initialization and refresh, automatic renewal,
  local expiration, logout, and teardown. Header credentials use memory by
  default, with optional sessionStorage persistence.

### Changed

- Application-data keys containing dots now represent paths, without escaping
  or literal-key fallback. Empty paths and empty segments are rejected with
  `InvalidSessionDataException`. Existing nested-array records remain valid,
  but historical literal dotted keys are not automatically reinterpreted.
  Applications using those keys must migrate their data or invalidate affected
  sessions before adopting this behavior. Delete a scalar parent explicitly,
  for example with `delete('user')`, before writing a child such as
  `user.firstname`. See the [application data path contract](docs/contracts.md#application-data-paths).

### Security

- Application session data remains on the server. HS256 JWTs carry a
  cryptographic UUIDv4 session ID and technical claims, including an independent
  per-session verification proof. Every authenticated request validates the
  signature, issuer, audience, deadlines, and authoritative stored session,
  comparing the proof in constant time. There is no JWT-only fallback.
- Application namespaces isolate session storage. Cookie-authenticated mutations
  require an allowed Origin and a session-bound CSRF token by default; credentials
  are not accepted from URLs or an inactive transport.
- Disabling either cookie protection weakens cross-site request defenses;
  disabling both permits mutations without either proof. CORS and SameSite are
  not equivalent replacements. Client opt-out does not disable server enforcement:
  missing required proof still returns 403 without automatic fallback or replay.
  Header/Bearer behavior, configuration validation, live storage authentication,
  and CSRF token generation and response metadata remain unchanged. See the
  [security controls](docs/security.md#optional-cookie-protection-controls).
- Writes require live lease ownership, an existing record, matching revision,
  and valid deadlines. Stale writes cannot recreate revoked sessions, and writes
  after close are rejected. Session values use JSON rather than PHP object
  deserialization; separate cache payloads are authenticated before deserialization.
- Browser requests are restricted to the configured origin and path, reject
  redirects, and never automatically replay application requests. Late responses
  cannot restore client state after local invalidation.
- Browser logout distinguishes confirmed server revocation from local cleanup;
  failed logout reports revocation as unconfirmed, and `destroy()` does not
  revoke a server session.
- Logout, destroy, 401 responses, unauthenticated status, and local expiration
  attempt to clear **all sessionStorage for the current page origin in the
  current tab**, including unrelated application data, even in cookie or memory
  mode. Cleanup also invalidates other live clients sharing the module. It does
  not clear localStorage, other tabs, or HttpOnly cookies. Storage failures
  quarantine stored-token restoration and are exposed through
  `persistentCleanupFailed`; local cleanup is not proof of server revocation.
