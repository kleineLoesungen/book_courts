# Coding Conventions

**Analysis Date:** 2026-07-27

## Language & Type Safety

**PHP Configuration:**
- All PHP files start with `declare(strict_types=1);` and `session_start();`
- Strict types are enforced at file level: `declare(strict_types=1);` (see `index.php` line 11, `admin.php` line 11)
- All function parameters require type hints
- All function return types must be declared

**Type Hints:**
All functions use complete type hints:
```php
function pdo(): PDO
function csrf_token(): string
function is_logged_in(): bool
function flash(string $msg, string $type = 'success'): void
function h(string $s): string
```

## Naming Patterns

**Functions:**
- Use `snake_case` exclusively
- Examples: `pdo()`, `csrf_token()`, `csrf_check()`, `is_logged_in()`, `current_user()`, `is_admin()`, `flash()`, `render_flash()`, `anonymize_name()`, `format_duration_short()`, `redirect_to_day()`, `render_time_select()`
- Helper functions start with descriptive verbs: `render_*`, `format_*`, `is_*`, `has_*`
- See `index.php` lines 19-102 for helper function examples

**Variables:**
- Use `snake_case` for all variables
- Examples: `$csrf`, `$user_id`, `$court_id`, `$start_time`, `$booking_id`, `$series_id`, `$duration`, `$weekday`, `$startDt`, `$endDt`, `$booking_date`
- Short variable names in loops and single-use contexts: `$u` (user), `$b` (booking), `$s` (series), `$c` (court), `$st` (statement), `$m` (match array), `$f` (flash)
- See `index.php` lines 200-220 for variable naming patterns

**Constants:**
- PostgreSQL error codes checked as integers: `42883` (undefined_function)
- Array keys shortened: `'t'` for type, `'m'` for message in flash arrays
- See `index.php` line 278 and line 66

**JavaScript:**
- Use `camelCase` for all functions and variables
- Examples: `openWeekPicker()`, `openCancelDialog()`, `closeCancelDialog()`, `snapTo30min()`, `selectDay()`, `goWeek()`
- Global variable for state: `_pendingCancelForm` (underscore prefix for module-level state)
- See `index.php` lines 1496-1549 and `admin.php` lines 552-599

## Code Organization & Structure

**Section Markers:**
Code is organized into sections with clear delimiters:
```php
/* =======================
   Helpers / Bootstrap
   ======================= */
```

All major sections use this pattern:
- `Helpers / Bootstrap` - Database, CSRF, authentication helpers
- `Auth (simple)` - Login/logout logic
- `Mini-API` - AJAX endpoints returning JSON
- `View params` - Request parameters and state
- `POST Actions` - Form submissions and operations
- `Daten laden` - Data fetching for rendering

See `index.php` sections starting at lines 16, 195, 244, 403, 414, 646

**File Organization:**
1. `<?php` declaration with strict_types
2. `session_start()`
3. `require_once` statements
4. Helper/utility functions
5. Bootstrap functions (pdo singleton, CSRF)
6. HTML rendering helpers
7. Authentication logic
8. API endpoints (GET actions)
9. View parameter setup
10. POST action handlers
11. Data fetching
12. HTML page rendering

## Error Handling

**Exception Types:**
Three levels of exception handling used:
```php
try {
    // Business logic
} catch (PDOException $e) {
    // Database-specific errors
    // Check error codes: if ($ee->getCode() !== '42883')
} catch (Exception $e) {
    // Application errors with user-friendly messages
} catch (Throwable $e) {
    // Fallback for any error
}
```

See `index.php`:
- Line 270-300: PDOException with error code checking
- Line 423-643: Multi-level exception handling with custom error parsing
- Line 605-643: PDO error message regex parsing for user-friendly display

**Error Messages:**
- User-facing errors via `flash()` function with type 'error' or 'success'
- JSON responses use: `['ok' => false, 'error' => 'error_key']`
- Graceful fallbacks when error parsing fails
- See `index.php` lines 608-643 for sophisticated error message parsing

**HTTP Status Codes:**
- `http_response_code(400)` for CSRF failures
- `http_response_code(403)` for authorization failures
- JSON endpoints use appropriate Content-Type headers

## Input Validation

**Pattern:**
1. Retrieve from `$_GET`, `$_POST`, or request
2. Trim strings with `trim()` if needed
3. Validate format with `preg_match()` or built-in validators
4. Type cast for integers: `(int)$var`
5. Throw exception if invalid

Examples from `index.php`:
```php
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $today; // Line 409
$duration = isset($_GET['duration']) ? (int)$_GET['duration'] : 0; // Line 251
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Ungültige E-Mail.'); // admin.php line 270
```

