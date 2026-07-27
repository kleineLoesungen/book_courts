# Architecture

**Analysis Date:** 2026-07-27

## Pattern Overview

**Overall:** Monolithic PHP application with layered architecture and direct UI-to-database communication

**Key Characteristics:**
- Monolithic codebase with two entry points (user and admin interfaces)
- Mixed presentation and application logic in PHP files
- Direct PDO database interaction without ORM
- Session-based authentication with separate user/admin flows
- Server-side rendering with Tailwind CSS
- PostgreSQL database with stored functions for complex operations

## Layers

**Presentation Layer:**
- Purpose: Render HTML/Tailwind UI and handle user interactions
- Location: `index.php` (lines 710-968), `admin.php` (lines 88-732)
- Contains: HTML rendering functions, form generation, dialog markup
- Depends on: Application logic layer, session management
- Used by: Web browser clients

**Application/Routing Layer:**
- Purpose: Handle HTTP requests, parse parameters, route to operations, manage business logic
- Location: `index.php` (lines 198-644), `admin.php` (lines 141-349)
- Contains: Login/logout handlers, POST action routing, CSRF validation, data loading
- Depends on: Database layer, presentation layer
- Used by: Entry points for all user interactions

**API/Mini-Endpoints Layer:**
- Purpose: Provide JSON responses for dynamic UI (AJAX calls)
- Location: `index.php` (lines 245-301, 306-401)
- Contains: `free_courts` endpoint (availability lookup), `series_preview` endpoint (series conflict analysis)
- Depends on: Database layer via PDO
- Used by: Client-side JavaScript for real-time availability

**Data Access Layer:**
- Purpose: Execute database queries and manage database connections
- Location: `index.php` (lines 19-30), `admin.php` (lines 19-30)
- Contains: PDO singleton factory function, prepared statement execution
- Depends on: Configuration, PostgreSQL database
- Used by: All application logic that needs data persistence

**Database Layer:**
- Purpose: Store and manage data, enforce constraints, perform complex queries
- Location: `pg.sql`
- Contains: Schema definition, tables, indexes, triggers, stored functions
- Depends on: PostgreSQL 
- Used by: Data access layer via PDO

## Data Flow

**User Booking Flow:**

1. User navigates to `index.php` → Session checked → Week view rendered
2. User clicks day → Selected date parameter set → Day agenda loaded and displayed
3. User fills "Neue Buchung" form → POST with action `create_booking`
4. Application validates times (30-min grid), checks user is logged in
5. AJAX call to `?action=free_courts` → Returns available courts for selected time
6. User selects court → Form submits
7. Application calls `create_booking()` stored function
8. Database function validates no overlap, inserts booking, returns result
9. Redirect to day view with flash message
10. User sees updated day agenda with their booking

**Series Creation Flow:**

1. Admin opens "Neue Serie" form
2. Selects weekday, time, duration, date range
3. Form triggers AJAX to `?action=series_preview` → Returns conflict analysis per court
4. Admin selects court
5. Form submits with action `create_series`
6. Application validates conflict-free availability across entire date range
7. Inserts `recurring_series` record
8. Calls `materialize_series()` function (PHP, lines 1134-1553) to expand series into individual bookings
9. Redirect with flash message

**Admin Operations Flow:**

1. Admin logs in at `admin.php?action=login` → Separate session token
2. Accesses overview → `admin.php?action=home` → Loads active bookings and series
3. Can cancel individual bookings or entire series via POST actions
4. Can manage users/courts via CRUD forms
5. All changes redirect back to same page with flash feedback

**State Management:**
- Session: PHP `$_SESSION` for user/admin state and CSRF tokens
- URL Parameters: `date`, `selected`, `view`, `action` for navigation state
- Database: Single source of truth for bookings, users, courts, series

## Key Abstractions

**CSRF Protection:**
- Purpose: Prevent cross-site request forgery attacks
- Examples: `csrf_token()` (line 31-37), `csrf_check()` (line 38-47) in both files
- Pattern: Generate token in session, embed in form, validate on POST

**Authentication Helpers:**
- Purpose: Check user login status and permissions
- Examples: `is_logged_in()` (line 52-55), `is_admin()` (line 60-63), `current_user()` (line 56-59)
- Pattern: Check session variables, return boolean or user data

