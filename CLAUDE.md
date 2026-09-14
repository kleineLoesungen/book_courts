## Project

**Book Courts**

A PHP/PostgreSQL web app for booking sports courts (e.g. a club's tennis/badminton courts). Members see a week/day view of court availability and create single bookings within a 30-minute grid; admins create recurring booking series, manage users and courts, and cancel bookings. It is a working monolith that is being hardened, not rebuilt. See `README.md` for features, setup and deployment.

**Core Value:** Members can reliably book a court for a specific time without double-booking conflicts, and admins can manage the full roster of bookings, series, users, and courts.

### Constraints

- **Tech stack**: PHP + PostgreSQL, no framework — keep changes compatible with the existing PDO/stored-procedure architecture rather than introducing an ORM or framework migration
- **No package manager**: Composer is not used; introducing dependencies needs a deliberate, minimal setup. Third-party browser libraries are vendored into `assets/` with the version in the filename
- **Single deployment target**: Hetzner Webhosting, Apache + PHP via `.htaccess`, deployed over FTPS with `deploy.sh`
- **Shared production database**: one database and one database user, shared with other applications' schemas. Never run destructive SQL against production; `pg.sql` must stay idempotent and must only touch its own schema
- **Test locally**: develop and test against a local database (e.g. `book_courts_test`), never against production

## Technology Stack

- PHP 8.0+ with `declare(strict_types=1)` in every file (`str_contains()` requires 8.0)
- PostgreSQL 13+ (`gen_random_uuid()` from core, `TSTZRANGE` + GiST for overlap detection, PL/pgSQL functions and a trigger)
- Tailwind CSS 3.4.16 (Play CDN build) and Lucide 0.469.0, both served locally from `assets/`
- Vanilla JavaScript inline in `index.php`/`admin.php` for AJAX and form handling
- Timezone is fixed to `Europe/Berlin` in PHP and SQL

## Structure

| File | Responsibility |
|---|---|
| `config.php` | Shared bootstrap for both entry points: `.env` parser, `pdo()`, `start_secure_session()` / `destroy_session()`, login rate limiting, `prune_old_if_due()` |
| `index.php` | Member UI: week/day view, bookings, series (admin only), AJAX endpoints `free_courts` and `series_preview` |
| `admin.php` | Admin UI: overview, users, courts; first-admin bootstrap when no user exists |
| `pg.sql` | Idempotent schema install: tables, enums, `prevent_overlap` trigger, `create_booking()`, `prune_old()`, `free_courts()` |
| `.htaccess` | HTTPS redirect, HSTS, security headers, access denial for secrets and dot-paths, asset caching |
| `deploy.sh` | `lftp mirror --reverse` of exactly `index.php`, `admin.php`, `config.php`, `.htaccess`, `.env`, `assets/` |

Both entry points start with:

```php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

start_secure_session();
```

Each entry point still defines its own `h()`, `csrf_token()`, `csrf_check()`, `flash()`, `render_flash()` and `is_logged_in()`. The `is_logged_in()` variants are intentionally different: `index.php` checks `$_SESSION['user']`, `admin.php` checks `$_SESSION['admin_user']`.

## Configuration

- `.env` (untracked, `chmod 600`) provides `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SCHEMA`; template in `.env.example`
- `parse_env_file()` takes values literally (everything after the first `=`). Do not switch back to `parse_ini_file()` — it truncates values at `;`/`#` and turns `off`/`no`/`none` into empty strings
- `DB_SCHEMA` is applied via `SET search_path` in `pdo()` (validated against `^[A-Za-z_][A-Za-z0-9_]*$`). SQL in PHP is **not** schema-qualified; stored functions pin their own `search_path`
- `.env.deploy` (untracked) holds FTP credentials; it is loaded with bash `source`, so comments use `#`
- `deploy.sh` uploads the local `.env`: it must contain production values when deploying

## Conventions

### Naming
- PHP: `snake_case` for functions and variables; helper verbs `render_*`, `format_*`, `is_*`
- Short names in loops/single use: `$u` user, `$b` booking, `$s` series, `$c` court, `$st` statement, `$m` match
- Flash arrays use short keys `'t'` (type) and `'m'` (message)
- JavaScript: `camelCase`; widget code lives in IIFEs, module-level state uses an underscore prefix (`_pendingCancelForm`)

### Formatting
- 4-space indentation for new code (`index.php` still mixes 2 and 4 spaces)
- Opening braces on the same line
- Double quotes with `{$var}` for interpolation, single quotes for static strings
- Section headers as `/* === ... === */`; inline comments in German for business logic; comments explain *why*

### Date & time
- ISO 8601 (`'c'`) towards the database, `d.m.Y` and `H:i` for display
- 30-minute grid enforced in PHP (`% 30`), in `create_booking()` and by the `booking_grid_chk` constraint

## Security Patterns

- **CSRF**: token in `$_SESSION['csrf']`, hidden field `csrf` in every form, `csrf_check()` on every POST
- **SQL**: prepared statements everywhere; identifiers (schema name) are validated, never taken from requests
- **XSS**: `h()` (`htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE`) on all output
- **Passwords**: `password_hash(..., PASSWORD_DEFAULT)` / `password_verify()`
- **Authorization**: check roles in the POST handler itself (`is_admin()`, owner comparison) — hiding a form is not a check
- **Sessions**: cookie `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS; `session_regenerate_id(true)` after login; 8h idle timeout
- **Login**: identical message for every failure ("E-Mail oder Passwort falsch."), `dummy_password_verify()` for unknown accounts, lockout via `login_attempt` (5 per account / 20 per IP in 15 minutes)
- **Errors**: never render raw PDO messages; log them with `error_log()` and translate known SQLSTATEs (`23503`, `23505`) into user-facing text
- **Privacy**: the public calendar shows initials only (`anonymize_name()`); full names require login

## Database

- Overlap protection has three layers: per-court `pg_advisory_xact_lock`, the conflict check in `create_booking()`, and the `trg_booking_no_overlap` trigger
- Conflict errors from `create_booking()` are parsed in `index.php` to show the court name and local time — keep the message format `Konflikt: Zeitraum <start> – <end> auf Platz <uuid> belegt` in sync
- `prune_old(14)` deletes bookings/series that ended more than 14 days ago; it runs at most once per day via the `maintenance` table (`prune_old_if_due()`), triggered by booking requests
- `recurring_exception` is read by `materialize_series()` but has no UI that writes to it yet
- Schema changes go into `pg.sql` as `CREATE ... IF NOT EXISTS` (enum types via guarded `DO` block) so the script can be re-run on existing installations
