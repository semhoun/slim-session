# PHP Contracts

All PHP classes use the `Semhoun\SlimSession` namespace. Storage implementations
live in its `Storage` namespace. The core does not use Slim, Doctrine, native
PHP sessions, application user models, or global output.

## Configuration and Infrastructure

`SessionManager` receives a `Storage\SessionStore`, `SessionConfig`,
`Psr\Clock\ClockInterface`, and injectable `Random\RandomSource`. The default
random source uses PHP's cryptographic random generator. Canonical lowercase
UUIDv4 session IDs are generated internally with version/variant bits set;
applications must not supply an ID to create a session.

`SessionConfig` requires an application namespace and accepts idle and absolute
lifetimes in seconds, lease and acquisition timeout in milliseconds, and JSON
size/depth limits. Namespaces accept letters, digits, `_`, `.`, and `-`, up to
128 bytes. Defaults are 65,536 data bytes and 32 levels of JSON depth.

`Clock\SystemClock` supplies UTC wall time. Real storage uses its own clock for
atomic lease and expiration decisions. Acquisition waits use monotonic local
time. A worker clock cannot grant ownership of an expired database lease.

## Lifecycle

`SessionManager::absent()` supplies an unauthenticated context.
`create(array $data = [], ?int $absoluteExpiresAt = null)` explicitly creates a session.
`load(string $id, bool $activity = true)` acquires a lease before loading mutable
state and rejects noncanonical UUIDv4 IDs, missing/expired records, and incompatible
record schemas. This is a trusted server API, not credential authentication.
HTTP callers must also verify JWTs and compare their `verificationSecret` against
the loaded record using `hash_equals`, as `SessionMiddleware` does.

`SessionContext` combines data operations and the request lifecycle:

| Method | Purpose |
| --- | --- |
| `state()` | Return `Absent`, `Open`, `Closed`, or `Destroyed` |
| `record()` | Read the current immutable record snapshot |
| `create(array $data = [], ?int $absoluteExpiresAt = null)` | Create explicitly from an absent context |
| `startNew(array $data = [], ?int $absoluteExpiresAt = null)` | Create from absent, or revoke and replace an open session on the same context |
| `get()`, `has()`, `set()`, `delete()` | Access application data |
| `assign()`, `clear()` | Bulk assignment or clear data without revoking |
| `flash()`, `consumeFlash()` | Store and consume flash values under the lease |
| `save()` | Conditional persistence while retaining an open context |
| `renew()` | Request JWT renewal without marking activity |
| `renewLease()` | Explicitly renew ownership before its deadline |
| `excludeActivity()` | Suppress this request's activity update; used by status and refresh |
| `rotate(bool $preserveData = true)` | Atomically change UUID, verification secret, and CSRF proof |
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

### Bounded Creation and New Authentication

Both creation APIs accept an optional server-calculated absolute deadline in Unix
seconds. After lease acquisition, the effective absolute deadline is the minimum
of this bound and `now + absoluteLifetime`. The idle deadline is the minimum of
that result and `now + idleLifetime`. Omitting the bound preserves the configured
lifetimes. An elapsed bound, including one elapsed during acquisition, throws
`InvalidSessionStateException` without creating a record or retaining the lease.
Activity, renewal, and rotation cannot extend the effective absolute deadline.
Never trust an unvalidated client deadline or freeze the context's clock.

Use the context supplied by the middleware for a new authentication:

```php
$context = \Semhoun\SlimSession\Http\SessionGuard::context($request);
$context->startNew(['user_id' => $authenticatedUserId], absoluteExpiresAt: $serverDeadline);
```

`startNew()` accepts only absent or open contexts, never closed or destroyed
ones. From open, it revokes the previous identity before allocating a fresh ID,
verification secret, CSRF proof, creation time, and deadlines. No old data or
flash is retained. The context object is unchanged, so middleware persistence and
credential emission continue to track it. Replacement is not atomic: a failure
after revocation leaves a terminal context, with no new credential to emit and
no restoration of the old identity. Do not replay business mutations on failure.
`rotate()` still preserves creation time and the absolute deadline; it is not
a new authentication. Early `saveAndClose()` remains supported before streaming.

### Application Data Paths

`get`, `has`, `set`, and `delete` interpret dots as path separators at any depth.
The top-level input keys of `assign` and `create` use the same rules, including
`SessionManager::create`. Values are stored as actual nested arrays:

```php
$session = $manager->create(['user.firstname' => 'Ada']);
$session->set('user.lastname', 'Lovelace');
$session->get('user'); // ['firstname' => 'Ada', 'lastname' => 'Lovelace']
$session->get('user.firstname'); // 'Ada'
$session->set('users.0.firstname', 'Grace');
```

Paths must contain non-empty segments. Empty paths, leading or trailing dots,
and consecutive dots throw `InvalidSessionDataException`. There is no escaping,
literal-key precedence, wildcard, or append syntax. Numeric segments use native
PHP array-key conversion: `0` becomes an integer key, while `01` stays a string.
Keys inside supplied array values remain literal; for example,
`set('settings', ['literal.dot' => true])` does not expand `literal.dot`.

Writes create missing intermediate arrays, but reject traversal through an
existing non-array parent, including null. Assigning a leaf replaces its entire
previous value or subtree; arrays are not deep-merged. To replace a scalar parent
with nested data, explicitly remove it first:

```php
$session->set('user', 'previous scalar');
$session->delete('user');
$session->set('user.firstname', 'Ada');
```

Bulk entries are applied in PHP iteration order. A later parent replaces earlier
descendants; a later child extends an array parent but fails on a scalar parent.
`assign(['user.name' => 'Ada', 'user' => null])` leaves `user` null, while the
reverse order fails. Bulk writes are atomic: invalid paths, collisions, invalid
JSON values, or configured depth/byte limits leave session data unchanged.
Incoming PHP references are detached before overlapping writes can modify them.
Creation normalizes and validates data before acquiring a lease or storing a record.

