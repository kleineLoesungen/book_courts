# Phase 1: Configuration & Secrets Hardening - Context

**Gathered:** 2026-07-27 (auto mode — recommended defaults selected without interactive prompts)
**Status:** Ready for planning

<domain>
## Phase Boundary

Database credentials and other config move out of plaintext, committed `conf.php` into an untracked `.env` file, and the previously-exposed production database password is rotated on the actual PostgreSQL server. This phase does NOT touch session security, testing, helper deduplication, or any other Active requirement — those are later phases.

</domain>

<decisions>
## Implementation Decisions

### .env location & protection
- **D-01:** `.env` lives in the project root (same level as `conf.php` today), is added to `.gitignore` (already done for `conf.php` in this session — extend to `.env`), and is `chmod 600`.
- [auto] Area: ".env location" — Q: "Store .env inside webroot (gitignored + chmod 600) or outside webroot (needs confirmed shell/vhost access)?" → Selected: "Inside webroot, gitignored + chmod 600" (recommended default — research flagged hosting shell/vhost access level as unconfirmed; the in-webroot option works regardless of hosting tier, matches the app's current deployment model of file-based-only access, and can be moved outside webroot later at zero cost if shell access is confirmed).

### Credential rotation mechanics
- **D-02:** Rotation is a single coordinated step: generate a new PostgreSQL role password (`ALTER ROLE ... PASSWORD ...`) and update `.env` in the same maintenance action, treated as a documented runbook step rather than a zero-downtime dual-role cutover mechanism.
- [auto] Area: "Credential rotation approach" — Q: "Build an automated zero-downtime dual-role cutover, or do a coordinated single-step rotation (brief acceptable downtime)?" → Selected: "Coordinated single-step rotation" (recommended default — this is a single-club, low-traffic booking app; PITFALLS.md's warning is about the two systems drifting out of sync, not about avoiding all downtime. A documented runbook — update Postgres role password, immediately update `.env`, restart/verify — fully addresses the risk without building rotation tooling disproportionate to this app's scale).

### conf.php disposition
- **D-03:** `conf.php` is fully replaced — it no longer defines `$DB_HOST`/`$DB_PORT`/`$DB_NAME`/`$DB_USER`/`$DB_PASS`/`$dsn` with real values. A new config-loading function (reading `.env`) becomes the single source these variables come from, included by both `index.php` and `admin.php`. `conf.php` itself may be deleted or reduced to nothing meaningful — no backward-compatible shim is needed since both entry points are updated together in this phase.
- [auto] Area: "conf.php disposition" — Q: "Delete conf.php entirely, or keep it as a thin shim for compatibility?" → Selected: "Delete/replace entirely" (recommended default — no external code depends on `conf.php`'s specific filename or variable names outside `index.php`/`admin.php`, both of which are touched in this same phase, so no compatibility shim is needed).

### .env scope
- **D-04:** `.env` for this phase holds only what `conf.php` already defines today (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`) plus a `.env.example` documenting each key without real values. No other config (e.g., future mailer settings for v2's password reset) is added preemptively.
- [auto] Area: ".env scope" — Q: "Migrate only existing DB config, or also scaffold config keys for known future needs (e.g., mailer settings)?" → Selected: "Only existing DB config" (recommended default — scope guardrail: this phase's boundary is the existing `conf.php` contents; provisioning keys for v2 features not yet decided would be scope creep and premature).

### Claude's Discretion
- Exact `.env` parsing implementation (hand-rolled `parse_ini_file()`-based loader per STACK.md recommendation)
- Whether to fully delete `conf.php` or leave an empty/comment-only file — functionally equivalent, pick whichever keeps `.htaccess`'s existing `FilesMatch "^(conf\.php|pg\.sql)$"` block still meaningful
- Exact `.env.example` formatting/comments

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Current state (what's being replaced)
- `conf.php` — current plaintext config file with real DB credentials (has real production password today; DO NOT copy actual credential values into any new document)
- `.htaccess` — existing `FilesMatch` rule blocking direct HTTP access to `conf.php`/`pg.sql`; must stay meaningful after this phase

### Research
- `.planning/research/STACK.md` — recommends native `parse_ini_file()` over `vlucas/phpdotenv` (zero dependencies vs. 4 transitive packages for a single config file); explicit "what NOT to use" section warns against storing secrets via `.htaccess` `SetEnv`
- `.planning/research/PITFALLS.md` — "Credential rotation is a two-system atomic operation, not one action" — Postgres role password and `.env` must flip together or the app 500s on every request
- `.planning/research/SUMMARY.md` — Phase 1 sequencing rationale: config must be extracted first since every later phase (tests, session hardening) reads from it
- `.planning/codebase/CONCERNS.md` — original finding: "CRITICAL: Hardcoded Database Credentials" with explicit remediation recommendations (this phase closes that finding)
- `.planning/codebase/INTEGRATIONS.md` — "Secrets location" section describing current `conf.php` exposure

### Requirements
- `.planning/REQUIREMENTS.md` — CONF-01, CONF-02

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- None yet — this phase creates the first shared config-loading pattern in the app. It becomes a reusable asset for later phases (helper extraction in Phase 3 will build on this).

### Established Patterns
- `conf.php` currently defines five global PHP variables (`$DB_HOST`, `$DB_PORT`, `$DB_NAME`, `$DB_USER`, `$DB_PASS`) plus a derived `$dsn` string; both `index.php` and `admin.php` `require` it and construct their own PDO singleton from those globals. The replacement should preserve this same "global variables consumed by a `pdo()` factory function" shape — don't introduce a config object/class, since that's a structural change beyond this phase's scope.

### Integration Points
- `index.php` (lines ~19-30) and `admin.php` (lines ~19-30) both `require 'conf.php'` and build a PDO connection from the resulting globals — both files need their `require` updated to point at the new config loader.
- `.htaccess` already blocks direct HTTP access to `conf.php` and `pg.sql` by name — if `.env` is added, its filename should be added to that `FilesMatch` pattern too (even though `.gitignore` and file permissions are the primary protection, defense in depth costs nothing here).

</code_context>

<specifics>
## Specific Ideas

No specific requirements beyond the decisions above — auto mode selected standard, research-backed defaults. Open to standard approaches for the exact loader implementation.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope. (Mailer/email config for self-service password reset is explicitly deferred to v2 per REQUIREMENTS.md AUTH-03, and D-04 above confirms it is NOT scaffolded in this phase's `.env`.)

</deferred>

---

*Phase: 01-configuration-secrets-hardening*
*Context gathered: 2026-07-27*