**HTML Escaping:**
- Purpose: Prevent XSS vulnerabilities
- Examples: `h()` function (line 48-51 in index.php, line 31-34 in admin.php)
- Pattern: Apply `htmlspecialchars()` with ENT_QUOTES to all user output

**Time/Duration Rendering:**
- Purpose: Generate UI elements for time selection
- Examples: `render_time_select()` (line 104-149), `render_duration_input()` (line 152-193), `format_duration_short()` (line 84-93)
- Pattern: Loop through time/duration options, render HTML with selected state

**Flash Messaging:**
- Purpose: Display user feedback across redirects
- Examples: `flash()` (line 64-67), `render_flash()` (line 68-77)
- Pattern: Store message in `$_SESSION['flash']`, render and clear on next page load

## Entry Points

**Public Interface (`index.php`):**
- Location: `index.php` (lines 1-1553)
- Triggers: GET requests to `index.php` with optional `action` and `view` parameters
- Responsibilities: 
  - User login/logout
  - Display week view of bookings with day-level detail
  - Allow authenticated users to create single bookings
  - Allow admins to create recurring series
  - AJAX endpoints for free court availability and series preview
  - Booking cancellation

**Admin Interface (`admin.php`):**
- Location: `admin.php` (lines 1-735)
- Triggers: GET/POST requests to `admin.php` with optional `action` parameter
- Responsibilities:
  - Admin login (separate from user login)
  - Bootstrap first admin creation if no users exist
  - Display all active bookings grouped by month/year (collapsible)
  - Display all recurring series
  - Allow cancellation of individual bookings or entire series
  - User management (CRUD)
  - Court management (CRUD)

**Configuration Entry (`conf.php`):**
- Location: `conf.php` (lines 1-19)
- Used by: Both `index.php` and `admin.php`
- Provides: Database credentials and DSN string

## Error Handling

**Strategy:** Try-catch with specific exception types, user-friendly flash messages

**Patterns:**

Exception Handling in Booking Operations:
```php
// index.php lines 423-644
try {
  if ($op === 'create_booking') {
    // Validation, database call
    pdo()->prepare($sql)->execute([...]);
    flash('Buchung erstellt.');
  }
} catch (PDOException $e) {
  // Parse PostgreSQL error messages for conflict details
  if (preg_match('/Konflikt:.../', $msg)) {
    flash("Specific conflict message", 'error');
  } else {
    flash('Datenbankfehler bei der Buchung...', 'error');
  }
} catch (Exception $e) {
  flash($e->getMessage(), 'error');
}
```

Database Trigger-Based Validation:
- Booking overlap check in `prevent_overlap()` PostgreSQL trigger (pg.sql lines 80-103)
- Constraint checks in `booking_grid_chk` (pg.sql lines 46-52) for 30-minute grid alignment
- Both raise EXCEPTION which becomes PDOException with specific message

API Error Responses (JSON):
```php
// index.php lines 247-301 for free_courts endpoint
if (!$date || !$start || $duration <= 0) {
  echo json_encode(['ok' => false, 'error' => 'missing_params']);
  exit;
}
```

## Cross-Cutting Concerns

**Logging:** Not implemented. No logging framework present.

**Validation:**
- Client-side: HTML5 form attributes (type="email", required, etc.)
- Server-side: PHP validation in POST handlers (lines 427-522 in index.php, lines 265-343 in admin.php)
- Database: Triggers and CHECK constraints enforce 30-minute grid alignment
- Times validated against DateTimeZone('Europe/Berlin') for consistency

**Authentication:**
- Login checks via session variables: `$_SESSION['user']` (users) or `$_SESSION['admin_user']` (admins)
- Permission checks: `is_admin()` for admin-only operations, user_id comparison for ownership
- Separate login flows prevent privilege escalation between user and admin contexts

**Timezone Handling:**
- Hardcoded to 'Europe/Berlin' throughout application
- All dates stored as TIMESTAMPTZ in database
- Conversion applied when displaying to users: `->setTimezone($tz)->format(...)`
- Series expansion respects timezone when calculating occurrences

**Concurrency:**
- Advisory locks in `create_booking()` function (pg.sql line 175-176) serialize per-court operations
- Prevents race conditions when multiple users book same court simultaneously

---

*Architecture analysis: 2026-07-27*