`get` returns its default and `has` returns false for missing or scalar-blocked
paths. A null leaf is present: `has` returns true and `get` returns null instead
of its default. `delete('user.firstname')` removes only that child, retaining an
empty parent array if necessary. `delete('user')` removes the entire subtree.
Missing or scalar-blocked deletions are no-ops; numeric keys are not reindexed.

Flash keys remain literal, including dots and empty strings. `clear`, lifecycle
restrictions, JSON limits, and persistence guarantees are unchanged.

**Compatibility change:** dotted keys formerly addressed literal keys; empty
application-data keys are now invalid. Existing nested-array records remain valid,
but stored literal dotted keys are neither reinterpreted nor used as a fallback.
Applications with historical literal dotted keys must transform their data or
invalidate affected sessions before adopting this behavior. No compatibility
layer or automatic migration is provided.

### Record Snapshots

An immutable `SessionRecord` includes schema version, revision, data, flash,
creation/activity times, idle/absolute deadlines, a CSRF proof, and a required
independent `verificationSecret`. Its constructor places `verificationSecret`
after `csrfToken`, before `schemaVersion` (default `2`). Codecs reject schema 1
and absent or malformed verification secrets. SQL payloads remain JSON; existing
ID columns accommodate the 36-character UUID without changing table definitions.
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

`FileSessionStore` implements the same session contract on trusted private local
POSIX disk, with namespace-scoped short `flock` operations, JSON records and
separate leases, atomic replacement, and a recoverable rotation journal. It also
implements `SessionStoreMaintenance`; no filesystem PSR-16 cache is provided.
See the [filesystem setup and failure limits](../README.md#local-filesystem-backend)
before deployment. It is not a distributed or power-loss-durable backend.

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

`JwtService::issue(string $sessionId, int $absoluteExpiresAt, string $verificationSecret)`
returns a JWT. `issueWithExpiry()` takes the same required arguments and returns
an `IssuedToken` containing the token, `expiresAt`, and `refreshAt` from one
clock snapshot. Its constructor accepts these three values in that order.
Supply the current committed record's ID, deadline, and secret. Verification
returns a `VerifiedToken`, including `verificationSecret`; signature verification
alone is not sufficient to authenticate. Neither API supports legacy credentials.

By default `JwtService` renews at `issuedAt + ceil((expiresAt - issuedAt) / 2)`,
using the actual token lifetime, including an absolute-deadline cap. Its optional
`?int $renewalWindow = null` constructor argument selects this default; an
explicit nonnegative integer retains a fixed window before expiry.
`refreshAt(int $issuedAt, int $expiresAt)` exposes the selected policy.
Automatic PHP renewal occurs on authenticated requests, not in a background job.
Explicit refresh remains available before the threshold and does not mark activity.

The status/refresh representation uses Unix-second deadlines:

```json
{
  "authenticated": true,
  "transport": "cookie",
  "tokenExpiresAt": 2000000000,
  "tokenRefreshAt": 1999956800,
  "idleExpiresAt": 2000000000,
  "absoluteExpiresAt": 2000500000,
  "csrfToken": "session-bound-proof"
}
```

Unauthenticated status omits deadlines. Cookie responses never expose the JWT.
Header responses use `X-Session-Token` by default. Business responses use
`X-Session-Token-Expires-At`, `X-Session-Token-Refresh-At`, `X-Session-Idle-Expires-At`, and
`X-Session-Absolute-Expires-At`. Cookie responses also return `X-CSRF-Token`.
Applications own route names, CORS, and preflight processing.
Expose the refresh timestamp header through CORS alongside the deadline headers.
The timestamp always belongs to the response's current credential, including
when that credential is unchanged. It never extends idle or absolute deadlines.

### Incoming Admission Metadata

Inside the session middleware's handler, `Http\SessionGuard::admission($request)`
returns a readonly `Http\AdmissionMetadata` with `transport` and `expiresAt`
(Unix seconds), or null when no incoming credential was supplied. Calling the
guard without session middleware throws `LogicException`. The DTO contains no
JWT, session ID, verification secret, or CSRF proof.

The middleware sets `SessionGuard::ADMISSION_ATTRIBUTE` only after JWT signature,
issuer, audience, and time validation, authoritative session loading, constant-time
verification-secret comparison, and applicable cookie request proofs. It
overwrites any previous value, including with null for absent requests. Invalid
credentials, revoked/expired records, or unavailable storage never reach the
handler with authenticated metadata.

`expiresAt` describes the incoming verified JWT, not a credential renewed in the
response. It remains unchanged if the handler creates, replaces, or destroys a
session. Use current server-session state as well when bounding capabilities;
revalidate that session when the capability is used. The DTO is a request-local
authentication result, not a credential. An immutable object or an attribute's
presence alone does not prove provenance: consume only the value delivered by
the middleware on the current request, never a client-supplied or copied DTO.

HTTP finalization remains middleware-owned. An HTML callback needing a header
credential in its body must use an application-owned one-time exchange or another
explicit integration. There is no shared commit-result API; calling JWT issuance
directly does not provide idempotent middleware finalization guarantees.

The PHP middleware requires `Bearer <JWT>` in the configured credential header,
even when its name is changed from `Authorization`. Keep the browser's default
`credentialPrefix: 'Bearer '` when using this middleware. Changing the browser
prefix is for integrations with a different compatible server, not a PHP
credential-detection fallback.

See [security boundaries](security.md) for commit, exception, streaming,
middleware-ordering, and failure semantics. See the runnable
[examples](../examples/README.md) for complete framework wiring.
