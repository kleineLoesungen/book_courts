# Stack Research

**Domain:** Hardening an existing framework-less PHP 8+ / PostgreSQL app (config/secrets, testing, session security, rate limiting)
**Researched:** 2026-07-27
**Confidence:** HIGH (secrets pattern, session config), MEDIUM (exact PHPUnit patch/PHP-version pairing — depends on the production PHP minor version, which was not confirmed in this pass)

## Scope Note

Core stack (PHP + PostgreSQL, no framework, no ORM) is fixed per `PROJECT.md` constraints and is **not** re-evaluated here. This file covers only the four hardening dimensions called out in the milestone: secrets/config management, a test framework, session hardening, and rate limiting — all chosen to add **zero or dev-only** dependencies and stay compatible with the existing PDO/stored-procedure architecture.

## Recommended Stack

### Core Technologies (new additions)

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| PHP native `parse_ini_file()` | Built into PHP 8.0+ (no install) | Parse a `.env`/`.ini` file into config | Zero dependencies, zero Composer footprint. Handles quoting, comments, and `KEY=VALUE` syntax natively — a hand-rolled parser or `vlucas/phpdotenv` both duplicate what core PHP already does correctly. Confidence: HIGH — this is documented core PHP behavior, not a hypothesis. |
| PHPUnit | **10.5.x** (PHAR) if prod PHP is 8.1–8.2; **12.x** (PHAR) if prod PHP is 8.3+ | Unit/integration testing for booking-conflict and series-materialization logic | Current stable line is PHPUnit 13.2 (as of 2026-07-25), which tracks the newest PHP releases closely and drops docblock-annotation support entirely. Given this app's stated floor is "PHP 8.0+" and the actual production PHP minor version was not confirmed, **PHPUnit 10.5 is the safe default** (supports PHP 8.1+, still installable, ships as a single self-contained `.phar` with zero Composer requirement). Verify `php -v` in production/CI before finalizing — see Version Compatibility table below. |
| Composer (dev-only, optional) | 2.x | Manage PHPUnit + optional dev tooling (PHPStan) as `require-dev`, without shipping `vendor/` to production | Only worth introducing if the team wants version-pinning/auto-update convenience over manual PHAR downloads. Production deployment does **not** need Composer or `vendor/` — the app has no autoloading dependency on it. Confidence: MEDIUM (a process/tooling choice, not a technical requirement). |

### Supporting Libraries

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| None (native PHP session functions) | Built-in | Session cookie hardening | Always — see Session Security pattern below. No library adds value here; PHP's own `session_set_cookie_params()`, `session.cookie_samesite`, and `session_regenerate_id()` cover the full OWASP session-security checklist. |
| None (existing PostgreSQL connection) | PostgreSQL 12+ (already in use) | Login rate limiting / brute-force lockout | Reuse the existing PDO/Postgres connection with a small `login_attempt` table (ip, email, success, attempted_at) instead of introducing Redis/Memcached. The app already uses Postgres advisory locks for booking-conflict safety — the same pattern (`pg_advisory_xact_lock(hashtext(...))`) can guard the attempt-counter increment if concurrent-write races become a concern, though at this app's traffic (single club) a plain `COUNT(*) WHERE attempted_at > now() - interval` query is sufficient without locking. |
| `password_hash()` / `PASSWORD_ARGON2ID` | Built-in (PHP 7.2+, if compiled with libargon2) | Password hashing | Switch the constant passed to `password_hash()` from `PASSWORD_DEFAULT` (bcrypt) to `PASSWORD_ARGON2ID` if the PHP build supports it (`password_algos()` will list `argon2id`); otherwise keep bcrypt with `['cost' => 12]`. This is a one-line change, not a new dependency. |

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| `phpunit-10.5.phar` or `phpunit-12.phar` | Test runner | Download once from `https://phar.phpunit.de/phpunit-10.phar` (rolling alias to latest 10.5.x patch) or `.../phpunit-12.phar`; commit to a `tools/` directory or document the download step in a `Makefile`/`composer.json` script. No `composer install` needed if using the PHAR directly. |
| `php -l` (built-in linter) | Syntax check | Already available; add to a pre-commit hook or CI step at zero cost — no new tool required. |
| PHPStan (optional, dev-only) | Static analysis to catch the kind of bugs already flagged in `CONCERNS.md` (e.g., the truncated `materialize_series()`) | If introduced, install as a Composer dev-dependency or its own PHAR (`phpstan.phar`) — same reasoning as PHPUnit. Not required for this milestone; flagged as a nice-to-have. |

## Installation

```bash
# Test framework — zero-Composer path (recommended given "no package manager" constraint)
mkdir -p tools
curl -L -o tools/phpunit.phar https://phar.phpunit.de/phpunit-10.phar   # or phpunit-12.phar on PHP 8.3+
chmod +x tools/phpunit.phar
php tools/phpunit.phar --version   # sanity check

# Run tests (once a tests/ dir + phpunit.xml bootstrap exist)
php tools/phpunit.phar --bootstrap tests/bootstrap.php tests/

# Secrets — no install needed, just a convention:
cp .env.example .env         # create real .env with production credentials
chmod 600 .env               # restrict file permissions
echo ".env" >> .gitignore    # never commit (do this alongside the existing conf.php ignore)
```

