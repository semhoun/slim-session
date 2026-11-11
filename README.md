# Slim Session

Server-backed sessions for PHP 8.3+ and a framework-independent TypeScript
browser client. The PHP core uses PSR-7/15/17/20 interfaces; Slim is only an
example integration.

`semhoun/slim-session` keeps application data on the server. HS256 JWTs carry
only a cryptographic UUIDv4 session ID and technical validation claims, including
an independent per-session verification secret. Authentication always checks
storage and compares that secret in constant time. A PSR-16 cache alone is not a session store.

## Packages

| Directory | Purpose |
| --- | --- |
| `src/` | PHP data, lifecycle, JWT, HTTP, and storage implementations |
| `browser/` | `@semhoun/slim-session`, ESM with TypeScript declarations |
| `schemas/` | Explicit SQL schemas; never installed during HTTP requests |
| `tests/` | PHP lifecycle, HTTP, JWT, cache, and backend tests |
| `examples/` | Isolated Slim integrations, not application migrations |

## Local Installation

Install PHP dependencies at the repository root:

```sh
composer install
```

Use a Composer `path` repository pointing at this checkout and require
`semhoun/slim-session` with an appropriate local development constraint in a
consumer. PDO and ext-redis are optional; install the extension matching the
selected backend. Redis Cluster is not a supported deployment claim.

Build and validate the browser package from `browser/`:

```sh
npm install
npm test
npm run typecheck
npm run build
npm pack --dry-run
```

The packages are intended for local installation. Nothing in this repository
publishes a package or migrates an existing application.
The [browser package guide](browser/README.md) covers typed ESM imports and
self-hosted standalone scripts for plain HTML.

## Security Model

- Create sessions explicitly after application authentication. Missing credentials never create a session automatically.
- Configure exactly one transport per integration: HttpOnly cookie or HTTP header. Inactive transports are not fallback authentication paths.
- Supply an independent namespace, issuer, audience, signing key, canonical origin, and cookie name for each application.
- Cookie mutations require an allowed Origin and a session-bound CSRF header by default. Explicit instance-wide opt-outs are described below. Never mutate on GET, HEAD, or OPTIONS.
- Server writes require a live lease, existing record, matching revision, and unexpired deadlines. Saves never upsert missing records.
- Close sessions before streaming or long work. A session lease does not make application side effects transactional.
- Local browser cleanup is not server logout. Failed logout reports revocation as unconfirmed.

The defaults are a 24-hour JWT, 24-hour idle deadline, seven-day absolute
lifetime, two-second lock acquisition timeout, and 30-second lease. Renewal
normally occurs in the final JWT hour. An expired JWT cannot renew, even if
the browser was suspended and its server record remains live.

See [security and lifecycle boundaries](docs/security.md) before integration.
The [PHP contracts](docs/contracts.md) describe the lifecycle and atomic store
interfaces. The [examples](examples/README.md) explain isolated setup and
application-owned routing.

