## Project

**Book Courts**

A PHP/PostgreSQL web app for booking sports courts (e.g. a club's tennis/badminton courts). Members log in to see a week/day view of court availability, create single bookings within a 30-minute grid, and admins can create recurring booking series, manage users and courts, and cancel bookings. The app already runs — it's an existing, working monolith (`index.php`, `admin.php`, `pg.sql`) that needs hardening rather than a from-scratch build.

**Core Value:** Members can reliably book a court for a specific time without double-booking conflicts, and admins can manage the full roster of bookings, series, users, and courts.

### Constraints

- **Tech stack**: PHP + PostgreSQL, no framework — keep changes compatible with the existing PDO/stored-procedure architecture rather than introducing an ORM or framework migration
- **No package manager**: Composer is not currently used; introducing dependencies (e.g. a test framework) needs a deliberate, minimal setup
- **Single deployment target**: Apache + mod_php via `.htaccess`, no containerization currently in place

## Technology Stack

## Languages
- PHP 8.0+ - All backend logic and server-side rendering. Uses strict type declarations (`declare(strict_types=1)`) throughout.
- JavaScript (Vanilla) - Client-side interactivity for AJAX requests and form handling
- SQL (PL/pgSQL) - Database stored procedures and triggers
## Runtime
- PHP CLI/CGI (built-in PHP web server or Apache mod_php)
- Requires PHP 7.4+ minimum (uses match expressions, named arguments, type hints)
- Not applicable - No external package dependency management (Composer not detected)
- All code is bundled directly in PHP files
## Frameworks
- Custom PHP MVC-inspired routing - All endpoints handled in `index.php` and `admin.php` with action-based routing via `$_GET['action']`
- Tailwind CSS 3.x - Loaded via CDN (`https://cdn.tailwindcss.com`)
- Lucide Icons - Icon library loaded via CDN (`https://unpkg.com/lucide@latest`)
## Key Dependencies
- PostgreSQL 12+ - Primary database with custom schema, stored procedures, and complex range-based queries
- PHP PDO PostgreSQL Driver - Built-in PDO extension with PostgreSQL support
- Apache 2.4+ - Web server with `.htaccess` configuration for URL rewriting and file access control
## Configuration
- Connection details stored in `conf.php` (NOT version controlled - contains database credentials)
- Configuration approach: Global PHP variables loaded at bootstrap
- Required settings:
- No build process detected
- Direct file serving via Apache
- `.htaccess` handles DirectoryIndex and access restrictions
## Platform Requirements
- PHP 8.0+ with PDO PostgreSQL support
- PostgreSQL 12+ with pgcrypto extension
- Web server (Apache with mod_rewrite or PHP built-in server)
- Browser with ES6 support for inline JavaScript
- Apache 2.4+ with mod_php or PHP-FPM
- PostgreSQL 12+ with pgcrypto extension (`CREATE EXTENSION IF NOT EXISTS pgcrypto`)
- TLS/SSL certificate for HTTPS (recommended)
- Session storage (default: filesystem via PHP)
- Application hardcoded to Europe/Berlin timezone in DateTime handling
- Database schema expects Europe/Berlin timezone for timestamp conversions

## Conventions

## Language & Type Safety
- All PHP files start with `declare(strict_types=1);` and `session_start();`
- Strict types are enforced at file level: `declare(strict_types=1);` (see `index.php` line 11, `admin.php` line 11)
- All function parameters require type hints
- All function return types must be declared
## Naming Patterns
- Use `snake_case` exclusively
- Examples: `pdo()`, `csrf_token()`, `csrf_check()`, `is_logged_in()`, `current_user()`, `is_admin()`, `flash()`, `render_flash()`, `anonymize_name()`, `format_duration_short()`, `redirect_to_day()`, `render_time_select()`
- Helper functions start with descriptive verbs: `render_*`, `format_*`, `is_*`, `has_*`
- See `index.php` lines 19-102 for helper function examples
- Use `snake_case` for all variables
- Examples: `$csrf`, `$user_id`, `$court_id`, `$start_time`, `$booking_id`, `$series_id`, `$duration`, `$weekday`, `$startDt`, `$endDt`, `$booking_date`
- Short variable names in loops and single-use contexts: `$u` (user), `$b` (booking), `$s` (series), `$c` (court), `$st` (statement), `$m` (match array), `$f` (flash)
- See `index.php` lines 200-220 for variable naming patterns
- PostgreSQL error codes checked as integers: `42883` (undefined_function)
- Array keys shortened: `'t'` for type, `'m'` for message in flash arrays
- See `index.php` line 278 and line 66
- Use `camelCase` for all functions and variables
- Examples: `openWeekPicker()`, `openCancelDialog()`, `closeCancelDialog()`, `snapTo30min()`, `selectDay()`, `goWeek()`
- Global variable for state: `_pendingCancelForm` (underscore prefix for module-level state)
- See `index.php` lines 1496-1549 and `admin.php` lines 552-599
## Code Organization & Structure
- `Helpers / Bootstrap` - Database, CSRF, authentication helpers
- `Auth (simple)` - Login/logout logic
- `Mini-API` - AJAX endpoints returning JSON
- `View params` - Request parameters and state
- `POST Actions` - Form submissions and operations
- `Daten laden` - Data fetching for rendering
## Error Handling
- Line 270-300: PDOException with error code checking
- Line 423-643: Multi-level exception handling with custom error parsing
- Line 605-643: PDO error message regex parsing for user-friendly display
- User-facing errors via `flash()` function with type 'error' or 'success'
- JSON responses use: `['ok' => false, 'error' => 'error_key']`
- Graceful fallbacks when error parsing fails
- See `index.php` lines 608-643 for sophisticated error message parsing
- `http_response_code(400)` for CSRF failures
- `http_response_code(403)` for authorization failures
- JSON endpoints use appropriate Content-Type headers
## Input Validation
## Database Access
## String Formatting & Output
- Use double quotes with curly braces for complex expressions: `"{$var}"`
- Use single quotes for static strings
- Concatenate with `.` operator for readability
- See `index.php` line 632: `"Konflikt: Auf Platz {$courtName} ist am {$dateLabel}..."`
## Date & Time Handling
- Line 97: DateTime with timezone
- Line 406-407: View parameter setup
- Line 435: DateTimeZone usage
- Database ISO format: 'c' (ISO 8601)
- Display format: 'd.m.Y' (German format)
- Time format: 'H:i' (24-hour)
- 30-minute raster enforced: `$minutes % 30 === 0`
## Closures & Arrow Functions
## Comments
- Section headers use /* === ... === */ style
- Inline comments in German for business logic
- No docblocks (simple functions don't need them)
- Descriptive comments for complex queries
- See `index.php` lines 16-18, 103, 269-270, 321
- Complex SQL queries
- Business rule explanations
- Non-obvious algorithm choices
- Regex patterns
- Fallback behavior explanations
## JSON & AJAX
## Security Patterns
- Token generated and stored in `$_SESSION['csrf']`
- On every POST request: `csrf_check()`
- Token included in forms: `<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">`
- See `index.php` lines 31-46
- Use `password_hash($pass, PASSWORD_DEFAULT)`
- Verify with `password_verify($pass, $hash)`
- See `admin.php` lines 274, 300
- Role-based checks: `is_admin()`, `is_logged_in()`
- Owner checks: `$b['user_id'] !== current_user()['id']`
- See `index.php` lines 448-449, 475
## Indentation & Formatting
- `index.php`: Uses 2-space indentation in some places, 4-space in others (inconsistent)
- `admin.php`: Uses 4-space indentation (more consistent)
- Choose 4-space for new code
- No strict limit, but keep SQL readable with line breaks at logical points
- Long HTML strings use concatenation with `.`
- All opening braces on same line: `if (...) {`
- Closing braces on own line for blocks

## Architecture

## Pattern Overview
- Monolithic codebase with two entry points (user and admin interfaces)
- Mixed presentation and application logic in PHP files
- Direct PDO database interaction without ORM
- Session-based authentication with separate user/admin flows
- Server-side rendering with Tailwind CSS
- PostgreSQL database with stored functions for complex operations
## Layers
- Purpose: Render HTML/Tailwind UI and handle user interactions
- Location: `index.php` (lines 710-968), `admin.php` (lines 88-732)
- Contains: HTML rendering functions, form generation, dialog markup
- Depends on: Application logic layer, session management
- Used by: Web browser clients
- Purpose: Handle HTTP requests, parse parameters, route to operations, manage business logic
- Location: `index.php` (lines 198-644), `admin.php` (lines 141-349)
- Contains: Login/logout handlers, POST action routing, CSRF validation, data loading
- Depends on: Database layer, presentation layer
- Used by: Entry points for all user interactions
- Purpose: Provide JSON responses for dynamic UI (AJAX calls)
- Location: `index.php` (lines 245-301, 306-401)
- Contains: `free_courts` endpoint (availability lookup), `series_preview` endpoint (series conflict analysis)
- Depends on: Database layer via PDO
- Used by: Client-side JavaScript for real-time availability
- Purpose: Execute database queries and manage database connections
- Location: `index.php` (lines 19-30), `admin.php` (lines 19-30)
- Contains: PDO singleton factory function, prepared statement execution
- Depends on: Configuration, PostgreSQL database
- Used by: All application logic that needs data persistence
- Purpose: Store and manage data, enforce constraints, perform complex queries
- Location: `pg.sql`
- Contains: Schema definition, tables, indexes, triggers, stored functions
- Depends on: PostgreSQL 
- Used by: Data access layer via PDO
## Data Flow
- Session: PHP `$_SESSION` for user/admin state and CSRF tokens
- URL Parameters: `date`, `selected`, `view`, `action` for navigation state
- Database: Single source of truth for bookings, users, courts, series
## Key Abstractions
- Purpose: Prevent cross-site request forgery attacks
- Examples: `csrf_token()` (line 31-37), `csrf_check()` (line 38-47) in both files
- Pattern: Generate token in session, embed in form, validate on POST
- Purpose: Check user login status and permissions
- Examples: `is_logged_in()` (line 52-55), `is_admin()` (line 60-63), `current_user()` (line 56-59)
- Pattern: Check session variables, return boolean or user data
- Purpose: Prevent XSS vulnerabilities
- Examples: `h()` function (line 48-51 in index.php, line 31-34 in admin.php)
- Pattern: Apply `htmlspecialchars()` with ENT_QUOTES to all user output
- Purpose: Generate UI elements for time selection
- Examples: `render_time_select()` (line 104-149), `render_duration_input()` (line 152-193), `format_duration_short()` (line 84-93)
- Pattern: Loop through time/duration options, render HTML with selected state
- Purpose: Display user feedback across redirects
- Examples: `flash()` (line 64-67), `render_flash()` (line 68-77)
- Pattern: Store message in `$_SESSION['flash']`, render and clear on next page load
## Entry Points
- Location: `index.php` (lines 1-1553)
- Triggers: GET requests to `index.php` with optional `action` and `view` parameters
- Responsibilities: 
- Location: `admin.php` (lines 1-735)
- Triggers: GET/POST requests to `admin.php` with optional `action` parameter
- Responsibilities:
- Location: `conf.php` (lines 1-19)
- Used by: Both `index.php` and `admin.php`
- Provides: Database credentials and DSN string
## Error Handling
```php
```
- Booking overlap check in `prevent_overlap()` PostgreSQL trigger (pg.sql lines 80-103)
- Constraint checks in `booking_grid_chk` (pg.sql lines 46-52) for 30-minute grid alignment
- Both raise EXCEPTION which becomes PDOException with specific message
```php
```
## Cross-Cutting Concerns
- Client-side: HTML5 form attributes (type="email", required, etc.)
- Server-side: PHP validation in POST handlers (lines 427-522 in index.php, lines 265-343 in admin.php)
- Database: Triggers and CHECK constraints enforce 30-minute grid alignment
- Times validated against DateTimeZone('Europe/Berlin') for consistency
- Login checks via session variables: `$_SESSION['user']` (users) or `$_SESSION['admin_user']` (admins)
- Permission checks: `is_admin()` for admin-only operations, user_id comparison for ownership
- Separate login flows prevent privilege escalation between user and admin contexts
- Hardcoded to 'Europe/Berlin' throughout application
- All dates stored as TIMESTAMPTZ in database
- Conversion applied when displaying to users: `->setTimezone($tz)->format(...)`
- Series expansion respects timezone when calculating occurrences
- Advisory locks in `create_booking()` function (pg.sql line 175-176) serialize per-court operations
- Prevents race conditions when multiple users book same court simultaneously
