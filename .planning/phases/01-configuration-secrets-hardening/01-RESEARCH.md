# Phase 1: Configuration & Secrets Hardening - Research

**Researched:** 2026-07-27
**Domain:** Environment-based configuration management, credential rotation, PHP secrets handling
**Confidence:** HIGH (native PHP features, common patterns) / MEDIUM (PostgreSQL rotation mechanics — depends on actual hosting setup for coordinated deploy timing)

## Summary

This phase replaces plaintext `conf.php` (committed to git with real credentials) with a `.env` file (gitignored, chmod 600, loaded via native PHP `parse_ini_file()`). The app's entry points (`index.php`, `admin.php`) will load config from the `.env` file instead of `conf.php`, maintaining the same global-variable interface. Simultaneously, the production PostgreSQL database password is rotated via `ALTER ROLE`, and the new `.env` is deployed as a coordinated single action to avoid an outage window where Postgres and app config are out of sync.

**Primary recommendation:** Use native PHP `parse_ini_file()` with a hand-rolled 10–15 line `.env` loader function; avoid `vlucas/phpdotenv` (adds 4 transitive dependencies for a single parse operation). Implement credential rotation as a documented, scripted procedure (change Postgres role password and deploy `.env` in the same maintenance window, not as separate steps), with a rollback password kept valid for 24 hours to recover from any deploy mishap.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**D-01:** `.env` location & protection
- `.env` lives in the project root (same level as `conf.php` today), is added to `.gitignore` (already done for `conf.php` — extend to `.env`), and is `chmod 600`
- Rationale: Recommended default selected — research flagged hosting shell/vhost access level as unconfirmed; the in-webroot option works regardless of hosting tier, matches the app's current deployment model of file-based-only access, and can be moved outside webroot later at zero cost if shell access is confirmed

**D-02:** Credential rotation mechanics
- Rotation is a single coordinated step: generate a new PostgreSQL role password (`ALTER ROLE ... PASSWORD ...`) and update `.env` in the same maintenance action, treated as a documented runbook step rather than a zero-downtime dual-role cutover mechanism
- Rationale: Recommended default — this is a single-club, low-traffic booking app; PITFALLS.md's warning is about the two systems drifting out of sync, not about avoiding all downtime. A documented runbook fully addresses the risk without building rotation tooling disproportionate to this app's scale

**D-03:** `conf.php` disposition
- `conf.php` is fully replaced — it no longer defines `$DB_HOST`/`$DB_PORT`/`$DB_NAME`/`$DB_USER`/`$DB_PASS`/`$dsn` with real values. A new config-loading function (reading `.env`) becomes the single source these variables come from, included by both `index.php` and `admin.php`. `conf.php` itself may be deleted or reduced to nothing meaningful — no backward-compatible shim is needed since both entry points are updated together in this phase

