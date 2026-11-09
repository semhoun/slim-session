# Slim Session

Server-backed sessions for PHP 8.3+ and a framework-independent TypeScript
browser client. The PHP core uses PSR-7/15/17/20 interfaces; Slim is only an
example integration.

`semhoun/slim-session` keeps application data on the server. HS256 JWTs carry
only a random session ID and technical validation claims. Authentication
always checks storage. A PSR-16 cache alone is not a session store.

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
- Cookie mutations require an allowed Origin and a session-bound CSRF header. Business mutations must not use GET.
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

## Verification

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
