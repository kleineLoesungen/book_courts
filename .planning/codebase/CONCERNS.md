# Codebase Concerns

**Analysis Date:** 2026-07-27

## Tech Debt

**Monolithic PHP Files:**
- Issue: Business logic, routing, database access, and presentation all mixed in single files (`index.php` 1552 lines, `admin.php` 734 lines)
- Files: `index.php`, `admin.php`
- Impact: Difficult to test, maintain, and extend. No separation of concerns. Code duplication (e.g., `pdo()`, `csrf_token()`, `h()` duplicated in both files)
- Fix approach: Refactor into layers - create separate files for PDO singleton, helpers, routing, API endpoints, business logic

**Duplicated Helper Functions:**
- Issue: `pdo()`, `csrf_token()`, `h()`, `flash()`, `render_flash()`, `is_logged_in()` are duplicated between `index.php` and `admin.php`
- Files: `index.php` (lines 19-76), `admin.php` (lines 19-73)
- Impact: Maintenance nightmare - bug fixes must be applied in multiple places
- Fix approach: Extract to shared `includes/helpers.php` or `includes/auth.php`

**Incomplete Code Visible:**
- Issue: `materialize_series()` function in `index.php` (line 1134) is truncated and incomplete in the codebase
- Files: `index.php` (line 1134+)
- Impact: Cannot verify correctness of series booking materialization logic
- Fix approach: Ensure full function implementation is present; add unit tests

**Unused Database Table:**
- Issue: `recurring_exception` table created in schema (`pg.sql` line 71-77) but never used in PHP code
- Files: `pg.sql`, no corresponding usage in `index.php` or `admin.php`
- Impact: Dead schema; adds confusion about feature completeness
- Fix approach: Either implement exception handling UI or remove table

## Security Concerns

**CRITICAL: Hardcoded Database Credentials:**
- Risk: `conf.php` file contains database connection credentials in plain text
- Files: `conf.php`
- Current mitigation: None detected - file is included in codebase
- Recommendations: 
  1. Move `conf.php` to `.gitignore` immediately
  2. Use environment variables (via `$_ENV` or `.env` file with php-dotenv)
  3. Never commit credentials to version control
  4. Rotate credentials on remote database immediately

**Session Security:**
- Risk: Session cookies not configured with security flags (HttpOnly, Secure, SameSite)
- Files: `index.php` (line 12), `admin.php` (line 12)
- Current mitigation: None - standard `session_start()` used
- Recommendations:
  - Add before `session_start()`:
    ```php
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
        'samesite' => 'Strict'
    ]);
    ```

**No Brute Force Protection:**
- Risk: Login endpoints allow unlimited password attempts
- Files: `index.php` (line 199-235), `admin.php` (line 192-227)
- Current mitigation: None
- Recommendations: Implement rate limiting using file-based or Redis counter for failed attempts

**CSRF Token Not Regenerated:**
- Risk: CSRF token stored in session and reused for entire session lifetime; no per-request regeneration
- Files: `index.php` (line 31-46), `admin.php` (line 35-48)
- Current mitigation: Token is checked against POST data
- Recommendations: Regenerate token after successful POST with `hash_equals()` (already used, but token should be refreshed)

**No Password Strength Requirements:**
- Risk: Users/admins can set weak passwords
- Files: `admin.php` (line 161, 274, 299)
- Current mitigation: Only `password_hash()` used
- Recommendations: Add validation: minimum 12 chars, uppercase, lowercase, digit, special char

**Potential SQL Error Leakage:**
- Risk: Database exceptions may leak schema information or query structure in error messages
- Files: `index.php` (line 605-643), `admin.php` (line 344-348)
- Current mitigation: Some exceptions caught and re-thrown with generic message
- Recommendations: Log full exception server-side; return generic message to client in production

**No Input Validation on AJAX Endpoints:**
- Risk: `free_courts` and `series_preview` AJAX endpoints validate times but don't sanitize all inputs
- Files: `index.php` (line 245-301, line 306-401)
- Current mitigation: Some DateTime validation, but incomplete
- Recommendations: Add explicit validation for all inputs (date format, time format, weekday range)