**D-04:** `.env` scope
- `.env` for this phase holds only what `conf.php` already defines today (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`) plus a `.env.example` documenting each key without real values. No other config (e.g., future mailer settings for v2's password reset) is added preemptively

### Claude's Discretion

- Exact `.env` parsing implementation (hand-rolled `parse_ini_file()`-based loader)
- Whether to fully delete `conf.php` or leave an empty/comment-only file — functionally equivalent, pick whichever keeps `.htaccess`'s existing `FilesMatch "^(conf\.php|pg\.sql)$"` block still meaningful
- Exact `.env.example` formatting/comments

### Deferred Ideas (OUT OF SCOPE)

- Mailer/email config for self-service password reset (explicitly deferred to v2 per REQUIREMENTS.md AUTH-03)
- Secrets manager integration (Vault, AWS SSM) — beyond scope for a single-club, self-hosted app
- Automatic credential rotation tooling — coordinated scripted rotation is sufficient for this scale
- Multi-environment .env files (.env.local, .env.production) — single production target, local dev uses .env.example

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| CONF-01 | Database credentials and other config load from a `.env` file (not committed, outside or protected within webroot) instead of plaintext `conf.php` committed to the working tree | Native PHP `parse_ini_file()` provides zero-dependency parsing; `.env` at project root with gitignore + chmod 600 satisfies protection requirement; existing Apache `.htaccess` rules can be extended to block direct access as defense in depth |
| CONF-02 | The exposed database password is rotated on the actual PostgreSQL server as part of this change (not just moved to a new file) | PostgreSQL `ALTER ROLE app_user PASSWORD '...'` performs the rotation; coordination with `.env` deployment in same maintenance window prevents outage window; runbook approach (script both changes together) avoids the two-system desynchronization risk documented in PITFALLS.md |

</phase_requirements>

## Standard Stack

### Core

| Library/Tool | Version | Purpose | Why Standard |
|---|---|---|---|
| PHP native `parse_ini_file()` | Built into PHP 8.0+ (no install) | Parse `.env` file into config array | Zero dependencies, zero Composer footprint. Handles comments (semicolon `;`), `KEY=VALUE` syntax, and quoted values natively. This is precisely what the function was designed for — a hand-rolled parser or `vlucas/phpdotenv` both duplicate what core PHP already does correctly. Verified against official PHP manual. Confidence: HIGH |
| PostgreSQL `ALTER ROLE` | PostgreSQL 12+ (already in use) | Rotate database user password | Native database operation; no new tooling required. Paired with `.env` update in same deploy step, prevents the "two-system drift" outage that PITFALLS.md flags. Confidence: HIGH |
| `.htaccess` file rules | Apache 2.4+ (already in use) | Block direct HTTP access to `.env` | Defense-in-depth layer beyond gitignore + file permissions. Prevents accidental exposure if Apache misconfigured or a dotfile rule is missing. Matches existing pattern for `conf.php`/`pg.sql` blocking. Confidence: HIGH |

### Supporting

| Tool | Purpose | When to Use |
|---|---|---|
| `.env.example` (template file) | Document required environment variables without real values | Always — provides a reference for deployment and new team members. Inline comments explain each variable (e.g., `DB_HOST=localhost # PostgreSQL hostname`). This is standard practice across all frameworks and deployment platforms. |

### Alternatives Considered

| Recommended | Alternative | Tradeoff |
|---|---|---|
| Native `parse_ini_file()` | `vlucas/phpdotenv` v5.6 | phpdotenv introduces 4 transitive dependencies (phpoption, graham-campbell/result-type, Symfony polyfills) purely to parse `KEY=VALUE` pairs. Overkill for a single config file in a no-Composer project; parse_ini_file is the native solution. |
| Single `.env` file | Apache/vhost `SetEnv` directives | `SetEnv` via `.htaccess` is still a plaintext file in the repo; doesn't solve CONCERNS.md's core complaint any better than `conf.php` and is harder to deploy. Requires Apache reload for changes. Verdict: `.env` is superior. |
| Coordinated single-step rotation | Dual-role zero-downtime rotation | Dual-role approach (create second Postgres role, cut app over, drop old role) adds architectural complexity (two roles in Postgres, migration logic in app, rollback coordination). For a low-traffic single-club app, acceptable downtime of a few minutes during scheduled maintenance (late night) is acceptable; coordinated script is sufficient. PITFALLS.md confirms this — the risk is "two systems drifting out of sync," not "any downtime at all." |
| File-based `.env` at project root | `.env` outside webroot | Outside-webroot placement is superior *if* the hosting environment provides shell access (can place file outside docroot). However, CONTEXT.md notes hosting-access-level is unconfirmed — in-webroot placement works for every hosting tier (FTP-only, VPS, shared hosting) and is portable. Outside-webroot can be adopted later as a cost-zero hardening step once hosting access is confirmed. |

## Architecture Patterns

### Recommended Project Structure

```
.
├── .env                    # Real config (gitignored, chmod 600)
├── .env.example            # Template for deployment (committed)
├── .gitignore              # Updated to include .env
├── conf.php                # Either deleted or reduced to empty
├── index.php               # Updated: require new config loader instead of conf.php
├── admin.php               # Updated: require new config loader instead of conf.php
└── includes/
    └── config.php          # NEW: .env parser + global variable loader (optional location)
```

### Pattern 1: `.env` File Format

**What:** An INI-style configuration file with `KEY=VALUE` pairs, parsed by PHP's native `parse_ini_file()`.

**When to use:** As the single source of config in this app. Replaces `conf.php` entirely.

**Example `.env` file (actual, with real credentials — DO NOT commit):**
```ini
DB_HOST=localhost
DB_PORT=5432
DB_NAME=booking_db
DB_USER=app_user
DB_PASS=<actual-production-password>
```

**Example `.env.example` (template, committed to git, used by new deployments):**
```ini
; Database Configuration
; Copy this file to .env and fill in real values (then chmod 600 .env)
DB_HOST=localhost
DB_PORT=5432
DB_NAME=booking_db
DB_USER=app_user
DB_PASS=
```

**Notes:**
- `.env` values can be quoted if they contain special characters: `DB_PASS="p@ssw0rd!with\"quotes"` (backslash-escaped internal quotes)
- Comments start with semicolon (`;`), not hash (`#`) — `parse_ini_file()` doesn't recognize `#` as comment marker by default
- No support for standard escape sequences (`\n`, `\t`) — parse_ini_file returns raw string values. If escape sequences needed post-processing, use `stripcslashes()` after parsing
- Empty values are allowed: `DB_PASS=` (parsed as empty string)
- No variable interpolation: `VAR_A=value` and `VAR_B=$VAR_A` will NOT expand `$VAR_A` inside `VAR_B` — `VAR_B` will literally be the string `$VAR_A`

### Pattern 2: `.env` Loader Function

**What:** A simple PHP function that loads `.env`, parses it, and assigns values to globals (`$DB_HOST`, etc.), maintaining the existing interface.

**When to use:** At app bootstrap, before any database operation. Include in both `index.php` and `admin.php` (or create a shared `includes/config.php` that both require_once).

**Example implementation:**
```php
<?php
declare(strict_types=1);

/**
 * Load .env file and populate global config variables.
 * 
 * Replaces the old conf.php pattern.
 * Must be called before any database operation.
 */
function load_env_config(): void {
    $env_file = __DIR__ . '/.env';
    
    if (!file_exists($env_file)) {
        throw new RuntimeException(
            "Configuration file not found: $env_file\n" .
            "Please create .env by copying .env.example and filling in real values.\n" .
            "Then run: chmod 600 .env"
        );
    }
    
    // parse_ini_file returns associative array of key => value pairs
    $config = parse_ini_file($env_file, false, INI_SCANNER_NORMAL);
    
    if ($config === false) {
        throw new RuntimeException("Failed to parse .env file at $env_file");
    }
    
    // Assign to globals (maintains existing interface for rest of app)
    // Declare as global if needed, or use the explicit assignment pattern:
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;
    
    $DB_HOST = (string)($config['DB_HOST'] ?? '');
    $DB_PORT = (int)($config['DB_PORT'] ?? 5432);
    $DB_NAME = (string)($config['DB_NAME'] ?? '');
    $DB_USER = (string)($config['DB_USER'] ?? '');
    $DB_PASS = (string)($config['DB_PASS'] ?? '');
    
    // Construct DSN (same as old conf.php did)
    global $dsn;
    $dsn = "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME}";
}

// Call this at app bootstrap (top of index.php / admin.php)
load_env_config();

// Rest of app uses $DB_HOST, $DB_PORT, etc. as before — no refactoring needed
?>
```

**Why this pattern:**
- Minimal change to existing code (rest of app uses same `$DB_HOST` globals)
- Single point of config load (easier to debug)
- Clear separation of "load config" from "use config"
- Fails fast with a useful error message if `.env` is missing

### Pattern 3: Credential Rotation Runbook

**What:** A documented, scripted procedure to rotate PostgreSQL password without causing an outage.

**When to use:** When the initial `.env` migration is deployed, and whenever credentials need rotation (e.g., annually, or after a security incident).

**Example runbook (pseudo-code, to be translated into shell/SQL):**

```bash
#!/bin/bash
# Credential Rotation Runbook
# Prerequisites:
# - Access to PostgreSQL server (psql client)
# - SSH or shell access to app server
# - Maintenance window approved (e.g., 2 AM UTC when no bookings in progress)

set -e  # Exit on error

OLD_PASSWORD="<current-password-in-.env>"
NEW_PASSWORD="<new-randomly-generated-password>"
POSTGRES_HOST="<db-host>"
POSTGRES_PORT="<db-port>"
POSTGRES_ADMIN_USER="postgres"  # or whoever has ALTER ROLE permission
APP_USER="app_user"

echo "Step 1: Generate or securely receive new password (already done: $NEW_PASSWORD)"

echo "Step 2: Change PostgreSQL role password"
# This command runs on the Postgres server and atomically updates the role
psql -h "$POSTGRES_HOST" -p "$POSTGRES_PORT" -U "$POSTGRES_ADMIN_USER" \
  -c "ALTER ROLE $APP_USER WITH PASSWORD '$NEW_PASSWORD';"
echo "  ✓ PostgreSQL password updated"

# CRITICAL: Old and new passwords are now out of sync briefly
# App will start failing on the NEXT request that tries to connect

echo "Step 3: Update app .env file with new password and deploy"
# Option A: If shell access available:
#   sed -i "s/DB_PASS=.*/DB_PASS=$NEW_PASSWORD/" /path/to/.env
#   chmod 600 /path/to/.env
# Option B: If only FTP/SFTP available:
#   Download .env, edit locally, upload with chmod 600 via hosting control panel
# For this example, assume we edit and redeploy in same action
echo "  (Assume .env is now deployed with new password)"

echo "Step 4: Health check — verify app can still connect"
# Hit an actual endpoint that requires DB access (not just a static page)
# This confirms the new credential works before considering rotation "done"
curl -v "https://booking-app.example.com/index.php" 2>&1 | grep -q "200\|302" && \
  echo "  ✓ App connected successfully with new credential" || \
  { echo "  ✗ FAILED: App cannot connect. Rolling back..."; \
    # Rollback: revert .env to old password and roll back Postgres
    psql -h "$POSTGRES_HOST" -p "$POSTGRES_PORT" -U "$POSTGRES_ADMIN_USER" \
      -c "ALTER ROLE $APP_USER WITH PASSWORD '$OLD_PASSWORD';"; \
    exit 1; }

echo "Step 5: Keep old password valid for 24 hours (for emergency rollback)"
# Don't drop the old role immediately — if a mishap is discovered in the next few hours,
# you can quickly revert .env back to the old password and the app will still work
echo "  Old password still valid until tomorrow at this time"
echo "  If you need to rollback, revert .env to old password and restart app"

echo "Step 6: After 24 hours, fully revoke old password (optional)"
# psql -h "$POSTGRES_HOST" -p "$POSTGRES_PORT" -U "$POSTGRES_ADMIN_USER" \
#   -c "ALTER ROLE $APP_USER WITH PASSWORD NULL;"
echo "  (Run this command 24 hours later to fully disable old password)"

echo "✓ Credential rotation complete"
```

**Why this pattern:**
- Scripted and repeatable (not manual SSH edits, which are error-prone)
- Coordinated: both Postgres and `.env` change in same maintenance window
- Fail-fast health check confirms app works after rotation
- Rollback plan ready if something goes wrong
- Clear timeline for completing the rotation (revoke old password after grace period)

### Anti-Patterns to Avoid

- **Manual `.env` edits via SSH while requests are in flight:** Risks partial reads of the file if Apache is actively serving requests while you're editing. Use a scripted, atomic approach instead (e.g., `sed -i`, or reupload via FTP).
- **Rotating Postgres password without updating `.env` simultaneously:** The outage window (moment of desync) is the exact risk PITFALLS.md flags. Never do these as separate, sequential steps hours apart.
- **Treating `.env` like `conf.php` (checking if it needs `.htaccess` blocking, then omitting it):** Even though `.gitignore` prevents the file from being committed, Apache misconfiguration (or missing `.htaccess` rule for dotfiles) could expose it over HTTP. Extend the existing `FilesMatch "^(conf\.php|pg\.sql)$"` rule to include `.env` as a defense-in-depth layer.
- **Using `parse_ini_file()` with `INI_SCANNER_RAW` and assuming escape sequences work:** `INI_SCANNER_RAW` disables quote-unquoting, NOT escaping. If your passwords contain backslashes or quotes, test them carefully. The safe default is `INI_SCANNER_NORMAL` (or omit the flag, same behavior).
- **Committing `.env.example` with real/dummy production credentials:** `.env.example` is a template for *new* deployments. It should show which keys are needed and ideally include safe defaults (e.g., `DB_HOST=localhost` for a dev machine), but never include a real production password, even as a "template." Use empty string: `DB_PASS=`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---|---|---|---|
| Parse `.env` file into PHP variables | Custom regex-based parser or string split() logic | `parse_ini_file()` built-in function | Handles edge cases: quoted values with spaces, escaped quotes, section headers (optional), multiline values. A hand-rolled parser will miss these cases or get them wrong. parse_ini_file is the standard solution; it's 2 lines of code. Confidence: HIGH |
| Load environment variables without file parsing overhead | Store credentials in `$_SERVER` via Apache `SetEnv` directive in `.htaccess` | `.env` file + `parse_ini_file()` | `SetEnv` is still plaintext in a file (``.htaccess`) that lives in the repo and can be accidentally committed just like `conf.php`. It doesn't solve the original problem. Plus it requires Apache reload for changes. Verdict: `.env` file is superior and standard. Confidence: HIGH (verified against STACK.md anti-pattern warning). |
| Rotate credentials safely and atomically | Write a custom dual-role database manager or implement retry/fallback logic in the PDO singleton | Scripted runbook that updates Postgres password and deploys new `.env` in the same maintenance window | The "two systems drifting out of sync" risk is a coordination problem, not a code problem. A script running both commands in sequence (with a health check after) is the proven, standard solution. Building retry logic in the app is a band-aid that works around bad operations process. Coordinated deploy is the right fix. Confidence: HIGH (verified against PITFALLS.md). |
| Validate that `.env` contains all required keys | Custom startup validation logic | Simple check loop at app bootstrap | Doesn't matter what tool you use — the pattern is: load `.env`, check for required keys, throw an error with a helpful message. Two lines of code. Example: `$required = ['DB_HOST', 'DB_USER', 'DB_PASS']; foreach ($required as $k) { if (empty($config[$k])) throw new RuntimeException("Missing: $k"); }` This is so simple that hand-rolling is fine, but can also use a library if already adopted. Confidence: MEDIUM (validation choice, not a hidden-complexity domain). |

## Common Pitfalls

### Pitfall 1: Credential Rotation Outage (CRITICAL)

**What goes wrong:**
The PostgreSQL password is changed in the database (`ALTER ROLE app_user PASSWORD '...'`), but the `.env` file on the app server still has the old password. The next request that needs a database connection immediately fails with `SQLSTATE[08006] password authentication failed`. This isn't just "one request fails" — it's every single request that opens a PDO connection, which is most requests. For 10 minutes (or however long the deploy takes), every user sees 500 errors and cannot book. This is the exact scenario PITFALLS.md highlights as "Credential Rotation Causes a Booking Outage."

**Why it happens:**
Teams treat credential rotation as "change the password" (one action) without realizing it's actually two systems (Postgres role + app config) that must flip in lockstep. Because PHP doesn't hold connection pooling between requests, there's no "try old password, then new" fallback.

**How to avoid:**
1. **Script both changes together.** In the same runbook (shell script or documented procedure), run the `ALTER ROLE` command AND update `.env` within seconds of each other during a scheduled maintenance window.
2. **Health-check immediately after.** Hit a real endpoint that requires database access (not just a static page load) to confirm the new credential works.
3. **Keep rollback password valid.** After rotation, don't immediately drop the old role — keep it valid for 24 hours as an emergency rollback measure. If you discover a problem, just revert `.env` back to the old password and the app will work again.
4. **Avoid manual SSH edits of .env while serving traffic.** Use automated tooling (sed, script, or FTP upload) to prevent partial reads of the file.

**Warning signs:**
- No documented rotation procedure exists yet (currently true — `conf.php` edited by hand)
- Postgres password and `.env` update planned as separate deploy steps ("first deploy to prod, then SSH in to update DB")
- No health check in the rotation procedure
- Assumption that "it'll be fine, we'll just be quick" rather than an explicit pause between the two changes

**Phase to address:**
This phase itself — credential rotation is part of CONF-02. Implement a documented, scripted runbook from day one so the first rotation (migration from `conf.php` to `.env`) succeeds and future rotations are repeatable.

---

### Pitfall 2: `.env` File Accidentally Exposed Over HTTP

**What goes wrong:**
Despite `.gitignore` and `chmod 600` protection, the `.env` file can still be downloaded via HTTP if Apache is misconfigured or missing a `.htaccess` rule that blocks direct access to dot-files. An attacker (or a curious person on the same shared hosting) makes a request to `https://booking-app.example.com/.env` and gets a `200 OK` response with the file contents, including the production database password. This is a lower-probability risk (Apache has good dotfile defaults in most configs), but it's a "silent" failure — there's no error log, just a successful download.

**Why it happens:**
Teams use `.gitignore` and file permissions as the primary defense and assume Apache will block `.env` access automatically. Apache DOES have good defaults for hiding dotfiles (files starting with `.`), but (a) those defaults can be disabled/overridden in .htaccess, (b) misconfigured hosting can expose them, and (c) defense-in-depth says "don't assume a single layer is enough."

**How to avoid:**
1. **Extend `.htaccess` with an explicit rule** for `.env` (and `.env.example` if you commit it). Add to the existing `FilesMatch` rule that already blocks `conf.php` and `pg.sql`:
   ```apache
   <FilesMatch "^(\.(env|htaccess|git)|conf\.php|pg\.sql)$">
       Order allow,deny
       Deny from all
   </FilesMatch>
   ```
   This is defense-in-depth: `.gitignore` + `chmod 600` are the primary protections, but this `.htaccess` rule is a safety net.

2. **Test it.** After deploying the new `.htaccess` rule, try to download `.env` via HTTP:
   ```bash
   curl -I https://booking-app.example.com/.env
   # Should return 403 Forbidden, not 200 OK
   ```

3. **Verify in production.** Don't assume the rule works locally — test on the actual hosting environment.

4. **Consider outside-webroot placement (future).** Once hosting shell access is confirmed, `.env` can be placed one directory above the webroot, eliminating the risk entirely. This is a cost-zero hardening step for later.

**Warning signs:**
- `.htaccess` file never updated when `.env` is introduced
- No test of direct HTTP access to `.env` after deployment
- Assumption that `.gitignore` alone is sufficient protection
- Shared hosting with permissive Apache defaults or user-editable Apache config

**Phase to address:**
This phase — configuration & secrets hardening. The `.htaccess` rule and HTTP access test should be part of the implementation verification criteria.

---

### Pitfall 3: `.env` Parsed with Edge Cases Silently Breaking Config

**What goes wrong:**
A database password containing special characters (e.g., `P@ssw0rd!` or `password"with\"quotes"`) is put into `.env` without proper quoting or escaping. The `parse_ini_file()` function either fails to parse it correctly, or parses it as-is without interpretation of escape sequences, resulting in `$DB_PASS` being the literal string `password"with\"quotes"` (with backslashes included as literal characters). The app tries to authenticate with the wrong password and fails.

Separately, someone uses `#` for a comment in `.env` (common habit from shell scripts), but `parse_ini_file()` doesn't recognize `#` as a comment marker — it only recognizes `;`. The line is parsed as a key-value pair, causing confusion.

**Why it happens:**
`.env` format is similar to shell scripts (visually), but it's actually INI format, with different escaping rules and comment markers. Developers switching between contexts (bash scripts + `.env` files) mix up the rules. Additionally, `parse_ini_file()` doesn't support standard escape sequences (`\n`, `\t`, `\"` becomes literal backslash-quote, not an escaped quote).

**How to avoid:**
1. **Quote passwords with special characters.** If `DB_PASS` contains spaces or special characters, wrap it in double quotes:
   ```ini
   DB_PASS="P@ssw0rd!with\"special\"chars"
   ```
   Backslashes inside the quoted string must be doubled if they precede a quote: `\"` is an escaped quote within the string.

2. **Use semicolon for comments, not hash.** In INI files, comments start with `;`, not `#`:
   ```ini
   ; This is a comment (correct for .env / parse_ini_file)
   # This is NOT treated as a comment by parse_ini_file
   ```

3. **Test `.env` parsing locally.** Write a quick validation script:
   ```php
   $config = parse_ini_file('.env', false, INI_SCANNER_NORMAL);
   var_dump($config);
   // Verify: $config['DB_PASS'] contains exactly the password you intended
   ```

4. **Don't rely on escape sequences.** If you need a newline in a config value, `parse_ini_file()` won't interpret `\n` as a newline — it will be literal characters. Use a multiline value (INI supports these with continuation lines), or avoid the need for escape sequences.

5. **Use `.env.example` with inline comments showing the format.** Example:
   ```ini
   ; Database Connection
   ; Passwords with special chars must be quoted: DB_PASS="p@ss!word"
   DB_HOST=localhost
   DB_PASS=
   ```

**Warning signs:**
- Config loaded, but `$DB_PASS` is wrong (includes extra quotes or backslashes)
- Comments in `.env` using `#` are somehow ending up in config values
- `.env` file with multiline values that aren't properly indented

**Phase to address:**
Implementation phase — validate `.env` parsing with a test case that includes passwords with special characters and comments.

---

### Pitfall 4: `.env.example` Accidentally Committed with Real Credentials

**What goes wrong:**
A developer creates the `.env.example` template by copying the real `.env` file and forgetting to replace real values with placeholders. The git history now contains the production database password forever (since it was committed, even if later removed from `.env.example`, the old commit is still in history). An attacker with read access to the repo sees the password. This is especially risky on public GitHub, but even "private" repos can be leaked.

**Why it happens:**
`.env.example` is a boring task ("just copy .env and blank out the values"), and it's easy to forget to actually blank the values out, or to keep the `.env` commit in history by accident.

**How to avoid:**
1. **Create `.env.example` fresh, never copy `.env`.** Start from scratch with just the key names and safe defaults or empty values:
   ```ini
   DB_HOST=localhost
   DB_PORT=5432
   DB_NAME=booking_db
   DB_USER=app_user
   DB_PASS=
   ```

2. **Add to git BEFORE `.env` exists.** Commit `.env.example` first, then create `.env` locally and verify it's in `.gitignore` before ever committing.

3. **Use a `.gitignore` rule that's explicit.** Instead of just `.env`, consider:
   ```
   .env
   .env.*.php
   conf.php
   ```
   to prevent other config files from being accidentally committed.

4. **Pre-commit hook (if team adopts git hooks).** A hook that checks for `DB_PASS=.*[a-zA-Z0-9!@#$]` (password-like values in committed files) can catch this. But the best defense is process discipline.

**Warning signs:**
- `.env.example` contains real-looking values (e.g., `DB_PASS=xK9$p2L@w` instead of empty)
- A developer copies `.env` directly as `.env.example` without editing
- `.env` is ever present in git history (check `git log -- .env` — if anything shows up, the password has been exposed)

**Phase to address:**
Implementation — when creating `.env` and `.env.example` initially, do it carefully with explicit steps and review before commit.

---

### Pitfall 5: Forgetting to Update Entry Points (index.php / admin.php)

**What goes wrong:**
`conf.php` is replaced with a new `.env` loader, but one of the two entry points (`index.php` or `admin.php`) still has `require 'conf.php'` at the top. This entry point fails to load config and crashes with an undefined variable error when it tries to use `$DB_HOST` or `$dsn`. Only one interface (user or admin) works, the other is broken.

**Why it happens:**
Refactoring requires changes in two places (`index.php` and `admin.php`), which are duplicated large files. It's easy to update one and forget the other, especially if they're not tested simultaneously.

**How to avoid:**
1. **Make the change identical in both files.** Use a checklist or script to ensure both files have:
   - The old `require 'conf.php'` line removed (or replaced)
   - A new `require 'includes/config.php'` (or inline loader) added
   - The same location in bootstrap (before any DB operation, after session_start)

2. **Test both entry points.** After the change, load `https://booking-app/index.php` and `https://booking-app/admin.php` and confirm both work. This is a smoke test, not a full regression test, but it catches this mistake.

3. **Consider extracting to shared file.** Instead of duplicating the loader in both files, create `includes/config.php`:
   ```php
   <?php
   load_env_config();  // Single definition, both files require_once it
   ```
   Then both `index.php` and `admin.php` just do `require_once 'includes/config.php'` at the top.

**Warning signs:**
- One interface works, the other shows PHP errors about undefined variables
- Config loading code in `index.php` but not in `admin.php` (or vice versa)

**Phase to address:**
Implementation — this is caught by testing both entry points during verification.

</Common Pitfalls>

## Code Examples

Verified patterns from official sources and the project's own constraints:

### Loading `.env` with `parse_ini_file()`

```php
<?php
declare(strict_types=1);
session_start();

// Source: PHP Official Manual (php.net/manual/en/function.parse-ini-file.php)
// + Project conventions (CLAUDE.md: strict types, snake_case)

/**
 * Load .env configuration file and populate globals.
 * 
 * Replaces the old conf.php approach.
 * Must be called before any database operations.
 * 
 * @throws RuntimeException if .env is missing or malformed
 */
function load_env_config(): void {
    $env_file = __DIR__ . '/.env';
    
    if (!file_exists($env_file)) {
        throw new RuntimeException(
            "Configuration file not found: {$env_file}\n" .
            "Please create .env by copying .env.example and filling in real values.\n" .
            "Then run: chmod 600 .env"
        );
    }
    
    // Parse .env file as INI format (native PHP)
    // INI_SCANNER_NORMAL: process quotes and escape sequences normally
    $config = parse_ini_file($env_file, false, INI_SCANNER_NORMAL);
    
    if ($config === false) {
        throw new RuntimeException("Failed to parse .env file at {$env_file}");
    }
    
    // Populate global variables (maintains existing interface)
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, $dsn;
    
    $DB_HOST = (string)($config['DB_HOST'] ?? '');
    $DB_PORT = (int)($config['DB_PORT'] ?? 5432);
    $DB_NAME = (string)($config['DB_NAME'] ?? '');
    $DB_USER = (string)($config['DB_USER'] ?? '');
    $DB_PASS = (string)($config['DB_PASS'] ?? '');
    
    // Validate required keys
    $required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
    foreach ($required as $key) {
        if (empty($$key)) {  // Check that global var is not empty
            throw new RuntimeException("Missing or empty configuration: {$key}");
        }
    }
    
    // Build DSN (same as old conf.php)
    $dsn = "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME}";
}

// Call at app bootstrap (before PDO singleton or any DB query)
load_env_config();

// Rest of app uses $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, $dsn as before
?>
```

### `.env.example` Template (Committed to Git)

```ini
; Book Courts - Database Configuration
; 
; Instructions:
; 1. Copy this file to .env: cp .env.example .env
; 2. Edit .env with real database credentials
; 3. Restrict permissions: chmod 600 .env
; 4. NEVER commit .env to git (it's in .gitignore)

; PostgreSQL Connection Details
DB_HOST=localhost
DB_PORT=5432
DB_NAME=booking_db
DB_USER=app_user
DB_PASS=
```

### Scripted Credential Rotation (Documented Procedure)

```bash
#!/bin/bash
# Credential Rotation for Book Courts
# 
# Prerequisites:
# - PostgreSQL admin access (or access to ALTER ROLE user)
# - SSH access to app server (or FTP with chmod capability)
# - Maintenance window scheduled
# - New password generated and stored securely
#
# Usage: ./rotate-credentials.sh <old-pass> <new-pass>

set -e  # Exit on any error

if [ $# -ne 2 ]; then
    echo "Usage: $0 <old-password> <new-password>"
    exit 1
fi

OLD_PASS="$1"
NEW_PASS="$2"
POSTGRES_HOST="<db-host>"
POSTGRES_PORT="5432"
POSTGRES_ADMIN="postgres"
APP_USER="app_user"
APP_DIR="/path/to/book-courts"
ENV_FILE="$APP_DIR/.env"

echo "=== Credential Rotation for Book Courts ==="
echo "Old password will remain valid for 24h as rollback measure"
echo ""

# Step 1: Verify we can connect with old credential
echo "[1/5] Verifying old credential..."
psql -h "$POSTGRES_HOST" -p "$POSTGRES_PORT" -U "$APP_USER" -d booking_db \
    -c "SELECT 1;" > /dev/null || \
    { echo "ERROR: Cannot connect with old credential. Aborting."; exit 1; }
echo "  ✓ Old credential verified"

# Step 2: Change PostgreSQL password
echo "[2/5] Updating PostgreSQL role password..."
PGPASSWORD="$POSTGRES_ADMIN" psql -h "$POSTGRES_HOST" -p "$POSTGRES_PORT" \
    -U "$POSTGRES_ADMIN" -c \
    "ALTER ROLE $APP_USER WITH PASSWORD '$NEW_PASS';" || \
    { echo "ERROR: Failed to update PostgreSQL password. Aborting."; exit 1; }
echo "  ✓ PostgreSQL password updated"
echo "  ⚠ WARNING: App will now fail to connect until .env is updated"

# Step 3: Update .env file (atomic: create temp, move into place)
echo "[3/5] Updating .env file..."
TEMP_ENV=$(mktemp)
sed "s/^DB_PASS=.*/DB_PASS=$NEW_PASS/" "$ENV_FILE" > "$TEMP_ENV"
chmod 600 "$TEMP_ENV"
mv "$TEMP_ENV" "$ENV_FILE"
echo "  ✓ .env updated with new password"

# Step 4: Health check (verify app can connect)
echo "[4/5] Health check (verifying app can connect)..."
sleep 1  # Give app time to reload config on next request
curl -s "https://booking-app.example.com/index.php" > /dev/null || \
    { echo "ERROR: App cannot connect with new credential. Rolling back..."; \
      PGPASSWORD="$POSTGRES_ADMIN" psql -h "$POSTGRES_HOST" -p "$POSTGRES_PORT" \
          -U "$POSTGRES_ADMIN" -c \
          "ALTER ROLE $APP_USER WITH PASSWORD '$OLD_PASS';"; \
      sed -i "s/^DB_PASS=.*/DB_PASS=$OLD_PASS/" "$ENV_FILE"; \
      echo "  ROLLED BACK: Old credential restored."; exit 1; }
echo "  ✓ App is connecting successfully"

echo "[5/5] Rotation complete"
echo ""
echo "✓ SUCCESS: Credentials rotated"
echo ""
echo "Emergency rollback (if needed in next 24h):"
echo "  1. SSH to app server"
echo "  2. Edit .env, set DB_PASS=$OLD_PASS"
echo "  3. App will reconnect on next request"
echo ""
echo "After 24h, to fully revoke old password (optional):"
echo "  PGPASSWORD=$POSTGRES_ADMIN psql -h $POSTGRES_HOST -U $POSTGRES_ADMIN -c \"ALTER ROLE $APP_USER WITH PASSWORD NULL;\""
```

### Updated `index.php` / `admin.php` Bootstrap (Before: `require 'conf.php'`)

```php
<?php
declare(strict_types=1);
session_start();

// OLD (to be removed):
// require 'conf.php';

// NEW (replaces conf.php):
require_once 'includes/config.php';  // Loads .env, populates $DB_HOST, $DB_PASS, etc.

// Rest of index.php / admin.php continues unchanged
// All existing code that uses $DB_HOST, $dsn, etc. works as before
?>
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|---|---|---|---|
| Plaintext `conf.php` committed to git with real credentials | `.env` file (gitignored, chmod 600) loaded via native `parse_ini_file()` | 2026-07-27 (this phase) | Credentials no longer in git history; single-use `conf.php` replacement approach is simpler than introducing a full secrets manager for a low-traffic app |
| Manual credential updates via SSH edits to `conf.php` | Scripted, coordinated credential rotation (Postgres + .env updated together) | 2026-07-27 (this phase) | Rotation is documented, repeatable, and prevents the "two-system drift" outage; emergency rollback procedure built in |
| No documented config loading pattern | `.env` loader function as single bootstrap entrypoint | 2026-07-27 (this phase) | Clear separation of "load config" from "use config"; easier to test and debug; foundation for future multi-environment configs if needed |

## Open Questions

1. **Actual production PHP minor version**
   - What we know: App requires PHP 8.0+; STACK.md noted this was unconfirmed at time of research
   - What's unclear: Is production running PHP 8.1, 8.2, 8.3, or later? This doesn't affect `.env` loading (parse_ini_file is stable across all versions), but matters for Phase 2 (PHPUnit version choice)
   - Recommendation: Verify `php -v` on production server. Doesn't block Phase 1, but helps finalize Phase 2 plan. Low priority for this phase.

2. **Shell / vhost access on hosting**
   - What we know: CONTEXT.md noted hosting access level is unconfirmed; in-webroot `.env` placement was chosen as the safe default (works for all hosting tiers)
   - What's unclear: Can the team place files outside the webroot if desired in the future?
   - Recommendation: Ask hosting provider if shell/vhost access is available. Not required for Phase 1 (in-webroot works), but cost-zero future hardening if confirmed.

3. **Credential rotation frequency**
   - What we know: Rotation procedure is part of Phase 1 (migrate from conf.php to .env); future rotations (e.g., annually, or after a security incident) will use the same runbook
   - What's unclear: What's the intended rotation policy after this migration? (Annual? Only after breaches? Whenever someone with shell access leaves the team?)
   - Recommendation: Document rotation policy in a README or operations guide. Doesn't affect this phase's implementation.

## Validation Architecture

**Skipped** — per `.planning/config.json`, `workflow.nyquist_validation` is set to `false`. No test framework research or validation mapping required for Phase 1.

## Sources

### Primary (HIGH confidence)

- [PHP Official Manual: parse_ini_file()](https://www.php.net/manual/en/function.parse-ini-file.php) — Verified parse_ini_file behavior, edge cases, and flags
- [Mastering `.env` Files in PHP: Concepts, Tools, and Best Practices — DEVSENSE Blog](https://blog.devsense.com/2025/mastering-.env-files-in-php-concepts-tools-and-pra/) — Verified `.env` format, best practices, and comparison with alternatives
- [PHP.Watch: Security Considerations When Parsing User-Provided INI Strings and Files](https://php.watch/articles/parse_ini_string-file-security-considerations) — Security edge cases and escaping behavior
- `.planning/research/STACK.md` (2026-07-27) — Verified parse_ini_file as standard choice, confirmed phpdotenv alternatives, `.htaccess` anti-patterns
- `.planning/research/PITFALLS.md` (2026-07-27) — Verified credential rotation outage risk, dual-role alternatives, scripted rotation as best practice
- `.planning/codebase/CONCERNS.md` (2026-07-27) — Verified original hardcoded credentials risk and .htaccess protection patterns

### Secondary (MEDIUM confidence)

- [PostgreSQL ALTER ROLE: Practical Role Management in 2026 — TheLinuxCode](https://thelinuxcode.com/postgresql-alter-practical-role-management-in-2026/) — Verified `ALTER ROLE PASSWORD` syntax and rotation mechanics
- [PostgreSQL Credentials Rotation — Infisical](https://infisical.com/docs/documentation/platform/secret-rotation/postgres-credentials) — Verified dual-credential rotation pattern as alternative (noted as not needed for this phase)
- [Infisical Dual Password Rotation](https://www.linkedin.com/pulse/dual-password-rotation-secure-databases-zero-downtime-mark-kleinhaus-r9xhc) — Verified zero-downtime rotation pattern as reference
- [Apache Security: Hiding .env Files using .htaccess — Web Niraj](https://www.webniraj.com/2024/09/12/hiding-env-files-using-htaccess/) — Verified `.htaccess` rules for blocking `.env` access
- [Preventing .env File Public Access — Laracasts](https://laracasts.com/discuss/channels/servers/prevent-env-file-from-been-accessed-on-a-server) — Verified defense-in-depth approach (gitignore + chmod + .htaccess)
- [chmod 600 Security Explained — SiliconBased](https://siliconbased.dev/chmod/600) — Verified chmod 600 as standard protection for secrets
- [Best Practices with Environment Variable Files (.env) in SDLC — Stackademic](https://blog.stackademic.com/best-practices-with-environment-variable-files-env-in-sdlc-25806194d438) — Verified `.env.example` template best practices and validation patterns

---

**Research completed:** 2026-07-27
**Domain:** PHP configuration management, PostgreSQL credential rotation, Apache web security
**Valid until:** 2026-08-27 (30 days — stable domain, no rapid version churn)