**Null Coalescing:**
Use `??` operator extensively for safe defaults:
```php
$action = $_GET['action'] ?? 'home'; // Line 198
$email = trim($_POST['email'] ?? ''); // Line 202
$msg = $e->getMessage(); // Line 606
```

## Database Access

**Pattern:**
All database access uses PDO prepared statements:
```php
$st = pdo()->prepare($sql);
$st->execute([':param' => $value]);
$result = $st->fetch();  // single row
$results = $st->fetchAll();  // all rows
$single = $st->fetchColumn();  // single value
```

See `index.php` lines 204-209, 207-209, 385-395

**Connection:**
Singleton pattern with static variable:
```php
function pdo(): PDO
{
  static $pdo = null;
  if ($pdo === null) {
    global $dsn, $DB_USER, $DB_PASS;
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [...]);
  }
  return $pdo;
}
```
See `index.php` lines 19-30

**PDO Configuration:**
```php
PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
```

## String Formatting & Output

**HTML Escaping:**
All user-generated content must pass through `h()` helper:
```php
function h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```
See `index.php` lines 48-51

Usage: `echo h($user_input);` - Every single value from user input or database that goes to HTML must be escaped.

**Multiline Strings:**
Use heredoc syntax for SQL and HTML blocks:
```php
$sql = <<<SQL
SELECT ...
FROM ...
SQL;
```

HTML heredoc:
```php
return <<<HTML
<!doctype html>
<html>...
</html>
HTML;
```
See `index.php` lines 333-383 for SQL, lines 138-148 for HTML

**String Interpolation:**
- Use double quotes with curly braces for complex expressions: `"{$var}"`
- Use single quotes for static strings
- Concatenate with `.` operator for readability
- See `index.php` line 632: `"Konflikt: Auf Platz {$courtName} ist am {$dateLabel}..."`

## Date & Time Handling

**Timezone:**
Always use 'Europe/Berlin' timezone explicitly:
```php
$tz = new DateTimeZone('Europe/Berlin');
$date = (new DateTime($iso))->setTimezone($tz)->format('Y-m-d');
```

See `index.php`:
- Line 97: DateTime with timezone
- Line 406-407: View parameter setup
- Line 435: DateTimeZone usage

**Format:**
- Database ISO format: 'c' (ISO 8601)
- Display format: 'd.m.Y' (German format)
- Time format: 'H:i' (24-hour)
- 30-minute raster enforced: `$minutes % 30 === 0`

## Closures & Arrow Functions

**Arrow Functions:**
Used for simple transformations:
```php
$ini = array_map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)), $parts); // Line 81
```

**Anonymous Functions:**
Event handlers in JavaScript use arrow syntax:
```javascript
el.addEventListener('change', schedule);
radios.forEach(r => r.addEventListener('change', schedule));
```
See `index.php` lines 1451-1456

## Comments

**Style:**
- Section headers use /* === ... === */ style
- Inline comments in German for business logic
- No docblocks (simple functions don't need them)
- Descriptive comments for complex queries
- See `index.php` lines 16-18, 103, 269-270, 321

**When to Comment:**
- Complex SQL queries
- Business rule explanations
- Non-obvious algorithm choices
- Regex patterns
- Fallback behavior explanations

## JSON & AJAX

**Response Format:**
```javascript
{
  ok: true,
  items: [...]  // or error: 'error_key'
}
```

**Headers:**
```php
header('Content-Type: application/json; charset=utf-8');
echo json_encode($data, JSON_UNESCAPED_UNICODE);
```

See `index.php` lines 245-246, 395

**Parameter Validation:**
Always validate required parameters and return error:
```php
if (!$date || !$start || $duration <= 0) {
  echo json_encode(['ok' => false, 'error' => 'missing_params']);
  exit;
}
```
See `index.php` lines 253-256

## Security Patterns

**CSRF Protection:**
- Token generated and stored in `$_SESSION['csrf']`
- On every POST request: `csrf_check()`
- Token included in forms: `<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">`
- See `index.php` lines 31-46

**Password Hashing:**
- Use `password_hash($pass, PASSWORD_DEFAULT)`
- Verify with `password_verify($pass, $hash)`
- See `admin.php` lines 274, 300

**Authorization:**
- Role-based checks: `is_admin()`, `is_logged_in()`
- Owner checks: `$b['user_id'] !== current_user()['id']`
- See `index.php` lines 448-449, 475

## Indentation & Formatting

**Indentation:**
- `index.php`: Uses 2-space indentation in some places, 4-space in others (inconsistent)
- `admin.php`: Uses 4-space indentation (more consistent)
- Choose 4-space for new code

**Line Length:**
- No strict limit, but keep SQL readable with line breaks at logical points
- Long HTML strings use concatenation with `.`

**Braces:**
- All opening braces on same line: `if (...) {`
- Closing braces on own line for blocks

---

*Convention analysis: 2026-07-27*
