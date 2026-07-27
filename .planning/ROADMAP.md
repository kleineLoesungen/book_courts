# Roadmap: Book Courts (Hardening Milestone)

## Overview

Book Courts is a working, single-club court-booking app that needs hardening, not a rewrite. This milestone closes the highest-priority gaps identified in `.planning/codebase/CONCERNS.md` and `.planning/research/`: exposed plaintext credentials, zero test coverage on conflict-critical logic, duplicated helpers across the two entry points, weak session handling, no brute-force protection, no self-service password change, and no audit trail. The five phases below follow the dependency chain the research surfaced: config must be extracted before anything else reads from it; automated tests must exist before duplicated logic is touched, so extraction can be verified against a green suite instead of by hand; the realm-aware auth extraction is called out as its own deliberate reconciliation step (not a mechanical copy-paste) because `index.php` and `admin.php` currently use identically-named functions that check different session keys; and the new cross-cutting security features (session hardening, brute-force protection, self-service password change, audit trail) land last, once the shared `includes/` layer they depend on is stable.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [ ] **Phase 1: Configuration & Secrets Hardening** - Database credentials move out of plaintext `conf.php` into an untracked `.env`, and the exposed production password is rotated.
- [ ] **Phase 2: Automated Test Coverage & Series Verification** - Booking conflict detection and series materialization get regression tests, and `materialize_series()` is verified/completed against the 30-minute grid and date-range rules.
- [ ] **Phase 3: Code Consolidation - Shared Helpers & Realm-Aware Auth** - Duplicated helpers are extracted into shared includes, and member/admin login checks are deliberately reconciled into realm-scoped functions.
- [ ] **Phase 4: Session Security & Brute-Force Protection** - Session cookies are hardened, idle/absolute session timeouts are enforced, and repeated failed logins lock out the offending account without affecting the rest of the club.
- [ ] **Phase 5: Self-Service Password Management & Audit Trail** - Users can change their own password, and every sensitive action across the app is recorded in an audit log.

## Phase Details

### Phase 1: Configuration & Secrets Hardening
**Goal**: Database credentials and other config load from an untracked `.env` file instead of plaintext, committed `conf.php`, and the previously-exposed production password has been rotated on the actual PostgreSQL server.
**Depends on**: Nothing (first phase)
**Requirements**: CONF-01, CONF-02
**Success Criteria** (what must be TRUE):
  1. The application connects to PostgreSQL using credentials loaded from a `.env` file that is not committed to the working tree.
  2. The live production database password is different from the one that was previously exposed, and the app is verified working end-to-end against the new credential.
  3. A committed `.env.example` documents every required config key without containing a real secret.
  4. `conf.php` no longer contains a live credential (deprecated or removed).
**Plans**: 2 plans
Plans:
- [ ] 01-01-PLAN.md — Build the .env loader, template, and protection layer; wire index.php/admin.php to it and retire conf.php (CONF-01)
- [ ] 01-02-PLAN.md — Rotate the production PostgreSQL password and deploy the matching .env, verified end-to-end (CONF-02)

### Phase 2: Automated Test Coverage & Series Verification
**Goal**: The two highest-risk pieces of business logic — booking conflict detection and recurring series materialization — have automated regression coverage, and `materialize_series()` is confirmed complete and correct.
**Depends on**: Phase 1 (a test run needs a working config/DB connection to bootstrap against)
**Requirements**: TEST-01, TEST-02, TEST-03, REFAC-03
**Success Criteria** (what must be TRUE):
  1. PHPUnit runs against this codebase (as a PHAR or dev-only dependency) without requiring a package-manager migration for production.
  2. Automated tests cover overlapping booking time spans and Europe/Berlin DST boundary cases, and all pass against current, verified-correct behavior.
  3. Automated tests cover series materialization across series end-dates and long-running series.
  4. `materialize_series()` has been reviewed against the 30-minute grid and date-range rules, any truncation or bugs found are fixed, and the fix is covered by a passing test.
**Plans**: TBD

### Phase 3: Code Consolidation - Shared Helpers & Realm-Aware Auth
**Goal**: Shared logic duplicated between `index.php` and `admin.php` lives in one place, and member/admin login-check logic is deliberately reconciled — not merged — into realm-scoped functions that preserve today's session separation.
**Depends on**: Phase 2 (extraction and reconciliation must be verified against a green test suite, not by hand)
**Requirements**: REFAC-01, REFAC-02
**Success Criteria** (what must be TRUE):
  1. `pdo()`, `csrf_token()`/`csrf_check()`, `h()`, and `flash()`/`render_flash()` each exist in exactly one shared include, required by both entry points.
  2. Member and admin login checks are separate, realm-scoped functions (e.g. `user_is_logged_in()`, `admin_is_logged_in()`) that preserve the existing separation between member and admin sessions.
  3. The full Phase 2 test suite passes unchanged after consolidation, confirming no behavior regression.
  4. Neither `index.php` nor `admin.php` contains a duplicate definition of any extracted helper.
**Plans**: TBD

### Phase 4: Session Security & Brute-Force Protection
**Goal**: Sessions are hardened against hijacking and staleness, and login endpoints resist repeated-guessing attacks without locking out the whole club from a shared network.
**Depends on**: Phase 3 (session and login code paths now live in the shared includes layer)
**Requirements**: SESS-01, SESS-02, SESS-03, AUTH-02
**Success Criteria** (what must be TRUE):
  1. Session cookies are set with `HttpOnly`, `Secure` (verified against the real production HTTPS config), and `SameSite=Lax`, confirmed by testing login via direct visit, bookmark, and external referrer.
  2. A user idle longer than the configured inactivity window is automatically logged out on their next request.
  3. A session older than the configured absolute maximum lifetime is ended regardless of activity.
  4. Repeated failed logins on one account lock out that account specifically (not other accounts sharing the same IP/network), with a documented admin override/unlock path.
**Plans**: TBD

### Phase 5: Self-Service Password Management & Audit Trail
**Goal**: Users can manage their own password without admin help, and every sensitive action across bookings, series, admin CRUD, and auth is recorded for later review.
**Depends on**: Phase 3 (needs realm-scoped session/user identity for attribution), Phase 4 (password change should regenerate the session ID using the hardened session layer)
**Requirements**: AUTH-01, AUDIT-01
**Success Criteria** (what must be TRUE):
  1. A logged-in user can change their own password by supplying their current password plus a new one, and their session ID is regenerated afterward.
  2. An incorrect current-password attempt is rejected with a generic error and does not change the stored password.
  3. Login success/failure, booking create/cancel, series create/cancel, admin user/court CRUD, and password changes each write a row to an `audit_log` table capturing who, what, and when.
  4. `audit_log` is directly queryable via SQL, even without a dedicated admin viewer UI.
**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Configuration & Secrets Hardening | 0/2 | Not started | - |
| 2. Automated Test Coverage & Series Verification | 0/TBD | Not started | - |
| 3. Code Consolidation - Shared Helpers & Realm-Aware Auth | 0/TBD | Not started | - |
| 4. Session Security & Brute-Force Protection | 0/TBD | Not started | - |
| 5. Self-Service Password Management & Audit Trail | 0/TBD | Not started | - |

---
*Roadmap created: 2026-07-27*
</content>
