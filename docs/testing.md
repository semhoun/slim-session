# Local Verification

Use disposable infrastructure only. The reference applications and their
operational services are not test fixtures.

## PHP

The package targets PHP 8.3, 8.4, and 8.5. Install dependencies with Composer,
then run these commands from the repository root:

```sh
composer test
composer analyse
composer validate --strict
```

The Composer platform is PHP 8.3 so dependency resolution does not accidentally
raise the minimum runtime to the development host's PHP version. CI defines a
three-version PHP matrix; a workflow definition alone is not proof it passed.

SQLite tests run without external services when `pdo_sqlite` is installed.
Real-backend contract tests use the following opt-in environment variables:

| Backend | Configuration |
| --- | --- |
| PostgreSQL | `SESSION_TEST_PGSQL_DSN`, `SESSION_TEST_PGSQL_USER`, `SESSION_TEST_PGSQL_PASSWORD` |
| MySQL | `SESSION_TEST_MYSQL_DSN`, `SESSION_TEST_MYSQL_USER`, `SESSION_TEST_MYSQL_PASSWORD` |
| MariaDB | `SESSION_TEST_MARIADB_DSN`, `SESSION_TEST_MARIADB_USER`, `SESSION_TEST_MARIADB_PASSWORD` |
| Redis | `SESSION_TEST_REDIS_HOST`, `SESSION_TEST_REDIS_PORT`, with ext-redis installed |

Supply dedicated databases and install the matching schema from `schemas/`
before serving requests. Tests use primary connections. Do not point these
variables at production or a shared application's database. Redis tests require
ext-redis, not a replacement PHP client.

CI pins Redis 7.4.12, PostgreSQL 16.15, MySQL 8.4.13, and MariaDB 11.4.13.
Those are service-image version pins, not a claim that every PHP/backend pair
was executed locally. `pcntl` is needed for real multiprocess tests.

`tests/Storage/Dockerfile` builds an isolated PHP runtime with PDO drivers,
pcntl, and ext-redis 6.3.0. Select `PHP_VERSION` through its Docker build
argument. Mount this checkout at `/app` and supply the disposable-service
environment variables when running tests in that runtime. The image does not
contain or provision application data.

## Browser Package

Run from `browser/`:

```sh
npm ci
npm test
npm run typecheck
npm run build
npm pack --dry-run
```

Unit tests inject fetch, storage failures, and timing. Browser imports must be
safe without a DOM or sessionStorage. The ESM entry and declarations are built
into `browser/dist/`; those generated files are excluded from the worktree.

## HTTP Examples

See [the example setup](../examples/README.md) for explicit schema setup,
ephemeral signing keys, independent origins, and isolated SQLite files.
The example's simulated username is not production authentication.

Playwright tests live in `e2e/` separately from the distributed browser package.
They require a headless Chromium installation, the built browser package,
example Composer dependencies, and the example servers. Use multiple PHP
workers to verify that early-close streams do not monopolize a session lease;
a single-threaded development server cannot demonstrate that property.

## Distribution Checks

Composer's archive contents are controlled by `.gitattributes`. PHP consumers
need `src/`, schemas, documentation, and standalone example sources, not browser
development dependencies or test services. The npm package uses its `files`
allowlist and a prepack build.
Always inspect archive listings locally before any future publication.

No verification command authorizes publishing, committing, pushing, deploying,
or modifying the reference applications.

## Executed Checks

The implementation session executed the following checks against isolated data:

| Check | Result |
| --- | --- |
| PHP 8.3.36, 8.4.27, and 8.5.12, all five real backends | Each passed 142 tests and 868 assertions |
| Host PHP 8.5.12, default locally available backends | 92 tests, 350 assertions passed |
| `composer analyse`, PHPStan level 6 | Passed without a baseline or ignored errors |
| Real multiprocess locking, rotation, revocation, stale writes | Passed in the real-backend matrix |
| Disposable Redis shutdown during valid-JWT authentication | HTTP 503, handler not called, no replacement JWT |
| `composer smoke` from `examples/` | 51 isolated checks passed |
| `npm run typecheck` from `examples/typescript/` | Passed against generated browser declarations |
| Composer archive and independent consumer install | Clean archive installed; create/load/destroy lifecycle passed |
| Browser unit and artifact tests | 42 tests passed |
| Browser source/test typecheck and ESM/IIFE/minified builds | Passed |
| Playwright Chromium, both builds and both transports | 8 tests passed; two origins, concurrent writes, revoking streams |
| npm archive installed into an independent Node consumer | Seven-file package; SSR import without browser globals passed |
| `composer audit --locked` and browser `npm audit` | No known vulnerability advisories reported |

The real-backend containers and their anonymous volumes were removed after
testing. These results do not certify Redis Cluster, read-replica failover,
production operations, or a live deployment. The GitHub Actions workflow has
not been run from this unpublished worktree.
Real browser checks used Chromium on loopback HTTP, where Chromium permits
Secure localhost cookies. HTTPS deployment, Firefox, and WebKit were not tested.
