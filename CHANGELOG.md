# Changelog

## [1.0.0] - 2026-11-09

Initial release notes. The date above is proposed; no release tag or package
publication is implied.

### Added

- Server-backed sessions for PHP 8.3+ (`semhoun/slim-session`), using
  PSR-7/15/17/20 interfaces independently of Slim and application user models.
- Explicit session creation, JSON-compatible data and flash values, configurable
  idle and absolute deadlines, renewal, atomic rotation, single-session
  revocation, and explicit save/close operations for streaming or long work.
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
- A framework-independent browser client (`@semhoun/slim-session`) with typed
  ESM and standalone scripts, no runtime dependencies, configurable endpoints,
  state subscriptions, coalesced initialization and refresh, automatic renewal,
  local expiration, logout, and teardown. Header credentials use memory by
  default, with optional sessionStorage persistence.

### Security

- Application session data remains on the server. HS256 JWTs carry a
  cryptographic UUIDv4 session ID and technical claims, including an independent
  per-session verification proof. Every authenticated request validates the
  signature, issuer, audience, deadlines, and authoritative stored session,
  comparing the proof in constant time. There is no JWT-only fallback.
- Application namespaces isolate session storage. Cookie-authenticated mutations
  require an allowed Origin and a session-bound CSRF token; credentials are not
  accepted from URLs or an inactive transport.
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