## Known Bugs

**Hardcoded Timezone:**
- Symptoms: All times are locked to Europe/Berlin timezone; deployment in other regions will have confusing UX
- Files: `index.php` (line 406, 435, 513, 626, etc.), `admin.php` (line 354)
- Trigger: Any date/time operation uses hardcoded timezone
- Workaround: None - requires database redesign

**Series Materialization Scalability:**
- Symptoms: Long-running series (e.g., 5 years weekly) will create thousands of individual bookings immediately, causing slow response times on `create_series` action
- Files: `index.php` (line 599, 1134+)
- Trigger: Creating recurring series with long duration
- Workaround: Limit UI to series end_date within 6 months (line 599 already does this, but admin can override)

**N+1 Query Problem in Admin:**
- Symptoms: Slow page load when many users/courts exist
- Files: `admin.php` (line 494-501)
  ```php
  foreach ($series as $s) {
    // Courts and users loaded per iteration
    $courtName = $cmap[$s['court_id']] ?? 'Platz';
  }
  ```
- Trigger: Viewing admin home/series with 100+ series
- Workaround: Queries are cached in loops, not repeated, so impact is moderate but not critical

**Missing .htaccess Rules:**
- Symptoms: Users can access `.env`, `conf.php`, or other sensitive files via HTTP if web server misconfigured
- Files: `.htaccess` exists but is minimal
- Trigger: Web server misconfiguration
- Workaround: Verify web server configuration limits access to `*.php` files outside public directory

## Performance Bottlenecks

**Series Preview Query Complexity:**
- Problem: `series_preview` endpoint uses complex CTEs with recursive date generation for each court
- Files: `index.php` (line 333-383)
- Cause: Query generates all occurrences for date range across 7+ CTE layers
- Improvement path: 
  1. Cache results in application (Redis/Memcached)
  2. Limit date range in UI to 3 months instead of arbitrary range
  3. Consider moving logic to application layer instead of SQL

**Booking Conflict Check Run Twice:**
- Problem: Conflict check run once in PHP validation, then again in database trigger
- Files: `index.php` (line 530-577, then DB trigger in `pg.sql`), `admin.php` (implicit DB validation)
- Cause: Redundant safety check
- Improvement path: Remove PHP check or make it advisory-only (just alert user without blocking)

**Series Materialization Blocks Request:**
- Problem: `materialize_series()` creates all booking records synchronously, blocking response
- Files: `index.php` (line 599)
- Cause: Business logic runs inline
- Improvement path: Move to async queue (PHP-RQ, Gearman) or background cron job

## Fragile Areas

**Authentication Logic:**
- Files: `index.php` (line 199-235), `admin.php` (line 192-227)
- Why fragile: Duplicated in two places; password verification uses `password_verify()` correctly but no retry limits; session not initialized with security flags
- Safe modification: Create `includes/auth.php` with shared `loginUser()` and `requireLogin()` functions
- Test coverage: No tests detected

**AJAX Endpoints (free_courts, series_preview):**
- Files: `index.php` (line 245-401)
- Why fragile: No formal parameter validation contract; relies on regex for date format (line 409-410); error handling swallows exceptions (line 297-299)
- Safe modification: Create input validation class before touching these
- Test coverage: No tests detected

**Series Conflict Validation:**
- Files: `index.php` (line 530-577)
- Why fragile: Complex SQL validation logic duplicated between conflict-check query and actual insertion; timezone handling in SQL matches PHP but different code paths
- Safe modification: Extract to database function or dedicated validation service
- Test coverage: No tests detected

**Session Flash Messages:**
- Files: `index.php` (line 64-76), `admin.php` (line 61-73)
- Why fragile: Messages persist in `$_SESSION['flash']` and cleared on display; if rendering fails, message is lost
- Safe modification: Store flash in separate session variable, clear only on successful render
- Test coverage: No tests detected

## Scaling Limits

**Single-File Architecture:**
- Current capacity: Functional up to 100s of bookings and 1-2 concurrent users
- Limit: Beyond that, file structure becomes unmaintainable and debugging is difficult
- Scaling path: Split into modular structure (MVC or DDD architecture)

