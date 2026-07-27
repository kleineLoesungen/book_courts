# Requirements: Book Courts (Hardening Milestone)

**Defined:** 2026-07-27
**Core Value:** Members can reliably book a court for a specific time without double-booking conflicts, and admins can manage the full roster of bookings, series, users, and courts.

## v1 Requirements

This milestone hardens an already-live app. Every requirement below targets a specific gap identified in `.planning/codebase/CONCERNS.md` and `.planning/research/`.

### Configuration & Secrets

- [x] **CONF-01**: Database credentials and other config load from a `.env` file (not committed, outside or protected within webroot) instead of plaintext `conf.php` committed to the working tree
- [ ] **CONF-02**: The exposed database password is rotated on the actual PostgreSQL server as part of this change (not just moved to a new file)

### Session Security

- [ ] **SESS-01**: Session cookies are set with `HttpOnly`, `Secure` (when HTTPS), and `SameSite=Lax`
- [ ] **SESS-02**: An idle session is automatically ended after a period of inactivity (app-enforced via a tracked last-activity timestamp, not reliant on PHP's garbage-collection settings)
- [ ] **SESS-03**: A session is automatically ended after an absolute maximum lifetime regardless of activity

### Authentication

- [ ] **AUTH-01**: A logged-in user can change their own password (current password + new password), without needing an admin
- [ ] **AUTH-02**: Repeated failed login attempts on an account are rate-limited/locked out (keyed per-account, not per-IP, so one member's lockout doesn't affect the whole club on a shared network), with an admin override path

### Code Quality (Refactor)

- [ ] **REFAC-01**: Shared helpers (`pdo()`, `csrf_token()`/`csrf_check()`, `h()`, `flash()`/`render_flash()`) are extracted once into shared includes instead of duplicated between `index.php` and `admin.php`
- [ ] **REFAC-02**: User-session and admin-session login checks are extracted into realm-scoped functions (not merged into one), preserving the existing separation between member and admin sessions
- [ ] **REFAC-03**: `materialize_series()` is verified complete and correct (the current file was flagged as possibly truncated) with behavior confirmed against the 30-minute grid and date-range rules

### Test Coverage

- [ ] **TEST-01**: A test framework (PHPUnit) runs against this codebase without requiring a package manager migration
- [ ] **TEST-02**: Booking conflict detection has automated test coverage, including overlapping time spans and timezone/DST boundary cases
- [ ] **TEST-03**: Series materialization has automated test coverage, including series end-dates and long-running series

### Audit Trail

- [ ] **AUDIT-01**: A flat `audit_log` table records who did what and when for: login success/failure, booking create/cancel, series create/cancel, admin user/court CRUD, and password changes

## v2 Requirements

Deferred to future release. Tracked but not in current roadmap.

### Authentication

- **AUTH-03**: Self-service "forgot password" via emailed reset link — blocked on an explicit decision about mail delivery (PHP `mail()` vs. PHPMailer-without-Composer vs. staying admin-mediated); the app has no outbound email capability today

### Audit Trail

- **AUDIT-02**: Admin-facing audit log viewer (filter by user/date/action) — the raw table is queryable from day one; this is UX polish
- **AUDIT-03**: Feed failed-login rows from the audit log into the brute-force lockout counter (currently a separate, simpler counter)

### Authentication Hardening

- **AUTH-04**: Password breach-list screening (HIBP range API k-anonymity check)
- **AUTH-05**: Email notification on password change/reset ("if this wasn't you...") — depends on the same mailer decision as AUTH-03

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Security questions as password-reset fallback | NIST SP 800-63B-4 and OWASP explicitly prohibit knowledge-based authentication (guessable/phishable) |
| Forced periodic password rotation + composition rules (upper/lower/digit/special) | Current NIST guidance (2025) recommends against this; pushes users toward predictable patterns without security benefit. Minimum length is used instead |
| Two-factor authentication (2FA/MFA) | Disproportionate cost for a closed-membership club where every account is already admin-vetted at creation; the account-creation step is the real trust boundary |
| Full RBAC / granular permission system | Only two roles exist and are needed (member, admin); already cleanly separated |
| SSO / OAuth login | Adds external identity-provider integration for a few dozen people who already get accounts provisioned by an admin |
| Immutable/tamper-proof audit log (external SIEM, log shipping) | Massive overkill for a single-club app; a determined DB-level attacker already has full data access regardless |
| Concurrent-session limiting ("log out all other devices") | The actual risk (stale session on shared device) is already covered by SESS-02/SESS-03 |
| "Remember me" persistent login | Adds a parallel long-lived-token attack surface for marginal convenience in a 2-minute booking flow |
| Framework migration / ORM adoption | Would bypass the PDO/trigger/advisory-lock-based conflict guarantees that are this app's core value |
| Multi-timezone support | Single club, single location — not worth the redesign |
| Native mobile app | Web-first is sufficient for a club booking tool |
| Payment processing | Courts are booked, not paid for, through this system |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| CONF-01 | Phase 1 | Complete |
| CONF-02 | Phase 1 | Pending |
| TEST-01 | Phase 2 | Pending |
| TEST-02 | Phase 2 | Pending |
| TEST-03 | Phase 2 | Pending |
| REFAC-03 | Phase 2 | Pending |
| REFAC-01 | Phase 3 | Pending |
| REFAC-02 | Phase 3 | Pending |
| SESS-01 | Phase 4 | Pending |
| SESS-02 | Phase 4 | Pending |
| SESS-03 | Phase 4 | Pending |
| AUTH-02 | Phase 4 | Pending |
| AUTH-01 | Phase 5 | Pending |
| AUDIT-01 | Phase 5 | Pending |

**Coverage:**
- v1 requirements: 14 total
- Mapped to phases: 14 (100%)
- Unmapped: 0

---
*Requirements defined: 2026-07-27*
*Last updated: 2026-07-27 after roadmap creation*
</content>
