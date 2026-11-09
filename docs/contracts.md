# PHP Contracts

All PHP classes use the `Semhoun\SlimSession` namespace. Storage implementations
live in its `Storage` namespace. The core does not use Slim, Doctrine, native
PHP sessions, application user models, or global output.

## Configuration and Infrastructure

`SessionManager` receives a `Storage\SessionStore`, `SessionConfig`,
`Psr\Clock\ClockInterface`, and injectable `Random\RandomSource`. The default
random source uses PHP's cryptographic random generator. Session IDs are
generated internally; applications must not supply an ID to create a session.

`SessionConfig` requires an application namespace and accepts idle and absolute
lifetimes in seconds, lease and acquisition timeout in milliseconds, and JSON
size/depth limits. Namespaces accept letters, digits, `_`, `.`, and `-`, up to
128 bytes. Defaults are 65,536 data bytes and 32 levels of JSON depth.

`Clock\SystemClock` supplies UTC wall time. Real storage uses its own clock for
atomic lease and expiration decisions. Acquisition waits use monotonic local
time. A worker clock cannot grant ownership of an expired database lease.

## Lifecycle

`SessionManager::absent()` supplies an unauthenticated context.
`create(array $data = [])` explicitly creates a session.
`load(string $id, bool $activity = true)` acquires a lease before loading mutable
state and rejects missing or expired records. Only trusted, validated session
IDs should reach `load()` from HTTP authentication.

`SessionContext` combines data operations and the request lifecycle:

| Method | Purpose |
| --- | --- |
| `state()` | Return `Absent`, `Open`, `Closed`, or `Destroyed` |
| `record()` | Read the current immutable record snapshot |
| `create(array $data = [])` | Create explicitly from an absent context |
| `get()`, `has()`, `set()`, `delete()` | Access application data |
| `assign()`, `clear()` | Bulk assignment or clear data without revoking |
| `flash()`, `consumeFlash()` | Store and consume flash values under the lease |
| `save()` | Conditional persistence while retaining an open context |
| `renew()` | Request JWT renewal without marking activity |
| `renewLease()` | Explicitly renew ownership before its deadline |
| `excludeActivity()` | Suppress this request's activity update; used by status and refresh |
| `rotate(bool $preserveData = true)` | Atomically change ID and CSRF proof |
| `destroy()` | Revoke this session; do not merely clear data |
| `saveAndClose()` | Persist and release ownership before long work |
| `discard()` | Release ownership without saving pending changes |
| `pendingResponse()` | Committed finalization information, retained after close |

Values must be JSON-compatible. Arbitrary PHP objects, recursive structures,
invalid UTF-8, and non-finite numbers are not portable session values. Metadata
and flash live outside the application data bag. No serializer hooks or
application-object deserialization form part of the session contract.
Backend codecs impose an additional 16 MiB/60-level hard ceiling. Keep configured
limits within that ceiling; the default 64 KiB/32-level limits are smaller.

An immutable `SessionRecord` includes schema version, revision, data, flash,
creation/activity times, idle/absolute deadlines, and a separate CSRF proof.
Record deadlines are Unix seconds. `Lease` identifies namespace, session,
owner, and a fractional Unix-second expiration. Possessing a `Lease` value is
not proof that storage still recognizes its owner.

## Atomic Storage

`Storage\SessionStore` is separate from PSR-16:

| Operation | Required Atomic Behavior |
| --- | --- |
| `acquire()` | Bounded exclusive lease acquisition |
| `read()` | Read the primary authoritative record; distinguish failure from absence |
| `create()` | Insert only, with live ownership; report collision without replacement |
| `save()` | Check current record, revision, live ownership, and deadlines; never upsert |
| `renewLease()` | Extend only the current live owner's lease |
| `release()` | Delete only the matching owner's lease |
| `revoke()` | Invalidate only the matching existing record under ownership |
| `rotate()` | Create fresh record and transfer ownership while invalidating old ID |

The in-memory implementation exists for tests, not shared production workers.
Redis uses atomic Lua operations and Redis time. PDO uses short transactions,
explicit tables, and database-time checks, not a transaction held throughout
an HTTP request. Install the matching SQL schema explicitly before serving
requests. All reads must target the primary; asynchronous read replicas do not
satisfy revocation guarantees.

Pass a dedicated PDO connection with no application transaction open. SQL lock
waits are bounded, but MySQL's server wait setting has one-second granularity;
very small requested acquisition timeouts are therefore not hard real-time
guarantees. Use raw ext-redis connections with prefix and serializer disabled.
Redis Cluster is deliberately not supported. `cleanup($namespace, $limit)` on
the PDO store removes bounded batches of expired records and leases.
`PdoCache::cleanup($limit)` removes expired cache entries in its own namespace.
Schedule cleanup outside requests when using SQL storage.

PSR-16 facades have separate cache namespaces and cannot overwrite session or
lease records through cache keys or `clear()`. Generic cache serialization is
distinct from strict JSON session serialization. Treat cache serialization
keys as secrets, separate from JWT keys, and never deserialize unauthenticated
cache values. Both facades require an injected authentication key of at least
32 bytes; they verify a namespace/key-bound HMAC before PHP deserialization.
Objects may be cached only when their serialized bytes originate from trusted
producers. Never use a generic cache as the atomic session store.

## HTTP Metadata

The status/refresh representation uses Unix-second deadlines:

```json
{
  "authenticated": true,
  "transport": "cookie",
  "tokenExpiresAt": 2000000000,
  "idleExpiresAt": 2000000000,
  "absoluteExpiresAt": 2000500000,
  "csrfToken": "session-bound-proof"
}
```

Unauthenticated status omits deadlines. Cookie responses never expose the JWT.
Header responses use `X-Session-Token` by default. Business responses use
`X-Session-Token-Expires-At`, `X-Session-Idle-Expires-At`, and
`X-Session-Absolute-Expires-At`. Cookie responses also return `X-CSRF-Token`.
Applications own route names, CORS, and preflight processing.

The PHP middleware requires `Bearer <JWT>` in the configured credential header,
even when its name is changed from `Authorization`. Keep the browser's default
`credentialPrefix: 'Bearer '` when using this middleware. Changing the browser
prefix is for integrations with a different compatible server, not a PHP
credential-detection fallback.

See [security boundaries](security.md) for commit, exception, streaming,
middleware-ordering, and failure semantics. See the runnable
[examples](../examples/README.md) for complete framework wiring.
