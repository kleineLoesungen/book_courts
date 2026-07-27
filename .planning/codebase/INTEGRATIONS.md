# External Integrations

**Analysis Date:** 2026-07-27

## APIs & External Services

**Frontend UI:**
- Tailwind CSS v3 - CSS framework via CDN
  - URL: `https://cdn.tailwindcss.com`
  - Used for: All responsive UI styling and mobile-first design
  
- Lucide Icons - Icon library via CDN
  - URL: `https://unpkg.com/lucide@latest`
  - Used for: Navigation icons, UI indicators, hamburger menus

**Custom JSON APIs:**
- `index.php?action=free_courts` - Returns available courts for a given time slot
  - Response: JSON with court ID and name
  - Request params: `date`, `start`, `duration`
  
- `index.php?action=series_preview` - Shows court availability conflicts for recurring booking series
  - Response: JSON with court details, total occurrences, and conflict times
  - Request params: `weekday`, `start_time`, `duration`, `start_date`, `end_date`

## Data Storage

**Databases:**
- PostgreSQL 12+ (remote)
  - Host: `jpxw.your-database.de`
  - Port: `5432`
  - Database: `frickeee_db1`
  - Schema: `book_courts`
  - Connection: PDO with DSN in `conf.php`
  - Client: PHP PDO PostgreSQL driver (built-in)

**Schema Overview:**
- `app_user` - User accounts with role-based access (user/admin)
- `court` - Tennis/sports courts available for booking
- `booking` - Individual court reservations with timezone-aware scheduling
- `recurring_series` - Recurring booking templates
- `recurring_exception` - Exceptions to recurring series (cancellations)
- Custom Types: `user_role`, `booking_source`, `booking_status`
- Triggers: `prevent_overlap()` - Ensures no double-booking on same court
- Stored Procedures:
  - `create_booking()` - Atomic booking creation with conflict checking
  - `prune_old()` - Cleanup of old bookings and series (default: 14 days)
  - `free_courts()` - Query available courts in time range

**File Storage:**
- Local filesystem only - No cloud storage integration detected
- Session storage: PHP default (filesystem-based)

**Caching:**
- None detected - All queries direct to PostgreSQL
- No Redis, Memcached, or other caching layer

## Authentication & Identity

**Auth Provider:**
- Custom session-based authentication
  - Implementation: PHP `$_SESSION` with email/password validation
  - Password storage: `password_hash()` with PASSWORD_DEFAULT (bcrypt)
  - Password verification: `password_verify()` for login validation

**Roles:**
- `admin` - Full system access (view all bookings, manage users/courts)
- `user` - Standard booking permissions (30 or 60 min slots only)
- Role-based access control (RBAC) via `current_user()['role']` checks

**Session Management:**
- Session tokens stored in `$_SESSION['user']` containing: `id`, `email`, `name`, `role`
- Admin sessions in `$_SESSION['admin_user']`
- CSRF tokens: `$_SESSION['csrf']` - random 32-byte hex strings

## Monitoring & Observability

**Error Tracking:**
- None detected - No Sentry, Rollbar, or similar
- Errors logged to PHP error log via exceptions

**Logs:**
- PHP error log (server default)
- No application-level logging framework
- Database operations via PDO exception handling

## CI/CD & Deployment

**Hosting:**
- Apache web server (direct file serving)
- No cloud platform detection (AWS, Heroku, etc.)
- Assumes traditional shared hosting or VPS

**CI Pipeline:**
- None detected - No GitHub Actions, GitLab CI, or similar

**Build/Deployment:**
- Direct file upload to web server
- `.htaccess` restricts access to `conf.php` and `pg.sql`

## Environment Configuration

**Required env vars:**
- Database credentials stored in `conf.php` (PHP globals, not environment variables)
- `$DB_HOST`, `$DB_PORT`, `$DB_NAME`, `$DB_USER`, `$DB_PASS`

**Secrets location:**
- `conf.php` - Contains database credentials (blocked by .htaccess from web access)
- File blocked from direct HTTP access via `FilesMatch` in `.htaccess`

**Credentials Management:**
- CRITICAL: Credentials are in plaintext in `conf.php` and NOT version controlled
- No env var support - Must edit PHP file directly
- Database password visible in repository snapshot

## Webhooks & Callbacks

**Incoming:**
- None detected

**Outgoing:**
- None detected

**AJAX Interactions:**
- Free court availability check triggers `action=free_courts` endpoint
- Series preview conflicts load via `action=series_preview` endpoint
- Both return JSON responses for client-side rendering

## Database-Specific Features

**PostgreSQL Extensions:**
- `pgcrypto` - Required for UUID generation (`gen_random_uuid()`)

**Advanced Features:**
- Range type (`tstzrange`) for booking time spans
- GiST indexes for efficient range queries
- Advisory locks for transaction-level court locking (`pg_advisory_xact_lock()`)
- Trigger-based overlap prevention at database layer
- Stored procedure atomicity for booking creation

---

*Integration audit: 2026-07-27*