If the team later decides Composer is worth it for dev tooling (version pinning, auto-updates, PHPStan alongside PHPUnit), the equivalent is:

```bash
composer init --no-interaction
composer require --dev phpunit/phpunit:^10.5   # or ^12.0 on PHP 8.3+
echo "/vendor/" >> .gitignore
```

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|--------------------------|
| Native `parse_ini_file()` + hand-rolled `.env` loader (~15 lines) | `vlucas/phpdotenv` v5.6 | Use phpdotenv only if Composer is already being adopted for other reasons and the team wants `.env` syntax parity with Laravel/Symfony conventions (comments, multiline values, variable interpolation). It pulls in 4 transitive packages (`phpoption/phpoption`, `graham-campbell/result-type`, two Symfony polyfills) purely to parse `KEY=VALUE` pairs — disproportionate for a single `conf.php` replacement. |
| `.env` file loaded by the app at bootstrap | Apache `SetEnv` / vhost `Environment=` directives | Use this if the team has direct control over the Apache vhost (not just `.htaccess`) and prefers OS-level env vars with zero PHP-side parsing. Downside: every environment change requires an Apache reload/restart and vhost access, which is less portable for a small team without full server admin access on every deploy. |
| PHPUnit (PHAR, no Composer) | Composer-installed PHPUnit / Pest | Use Composer if the team is comfortable adding it as a **dev-only** tool (not shipped to prod) for easier version bumps and to add PHPStan alongside it in one `composer.json`. Pest itself requires Composer and a PHPUnit-compatible bootstrap; it adds a nicer DSL but no functional advantage for this app's needs (a handful of conflict-detection/series-materialization test suites) and pulls in more moving parts than plain PHPUnit. |
| PostgreSQL-table-based rate limiting | Redis/Memcached-based rate limiting | Use Redis only if traffic grows well beyond a single-club deployment and sub-millisecond counter increments under high concurrency become necessary. At current scale (a handful of concurrent users), adding a new infrastructure dependency purely for login-attempt counting is not justified — the existing Postgres connection is one query away from doing this safely. |
| `session_set_cookie_params()` + core `session.*` ini directives | A session-management library (e.g., a custom `SessionHandlerInterface` implementation, Symfony's session component) | Only worth it if session storage itself needs to move off the filesystem (e.g., database- or Redis-backed sessions for horizontal scaling across multiple app servers). `CONCERNS.md` already flags file-based session storage as a scaling limit above ~1000 concurrent sessions — irrelevant at this app's current/expected scale, so defer. |

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| A full framework migration (Laravel, Symfony) "to get testing/config for free" | Explicitly out of scope per `PROJECT.md` constraints; would force a rewrite of the working PDO/trigger-based conflict-detection logic that is the highest-value, best-tested part of the app | Targeted additions only: native PHP config parsing, PHAR-based PHPUnit, native session hardening |
| An ORM (Doctrine, Eloquent standalone) to "make testing easier" | The app's conflict-safety guarantees live in PostgreSQL triggers/advisory locks and hand-written SQL (range overlap checks) — an ORM would either bypass these guarantees or require reimplementing them in PHP, reintroducing the exact bug class (`CONCERNS.md`: "Booking Conflict Check Run Twice") the schema was designed to prevent | Keep PDO + raw SQL; test the SQL functions directly with PHPUnit database tests against a real (test) Postgres instance |
| Storing secrets in `.htaccess` via `SetEnv` | `.htaccess` is still a plaintext file in the repo/working directory; while Apache blocks direct HTTP access to it, it doesn't solve the underlying problem (`CONCERNS.md`'s core complaint about `conf.php`) any better than the current file, and it's easy to accidentally commit or copy alongside deploys | `.env` file, `.gitignore`'d, `chmod 600`, ideally stored outside the web root if hosting allows it |
| `vlucas/phpdotenv` without also adopting Composer generally | Its own `composer.json` requires 3 additional runtime packages (not just dev) — that's a "package manager introduced for one config file" outcome, contradicting the "no package manager" constraint's spirit | Native `parse_ini_file()` (see above) |
| Redis/Memcached for rate limiting | New infrastructure dependency (install, monitor, secure, back up) for a problem the existing Postgres database already solves at this traffic level | PostgreSQL table + simple windowed `COUNT(*)` query, or advisory lock if concurrency safety is later needed |
| `session.cookie_samesite = 'Strict'` as a blanket default without checking UX impact | Strict blocks the session cookie from being sent on top-level navigation arriving from outside the site (e.g., a user clicking a bookings link from a calendar invite or email while already logged in) — this can silently "log out" users on external navigation, which is confusing and hard to diagnose | `SameSite=Lax` (sends cookie on top-level GET navigation, blocks it on cross-site POST/embed) — this is also the value PHP's own upcoming default hardening RFC converged on for exactly this reason |

## Stack Patterns by Variant