For reauthentication, use `SessionGuard::context($request)->startNew(...)` rather
than replacing the PSR-7 context attribute. Creation accepts a server-calculated
`absoluteExpiresAt` bound; `SessionGuard::admission($request)` exposes only the
incoming authenticated credential's transport and expiry. See the
[lifecycle contracts](docs/contracts.md#bounded-creation-and-new-authentication)
and [admission metadata](docs/contracts.md#incoming-admission-metadata).

### Optional Cookie Protections

Install package versions supporting these switches before configuring an opt-out.
Existing configurations retain both checks.

PHP `HttpConfig` adds trailing `bool $csrfRequired = true` and
`bool $originRequired = true` parameters, preserving existing argument positions.
For authenticated cookie requests other than GET, HEAD, and OPTIONS:

| `csrfRequired` | `originRequired` | Required proof |
| --- | --- | --- |
| `true` | `true` | Session-bound CSRF header and allowed Origin (secure default) |
| `true` | `false` | Session-bound CSRF header only |
| `false` | `true` | Allowed Origin only |
| `false` | `false` | Neither proof; explicit high-risk opt-out |

These are middleware-instance settings, not per-route or per-request exemptions.
Header/Bearer transport remains unchanged and bypasses these cookie checks.
Disabling a check does not relax configuration validation, authentication, live
storage validation, or deadlines. CSRF token generation, rotation, storage, response
headers, and metadata remain unchanged, even with enforcement disabled.

The browser option `csrfRequired?: boolean` defaults to true; only explicit
`false` skips the managed CSRF header and bootstrap-token requirement for
cookie mutations, including refresh and logout. There is no browser Origin switch.
Keep client and server aligned: a client opt-out cannot disable server enforcement,
and a missing required proof still produces 403 without automatic fallback or
business-request replay. Restore client proofs before re-enabling server enforcement.

Keep both protections enabled unless an application-specific security review
justifies an exception. Disabling either weakens protection; disabling both permits
cookie-authenticated mutations without either proof. CORS and SameSite are not
equivalent replacements. Initial login/session creation and login-CSRF protection
remain application responsibilities. See the [security guide](docs/security.md#optional-cookie-protection-controls)
and [browser options](browser/README.md#optional-csrf-control) for details.

## Local Filesystem Backend

`FileSessionStore` stores one JSON record per session without a database or PHP
object serialization. Use it only on a **local POSIX filesystem on one host**,
not NFS, SMB, distributed volumes, or multiple hosts with independent disks.
It is a session store only, not a PSR-16 cache.

Provision a dedicated directory outside the web root, owned by the PHP worker
account with mode `0700`. Pass its canonical absolute path (no symlink components
or `..`). All workers must use the same directory and system clock.

```php
use Semhoun\SlimSession\Clock\SystemClock;
use Semhoun\SlimSession\SessionConfig;
use Semhoun\SlimSession\SessionManager;
use Semhoun\SlimSession\Storage\FileSessionStore;

// Provision /var/lib/my-app/sessions as the worker account, mode 0700 first.
$store = new FileSessionStore('/var/lib/my-app/sessions');
$manager = new SessionManager($store, new SessionConfig(namespace: 'my_app'), new SystemClock());
```

Each SHA-256 namespace directory contains `<id>.json` records, separate
`<id>.lease` JSON metadata, and a **persistent `lock` file**. During writes there
may also be `tmp-*` files and a `rotation` redo journal. Files use mode `0600`.
Leases can exist without records; acquiring a lease never creates a session.
The namespace lock coordinates short storage operations, not whole requests.
Never unlink or replace the lock file while any worker can use the directory.
Keep parent directories trusted and protected from replacement. Symlinks,
hard-linked files, non-regular files, and non-private permissions are rejected;
this is not a sandbox against processes running as the same OS account.

Nonblocking `flock` retries have a monotonic deadline: `acquire()` uses its wait
argument; other operations use `lockTimeoutMilliseconds` (default 2000).
Slow filesystem I/O itself cannot be time-bounded by PHP. The default storage
clock is system wall time; the optional `ClockInterface` is for controlled tests,
not independently skewed worker clocks.

Writes use same-directory temporary files and atomic rename. Rotation publishes
a redo journal before removing the old ID and installing the new record and
lease. Every operation first recovers any journal under the namespace lock;
failed recovery makes the entire namespace unavailable rather than exposing a
partial rotation. An error after journal publication has an **indeterminate
outcome** to its caller and may complete on the next operation; never replay
business mutations automatically. This handles process interruption, not full
power-loss durability: directory entries are not fsynced, and disk/filesystem
failure can lose acknowledged changes. Use a database backend when that guarantee
is required. Do not restore stale snapshots into a live session directory.

`cleanup($namespace, $limit)` and the existing probabilistic cleanup configuration
are supported. At most `$limit` expired records and `$limit` expired leases are
removed; records with live leases are retained. Each sweep has a 50ms scan budget
(excluding filesystem I/O and lock wait). Large directories can require scheduled
maintenance; sweeps restart at the beginning and do not guarantee progress past
a very large live prefix. Cleanup never removes lock files, journals, unknown
files, or crash-orphaned `tmp-*` files. Reclaim orphan temporary files only during
offline maintenance with all workers stopped. Monitor disk space and inode use.

## Expired Session Cleanup

Expired sessions are rejected on access regardless of cleanup. PDO retains expired
rows until swept; Redis uses TTLs and needs no sweep. Cleanup never extends a
session deadline or deletes live rows, and is scoped to one application namespace.

For manual PDO maintenance, use the same dedicated session connection and table
prefix as your application (install the matching `schemas/` SQL first):

```php
use Semhoun\SlimSession\Storage\PdoSessionStore;

// $pdo is your application's configured session PDO connection.
$store = new PdoSessionStore($pdo);
$deleted = $store->cleanup('my_app', 100);
```

The limit is 1..10000 expired rows **per table** (records and leases), so this
call deletes at most 200 rows and returns their combined count. It does not sweep
the separate PSR-16 cache. The database clock determines expiry, and deletion
rechecks the deadline. Manual calls report storage failures to the caller.

For predictable maintenance, put this call in an application-owned CLI command
that bootstraps the session connection. Schedule it with cron or your job runner,
for example once per minute. Use bounded batches and a job time budget, monitor
failures, and prevent overlapping jobs. Run it for each configured namespace;
never use a business transaction or a production database for tests.

Alternatively, opt into opportunistic cleanup on newly created sessions:

```php
use Semhoun\SlimSession\Clock\SystemClock;
use Semhoun\SlimSession\SessionConfig;
use Semhoun\SlimSession\SessionManager;

$config = new SessionConfig(
    namespace: 'my_app',
    cleanupProbabilityBasisPoints: 100, // 1%; default 0 disables cleanup.
    cleanupBatchSize: 100,             // 1..10000 per table; default 100.
);
$manager = new SessionManager($store, $config, new SystemClock());
$session = $manager->create(['user_id' => $authenticatedUserId]);
$session->saveAndClose();
```

Probability accepts integer basis points 0..10000 (10000 always selects cleanup).
Only stores implementing the optional `Storage\SessionStoreMaintenance` interface
participate; other stores are a no-op, even when enabled. The atomic `SessionStore`
interface is unchanged. PDO and FileSessionStore implement this capability.

A successful `create()` schedules one opportunity on that context, including
creation through `absent()->create()`. Cleanup runs synchronously after successful
lease release on close/discard, including destroy or an error path that closes
the context. It never runs inside creation or while holding that context's lease.
Collision retries do not add opportunities; loads, saves, refreshes, and rotations
do not schedule new ones. Repeated close calls do not retry cleanup. A context
that is never closed does not run cleanup; failed lease release skips it too.

Sampling uses the injected `RandomSource` (cryptographic by default), with unbiased
rejection sampling. Disabled/always modes and unsupported stores need no random
draw. Invalid random lengths, eight consecutive rejected samples, or sampling
`RuntimeException`/`Random\RandomException` skip maintenance. Cleanup
`StorageUnavailableException` is ignored: committed creation and response metadata
are preserved, and business mutations are never replayed. Other programming errors
propagate after lease release. Normal session-operation errors still propagate.
Cleanup adds close-time latency and is not guaranteed to run on low-traffic sites;
use scheduled maintenance when predictable reclamation and failure monitoring matter.

## Verification

### Breaking Session Format Change

Session records now use schema version `2`, with a required `verificationSecret`
field (32 random bytes encoded as 64 lowercase hex characters). JWTs require
`sv: 2` and the matching `svp` proof, and `sub` must be a canonical lowercase
UUIDv4. Old 64-hex session IDs, schema-v1 records, and previous JWTs are rejected;
there is no compatibility fallback. Stop old workers, purge only the affected
application's session records and leases, deploy all workers together, and require
users to log in again. Do not purge unrelated caches or application data.
Existing SQL columns hold the new UUID and JSON payload without DDL changes.
Custom stores and direct record/JWT constructors must adopt the new fields.

Browser logout and local cleanup touch only the persistent header client's own
credential key. Cookie and memory clients never access browser storage, and
independent clients are not invalidated. See
[cleanup behavior and limits](browser/README.md#storage-and-refresh).

### Commands

Run PHP tests and static analysis at the repository root:

```sh
composer test
composer analyse
composer validate --strict
```

Backend tests must target disposable services with their own namespaces and
schemas, never an application's operational database. Tests requiring an
unavailable runtime, extension, or service are not evidence of backend
compatibility. See [local verification](docs/testing.md) for tested commands,
service configuration, and remaining validation gaps.

## Integration Scope

User models, business authorization, SSO/OIDC, all-devices revocation,
conversation storage, and stream resource authorization remain application
responsibilities. The library revokes one session at a time.

Claire, Athena-Sense, and Hephaistos are read-only design references, not
migration targets. Their existing JWTs and remember records are deliberately
unsupported. A future migration requires an explicit reauthentication or
audited bridge decision, independent namespaces/cookies, and a plan for open
streams. No compatibility bridge is included.

## License

MIT. See [LICENSE](LICENSE).
