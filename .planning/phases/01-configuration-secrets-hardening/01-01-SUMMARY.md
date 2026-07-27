---
phase: 01-configuration-secrets-hardening
plan: 1
subsystem: infra
tags: [php, postgresql, dotenv, config, secrets, htaccess, gitignore]

# Dependency graph
requires: []
provides:
  - "config.php — .env loader (load_env_config()) populating $DB_HOST/$DB_PORT/$DB_NAME/$DB_USER/$DB_PASS/$dsn globals"
  - ".env — real runtime DB config, gitignored, chmod 600, migrated from conf.php without corruption"
  - ".env.example — committed template documenting all five required keys with no real values"
  - "conf.php reduced to a comment-only stub — no live credential, kept as a file so its .htaccess protection stays meaningful"
  - "index.php/admin.php now bootstrap DB config via config.php instead of conf.php"
affects: [01-configuration-secrets-hardening/01-02 (credential rotation plan depends on the app reading from .env)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Global-variable config loader: load_env_config() populates the same $DB_* / $dsn globals conf.php used to define directly, so pdo() and all callers are unchanged"
    - "INI-based .env parsing via parse_ini_file() with double-quoting for values containing ;, quotes, backslashes, whitespace, or non-ASCII bytes"

key-files:
  created: [config.php, .env.example, .env]
  modified: [.gitignore, .htaccess, index.php, admin.php, conf.php]

key-decisions:
  - "conf.php kept as a stub file (not deleted) so the pre-existing .htaccess FilesMatch rule for it stays meaningful (D-03 from plan/context)"
  - "conf.php remains gitignored and untracked in git, consistent with the existing project decision to exclude it from version control entirely — only its local stub content changed"
  - ".env migrated programmatically via a PHP one-liner reading conf.php directly, so the real DB_PASS value was never printed to a terminal transcript, plan file, or chat output"

requirements-completed: [CONF-01]

# Metrics
duration: 14min
completed: 2026-07-27
---

# Phase 01 Plan 01: .env-based configuration loader Summary

**Replaced plaintext, git-adjacent `conf.php` DB credentials with a `.env`-based loader (`config.php`) wired into both `index.php` and `admin.php`, with `conf.php` reduced to a harmless stub.**

## Performance

- **Duration:** 14 min
- **Started:** 2026-07-27T20:44:00Z
- **Completed:** 2026-07-27T20:58:17Z
- **Tasks:** 2 completed
- **Files modified:** 7 (config.php, .env, .env.example, .gitignore, .htaccess, index.php, admin.php, conf.php)

## Accomplishments
- `config.php` now loads DB credentials from an untracked, `chmod 600` `.env` file via `load_env_config()`, populating the exact same globals (`$DB_HOST`, `$DB_PORT`, `$DB_NAME`, `$DB_USER`, `$DB_PASS`, `$dsn`) that `conf.php` used to define directly — zero changes needed to `pdo()` or any downstream caller.
- Real production DB values migrated from `conf.php` into `.env` via a PHP one-liner that never printed the password to any output, with automatic double-quoting for the value's embedded `;` and non-ASCII character (which would otherwise have been silently truncated by `parse_ini_file()`'s INI comment-marker parsing).
- `.env.example` committed as a template documenting all five required keys with no real values.
- `.gitignore` and `.htaccess` both extended to cover `.env` alongside the pre-existing `conf.php`/`pg.sql` protections (defense-in-depth: gitignore + chmod 600 + HTTP deny).
- `index.php` and `admin.php` now `require_once __DIR__ . '/config.php'` instead of `conf.php`; `conf.php` itself reduced to a comment-only stub with no `$DB_*` assignments.

## Task Commits

Each task was committed atomically:

1. **Task 1: Build the .env loader, template, and protection layer** - `b12223a` (feat)
2. **Task 2: Wire index.php/admin.php to the new loader and retire conf.php** - `4aef3ac` (feat)

**Plan metadata:** (pending — final docs commit follows this summary)

## Files Created/Modified
- `config.php` - New `.env` loader; `load_env_config()` validates presence of all 5 required keys, throws `RuntimeException` with actionable message if `.env` is missing/incomplete
- `.env` - Real runtime DB config (gitignored, `chmod 600`), migrated verbatim from `conf.php` (byte-for-byte match verified without printing the secret)
- `.env.example` - Committed template with all 5 keys present and empty, plus quoting guidance for special characters
- `.gitignore` - Added `.env` alongside existing `conf.php` entry
- `.htaccess` - Extended `FilesMatch` pattern from `^(conf\.php|pg\.sql)$` to `^(\.env|conf\.php|pg\.sql)$`
- `index.php` - Line 14: `require_once __DIR__ . '/conf.php'` → `require_once __DIR__ . '/config.php'`
- `admin.php` - Line 14: same change as index.php
- `conf.php` - Replaced with a comment-only deprecation stub; no `$DB_*` variables or DSN remain

## Decisions Made
- Kept `conf.php` as a stub file rather than deleting it, so the existing `.htaccess` rule protecting that filename continues to protect a real file (per plan's D-03 and CONTEXT.md).
- Left `conf.php` untracked/gitignored in git (consistent with the pre-existing project decision logged in PROJECT.md to exclude it from version control) — only its on-disk stub content changed, no git history exists for it either way.
- Migrated the real secret via a PHP script rather than manual retyping, eliminating any risk of the password appearing in a shell history, plan file, or chat transcript.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None. All automated verification commands (per-task and plan-level) passed on the first attempt, including the functional smoke test confirming `.env` → `config.php` → `$dsn`/`$DB_*` globals resolve end-to-end.

## User Setup Required

None - no external service configuration required. `.env` was generated automatically from the existing `conf.php` values already present in the working directory; no new secrets need to be obtained.

## Next Phase Readiness

- Plan 01-02 (credential rotation, CONF-02) can now proceed: the app reads DB credentials exclusively from `.env`, so rotating the actual production password is a matter of updating `.env`'s `DB_PASS` value and testing connectivity — no code changes required.
- No blockers identified.

---
*Phase: 01-configuration-secrets-hardening*
*Completed: 2026-07-27*

## Self-Check: PASSED

All claimed files exist on disk (config.php, .env, .env.example, .gitignore, .htaccess, index.php, admin.php, conf.php, this SUMMARY.md) and both task commits (`b12223a`, `4aef3ac`) are present in git history.
