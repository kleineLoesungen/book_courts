# Book Courts

## What This Is

A PHP/PostgreSQL web app for booking sports courts (e.g. a club's tennis/badminton courts). Members log in to see a week/day view of court availability, create single bookings within a 30-minute grid, and admins can create recurring booking series, manage users and courts, and cancel bookings. The app already runs — it's an existing, working monolith (`index.php`, `admin.php`, `pg.sql`) that needs hardening rather than a from-scratch build.

## Core Value

Members can reliably book a court for a specific time without double-booking conflicts, and admins can manage the full roster of bookings, series, users, and courts.

## Requirements

### Validated

- ✓ User login/logout with session-based auth — existing
- ✓ Week view of bookings with day-level detail — existing
- ✓ Single booking creation with 30-minute grid validation — existing
- ✓ Real-time court availability lookup via AJAX (`free_courts`) — existing
- ✓ Recurring series creation with conflict preview (`series_preview`) and materialization — existing
- ✓ Booking/series cancellation — existing
- ✓ Separate admin login and admin overview of all bookings/series (grouped by month) — existing
- ✓ Admin CRUD for users and courts — existing
- ✓ CSRF protection on all POST actions — existing
- ✓ Database-enforced conflict prevention (advisory locks + overlap trigger) — existing
- ✓ Timezone-consistent handling (Europe/Berlin) — existing

### Active

- [ ] Rotate and secure the exposed database credentials; move config out of a plaintext, world-readable-by-default file
- [ ] Add session cookie security flags (HttpOnly, Secure, SameSite)
- [ ] Add brute-force protection on login (rate limiting / lockout after repeated failures)
- [ ] Add automated test coverage for booking conflict detection and series materialization (currently zero tests on the highest-risk logic)
- [ ] Deduplicate shared helpers (`pdo()`, `csrf_token()`, `h()`, `flash()`, auth checks) currently copy-pasted between `index.php` and `admin.php`
- [ ] Add user self-service password reset and change-password
- [ ] Add basic audit trail / logging for bookings and admin actions
- [ ] Add session timeout for inactive sessions
- [ ] Verify/complete the `materialize_series()` implementation and add regression coverage for it (research flagged it as truncated in the current file)

### Out of Scope

- Multi-timezone / non-Europe/Berlin deployment — single club, single location; not worth the redesign
- Native mobile app — web-first is sufficient for a club booking tool
- Payment processing — courts are booked, not paid for, through this system
- Self-hosting Tailwind/Lucide (removing CDN dependency) — acceptable risk for a low-traffic internal tool, revisit only if it causes real outages
- Multi-database support (MySQL abstraction) — PostgreSQL-only is an intentional simplification

## Context

- This is a brownfield project. `.planning/codebase/` contains the full mapper output (STACK.md, ARCHITECTURE.md, STRUCTURE.md, CONVENTIONS.md, TESTING.md, INTEGRATIONS.md, CONCERNS.md) — read these before planning any phase.
- Stack: PHP 8+ (strict types), PostgreSQL 12+ with PL/pgSQL stored functions/triggers, vanilla JS, Tailwind CSS via CDN, Apache + `.htaccess`. No package manager, no build step, no test framework currently installed.
- The codebase is two large monolithic entry points (`index.php` ~1552 lines, `admin.php` ~734 lines) plus `pg.sql` (schema/functions) and `conf.php` (DB config).
- `conf.php` contains a real, plaintext production database password and was previously untracked but unprotected by `.gitignore` — this has been fixed (added to `.gitignore` in this session), but the credential itself should still be rotated since it lived in the working directory unprotected.
- Full concern inventory (security, tech debt, bugs, performance, scaling) is in `.planning/codebase/CONCERNS.md` — the Active requirements above are the highest-priority subset of that list.

## Constraints

- **Tech stack**: PHP + PostgreSQL, no framework — keep changes compatible with the existing PDO/stored-procedure architecture rather than introducing an ORM or framework migration
- **No package manager**: Composer is not currently used; introducing dependencies (e.g. a test framework) needs a deliberate, minimal setup
- **Single deployment target**: Apache + mod_php via `.htaccess`, no containerization currently in place

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Treat existing codebase as the source of truth instead of writing a fresh idea doc | Substantial working app already exists (2500+ lines); re-speccing from scratch would ignore real, validated behavior | — Pending |
| Prioritize security/reliability hardening over new user-facing features for v1 | CONCERNS.md surfaced critical gaps (plaintext credentials, no tests on conflict-critical logic, no brute-force protection) that are riskier than missing features | — Pending |
| Added `conf.php` to `.gitignore` immediately | Real plaintext DB password was sitting untracked and unprotected in the working directory | ✓ Good |

---

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd:transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd:complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-07-27 after initialization*
