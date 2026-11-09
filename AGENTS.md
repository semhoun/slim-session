# Agent Instructions

## Project

This repository provides `semhoun/slim-session` (PHP 8.3+) and
`@semhoun/slim-session` (framework-independent TypeScript/JavaScript).
PHP code lives in `src/`; browser code lives in `browser/`.

## Documentation

- Write all documentation, API descriptions, examples, and code comments in English.
- Keep this file concise. Link to focused documentation instead of duplicating it.
- Document actual behavior and verified commands, not planned features as if shipped.

## Architecture

- Session data always lives on the server. JWTs contain only a session ID and technical validation claims.
- Validate storage on every authenticated request. Never fall back to JWT-only authentication.
- Support explicitly configured cookie or header transports. Never accept session credentials in URLs.
- Keep the PHP core independent of Slim, Doctrine, user models, SSO, and application business logic.
- Keep the browser client independent of Vue and application endpoints; never patch global fetch.
- Use PSR-7/15/17/20 interfaces and injected configuration, clock, and infrastructure.
- PSR-16 alone is insufficient for session safety. Preserve the atomic session-store contract.

## Security and Concurrency

- Preserve application namespaces, issuer/audience checks, signature validation, and strict deadlines.
- Protect cookie-authenticated mutations with Origin validation and session-bound CSRF tokens by default; permit only explicitly configured, instance-wide independent opt-outs as described in [security controls](docs/security.md#optional-cookie-protection-controls).
- Require lease ownership, record existence, and revision checks for writes. Never upsert a stale session.
- Release locks on every exit path. Close the session before streaming or long-running work.
- Reject writes after lease loss or session close. Never replay business mutations automatically.
- Never log secrets, full JWTs, cookies, or complete session identifiers.
- Distinguish local client cleanup from confirmed server-side revocation.

## Testing

- Test PHP 8.3, 8.4, and 8.5 compatibility.
- Run shared backend contract tests against Redis, PostgreSQL, MySQL, MariaDB, and SQLite.
- Include real multiprocess tests for locks, rotation, revocation, and stale-write rejection.
- Cover browser refresh races, logout, storage failures, origin isolation, and cleanup.
- Use isolated test services only; report tests that could not be run.
- Run `composer test` at the repository root. See [local verification](docs/testing.md) for backend configuration and other checks.

## Scope and Change Safety

- Claire, Athena-Sense, and Hephaistos are read-only references, not migration targets.
- Do not access their production data or modify their files.
- Do not add legacy token compatibility, SSO, conversation storage, or business authorization implicitly.
- Preserve unrelated changes. Do not publish packages, commit, push, or deploy without explicit authorization.