**Series Materialization:**
- Current capacity: Series up to ~260 occurrences (5 years weekly)
- Limit: Creating 1000+ booking records will cause timeout
- Scaling path: Move to lazy evaluation with stored procedures or application-level caching

**Session Storage:**
- Current capacity: Default PHP session file storage works for <1000 active sessions
- Limit: Concurrent users beyond that; file I/O becomes bottleneck
- Scaling path: Move to Redis or database-backed sessions

**Database Indexes:**
- Current indexes: `booking_time_idx` (GIST on tstzrange), `app_user_email_ci_unique`
- Missing: `booking_court_id`, `booking_status`, `recurring_series_user_id`
- Limit: Queries filtering by court_id or status will full-table scan
- Scaling path: Add indexes for common filter conditions

## Dependencies at Risk

**External CDN Dependencies (Tailwind CSS, Lucide Icons):**
- Risk: Application is unusable if CDN is down (no fallback CSS/icons)
- Files: `index.php` (line 1037-1039), `admin.php` (line 99-100)
- Impact: Complete UI failure without internet access to CDNs
- Migration plan: 
  1. Download Tailwind CSS and self-host
  2. Switch Lucide Icons to inline SVGs or self-hosted icon set
  3. Test with network disabled

**PDO PostgreSQL Extension:**
- Risk: Code assumes PostgreSQL; MySQL commented out (line 17) but not tested
- Impact: Cannot easily switch databases
- Migration plan: Abstract database layer if multi-database support needed

## Missing Critical Features

**No Logging or Audit Trail:**
- Problem: No record of who booked what, when admins modified data, failed login attempts
- Blocks: Compliance, debugging, user support (can't track who canceled booking)

**No Email Notifications:**
- Problem: Users don't receive booking confirmations or series updates
- Blocks: Users need to manually check website to verify bookings

**No Password Reset for Users:**
- Problem: Users who forget password have no self-service recovery; must contact admin
- Blocks: User experience degradation

**No Change Password Feature:**
- Problem: Users cannot change their own password; only admin can reset
- Blocks: Security best practice (least privilege)

**No Session Timeout:**
- Problem: Sessions persist indefinitely
- Blocks: Unattended client sessions expose booking data

**No Recurring Exception Handling UI:**
- Problem: Schema supports skipping individual occurrences of series (`recurring_exception` table), but no UI to use it
- Blocks: Cannot skip single occurrence without canceling entire series

**No Timezone Selection:**
- Problem: Hardcoded to Europe/Berlin; users in other zones see wrong times
- Blocks: International deployment

## Test Coverage Gaps

**No Test Files Detected:**
- What's not tested: All core business logic (booking creation, series materialization, conflict detection)
- Files: Entire codebase
- Risk: Booking conflicts could occur silently; series creation could corrupt data
- Priority: High - booking system is mission-critical

**Booking Conflict Detection:**
- What's not tested: Edge cases like overlapping time spans, timezone boundaries, daylight saving time transitions
- Files: `index.php` (line 530-577), `pg.sql` (line 80-103)
- Risk: Conflicts might be missed due to timezone/DST bugs
- Priority: High

**Series Materialization:**
- What's not tested: Series with end_date, series spanning leap days, series creation with conflicts
- Files: `index.php` (line 1134+)
- Risk: Data corruption, duplicate bookings, silent failures
- Priority: High

**AJAX Endpoints:**
- What's not tested: Invalid date formats, boundary times (23:30), negative durations, missing parameters
- Files: `index.php` (line 245-401)
- Risk: Client crashes, server returns malformed JSON
- Priority: Medium

**Authentication:**
- What's not tested: Invalid credentials, deactivated users, SQL injection via email field, timing attacks
- Files: `index.php` (line 199-235), `admin.php` (line 192-227)
- Risk: Account takeover via brute force or SQL injection
- Priority: High

**Admin CRUD Operations:**
- What's not tested: Deleting user with active bookings, deleting court with series, updating user role mid-session
- Files: `admin.php` (line 264-343)
- Risk: Data orphaning, inconsistent state
- Priority: Medium

---

*Concerns audit: 2026-07-27*
