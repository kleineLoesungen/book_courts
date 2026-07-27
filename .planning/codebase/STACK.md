# Technology Stack

**Analysis Date:** 2026-07-27

## Languages

**Primary:**
- PHP 8.0+ - All backend logic and server-side rendering. Uses strict type declarations (`declare(strict_types=1)`) throughout.

**Secondary:**
- JavaScript (Vanilla) - Client-side interactivity for AJAX requests and form handling
- SQL (PL/pgSQL) - Database stored procedures and triggers

## Runtime

**Environment:**
- PHP CLI/CGI (built-in PHP web server or Apache mod_php)
- Requires PHP 7.4+ minimum (uses match expressions, named arguments, type hints)

**Package Manager:**
- Not applicable - No external package dependency management (Composer not detected)
- All code is bundled directly in PHP files

## Frameworks

**Core:**
- Custom PHP MVC-inspired routing - All endpoints handled in `index.php` and `admin.php` with action-based routing via `$_GET['action']`

**Frontend:**
- Tailwind CSS 3.x - Loaded via CDN (`https://cdn.tailwindcss.com`)
- Lucide Icons - Icon library loaded via CDN (`https://unpkg.com/lucide@latest`)

## Key Dependencies

**Critical:**
- PostgreSQL 12+ - Primary database with custom schema, stored procedures, and complex range-based queries
- PHP PDO PostgreSQL Driver - Built-in PDO extension with PostgreSQL support

**Infrastructure:**
- Apache 2.4+ - Web server with `.htaccess` configuration for URL rewriting and file access control

## Configuration

**Environment:**
- Connection details stored in `conf.php` (NOT version controlled - contains database credentials)
- Configuration approach: Global PHP variables loaded at bootstrap
- Required settings:
  - `$DB_HOST` - PostgreSQL hostname
  - `$DB_PORT` - PostgreSQL port (default 5432)
  - `$DB_NAME` - Database name
  - `$DB_USER` - Database user
  - `$DB_PASS` - Database password
  - `$dsn` - PDO DSN string constructed from above

**Build:**
- No build process detected
- Direct file serving via Apache
- `.htaccess` handles DirectoryIndex and access restrictions

## Platform Requirements

**Development:**
- PHP 8.0+ with PDO PostgreSQL support
- PostgreSQL 12+ with pgcrypto extension
- Web server (Apache with mod_rewrite or PHP built-in server)
- Browser with ES6 support for inline JavaScript

**Production:**
- Apache 2.4+ with mod_php or PHP-FPM
- PostgreSQL 12+ with pgcrypto extension (`CREATE EXTENSION IF NOT EXISTS pgcrypto`)
- TLS/SSL certificate for HTTPS (recommended)
- Session storage (default: filesystem via PHP)

**Timezone:**
- Application hardcoded to Europe/Berlin timezone in DateTime handling
- Database schema expects Europe/Berlin timezone for timestamp conversions

---

*Stack analysis: 2026-07-27*
