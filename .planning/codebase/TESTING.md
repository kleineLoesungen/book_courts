# Testing Patterns

**Analysis Date:** 2026-07-27

## Current State

**CRITICAL GAP: No testing framework or test suite exists in this codebase.**

No test files, no testing configuration, and no testing tools detected:
- No `phpunit.xml`, `phpunit.dist.xml`, or PHPUnit configuration
- No `pytest`, `unittest`, or other test framework config
- No test files matching `*.test.php`, `*.spec.php`, `*Test.php`, or similar patterns
- No `tests/`, `test/`, or `__tests__/` directories
- No CI/CD pipeline configuration (no `.github/workflows/`, `.gitlab-ci.yml`, `Jenkinsfile`, etc.)

## Recommended Testing Framework

**PHPUnit** is the standard PHP testing framework and is recommended for this project:
- Framework: PHPUnit 10.x+
- Assertion Library: Built-in (PHPUnit uses native assertions)
- PHP Version Requirement: 8.1+ (current project uses strict types)

## Proposed Test File Organization

**Test Location Pattern:**
Create a `tests/` directory structure mirroring `src/`:

```
tests/
├── Unit/
│   ├── Helpers/
│   │   ├── PDOHelperTest.php
│   │   ├── CSRFHelperTest.php
│   │   ├── AuthHelperTest.php
│   │   └── HtmlHelperTest.php
│   ├── Validation/
│   │   ├── DateValidationTest.php
│   │   └── InputValidationTest.php
│   └── Models/
│       ├── BookingTest.php
│       └── UserTest.php
├── Integration/
│   ├── BookingFlowTest.php
│   ├── SeriesCreationTest.php
│   ├── AuthenticationTest.php
│   └── AdminOperationsTest.php
├── Fixtures/
│   ├── UserFixtures.php
│   ├── CourtFixtures.php
│   └── BookingFixtures.php
└── bootstrap.php
```

## Test Structure

**PHPUnit Convention:**
```php
<?php

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

class CSRFHelperTest extends TestCase
{
    /**
     * @test
     */
    public function testCsrfTokenGeneratesValidToken(): void
    {
        // Arrange
        session_start();
        
        // Act
        $token = csrf_token();
        
        // Assert
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        $this->assertEquals($_SESSION['csrf'], $token);
    }

    protected function tearDown(): void
    {
        session_destroy();
    }
}
```

**Test Naming:**
- Class names: `[SubjectName]Test.php`
- Method names: `test[Scenario][ExpectedResult]` or use `@test` annotation
- Examples: `testCsrfTokenGeneratesValidToken()`, `testLoginFailsWithInvalidPassword()`, `testBookingConflictDetection()`

## Testing Areas (Priority Order)

### HIGH PRIORITY - Security Critical

**Authentication & Authorization (Tests needed in `tests/Integration/AuthenticationTest.php`):**
- Login with valid credentials succeeds
- Login with invalid email fails
- Login with wrong password fails
- Inactive users cannot login
- CSRF validation prevents POST without token
- CSRF validation blocks requests with wrong token
- Unauthorized users cannot access admin panel
- Users cannot access other users' bookings
- Admin can access all bookings

**CSRF Protection (`tests/Unit/Helpers/CSRFHelperTest.php`):**
- Token generation creates unique tokens
- Token storage in session works
- Token validation passes with correct token
- Token validation fails with missing token
- Token validation fails with incorrect token

**SQL Injection Prevention (`tests/Unit/Helpers/PDOHelperTest.php`):**
- All database queries use prepared statements
- Malicious input in parameters doesn't execute
- Database error doesn't expose sensitive info

### HIGH PRIORITY - Core Business Logic

**Booking Creation (`tests/Integration/BookingFlowTest.php`):**
- Single booking creation succeeds
- Booking conflicts are detected
- Duration validation (30-min increments)
- Time validation (00:00 or :30 only)
- Booking by non-admin limited to 30/60 min
- Booking by admin allows 30-720 min
- End time must be after start time
- Users can only book available courts

**Series Creation (`tests/Integration/SeriesCreationTest.php`):**
- Series creation succeeds with valid data
- Series conflicts detected before creation
- Series materialization creates correct booking instances
- Series with end date respects boundary
- Series without end date uses default (6 months)
- User role restrictions on duration enforced

**Booking Cancellation:**
- Users can cancel their own bookings
- Users cannot cancel others' bookings
- Admins can cancel any booking
- Series cancellation cascades to all linked bookings
- Cancellation sets status to 'canceled' (not deleted)

### MEDIUM PRIORITY - Data Validation

**Input Validation (`tests/Unit/Validation/InputValidationTest.php`):**
- Date format validation (Y-m-d pattern)
- Time format validation (HH:mm pattern)
- Email validation for user creation
- HTML escaping prevents XSS
- Trim behavior on strings
- Null coalescing operator default handling

**Date/Time Handling (`tests/Unit/Validation/DateValidationTest.php`):**
- DateTime creation with Berlin timezone
- Date range calculations
- 30-minute raster enforcement
- Daylight saving time handling in Europe/Berlin

### MEDIUM PRIORITY - HTML & Display

**Output Escaping (`tests/Unit/Helpers/HtmlHelperTest.php`):**
- `h()` function escapes HTML entities
- HTML special characters encoded correctly
- UTF-8 encoding preserved
- Quote handling correct

**Time Selects Rendering (`tests/Unit/Helpers/TimeSelectTest.php`):**
- Start time select includes 00:00 to 23:30
- End time select includes 00:30 to 00:00 (next day)
- Selected value marked correctly
- HTML attributes properly escaped

### LOW PRIORITY - Error Handling

