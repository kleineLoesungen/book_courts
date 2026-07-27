# Architecture Research

**Domain:** Incremental extraction of shared PHP helpers from a two-file monolith (`index.php` + `admin.php`), plus placement of new cross-cutting concerns (env config, session security, audit logging, password reset, tests)
**Researched:** 2026-07-27
**Confidence:** HIGH (based on direct reading of the existing codebase — `index.php`, `admin.php`, `conf.php`, and the `.planning/codebase/*` mapper output — cross-checked against general PHP legacy-refactor and PHPUnit-without-framework practices)

## Standard Architecture

### System Overview — Target State After Extraction

The extraction does **not** change the request/response model (still plain PHP scripts served by Apache, no router, no front controller). It only relocates duplicated code from the two entry points into a shared `includes/` layer that both `require_once`. Everything below the dashed line is unchanged from today.

```
┌───────────────────────────────────────────────────────────────────────┐
│  Browser                                                               │
└───────────────────────────────┬───────────────────────────────────────┘
                                 │ HTTP GET/POST (?action=...)
┌────────────────────────────────▼───────────────────────────────────────┐
│  Entry Points (unchanged responsibility, shrinking line count)         │
│  ┌─────────────────────┐        ┌─────────────────────┐                │
│  │     index.php        │        │     admin.php        │                │
│  │  routing + rendering │        │  routing + rendering │                │
│  └──────────┬───────────┘        └───────────┬───────────┘                │
│             │  require_once includes/bootstrap.php (both)              │
├─────────────┴──────────────────────────────────┴────────────────────────┤
│                        includes/ (NEW shared layer)                     │
│  ┌───────────┐ ┌─────────┐ ┌──────────┐ ┌────────┐ ┌────────┐ ┌───────┐ │
│  │ config.php│ │ db.php  │ │security. │ │auth.php│ │flash.  │ │audit. │ │
│  │ (env)     │ │ (pdo()) │ │php (csrf,│ │(realms)│ │php     │ │php    │ │
│  │           │ │         │ │  h())    │ │        │ │        │ │(new)  │ │
│  └─────┬─────┘ └────┬────┘ └────┬─────┘ └───┬────┘ └───┬────┘ └───┬───┘ │
│        └────────────┴───────────┴───────────┴──────────┴──────────┘    │
│                     bootstrap.php (wires the above, session_start())    │
├──────────────────────────────────────────────────────────────────────────┤
│  Database Layer (unchanged)                                             │
│  PostgreSQL: create_booking(), free_courts(), prevent_overlap() trigger  │
│  + NEW: audit_log table, password_reset_token table                     │
└──────────────────────────────────────────────────────────────────────────┘
                                 ▲
                                 │ require_once (also used by)
┌────────────────────────────────┴───────────────────────────────────────┐
│  tests/ (NEW — PHPUnit, dev-only, no Composer in production)           │
│  tests/bootstrap.php → requires the same includes/*.php files          │
│  Unit tests: h(), csrf_*, format_duration_short(), auth realm logic     │
│  Integration tests: create_booking(), materialize_series() via test DB │
└──────────────────────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | Responsibility | Notes on current duplication |
|-----------|----------------|------------------------------|
| `includes/config.php` | Load DB credentials + app settings from environment (`.env` or real env vars), never hardcoded; expose typed accessors (`config('db.dsn')` or a small `Config` class) | Currently `conf.php` holds plaintext secrets, loaded via `global $dsn, $DB_USER, $DB_PASS` — a global-variable anti-pattern that both entry points repeat |
| `includes/db.php` | `pdo()` singleton factory | Byte-identical in both files today (`index.php` L19-30, `admin.php` L19-30) — safest first extraction |
| `includes/security.php` | `h()`, `csrf_token()`, `csrf_check()` | Byte-identical (or near-identical) in both files — second safest extraction |
| `includes/flash.php` | `flash()`, `render_flash()` | Byte-identical in both files — safe extraction |
| `includes/auth.php` | Login state, `current_user()`, `is_admin()`, ownership checks | **Not** identical: `index.php`'s `is_logged_in()` checks `$_SESSION['user']`; `admin.php`'s checks `$_SESSION['admin_user']`. These are two different realms, not one duplicated function — must be reconciled explicitly during extraction (see Pattern 3 below), not naively merged |
| `includes/bootstrap.php` | Single require target for both entry points: loads config, starts session with hardened cookie params, requires db/security/auth/flash | New file — replaces the raw `session_start()` currently at the top of each entry point |
| `includes/audit.php` (new) | `audit_log(string $event, array $context)` — writes to a new `audit_log` table | Does not exist today; this is new cross-cutting behavior, not an extraction |
| `includes/ui_helpers.php` (optional, lower priority) | `render_time_select()`, `render_duration_input()`, `format_duration_short()`, `anonymize_name()` | Currently only in `index.php` (not duplicated in `admin.php`), so extracting this is about testability, not deduplication — lower priority than the truly duplicated helpers |
| `tests/bootstrap.php` (new) | Requires the same `includes/*.php` files PHPUnit needs, points `config.php` at a test DSN | Needs helpers to have zero side effects at require-time (no `session_start()`, no DB connection) to be requirable in isolation |

## Recommended Project Structure

```
book_courts/
├── includes/
│   ├── config.php        # env loading (.env or real env vars) + typed accessors; no secrets in git
│   ├── db.php             # pdo() singleton, depends on config.php
│   ├── security.php       # h(), csrf_token(), csrf_check() — pure/session-only, no DB dependency
│   ├── session.php        # session_set_cookie_params() + session_start(); depends on config.php (APP_ENV/HTTPS flag)
│   ├── auth.php           # realm-aware auth: user_is_logged_in(), admin_is_logged_in(), current_user(), current_admin(), is_admin()
│   ├── flash.php           # flash(), render_flash()
│   ├── audit.php           # NEW: audit_log() writes to audit_log table
│   └── bootstrap.php       # requires config → db → security → session → auth → flash, in that order
├── index.php               # entry point: require_once includes/bootstrap.php; routing + rendering only
├── admin.php                # entry point: require_once includes/bootstrap.php; routing + rendering only
├── conf.php                 # DEPRECATED after config.php lands — kept only as a fallback shim during transition, then deleted
├── .env                      # new — real secrets, gitignored (mirrors conf.php's current .gitignore treatment)
├── .env.example               # new — committed, documents required keys with placeholder values
├── pg.sql                    # existing schema + new audit_log / password_reset_token tables
├── tests/
│   ├── bootstrap.php          # requires includes/*.php against a test DSN; no entry-point HTML pulled in
│   ├── Unit/                  # security.php, flash.php, auth.php (realm logic), ui_helpers.php
│   └── Integration/            # create_booking(), materialize_series(), auth flows, against a real test Postgres DB
├── composer.json               # NEW, dev-only: requires phpunit/phpunit as --dev; production code has zero runtime dependencies
└── .htaccess                    # extend existing deny rules to also cover includes/ and tests/ (deny from web, PHP require still works)
```

### Structure Rationale

- **`includes/` over `src/`:** matches the project's existing flat, non-namespaced, non-autoloaded convention (STRUCTURE.md/CONVENTIONS.md confirm no PSR-4, no Composer today). Introducing `src/` + namespaces + autoloading in the same milestone as hardening would be two changes at once — avoid it.
- **One file per concern, not one big `helpers.php`:** `CONCERNS.md` already names the risk ("maintenance nightmare"); a single flat `helpers.php` just recreates the same duplication risk one level up. Splitting by concern (`db`, `security`, `auth`, `flash`, `audit`, `session`) keeps each file small, single-purpose, and independently testable — and lets `tests/bootstrap.php` require only what a given test class needs.
- **`bootstrap.php` as the only thing entry points require:** keeps `index.php`/`admin.php` diffs minimal (`session_start();` + implicit globals → one `require_once __DIR__ . '/includes/bootstrap.php';`). This is the key seam that lets extraction happen file-by-file without ever breaking either entry point mid-refactor.
- **`config.php` before everything else:** every other extraction (db, session hardening, tests, audit) either reads a secret or reads an environment flag (`APP_ENV`, `TEST_DATABASE_DSN`). Doing config last would mean re-touching every other file a second time.
- **`.env` + `.env.example`, no Composer package for parsing:** no dependency manager is in place today (STACK.md/PROJECT.md); pulling in `vlucas/phpdotenv` means introducing Composer into production just to parse three lines of `KEY=value`. A ~15-line custom parser that populates `$_ENV` (not `putenv()`, which is not thread-safe under some SAPIs) is standard practice for small PHP apps without a package manager and avoids a new runtime dependency for a one-time win.
- **Composer only as a dev dependency for PHPUnit:** this satisfies TESTING.md's PHPUnit recommendation without contradicting PROJECT.md's constraint ("Composer is not currently used; introducing dependencies needs a deliberate, minimal setup"). `composer.json` with `require-dev` only, `vendor/` gitignored, does not change how the app is deployed (still copy `.php` files to web root).

## Architectural Patterns

### Pattern 1: Strangler-Fig Extraction via Shared Bootstrap

**What:** Instead of rewriting `index.php`/`admin.php`, create `includes/bootstrap.php` first as an empty/near-empty file, add one `require_once` to it in both entry points (replacing nothing yet), then move one helper function at a time out of the entry points and into a new `includes/*.php` file that `bootstrap.php` requires — deleting the now-duplicate definition from the entry point on each move.
**When to use:** Every extraction step in this milestone. Each function move is a single, independently-committable, independently-testable change — never a multi-file rewrite.
**Trade-offs:** Slower than a rewrite, but each step keeps the app in a working, deployable state (important since this is a live club-booking tool with no staging environment mentioned). Requires discipline to not "just fix it while I'm in there" — extraction commits should be behavior-preserving only.

**Example (step for `db.php`):**
```php
// includes/db.php (new file)
<?php
declare(strict_types=1);
function pdo(): PDO
{
  static $pdo = null;
  if ($pdo === null) {
    $cfg = config(); // from includes/config.php, required earlier in bootstrap.php
    $pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
  return $pdo;
}
```
```php
// index.php and admin.php: delete their own pdo() function, keep everything else unchanged
require_once __DIR__ . '/includes/bootstrap.php'; // now brings pdo() in scope
```

### Pattern 2: Config Object/Array Over Scattered Globals

**What:** Replace `conf.php`'s `global $dsn, $DB_USER, $DB_PASS` pattern with a single `config(string $key = null)` function backed by a loaded associative array (or a small readonly class), populated from `.env`/real environment variables with `conf.php`-style defaults only as a documented local-dev fallback.
**When to use:** Immediately, before any other extraction — both `db.php` and the future test harness need a single source of truth for "where do credentials/settings come from" (dev machine `.env`, CI environment variables, production environment variables).
**Trade-offs:** One extra layer of indirection (`config('db.dsn')` vs `$dsn`) for a codebase that otherwise uses raw globals everywhere — but this is the one place indirection is worth it, since it's the seam that lets the exposed production credential be rotated and kept out of git going forward, and lets tests point at a different database without touching `db.php`.

### Pattern 3: Realm-Scoped Auth Helpers (Resolve, Don't Merge, the Duplication)

**What:** `is_logged_in()` in `index.php` and `is_logged_in()` in `admin.php` have the *same name* but *different meaning* (different session keys: `user` vs `admin_user`). A naive "extract to shared file" would silently break one of the two call sites. Instead, extraction must rename to intent-revealing, realm-scoped functions: `user_is_logged_in()`, `admin_is_logged_in()`, `current_user()`, `current_admin()`, and keep `is_admin()` (role check on the logged-in *user*, distinct from "is this the separate admin login") as-is. Update call sites in both files as part of the same commit that creates `includes/auth.php`.
**When to use:** During the auth extraction step — this is the one helper set that cannot be a pure copy-paste.
**Trade-offs:** Slightly more verbose function names than today's `is_logged_in()`, but removes a real landmine (a future contributor "deduplicating" the two functions by keeping only one would silently merge user and admin sessions).

### Pattern 4: Session Ownership Moves to `bootstrap.php`, Not Left in Entry Points

**What:** `session_set_cookie_params()` (HttpOnly/Secure/SameSite — flagged in CONCERNS.md) must run *before* `session_start()`. Rather than pasting that block into both entry points, `includes/session.php` owns both calls, and `bootstrap.php` requires it. Entry points lose their raw `session_start();` line entirely.
**When to use:** As part of the config/bootstrap extraction step, since the `Secure` flag needs to know whether the request is HTTPS (from config/environment, not hardcoded).
**Trade-offs:** None significant — this is a pure win and directly addresses the "Active" requirement to add session cookie security flags.

### Pattern 5: Cross-Cutting Concerns as Explicit Call Sites, Not Middleware

**What:** Because there is no router/dispatcher (each entry point is a flat script with `if ($action === '...')` blocks), "plugging in" audit logging cannot be done as global middleware. Instead, `audit_log(string $event, array $context)` calls are added explicitly at the end of each mutating action handler (`create_booking`, `cancel_booking`, `create_series`, `cancel_series`, `user_create/update/delete`, `court_create/update/delete`, login success/failure) — right next to the existing `flash(...)` call, following the same "one line at the point of the state change" convention already used for flash messages.
**When to use:** After `auth.php` extraction (audit entries need `current_user()`/`current_admin()` to record the actor) and after `db.php` is stable (audit writes go through the same `pdo()`).
**Trade-offs:** More call sites to add than a single middleware hook would need, but this matches the codebase's existing "no framework, no implicit magic" convention (CONVENTIONS.md) and avoids introducing a dispatcher just to get one hook point.

## Data Flow

Unchanged from the current architecture (see `.planning/codebase/ARCHITECTURE.md`) with two additions:

```
[Browser request]
    ↓
index.php / admin.php  (unchanged: parse $_GET['action'], route)
    ↓
require_once includes/bootstrap.php   ← NEW single seam (was: inline session_start + duplicated helpers)
    ├─ config.php   → loads .env / environment → returns config array
    ├─ session.php  → session_set_cookie_params() (NEW: HttpOnly/Secure/SameSite) → session_start()
    ├─ db.php       → pdo() singleton (unchanged behavior, now depends on config())
    ├─ security.php → h(), csrf_token(), csrf_check() (unchanged behavior)
    ├─ auth.php     → user_is_logged_in()/admin_is_logged_in()/current_user()/current_admin() (renamed, behavior preserved per realm)
    └─ flash.php    → flash(), render_flash() (unchanged behavior)
    ↓
[existing POST action handler logic — unchanged]
    ↓
audit_log('booking_created', [...])   ← NEW call site, alongside existing flash(...) call
    ↓
pdo()->prepare(...)->execute(...)      ← unchanged, now writes to audit_log table too
    ↓
[redirect + flash message — unchanged]
```

**Password reset flow (new, additive)** plugs in at the auth boundary, reusing `includes/auth.php` and `includes/db.php`, and needs one new decision point flagged for the roadmap: this app currently has **no outbound email integration** (confirmed in INTEGRATIONS.md — no SMTP/mail library, no webhook egress). A self-service "email me a reset link" flow requires either (a) adding a minimal `mail()`-based sender (works on many shared hosts but is unreliable/spam-flagged) or (b) an admin-assisted reset (admin generates a one-time token/link, shares it out-of-band) as a first increment, with real email as a later increment. This is a scope decision, not an architecture one — flag it for the phase that implements password reset rather than resolving it here.

**State management:** Still `$_SESSION` for user/admin state + CSRF, still URL params (`date`, `selected`, `view`, `action`) for navigation, still PostgreSQL as sole source of truth for bookings/users/courts/series. Extraction changes *where the code that reads/writes this state lives*, not the state model itself.

## Scaling Considerations

This is a low-traffic single-club tool (PROJECT.md: "Out of Scope" explicitly excludes multi-tenant/multi-timezone scaling), so "scale" here means **codebase growth and change-safety**, not user load.

| Stage | Codebase state | What to do |
|-------|-----------------|-------------|
| Today | 2 files, ~2,300 lines combined, dedicated helper functions duplicated 1:1 | Baseline — do not add features here until config/helpers are extracted, since every duplicated function is a second place bugs hide |
| After this milestone | `includes/` holds ~6-7 small single-purpose files; entry points shrink by the duplicated-helper line count (roughly 80-100 lines removed from each) but keep all routing/rendering | Safe to resume feature work (password reset, audit logging) on top of a single source of truth per concern |
| If the project grows further (more admin screens, more entry points) | Entry points still handle 5-10+ distinct `action` values each in a flat if/switch | Consider extracting the `switch`/routing table itself into a tiny dispatcher array (`$routes = ['create_booking' => 'handle_create_booking']`) — but only if a *third* entry point or significant new action count appears; not needed for this milestone |

### Scaling Priorities

1. **First bottleneck:** Duplicated helpers causing divergent bug fixes (already happening — `is_logged_in()` already silently means two different things). Fixed by this extraction.
2. **Second bottleneck (later, not this milestone):** If `index.php`'s action `switch`/`if` chain keeps growing (currently ~10 actions), readability suffers before performance does — a lightweight routing array would help, but introducing it now would be premature relative to the stated goal of avoiding a big-bang rewrite.

## Anti-Patterns

### Anti-Pattern 1: Big-Bang Rewrite into an MVC Framework

**What people do:** See "monolithic PHP files" flagged as tech debt and reach for Laravel/Symfony/a custom MVC layer with routing, controllers, and an ORM.
**Why it's wrong:** PROJECT.md explicitly constrains this ("keep changes compatible with the existing PDO/stored-procedure architecture rather than introducing an ORM or framework migration"). A framework migration would also touch every line of `index.php`/`admin.php` at once, the exact "rewrite" risk this milestone is trying to avoid, and would require re-verifying the conflict-detection logic that already works and is unverified by tests.
**Do this instead:** File-by-file extraction into `includes/`, keeping `index.php`/`admin.php` as the only two HTTP entry points, as described above.

### Anti-Pattern 2: Merging `is_logged_in()` Because the Names Match

**What people do:** See identical function names in both files and assume they're identical in behavior, then keep only one copy during extraction.
**Why it's wrong:** As established above, `index.php`'s version checks `$_SESSION['user']` and `admin.php`'s checks `$_SESSION['admin_user']` — merging silently breaks the separation between user and admin login (a security-relevant boundary, not just DRY cleanup).
**Do this instead:** Rename to realm-scoped functions (`user_is_logged_in()`, `admin_is_logged_in()`) during extraction, verify both call sites still resolve to their original session key.

### Anti-Pattern 3: `session_start()` or DB Connections as a Side Effect of `require`-ing a Helper File

**What people do:** Put `session_start()` inside `includes/auth.php` itself, or have `includes/db.php` connect eagerly at file-load time instead of lazily inside the `pdo()` function.
**Why it's wrong:** Breaks testability — PHPUnit test files that `require includes/auth.php` to unit-test a pure function would trigger session/DB side effects just by loading the file, causing "headers already sent" errors or requiring a live database for tests that shouldn't need one.
**Do this instead:** Keep `pdo()`'s existing lazy static-singleton pattern (already correct today) and move `session_start()` into a single explicit call inside `bootstrap.php`/`session.php`, never at the top level of a file that other code might `require` for its functions alone.

### Anti-Pattern 4: Introducing Composer/Autoloading for Production Code in the Same Step as Testing

**What people do:** Add PHPUnit via Composer, then also switch `includes/*.php` to PSR-4 namespaced classes with autoloading, since "Composer is already there now."
**Why it's wrong:** Two unrelated changes (dependency-free `require_once` includes → namespaced autoloaded classes) bundled into one migration multiplies the risk of the "no big-bang rewrite" goal, and the app's deployment model (copy `.php` files to shared Apache/mod_php hosting, no build step) doesn't need autoloading to benefit from tests.
**Do this instead:** Keep `composer.json` scoped to `require-dev: phpunit/phpunit`, `vendor/` gitignored and not deployed; production `includes/*.php` stay plain `require_once` files with global function names, matching the existing convention (CONVENTIONS.md confirms snake_case global functions, no namespaces anywhere today).

### Anti-Pattern 5: Storing New Secrets (Mail credentials, Reset Token Signing Key) Back in a Committed PHP File

**What people do:** Add a password-reset feature and put its new SMTP credentials or token secret directly into `conf.php` or a new PHP constants file, repeating the exact mistake this milestone is fixing.
**Why it's wrong:** PROJECT.md flags the original `conf.php` plaintext-credential incident as the top-priority issue being addressed this milestone; adding a second secret the same way regresses the fix.
**Do this instead:** Any new secret (mail transport credentials, token signing key) goes through the same `includes/config.php` / `.env` mechanism as the DB credentials.

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| PostgreSQL (existing) | `pdo()` singleton in `includes/db.php`, DSN from `includes/config.php` | No change to schema-side behavior; add `audit_log` and `password_reset_token` tables to `pg.sql` |
| Outbound email (new, for password reset) | Not yet integrated anywhere in the codebase (INTEGRATIONS.md confirms zero email/webhook egress today) | Roadmap should treat "how does the user receive a reset link" as an open decision (see Data Flow section) rather than assume PHP `mail()` will reliably work on the target host |
| PHPUnit (new, dev-only) | `composer.json` with `require-dev` only; `tests/bootstrap.php` requires `includes/config.php` pointed at a `TEST_DATABASE_DSN` env var, mirroring the pattern TESTING.md already proposes | Production deployment (copy `.php` files) is unaffected; `vendor/` and `composer.json` are dev-only artifacts |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| `index.php`/`admin.php` ↔ `includes/*.php` | Direct `require_once` + global function calls (no namespaces, no DI container) | Matches existing convention; each `includes/*.php` file should have zero side effects at require-time except `bootstrap.php`/`session.php` (which explicitly own `session_start()`) |
| `includes/auth.php` ↔ `includes/audit.php` | `audit.php`'s functions call `current_user()`/`current_admin()` from `auth.php` to record the actor | Requires `auth.php` extraction to land before `audit.php` is added, or audit entries can't attribute actions to a user |
| `includes/config.php` ↔ everything else | Every other `includes/*.php` file reads from `config()` rather than raw globals or hardcoded values | This is the dependency root — must be extracted first (see Suggested Build Order) |
| `tests/` ↔ `includes/*.php` | `tests/bootstrap.php` requires the same files production uses, pointed at a test DSN via `config()` | Only possible once helpers are extracted (today, testing a helper means loading the entire 1,552-line `index.php`, which is impractical) |

## Suggested Build Order

This is the dependency-driven order for the extraction + new cross-cutting work; later roadmap phases should not get ahead of earlier ones since each depends on the previous existing:

1. **`includes/config.php`** (env/config loading) — foundation. Nothing else should be built before this exists, since DB credentials, test DSNs, and session-security flags (HTTPS detection) all read from it. Directly addresses the Active requirement to rotate/secure DB credentials.
2. **`includes/db.php`** (pdo() singleton) — depends on (1). Pure extraction, byte-identical behavior, lowest risk, immediate deduplication win.
3. **`includes/security.php`** (`h()`, `csrf_token()`, `csrf_check()`) and **`includes/flash.php`** (`flash()`, `render_flash()`) — no dependency on (1)/(2) beyond `$_SESSION` already being active; can be done in parallel with (2). Pure extractions, byte-identical behavior.
4. **`includes/session.php`** (cookie hardening + `session_start()`) — depends on (1) for the HTTPS/environment flag. Directly addresses the Active requirement for session cookie security flags and lays groundwork for session timeout.
5. **`includes/auth.php`** (realm-scoped login/session helpers) — depends on (2) for DB lookups and (4) for session being active. This is the one extraction requiring behavior reconciliation (Pattern 3), not a pure copy.
6. **`includes/bootstrap.php`** — depends on (1)-(5) all existing; assembles them into the single require both entry points use. Once this lands, `index.php`/`admin.php` no longer contain any duplicated helper code.
7. **`tests/` + PHPUnit (dev-only Composer)** — depends on (1)-(6): needs `config()` to support a test DSN, and needs helpers isolated in `includes/` to be requirable without pulling in full page rendering. This is also the point where the flagged-as-truncated `materialize_series()` should get regression coverage, and where booking-conflict-detection tests become feasible.
8. **`includes/audit.php` + `audit_log` table** — depends on (2) and (5) (needs `pdo()` and `current_user()`/`current_admin()` to attribute events). Call sites added at each existing mutating action, alongside existing `flash()` calls.
9. **Password reset / change-password** — depends on (5) auth.php existing (reuses login/session primitives) and on a resolved decision about the email-delivery mechanism (see Data Flow). Should come last among the cross-cutting items since it's the only one with an open external-integration question rather than a pure internal-architecture one.

## Sources

- Direct reading of `index.php`, `admin.php`, `conf.php` (this repository) — HIGH confidence, primary source
- `.planning/codebase/ARCHITECTURE.md`, `STRUCTURE.md`, `CONVENTIONS.md`, `CONCERNS.md`, `TESTING.md`, `INTEGRATIONS.md` (GSD mapper output, same analysis date) — HIGH confidence
- `.planning/PROJECT.md` (Active requirements, constraints, Key Decisions) — HIGH confidence
- General PHP legacy-refactor practice ("strangler fig" incremental extraction, avoiding autoload/namespace changes bundled with test-harness introduction) — MEDIUM confidence, cross-checked via web search, consistent with multiple sources
- Minimal `.env` loading without Composer (custom parser into `$_ENV`, avoiding `putenv()` due to thread-safety concerns) — MEDIUM confidence, cross-checked via web search: [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv), [sabroan/php-dotenv](https://github.com/sabroan/php-dotenv)
- PHPUnit testing of non-namespaced legacy PHP code — MEDIUM confidence, cross-checked via web search: [Testing legacy code can be hard (tsh.io)](https://tsh.io/blog/testing-legacy-code), [PHPUnit legacy adapter](https://github.com/sanmai/phpunit-legacy-adapter)

---
*Architecture research for: PHP/PostgreSQL court-booking monolith hardening (helper extraction + cross-cutting concerns)*
*Researched: 2026-07-27*