**If production PHP is confirmed 8.1 or 8.2:**
- Use PHPUnit 10.5.x (last release supporting PHP 8.1) via PHAR.
- Because PHPUnit 11 already exited active bug-fix support (Feb 6, 2026, per project's own release policy) and PHPUnit 12 requires PHP 8.3 — 10.5 is the newest line still guaranteed compatible with 8.1/8.2.

**If production PHP is confirmed 8.3+:**
- Use PHPUnit 12.x via PHAR (or 13.x if willing to track the bleeding edge and drop all docblock-annotation-style tests in favor of PHP 8 attributes).
- Because newer PHPUnit majors get security/bugfix support for longer, and there's no reason to pin to an EOL'd major if the runtime supports better.

**If the team decides self-service password reset (an Active requirement in `PROJECT.md`) ships in this milestone:**
- Keep `SameSite=Lax`, not `Strict`, on the session cookie.
- Because reset-link emails create a fresh, unauthenticated navigation into the app — `Lax` guarantees the *new* session this flow establishes behaves correctly, and avoids the "logged out after clicking an external link" issue described above for any *already-logged-in* user who also clicks internal links from external contexts (e.g., a bookmarked admin link shared in chat).

**If the hosting environment gives shell/vhost access (not just FTP + `.htaccess`):**
- Consider storing the `.env` file **outside** the web root entirely (one directory above `public_html`/docroot) in addition to `.gitignore` + `chmod 600`.
- Because this removes even a misconfigured-Apache risk (`CONCERNS.md`'s "Missing .htaccess Rules" concern about dotfile exposure) as an attack vector for the credentials file, at zero additional cost.

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|-----------------|-------|
| PHPUnit 10.5.x | PHP 8.1, 8.2, 8.3 (forward-compatible) | Last PHPUnit major to support PHP 8.1; PHP 8.1 is the hard floor for 10.x (not 8.0, despite some third-party summaries stating otherwise — verified against official `docs.phpunit.de/en/10.5/installation.html`). |
| PHPUnit 11.x | PHP 8.2+ | Bug-fix support window closed 2026-02-06 per PHPUnit's own release policy — do not adopt new for this milestone; only relevant if already in use. |
| PHPUnit 12.x | PHP 8.3+ | Docblock annotations (`@test`, `@dataProvider`, etc.) fully removed — tests must use PHP 8 attributes (`#[Test]`, `#[DataProvider]`). |
| PHPUnit 13.x | PHP 8.3+ (verify exact floor at adoption time; not confirmed in this research pass) | Current stable as of 2026-07-25 (v13.2.5). Only adopt if production PHP is confirmed comfortably above the floor and the team wants to track newest releases. |
| `PASSWORD_ARGON2ID` | PHP 7.2+, but only if PHP was compiled `--with-password-argon2` (common on most Linux distro packages, not guaranteed on all shared hosting) | Check with `password_algos()` before switching; fall back to bcrypt (`PASSWORD_BCRYPT`, cost 12) if argon2id isn't listed. |
| `session.cookie_samesite` ini directive | PHP 7.3+ | Already satisfied by this app's PHP 8+ floor — no version risk. |

## Sources

- https://docs.phpunit.de/en/10.5/installation.html — verified PHPUnit 10.5 requires PHP 8.1 (official docs, HIGH confidence)
- https://docs.phpunit.de/en/12.5/installation.html — verified PHPUnit 12 requires PHP 8.3 (official docs, HIGH confidence)
- https://phar.phpunit.de/ — confirmed PHAR distribution channel and current latest release (phpunit-13.2.5.phar, dated 2026-07-25) (official distribution site, HIGH confidence)
- WebSearch: "PHPUnit 10 vs 11 vs 12 PHP version requirements matrix support" — cross-checked major-version support windows (MEDIUM confidence, corroborated by official docs above)
- WebSearch: "vlucas/phpdotenv latest version 2026 composer PHP 8" — confirmed phpdotenv v5.6.x, PHP `^7.2.5||^8.0` support, and its transitive dependency footprint (Packagist/GitHub, MEDIUM confidence)
- https://www.php.net/manual/en/function.session-set-cookie-params.php — official PHP manual for session cookie configuration (HIGH confidence)
- WebSearch: "PHP session security best practices 2026 session_set_cookie_params samesite strict" — corroborated `SameSite=Lax` as the broadly recommended default over `Strict`, including reference to PHP's own session-security-defaults RFC discussion (MEDIUM confidence, multiple sources agree)
- WebSearch: "PostgreSQL advisory locks beat Redis for rate limiting" / "Rate Limiting in Postgres - Neon Guides" — confirmed Postgres-native rate limiting (table + optional advisory lock) as a current, credible pattern that avoids introducing Redis (MEDIUM confidence)
- `.planning/codebase/CONCERNS.md`, `.planning/codebase/STACK.md`, `.planning/PROJECT.md` — project-specific constraints and existing gaps that shaped every recommendation above

---
*Stack research for: PHP/PostgreSQL app hardening (secrets, testing, sessions, rate limiting)*
*Researched: 2026-07-27*