**Error Message Parsing:**
- PDOException conflict message parsed correctly
- User-friendly error messages displayed
- Graceful fallback when parsing fails

## Fixtures and Test Data

**User Fixtures (`tests/Fixtures/UserFixtures.php`):**
```php
<?php

namespace Tests\Fixtures;

class UserFixtures
{
    public static function createTestUser(): array
    {
        return [
            'email' => 'test@example.com',
            'password' => 'TestPassword123!',
            'full_name' => 'Test User',
            'role' => 'user',
            'is_active' => true,
        ];
    }

    public static function createAdminUser(): array
    {
        return array_merge(self::createTestUser(), [
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
    }
}
```

**Court Fixtures (`tests/Fixtures/CourtFixtures.php`):**
```php
<?php

namespace Tests\Fixtures;

class CourtFixtures
{
    public static function createTestCourt(): array
    {
        return [
            'name' => 'Test Court A',
            'location' => 'Test Location',
            'is_active' => true,
        ];
    }
}
```

**Booking Fixtures (`tests/Fixtures/BookingFixtures.php`):**
```php
<?php

namespace Tests\Fixtures;

class BookingFixtures
{
    public static function createTestBooking(int $user_id, int $court_id): array
    {
        return [
            'user_id' => $user_id,
            'court_id' => $court_id,
            'title' => 'Test Booking',
            'time_span' => '2024-08-15 10:00 to 2024-08-15 10:30',
            'status' => 'active',
        ];
    }
}
```

## Database Testing

**Test Database Setup:**
- Use separate test PostgreSQL database (not production)
- Run migrations before each test class
- Roll back transactions after each test
- Use test fixtures for repeatable data

**Example Test Setup:**
```php
<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO(
            getenv('TEST_DATABASE_DSN'),
            getenv('TEST_DATABASE_USER'),
            getenv('TEST_DATABASE_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Begin transaction for rollback after test
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Rollback all changes
        $this->pdo->rollback();
    }
}
```

## Mocking Strategy

**What to Mock:**
- Database access (for unit tests) → Use in-memory data structures
- External HTTP calls (if added) → Mock with fixtures
- DateTime for time-sensitive tests → Use MockClock or similar

**What NOT to Mock:**
- PDO/Database (use test database instead)
- Authentication (test end-to-end in integration tests)
- CSRF tokens (they're part of security, must be real)
- Session handling (integration tests should test real sessions)

**Example Mock:**
```php
<?php

class TimeAwareMock
{
    private DateTime $now;

    public function __construct(DateTime $now)
    {
        $this->now = $now;
    }

    public function getCurrentTime(): DateTime
    {
        return $this->now;
    }
}
```

## Coverage Targets

**Current State:** 0% (no tests exist)

**Recommended Targets:**
- Overall: 70%+ (medium priority)
- Security-critical code: 95%+ (authentication, CSRF, SQL injection prevention)
- Business logic: 85%+ (bookings, series, cancellation)
- Utilities: 80%+ (helpers, validation)
- Views: 40%+ (HTML rendering is lower priority)

**View Coverage Report:**
Once tests are in place, generate with:
```bash
vendor/bin/phpunit --coverage-html build/coverage/
```

## CI/CD Integration

**Recommended GitHub Actions Workflow (`.github/workflows/tests.yml`):**
```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    services:
      postgres:
        image: postgres:15
        env:
          POSTGRES_PASSWORD: test
          POSTGRES_DB: book_courts_test
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    steps:
      - uses: actions/checkout@v3
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: phpunit
      
      - run: composer install
      - run: vendor/bin/phpunit --coverage-clover coverage.xml
      - uses: codecov/codecov-action@v3
```

## Common Testing Patterns

**Async Testing (via AJAX endpoints):**
```php
<?php

class FreeCourtApiTest extends TestCase
{
    /**
     * @test
     */
    public function testFreeCourtEndpointReturnsAvailableCourts(): void
    {
        // Simulate GET request
        $_GET = [
            'action' => 'free_courts',
            'date' => '2024-08-15',
            'start' => '10:00',
            'duration' => 30,
        ];

        // Capture JSON output
        ob_start();
        // Include endpoint code
        ob_end_clean();

        $this->assertTrue($result['ok']);
        $this->assertIsArray($result['items']);
    }
}
```

**Error Testing:**
```php
<?php

class BookingValidationTest extends TestCase
{
    /**
     * @test
     */
    public function testBookingWithInvalidDurationThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Dauer muss Vielfaches von 30 Minuten sein');
        
        // Attempt booking with invalid duration
        createBooking($user_id, $court_id, '2024-08-15', '10:00', 45);
    }
}
```

## Test Execution Commands

**Once testing is set up, use:**
```bash
# Run all tests
vendor/bin/phpunit

# Run with watch mode
vendor/bin/phpunit --watch

# Run specific test class
vendor/bin/phpunit tests/Unit/Helpers/CSRFHelperTest.php

# Run with coverage
vendor/bin/phpunit --coverage-html build/coverage/

# Run only fast tests (exclude integration)
vendor/bin/phpunit tests/Unit/
```

## Next Steps

1. Set up PHPUnit in `composer.json`:
   ```bash
   composer require --dev phpunit/phpunit
   ```

2. Create `phpunit.xml.dist` configuration

3. Create `tests/bootstrap.php` for shared setup

4. Start with HIGH PRIORITY tests (authentication, CSRF, SQL injection)

5. Add GitHub Actions workflow for CI/CD

6. Aim for 70%+ coverage before considering code "stable"

---

*Testing analysis: 2026-07-27*

**NOTE: This codebase currently has NO automated tests. This is a significant quality and maintenance risk. Implementation of the testing patterns described above is strongly recommended, starting with security-critical areas.**
