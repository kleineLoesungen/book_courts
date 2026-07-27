# Project Research Summary

**Project:** Book Courts
**Domain:** Hardening a live, framework-less PHP 8+ / PostgreSQL court-booking monolith (single club, brownfield — no from-scratch build)
**Researched:** 2026-07-27
**Confidence:** HIGH

## Executive Summary

Book Courts is a working, single-club court-booking application (two monolithic entry points, `index.php` and `admin.php`, backed by PostgreSQL stored procedures and triggers for conflict-safe scheduling) that needs a **hardening milestone**, not a rewrite. Across all four research tracks, one message repeats: this is a "strangler-fig" job — extract duplicated helpers into a shared `includes/` layer, add config/session/testing/audit infrastructure incrementally, and never touch the working PDO/trigger-based conflict-detection logic that is the app's highest-value asset. Every stack recommendation deliberately adds zero or dev-only dependencies (native `parse_ini_file()` for config, PHAR-distributed PHPUnit, native PHP session functions, a Postgres table for rate limiting) specifically to respect the project's "no package manager, no framework" constraint while still meeting professional security/testing bars (OWASP, NIST SP 800-63B-4).

The recommended approach is dependency-ordered: config/secrets extraction must land first (everything else — DB connection, session hardening, tests — reads from it), followed by pure byte-identical extractions (`db.php`, `security.php`, `flash.php`), then the one extraction that is *not* a pure copy-paste (`auth.php`, because `index.php` and `admin.php`'s identically-named `is_logged_in()` functions actually check different session keys and must be reconciled, not merged), then test coverage, then the new cross-cutting features (audit logging, password reset/change, session timeout) that depend on auth and testing being in place. Password reset is explicitly flagged as the one feature with an open, non-architectural decision (this app has zero outbound email capability today) that should be resolved during roadmap/requirements rather than assumed.

The key risks are not "will the code work" but "will the fix silently break something users rely on today": rotating the DB credential naively can cause a live outage; adding `Secure`/`SameSite=Strict` cookie flags can silently log everyone out or break external-link flows; IP-based rate limiting can lock out the entire club from a shared clubhouse network; and merging the two `is_logged_in()`-style helpers without diffing them first can quietly unify user and admin sessions. Each of these has a well-understood, low-cost mitigation (dual-role rotation, `SameSite=Lax` + verified HTTPS detection, per-account not per-IP lockout with an admin override, diff-before-merge with regression tests). The research is unusually high-confidence for a project this size because it's grounded directly in the existing codebase (`.planning/codebase/*`) rather than generic best practice.

## Key Findings

### Recommended Stack

The core stack (PHP + PostgreSQL, no framework, no ORM) is fixed by `PROJECT.md` and was not re-evaluated. Research covered only the four hardening additions needed this milestone, all chosen to add zero or dev-only dependencies: native PHP config parsing to replace plaintext `conf.php`, PHAR-distributed PHPUnit for testing, native PHP session functions for cookie hardening, and a PostgreSQL-table-based approach for login rate limiting (no Redis).

**Core technologies:**
- `parse_ini_file()` / hand-rolled `.env` loader (~15 lines, native PHP) — replaces plaintext `conf.php`; zero Composer footprint, avoids `vlucas/phpdotenv`'s 4 transitive dependencies for a one-file config parser
- PHPUnit 10.5.x (PHP 8.1/8.2) or 12.x (PHP 8.3+), distributed as a `.phar` — testing framework for booking-conflict and series-materialization logic; **verify production PHP minor version before finalizing**, since the correct major version depends on it
- Native `session_set_cookie_params()` + `session.cookie_samesite` — full OWASP session-cookie hardening checklist, no library needed
- PostgreSQL table (`login_attempt`) + windowed `COUNT(*)` query — brute-force/rate-limiting counter, reusing the existing Postgres connection instead of adding Redis/Memcached
- `password_hash()` / `PASSWORD_ARGON2ID` (fallback to bcrypt cost 12) — one-line upgrade if the PHP build supports argon2id (`password_algos()`)
- Composer (dev-only, optional) — only to manage PHPUnit/PHPStan as `require-dev`; production ships zero `vendor/` and no runtime dependency

### Expected Features

This is a hardening milestone for an already-live app, not a 0-to-1 feature build. Four features are in scope this milestone per `PROJECT.md`'s Active requirements: change-password, password reset, audit trail, and session timeout.

**Must have (table stakes):**
- Change password (self-service, logged-in) — cheapest, highest-value; currently only admin can change a user's password
- Session inactivity timeout (app-enforced via `$_SESSION['last_activity']`, not PHP's `gc_maxlifetime`, which is probabilistic) + an absolute lifetime cap
- Session cookie security flags (`HttpOnly`, `Secure`, `SameSite=Lax`) — bundle with timeout, same bootstrap code path
- Basic `audit_log` table + logging at every mutation point (bookings, series, admin CRUD, login success/failure, password events)

**Should have (differentiators):**
- Admin-facing audit log viewer (filterable UI) — pure UX polish once the table exists
- Self-service password reset via email — blocked on an explicit mail-delivery decision (`mail()` vs. PHPMailer-without-Composer vs. admin-mediated fallback)
- Password breach-list screening (HIBP range API) — defense-in-depth per NIST SP 800-63B-4

**Defer (v2+ or explicitly out of scope):**
- "Remember me" persistent login — adds a parallel long-lived-token attack surface for marginal gain
- 2FA/MFA, SSO/OAuth, full RBAC — disproportionate for a closed-membership, admin-provisioned single club
- Security questions as reset fallback, forced password rotation/composition rules — both explicitly contraindicated by current NIST SP 800-63B-4 guidance
- Immutable/tamper-proof audit log, concurrent-session limiting — infrastructure disproportionate to threat model

### Architecture Approach

The extraction does not change the request/response model — still plain PHP scripts served by Apache, no router or front controller. It relocates duplicated code from `index.php`/`admin.php` into a new `includes/` layer (`config.php`, `db.php`, `security.php`, `session.php`, `auth.php`, `flash.php`, `audit.php`, wired together by `bootstrap.php`) that both entry points `require_once`, using a strangler-fig pattern: one helper moved at a time, each move independently committable and deployable.

**Major components:**
1. `includes/config.php` — loads secrets/settings from `.env`/environment, exposes typed accessors; must be extracted first since every other component depends on it
2. `includes/db.php`, `security.php`, `flash.php` — pure, byte-identical extractions of `pdo()`, `h()`/`csrf_*`, `flash()`/`render_flash()`; lowest risk, done early
3. `includes/session.php` — owns `session_set_cookie_params()` + `session_start()`, depends on config for HTTPS detection
4. `includes/auth.php` — the one extraction requiring reconciliation, not copy-paste: `index.php`'s and `admin.php`'s identically-named `is_logged_in()` check different session keys (`user` vs `admin_user`) and must become realm-scoped functions (`user_is_logged_in()`, `admin_is_logged_in()`)
5. `includes/audit.php` (new) + `tests/` (new, PHPUnit dev-only) — cross-cutting additions that depend on auth/db being stable first

### Critical Pitfalls

1. **Credential rotation causes a booking outage** — rotating the Postgres password and updating `conf.php`/`.env` as two unsynchronized steps causes a live 500 window; avoid via dual-role cutover or a scripted, simultaneous rotation during low-traffic hours with a tested rollback.
2. **Session cookie hardening silently breaks login** — `Secure` on a not-fully-HTTPS deployment or behind an unverified reverse proxy silently prevents the cookie from ever being set (infinite login loop); `SameSite=Strict` breaks external-link entry (e.g., future reset-email links). Use `SameSite=Lax`, verify `$_SERVER['HTTPS']`/`X-Forwarded-Proto` against the real production config, and test login via direct visit, bookmark, and external referrer.
3. **Rate limiting locks out the whole club behind a shared IP/NAT** — IP-based lockout is the default reach but is uniquely bad for a small, geographically-concentrated user base (clubhouse Wi-Fi). Rate-limit per-account (email), keep IP-based limiting as a much looser secondary defense, and give admins an override path.
4. **Helper deduplication introduces "Cannot redeclare function" errors or silently changes behavior** — the two copies of each duplicated helper are rarely byte-identical; diff them line-by-line before merging, use `require_once` only, and sequence this phase *after* test coverage exists so a green suite verifies "no behavior changed."
5. **Retrofitted tests assert current (possibly buggy) behavior as if it were the spec** — especially risky for the flagged-as-truncated `materialize_series()`; separate characterization tests (freeze current behavior) from correctness tests (derived independently from business rules — 30-min grid, no overlaps, Europe/Berlin DST) written *before* helper dedup and any conflict-check "simplification."

## Implications for Roadmap

Based on combined research, a dependency-ordered phase structure emerges directly from the architecture's "Suggested Build Order" cross-checked against the pitfalls' phase-sequencing recommendations (test coverage and characterization must precede helper dedup; config must precede everything).

### Phase 1: Credential & Config Hardening
**Rationale:** Every other phase (DB connection, session hardening, tests, audit) reads from config; this is also the single highest-priority security fix (`PROJECT.md`'s top Active requirement) and research strongly recommends doing it in isolation so a rotation failure has a small diagnosis space.
**Delivers:** `includes/config.php`, `.env`/`.env.example`, scripted/tested Postgres credential rotation procedure (dual-role or synchronized-deploy pattern), `conf.php` deprecated.
**Addresses:** "Rotate and secure exposed database credentials" (PROJECT.md Active)
**Avoids:** Pitfall 1 (credential rotation outage) — via dual-role cutover or scripted simultaneous rotation with a tested rollback, done during low-traffic hours.

### Phase 2: Core Helper Extraction (Pure, Low-Risk)
**Rationale:** `db.php`, `security.php`, `flash.php` are byte-identical between `index.php`/`admin.php` today — safest possible extractions, and they establish the `bootstrap.php` seam every later phase relies on.
**Delivers:** `includes/db.php`, `includes/security.php`, `includes/flash.php`, `includes/bootstrap.php` (skeleton), both entry points slimmed down.
**Uses:** Strangler-fig extraction pattern (Pattern 1), `require_once`-only discipline.
**Implements:** `includes/db.php` / `security.php` / `flash.php` components from ARCHITECTURE.md.

### Phase 3: Session Security
**Rationale:** Cookie hardening and session bootstrap ownership both touch the same `session_start()` call site in both entry points — doing them together avoids opening the same files twice, and this is cheap, isolated, and independently revertible.
**Delivers:** `includes/session.php` (cookie flags + `session_start()`), verified HTTPS detection in the real production environment, `SameSite=Lax`.
**Addresses:** "Add session cookie security flags" (PROJECT.md Active)
**Avoids:** Pitfall 2 (cookie hardening silently breaks login) — via `SameSite=Lax` choice and an explicit test matrix (direct visit, bookmark, external referrer) as acceptance criteria, not just "flags present in code."

### Phase 4: Realm-Aware Auth Extraction
**Rationale:** The one extraction that cannot be a pure copy-paste — `is_logged_in()` means different things in each file and must be reconciled deliberately before anything (audit, password reset) depends on a unified `current_user()`/`current_admin()`.
**Delivers:** `includes/auth.php` with `user_is_logged_in()`, `admin_is_logged_in()`, `current_user()`, `current_admin()`, `is_admin()`; both entry points' call sites updated in the same commit.
**Implements:** Pattern 3 (Realm-Scoped Auth Helpers) from ARCHITECTURE.md.
**Avoids:** Anti-Pattern 2 (merging `is_logged_in()` because the names match) — a security-relevant landmine, not just a DRY cleanup.

### Phase 5: Test Coverage for Conflict-Critical Logic
**Rationale:** Must precede helper dedup and any conflict-check "simplification" so those later changes can be verified against a green suite rather than by hand; also directly addresses the Active requirement to verify/complete `materialize_series()`.
**Delivers:** PHPUnit (PHAR, dev-only) wired via `tests/bootstrap.php`; characterization tests explicitly labeled as such; independently-reasoned correctness tests for the 30-minute grid, overlap prevention, and Europe/Berlin DST boundaries; `materialize_series()` verified/completed against hand-calculated expected results.
**Addresses:** "Add automated test coverage for booking conflict detection and series materialization" and "Verify/complete `materialize_series()`" (PROJECT.md Active)
**Avoids:** Pitfall 5 (retrofitted tests encode current bugs as spec) — via the explicit characterization-vs-correctness split and DST/boundary test cases before the dedup phase touches this code.

### Phase 6: Helper Deduplication (Remaining Duplication)
**Rationale:** Sequenced after testing so extraction is verified against a green regression suite; this is the phase most exposed to silent behavior changes, so it needs the safety net from Phase 5 in place first.
**Delivers:** Any remaining duplicated logic consolidated; diff-before-merge discipline applied and documented per function.
**Addresses:** "Deduplicate shared helpers" (PROJECT.md Active)
**Avoids:** Pitfall 4 (redeclare errors / silent behavior change) and Pitfall 6 (removing the friendly conflict-check UX while "simplifying") — both called out as explicit carve-outs for this phase specifically.

### Phase 7: Brute-Force Protection
**Rationale:** Independent of the extraction work but benefits from `auth.php` and `db.php` already being stable; can run in parallel with Phase 6 if capacity allows, but is listed after test coverage since lockout logic should also get regression tests.
**Delivers:** Per-account (not per-IP) login rate limiting/lockout, with a looser secondary IP-based check, an admin override path, and a documented manual-unlock procedure.
**Addresses:** "Add brute-force protection on login" (PROJECT.md Active)
**Avoids:** Pitfall 3 (rate limiting locks out the whole club behind a shared IP/NAT) — via per-account keying and a tested two-account-same-IP scenario.

### Phase 8: Audit Trail
**Rationale:** Depends on `auth.php` (Phase 4, needs `current_user()`/`current_admin()` to attribute events) and `db.php` (Phase 2); call sites are added explicitly at each existing mutation point, following the codebase's "no framework, no implicit magic" convention (no middleware).
**Delivers:** `audit_log` table, `includes/audit.php`, logging calls at every mutating action (bookings, series, admin CRUD, login success/failure, password events).
**Addresses:** "Add basic audit trail / logging" (PROJECT.md Active)
**Implements:** Pattern 5 (Cross-Cutting Concerns as Explicit Call Sites) from ARCHITECTURE.md.

### Phase 9: Session Timeout, Change Password, Password Reset
**Rationale:** These three depend on auth (Phase 4) being stable and, for reset specifically, on an explicit decision about email delivery — the one feature in this milestone with an open external-integration question. Session timeout and change-password have no such blocker and could ship first within this phase; reset should be scoped last or split into its own sub-phase once the mail decision is made.
**Delivers:** App-enforced session inactivity timeout + absolute cap; self-service change-password (current-password re-verification, session ID regeneration); self-service password reset (token table, hashed single-use tokens, generic response messaging) — contingent on resolving the mail-delivery decision (native `mail()`, PHPMailer without Composer, or admin-mediated interim fallback).
**Addresses:** "Add session timeout," "Add user self-service password reset and change-password" (PROJECT.md Active)
**Avoids:** Pitfall 2 (SameSite/HTTPS interactions, relevant again once reset-email links exist).

### Phase Ordering Rationale

- **Config first, always:** every other component (DB, session, tests, audit) reads a secret or an environment flag from it — re-touching every file a second time is the cost of doing this out of order.
- **Pure extractions before the reconciliation extraction:** `db.php`/`security.php`/`flash.php` are safe, mechanical wins that build confidence and establish the `bootstrap.php` seam before the riskier `auth.php` merge.
- **Tests before dedup, dedup before new features on top of duplicated code:** this ordering is explicitly called out by PITFALLS.md as a key roadmap decision — writing characterization tests first, then correctness tests, gives helper dedup (and any future conflict-check changes) a regression safety net instead of manual verification.
- **Password reset last among the cross-cutting features:** it is the only item in this milestone with an unresolved external-integration decision (no existing email capability); sequencing it last avoids blocking other, purely-internal work on a decision that belongs in requirements/roadmap discussion, not architecture.

### Research Flags

Phases likely needing deeper research during planning:
- **Phase 1 (Credential & Config Hardening):** the exact production PHP minor version and hosting environment (shell/vhost access vs. FTP-only) were not confirmed in this research pass and materially affect both the PHPUnit version choice (Phase 5) and whether `.env` can live outside the web root — confirm before finalizing.
- **Phase 9 (Password Reset):** the mail-delivery decision (native `mail()` vs. PHPMailer-without-Composer vs. admin-mediated fallback) is an open scope question that should be resolved explicitly during phase planning, not assumed.

Phases with standard patterns (skip research-phase):
- **Phase 2 (Core Helper Extraction):** pure, byte-identical extractions with a well-documented strangler-fig pattern — low ambiguity.
- **Phase 3 (Session Security):** native PHP session functions fully cover OWASP's checklist; pattern is well-established (`SameSite=Lax`, verified HTTPS detection).
- **Phase 4 (Auth Extraction):** the reconciliation approach (realm-scoped renaming) is already fully specified in ARCHITECTURE.md.
- **Phase 8 (Audit Trail):** single flat `audit_log` table pattern is standard for apps this size; already fully specified.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH for secrets pattern and session config; MEDIUM for exact PHPUnit version pairing | Production PHP minor version wasn't confirmed during research — verify `php -v` before finalizing PHPUnit major version choice |
| Features | HIGH | Grounded in current OWASP Cheat Sheets and NIST SP 800-63B-4 (finalized 2025), cross-checked against multiple independent PHP-specific sources |
| Architecture | HIGH | Based on direct reading of the actual codebase (`index.php`, `admin.php`, `conf.php`) plus the existing GSD codebase-mapper output, not generic inference |
| Pitfalls | HIGH for credential rotation, session/CSRF mechanics, rate-limiting failure modes, PHP redeclare errors; MEDIUM for project-specific extrapolations | Core technical claims verified against current, authoritative sources; project-specific risk framing (e.g., "shared clubhouse Wi-Fi") is a reasonable but unverified extrapolation |

**Overall confidence:** HIGH

### Gaps to Address

- **Production PHP minor version unconfirmed:** determines whether PHPUnit 10.5.x or 12.x/13.x is the correct choice (Phase 5) — resolve with `php -v` before that phase starts.
- **Hosting environment access level unconfirmed:** whether the team has shell/vhost access (allowing `.env` to live outside the web root) vs. FTP + `.htaccess` only — affects the exact secrets-storage hardening in Phase 1.
- **Mail-delivery decision for password reset unresolved:** three viable paths (native `mail()`, PHPMailer-without-Composer, admin-mediated fallback) with different complexity/reliability tradeoffs — this is a scope decision for Phase 9, not an architecture one, and should be made explicitly during roadmap/requirements review rather than assumed.
- **Argon2id availability on the production PHP build unconfirmed:** check `password_algos()` before switching from bcrypt; trivial fallback exists (`PASSWORD_BCRYPT`, cost 12) if unsupported.

## Sources

### Primary (HIGH confidence)
- https://docs.phpunit.de/en/10.5/installation.html, https://docs.phpunit.de/en/12.5/installation.html, https://phar.phpunit.de/ — PHPUnit version/PHP-compatibility matrix
- https://www.php.net/manual/en/function.session-set-cookie-params.php — official PHP session cookie manual
- OWASP Forgot Password Cheat Sheet, OWASP Session Management Cheat Sheet, OWASP Authentication Cheat Sheet
- NIST SP 800-63B Revision 4 (finalized 2025) — via Enzoic and Netwrix summaries
- Direct reading of `index.php`, `admin.php`, `conf.php` (this repository); `.planning/codebase/ARCHITECTURE.md`, `STRUCTURE.md`, `CONVENTIONS.md`, `CONCERNS.md`, `TESTING.md`, `INTEGRATIONS.md`; `.planning/PROJECT.md`
- PHP "Cannot redeclare function" error — alvinalexander.com, MODX blog
- Rotating PostgreSQL Passwords with no downtime — Jannik Arndt; Infisical docs

### Secondary (MEDIUM confidence)
- WebSearch on `vlucas/phpdotenv` dependency footprint, Postgres-based rate limiting vs. Redis, `SameSite=Lax` vs. `Strict` community consensus
- SameSite=Strict breaks one-time login links — Drupal.org #3401709; session_regenerate_id race — PHP Externals; CSRF race condition — Drupal.org #2941102
- Rate-limiting login attempts — timoh6.github.io; Broken brute-force protection — Ahmad Sopyan, Medium
- General strangler-fig / legacy-refactor practice, cross-checked via tsh.io, PHPUnit legacy adapter

### Tertiary (LOW confidence)
- None flagged — all findings were corroborated by at least one authoritative or multiply-sourced reference.

---
*Research completed: 2026-07-27*
*Ready for roadmap: yes*
