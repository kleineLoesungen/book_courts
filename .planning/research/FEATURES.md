# Feature Research

**Domain:** Account/session hardening for a small self-hosted PHP + PostgreSQL court-booking app (single club, ~2 entry points, no framework, no package manager)
**Researched:** 2026-07-27
**Confidence:** HIGH (OWASP Cheat Sheets + NIST SP 800-63B-4 are authoritative and current; PHP-specific mechanics verified against multiple independent sources)

## Context Recap

The app already has: session-based login for users and admins (separate `$_SESSION['user']` / `$_SESSION['admin_user']`), `password_hash()`/`password_verify()`, CSRF tokens, flash messaging, and admin CRUD for users (admin can already set/overwrite a user's password by editing the user record). It has **no** email-sending capability today, **no** logging/audit trail, **no** brute-force protection, and **no** session security flags or timeout. This research covers the four features called out for this milestone: self-service password reset, change-password, audit trail/logging, and session timeout — scoped to "what's normal for an app this size," not a general auth-framework rewrite.

## Feature Landscape

### Table Stakes (Users Expect These)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Change password (logged-in, self-service) | Baseline security hygiene; currently only admin can change a user's password, which violates least-privilege and creates admin support burden | LOW | Form: current password + new password (+ confirm). Verify current via `password_verify()`, re-hash with `password_hash()`. No new infrastructure needed — reuses existing session/auth. Should invalidate/regenerate session ID after change (session fixation hygiene) per OWASP. |
| Session inactivity timeout | Club context often means shared/public devices (clubhouse PC, shared tablet); an unattended logged-in session exposes booking/member data indefinitely today | LOW | PHP's `session.gc_maxlifetime` is **not sufficient** — it only controls when the garbage collector *may* delete session files (probabilistic, not deterministic), so relying on `ini_set('session.gc_maxlifetime', ...)` alone does not reliably enforce a timeout. Correct pattern: store `$_SESSION['last_activity']` timestamp, check elapsed time on every request, `session_unset()` + `session_destroy()` + redirect-to-login if exceeded, otherwise update the timestamp. Add an **absolute** session lifetime as a second, independent cap (OWASP: absolute timeout should apply regardless of activity). |
| Session cookie security flags | Directly related to timeout/session hardening already flagged in CONCERNS.md; without `HttpOnly`/`Secure`/`SameSite` a stolen cookie or XSS gives a durable session, undermining any timeout logic | LOW | `session_set_cookie_params()` before `session_start()`. Bundle with the timeout work since both touch the same session bootstrap code in both entry points. |
| Self-service password reset (forgot password) | Users who forget their password today have no path except contacting an admin; acceptable for a very small club but becomes a real support burden and blocks users at the worst time (trying to book before courts fill up) | MEDIUM–HIGH | The hard part isn't the token logic (well-understood, see below) — it's that **this app has no outbound email capability yet**. Sending a reset link requires adding a mailer. See "Feature Dependencies" and "Notes on the email dependency" below for options that fit the "no package manager" constraint. |
| Basic audit trail for admin/security-relevant actions | Not something end users notice is *missing*, but the club (and PROJECT.md) explicitly need "who booked/canceled/changed what" — currently nobody can answer a dispute ("who canceled my recurring slot?") or investigate a suspicious login | LOW–MEDIUM | One `audit_log` table + a single `log_action()` helper called at each mutation point (booking create/cancel, series create/cancel, user/court CRUD, login success/failure, password change/reset). Best done *after* the helper-deduplication tech debt item, otherwise `log_action()` becomes a third copy-pasted function alongside `pdo()`/`csrf_token()`/`h()`. |

### Differentiators (Nice to Have, Not Required)

| Feature | Value Proposition | Complexity | Notes |
|---------|--------------------|------------|-------|
| Admin-facing audit log viewer (searchable/filterable UI) | Turns the raw audit table into something admins actually use day-to-day instead of querying via `psql` | LOW | Simple filtered table view in `admin.php` (by user, date range, action type). Purely additive once the table + logging calls exist. |
| Log failed login attempts specifically, feeding into brute-force lockout | Reuses the same audit table as evidence for the separately-planned rate-limiting/lockout feature, avoiding a second logging mechanism | LOW | Natural overlap with the already-active "brute-force protection" requirement — implement the `audit_log` insert for failed logins once, consume it from both features. |
| "Remember me" / persistent login | Convenience for members who book frequently from the same device | MEDIUM | Requires a separate long-lived, hashed, revocable token (never store the raw token or reuse the session ID) per OWASP guidance. Adds real attack surface (persistent-login token theft) for a marginal convenience gain in an app where a booking session is typically a 2-minute interaction. Recommend deferring past this milestone. |
| Password breach-list screening (e.g., k-anonymity check against Have I Been Pwned range API) | Current NIST SP 800-63B-4 (finalized 2025) explicitly recommends screening against breached/commonly-used credential lists instead of composition rules | LOW–MEDIUM | Optional outbound HTTPS call to the HIBP range API (no full password ever leaves the server — only a SHA-1 prefix). Nice differentiator once reset/change-password exist; not required for MVP of this milestone. |
| Notify user by email on password change/reset | Standard "security event" notification pattern; lets a user detect an unauthorized reset | LOW (once mailer exists) | Depends entirely on the same mailer decision as self-service reset — bundle if reset ships with email. |

### Anti-Features (Commonly Requested, Often Problematic for This App's Size)

| Feature | Why Requested | Why Problematic Here | Alternative |
|---------|---------------|------------------------|-------------|
| Security questions ("mother's maiden name") as a reset fallback | Feels like a natural backup when email isn't available | OWASP explicitly advises against knowledge-based authentication — answers are guessable/researchable/phishable, and NIST SP 800-63B-4 prohibits KBA outright as an authenticator | If email isn't available for some users, use the existing admin CRUD as the fallback: admin manually resets/sets a temporary password for that member (already possible today) |
| Forced periodic password rotation + complex composition rules (upper/lower/digit/special) | Feels like "obviously more secure"; CONCERNS.md itself recommends this | Current NIST SP 800-63B-4 (2025) explicitly says **no** forced composition rules and **no** arbitrary rotation schedule — evidence shows these rules push users toward predictable patterns (e.g. `Password1!` → `Password2!`) without a security benefit. Rotate only on suspected compromise | Enforce a **minimum length** (NIST recommends encouraging 12–16+ chars, minimum 8) and optionally screen against a breach list instead |
| Two-factor authentication (TOTP/SMS/email code) | Seen as the "gold standard" for auth hardening | High implementation cost (secret storage, backup codes, recovery flow, UI for enrollment) for a single club where admins already manually vet and create every account — the account-creation step itself is the trust boundary, not open self-signup | Defer indefinitely; revisit only if the app opens to self-registration or handles payments |
| Full RBAC / granular permission system | "Might need more roles later" | Only two roles exist and are needed (member, admin); admin/member is already cleanly separated via distinct session keys and `is_admin()` — a permissions matrix is solved-problem overkill | Keep the existing boolean admin flag; if a third role is ever truly needed, add it explicitly then |
| SSO / OAuth login (Google, Microsoft, etc.) | Convenience, "modern" login UX | Adds a whole external-identity-provider integration, consent flows, and account-linking edge cases for a closed membership of a few dozen people who already get accounts provisioned by an admin | Keep local password auth; it's already the right fit for a closed-membership app |
| Immutable/tamper-proof audit log (write-once storage, external SIEM, log shipping) | "Real" audit trails are immutable | Massive overkill for a single-club app with a handful of admins; adds infrastructure (external log store, shipping pipeline) with no proportionate threat model | A plain `audit_log` table with insert-only application discipline (no UPDATE/DELETE code paths written against it) is sufficient; a determined DB-admin-level attacker who could tamper with it already has full data access anyway |
| Concurrent-session limiting / "log out all other devices" self-service | Seen in banking apps | Adds session-tracking-per-device infrastructure disproportionate to the risk of a club booking tool | Cover the *actual* risk (stale/abandoned session on shared device) with the inactivity + absolute timeout instead |

## Feature Dependencies

```
Change Password (self-service)
    └──requires──> existing session/auth + password_hash()/password_verify() (already present)

Password Reset (self-service, forgot-password)
    ├──requires──> password_reset_tokens table (token_hash, user_id, expires_at, used_at)
    ├──requires──> shared "set new password" logic from Change Password (same hashing/validation)
    └──requires──> outbound email capability (NEW dependency — see notes below)
                       OR a documented interim fallback: admin manually resets password via existing user CRUD

Audit Trail / Logging
    ├──enhances──> Change Password / Password Reset (log the event itself, not the password)
    ├──enhances──> future brute-force lockout feature (failed-login rows become the lockout counter's data source)
    └──benefits from──> the already-planned helper deduplication (pdo()/csrf_token()/h() extraction) —
                          doing log_action() before that refactor means a 3rd duplicated function across
                          index.php and admin.php; doing it after means one shared helper

Session Timeout (inactivity + absolute)
    ├──pairs with──> session cookie security flags (HttpOnly/Secure/SameSite) — same bootstrap code, do together
    └──conflicts with──> "Remember me" persistent login (if ever added later, needs an explicit opt-in
                          long-lived token mechanism that bypasses the idle timeout — don't build both
                          in the same phase, the interaction needs deliberate design)
```

### Dependency Notes

- **Password Reset requires email OR an explicit fallback decision:** this is the one feature in this milestone that isn't "just add code to the existing files." The project constraints say no package manager is currently used and no framework migration is wanted. Three realistic paths, in increasing complexity:
  1. **Admin-mediated only (no reset feature at all):** rely on the admin's existing ability to edit a user's password. Zero new code, but doesn't satisfy "self-service."
  2. **PHP's built-in `mail()`:** zero new dependencies, but deliverability depends entirely on the host's mail transport (often unreliable/likely to land in spam without SPF/DKIM setup) — acceptable only if the hosting environment already has working outbound mail.
  3. **A minimal SMTP client (e.g. PHPMailer):** PHPMailer can be installed by downloading its source files directly and `require`-ing them (no Composer required), which fits the "no package manager" constraint while giving reliable authenticated SMTP delivery. This is the most common real-world choice for small PHP apps in this exact situation.
  This decision should be made explicitly during requirements/roadmap, since it determines whether "password reset" is a half-day task or needs an SMTP account provisioned first.
- **Audit Trail benefits from doing the helper-deduplication tech debt first:** CONCERNS.md already flags `pdo()`, `csrf_token()`, `h()`, `flash()`, and auth checks as duplicated between `index.php` and `admin.php`. Adding a `log_action()` call at every mutation point is the same class of change — worth extracting a shared `includes/` file once, rather than writing it twice.
- **Session Timeout and cookie security flags are cheap to do together:** both touch the exact same `session_start()` bootstrap in both entry points, so splitting them across separate phases just means opening the same files twice.

## MVP Definition

Framed as "this milestone," since the app itself is already live — this is a hardening milestone, not a 0-to-1 build.

### Launch With (this milestone)

- [ ] Change password (self-service, logged in) — cheapest, highest-value, no new infrastructure
- [ ] Session inactivity timeout (app-enforced via `$_SESSION['last_activity']`, not relying on `gc_maxlifetime`) + an absolute session lifetime cap
- [ ] Session cookie security flags (`HttpOnly`, `Secure`, `SameSite`) — bundle with the timeout work since it's the same code path
- [ ] Basic `audit_log` table + logging calls at: login success/failure, booking create/cancel, series create/cancel, admin user/court CRUD, password change/reset events

### Add After Validation (next milestone or once a decision is made)

- [ ] Self-service password reset via email — blocked on an explicit decision about mail delivery (`mail()` vs. PHPMailer-without-Composer vs. deferring to admin-mediated reset)
- [ ] Audit log viewer UI in the admin panel (filter by user/date/action) — the raw table is useful from day one even without a UI (queryable directly), the viewer is pure UX polish
- [ ] Feed failed-login rows from the audit log into the separately-planned brute-force/lockout feature

### Future Consideration (defer, revisit only if triggered)

- [ ] Password breach-list screening (HIBP range API) — nice defense-in-depth, not urgent for a closed-membership app
- [ ] Email notification on password change/reset ("if this wasn't you...") — trivial once a mailer exists, but not blocking
- [ ] "Remember me" persistent login — only if user feedback specifically asks for it; adds a parallel long-lived-token mechanism
- [ ] 2FA/MFA — only if the club ever opens self-registration or the threat model changes (e.g., payment processing is added)

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|----------------------|----------|
| Change password (self-service) | HIGH | LOW | P1 |
| Session inactivity + absolute timeout | HIGH | LOW | P1 |
| Session cookie security flags | MEDIUM (invisible until exploited) | LOW | P1 |
| Basic audit trail (bookings, series, admin CRUD, logins) | MEDIUM (admin/ops value, not end-user-visible) | LOW–MEDIUM | P1 |
| Self-service password reset via email | HIGH | MEDIUM–HIGH (depends on mailer decision) | P2 |
| Audit log viewer UI | LOW–MEDIUM | LOW | P2 |
| Password breach-list screening | LOW–MEDIUM | LOW–MEDIUM | P3 |
| "Remember me" persistent login | LOW | MEDIUM | P3 |
| 2FA/MFA | LOW (for this user base) | HIGH | P3 (do not build without a trigger) |

**Priority key:** P1 = should be in this milestone. P2 = next milestone or decision-gated. P3 = explicitly deferred, revisit only if a concrete trigger appears.

## How Similar Small Self-Hosted PHP Apps Handle This

| Feature | Typical small self-hosted PHP app pattern (forums, club/CMS admin tools, phpBB-style) | Recommended approach here |
|---------|----------------------------------------------------------------------------------------|----------------------------|
| Password reset | Emailed, single-use, time-boxed token (commonly 15–60 min expiry) stored hashed in a dedicated table; generic "if that email exists, we sent a link" response regardless of match | Same pattern; token via `random_bytes()` + `bin2hex()`, hash before storing, compare hash on redemption, invalidate on use, invalidate all other reset tokens for that user when a new one is requested |
| Change password | Simple form gated behind re-entering current password; often triggers session ID regeneration afterward | Same; add "log the event" to the audit table |
| Audit trail | Usually a single flat table (`id`, `actor_id`, `action`, `entity`, `entity_id`, `detail`/JSON, `ip`, `created_at`) rather than per-entity history tables; viewed via raw SQL or a minimal admin filter screen | Same — resist the urge to build per-table history/versioning; one flat table covers the actual need (accountability, not full undo/redo) |
| Session timeout | App-level idle check via a session timestamp field; cookie flags set once at bootstrap; rarely relies on PHP's `gc_maxlifetime` alone because GC timing isn't deterministic | Same; idle timeout in the 15–30 minute range fits a "low-risk" classification per OWASP guidance (this is a booking tool, not a banking app), plus a several-hour absolute cap |

## Sources

- [OWASP Forgot Password Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html) — token generation/storage/expiry, generic response messaging, no account lockout on reset requests, invalidate other sessions on reset
- [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html) — idle timeout ranges (2–5 min high-risk, 15–30 min low-risk) and absolute timeout guidance (4–8 hrs for full-day use)
- [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html) — general authentication hardening context
- NIST SP 800-63B Revision 4 (finalized 2025) coverage — [Enzoic: NIST SP 800-63B Rev 4 – What's New](https://www.enzoic.com/blog/nist-sp-800-63b-rev4/), [Netwrix: NIST Password Guidelines](https://netwrix.com/en/resources/blog/nist-password-guidelines/) — minimum length over composition rules, no forced rotation, breach-list screening, KBA (security questions) prohibited
- [PHPPot: Forgot Password in PHP (Secure Password Reset Example)](https://phppot.com/php/php-forgot-password-recover-code/) — practical PHP token flow pattern
- [Medium/DEV: Ensuring Secure User Sessions — Logging Out Users Due to Inactivity in PHP](https://dev.to/omacys/ensuring-secure-user-sessions-a-guide-to-logging-out-users-due-to-inactivity-in-php-3167) — confirms app-level `$_SESSION['last_activity']` pattern is the standard approach, not reliance on `session.gc_maxlifetime`
- Existing project artifacts: `.planning/PROJECT.md`, `.planning/codebase/ARCHITECTURE.md`, `.planning/codebase/CONCERNS.md` (used to ground findings in this specific codebase's current state, not generic advice)

---
*Feature research for: small self-hosted PHP/PostgreSQL court-booking app — password reset/change, audit logging, session timeout*
*Researched: 2026-07-27*
