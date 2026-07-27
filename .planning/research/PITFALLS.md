# Pitfalls Research

**Domain:** Retrofitting security hardening, test coverage, and refactoring onto a live, framework-less PHP + PostgreSQL monolith (no package manager, no prior tests)
**Researched:** 2026-07-27
**Confidence:** HIGH (credential rotation, session/CSRF mechanics, rate-limiting false positives, PHP redeclare errors are all verified against current sources and PHP/PostgreSQL semantics) / MEDIUM (project-specific extrapolations from CONCERNS.md)

## Critical Pitfalls

### Pitfall 1: Credential Rotation Causes a Booking Outage

**What goes wrong:**
Rotating the PostgreSQL password in place (`ALTER ROLE app_user PASSWORD '...'`) invalidates every already-open PDO connection's *next* auth attempt and, more importantly, any process that reads `conf.php` at request start will immediately start failing with `SQLSTATE[08006] password authentication failed` the instant the old password stops working — but only if the new password isn't deployed to the file at the exact same moment. Since this app has no connection pooler, no secrets manager, and a single flat `conf.php`, the naive "change password in DB, then update file" or "update file, then change password in DB" sequence has a window where one is out of sync with the other and every request 500s. For a live booking system, that's a visible outage during business hours.

**Why it happens:**
Teams treat credential rotation as a single atomic action ("just change the password") without realizing it's actually two systems (Postgres role + app config) that must flip in lockstep, and PHP's shared-nothing, per-request architecture means there's no in-memory place to hold "try old password, then new" fallback logic unless you explicitly write it.

**How to avoid:**
Use the dual-role or grace-period pattern: either (a) create a *second* Postgres role with the same grants, cut the app over to it, then drop the old role's login capability afterward, or (b) set the new password, but keep the app's `conf.php` update and the Postgres `ALTER ROLE` in the same deploy step executed within seconds of each other during a low-traffic window (e.g., late night for a single-club tool), with a rollback password ready. Test the rotation procedure once against a non-production database first. Never rotate by editing `conf.php` on the live server via manual SSH edit while users may be actively submitting bookings — script it.

**Warning signs:**
- No documented/scripted rotation procedure exists yet (currently true here — `conf.php` is edited by hand)
- No health-check or smoke test to confirm DB connectivity immediately after rotation
- Rotation planned for a time when admins are also mid-session creating series (their long-lived PHP session doesn't hold a DB connection between requests, so this is lower risk than it seems, but confirm)

**Phase to address:**
Dedicated "Credential & Config Hardening" phase, done early and in isolation from behavioral refactors — so if something breaks, the diagnosis space is small (only "did the rotation work," not "did the rotation work AND did the helper refactor also break something").

---

### Pitfall 2: Session Cookie Hardening Silently Breaks Login-from-Link Flows or Mixed HTTP/HTTPS

**What goes wrong:**
Adding `Secure` to session cookies on a site not fully served over HTTPS (or with mixed HTTP/HTTPS during a transition) causes the cookie to never be set, so every user appears logged out immediately after logging in — a silent, hard-to-diagnose failure since there's no error, just an infinite login loop. Separately, setting `SameSite=Strict` breaks any flow where a user arrives via an external link (e.g., a booking confirmation link from an email client, or a bookmarked deep link opened from Slack/WhatsApp preview) — the cookie won't be attached on that first navigation, so the user is bounced back to login even though they have a valid session. This is a well-documented issue with password-reset/one-time links across many frameworks.

**Why it happens:**
CONCERNS.md's recommended snippet sets `secure` based on `$_SERVER['HTTPS'] === 'on'`, which is correct in principle, but teams often ship it without verifying the production Apache config actually terminates/forwards HTTPS status correctly (e.g., behind a reverse proxy that doesn't set `HTTPS`, this flag silently evaluates false forever, meaning `secure` never activates and the intended hardening does nothing — the inverse failure mode, which is also dangerous because it looks fixed but isn't). `SameSite=Strict` is the "more secure sounding" choice, so teams default to it without testing external-link entry points.

**How to avoid:**
Verify TLS termination behavior in the actual production environment (check `$_SERVER['HTTPS']` and `$_SERVER['SERVER_PORT']` values via a temporary debug endpoint before/after) rather than assuming. Prefer `SameSite=Lax` over `Strict` for the main session cookie unless you've confirmed there are zero external-link entry points (this app has none currently — no email links — but any future "password reset email" or "booking confirmation link" feature would immediately hit this). Test login flow from: direct visit, bookmark, and a simulated external referrer before considering this "done."

**Warning signs:**
- Users report being logged out for no reason, especially right after visiting via a shared link
- `$_SERVER['HTTPS']` unverified in the actual Apache deployment (only assumed from `.htaccess` presence)
- No manual test of "click a link from an external context into the app" performed after the change

**Phase to address:**
Session security phase — implement, then explicitly test both cookie-not-set (HTTPS detection) and cookie-not-sent (SameSite) failure modes as acceptance criteria, not just "flags are present in code."

---

### Pitfall 3: Rate Limiting / Lockout Locks Out the Whole Club Behind a Shared IP or NAT

**What goes wrong:**
IP-based brute-force protection (the CONCERNS.md-recommended approach) is the single most common way small/club-sized deployments accidentally lock out all legitimate users at once: if the club has shared Wi-Fi, a school/office NAT, or members reset their own password wrong a few times from the clubhouse network, one IP address generates enough failed attempts to trip a global IP lockout — and now nobody at the club, including admins trying to fix it, can log in. This is especially likely here because it's a small user base concentrated at a single physical location (a club), the exact scenario where IP-based limiting fails hardest.

**Why it happens:**
IP-based rate limiting is the easiest to implement (no schema change, just a file or table keyed by IP) so it's the default reach, but it conflates "attacker" with "network," which breaks down precisely for small, geographically-concentrated user bases like a sports club.

**How to avoid:**
Rate-limit per-account (keyed by email, not IP) as the primary lockout mechanism, with a much higher/looser IP-based limit as a secondary defense against distributed attempts. Never let counters reset only on success — track failures independently. Ensure the admin login path has a separate, less aggressive threshold or an out-of-band unlock mechanism (e.g., a CLI script or direct DB update) so a lockout can't fully strand admins. Log lockout events so you can distinguish "attack" from "one member fat-fingering their password four times."

**Warning signs:**
- Lockout keyed only on `$_SERVER['REMOTE_ADDR']`
- No separate/relaxed threshold or override for admin login
- No way to clear a lockout except waiting out a timer or editing the database by hand

**Phase to address:**
Brute-force protection phase — design the key (account vs. IP) and the admin-override path explicitly as part of the plan, before writing the lockout logic itself.

---

### Pitfall 4: Deduplicating Helpers Introduces "Cannot Redeclare Function" Fatal Errors or Subtly Changes Behavior

**What goes wrong:**
Moving `pdo()`, `csrf_token()`, `h()`, `flash()`, etc. out of `index.php`/`admin.php` into a shared `includes/helpers.php` is mechanically simple but has two well-known failure modes: (1) if the new shared file is `include`d/`require`d without `_once`, or if it's included from a path that can be reached twice in the same request (e.g., both a top-level include and a nested include inside another shared file), PHP throws a fatal `Cannot redeclare function` error that takes the whole page down — not a graceful degradation; (2) more dangerously, because `index.php` and `admin.php` currently have their *own copies* of these functions, subtle differences between the two copies (e.g., slightly different CSRF token session key handling, or `admin.php`'s `flash()` behaving marginally differently than `index.php`'s) get silently unified into "one true version," which can change behavior in the file that *didn't* have the bug being "fixed." This is the exact scenario the project's own CONCERNS.md flags as a duplication risk — but the fix itself carries a matching risk if done without a diff-first approach.

**Why it happens:**
"Just move the function" feels like a pure refactor with no behavior change, but two independently-evolved copies of a helper are rarely byte-identical, and nobody diffs them line-by-line before merging.

**How to avoid:**
Before extracting, literally `diff` the two copies of each function side by side and document every discrepancy — decide explicitly which behavior wins (or if both need to be preserved via a parameter). Use `require_once` (never `require`/`include`) for the new shared file, and confirm it's only ever required from one place per request (e.g., only from the top of `index.php` and `admin.php`, never re-required elsewhere). After extraction, manually exercise every code path that touched the duplicated function in both files (login, CSRF-protected POST, flash message display) — this is exactly why this refactor should follow, not precede, the test-coverage phase; regression tests make "no behavior changed" verifiable instead of just "hoped."

**Warning signs:**
- Extraction PR touches both files' logic paths without an accompanying diff of the two original functions
- No smoke test of both admin and member login/CSRF/flash flows after merge
- `include` (not `include_once`) used anywhere near the new shared file

**Phase to address:**
Helper deduplication phase — sequence this *after* the test-coverage phase (Pitfall 5) so extraction can be verified against a green test suite, not by hand. This ordering itself is a key roadmap decision.

---

### Pitfall 5: Retrofitted Tests Assert Current (Possibly Buggy) Behavior Instead of Correct Behavior

**What goes wrong:**
When adding tests to code that has zero prior coverage — especially in conflict-detection and series-materialization logic that CONCERNS.md explicitly flags as fragile and possibly incomplete (`materialize_series()` is noted as truncated) — the natural approach of "write a test, run it against current code, assert whatever it returns" locks in existing bugs as if they were the spec. If `materialize_series()` has a latent boundary bug (e.g., off-by-one on series end date, or mishandling DST transitions in `Europe/Berlin`), a test suite written by observing current output will pass forever while the bug remains, giving false confidence and actively discouraging anyone from fixing it later (a passing test now signals "leave this alone").

**Why it happens:**
Retrofitting tests onto legacy code is naturally done by "characterization testing" (record what it does), which is a legitimate strategy for safe refactoring but is frequently misapplied as if it were correctness verification — teams conflate "I captured current behavior" with "I verified correct behavior."

**How to avoid:**
Explicitly separate two kinds of tests during this phase: (a) characterization tests written purely to freeze current behavior before touching risky code (label them as such, e.g., `testMaterializeSeriesCurrentBehavior_DoNotTreatAsSpec`), and (b) verified-correct tests derived independently from the business rules (30-minute grid, no overlaps, `Europe/Berlin` DST rules, admin-only long durations) written by reasoning about what *should* happen, then checked against real output — flagging mismatches as bugs to fix, not tests to adjust. Prioritize DST transition dates (late March / late October) and series-end-date boundaries as explicit test cases since these are the exact conditions CONCERNS.md flags as unverified.

**Warning signs:**
- Test suite reaches "100% coverage" on `materialize_series()` without anyone having manually verified a DST-boundary series or an exact-boundary end date against a hand-calculated expected result
- No test intentionally tries to reproduce the "truncated function" concern from CONCERNS.md before writing tests around it

**Phase to address:**
Test-coverage phase — before writing tests, first read and manually verify (or complete) `materialize_series()` in full (this is already flagged as an Active requirement); write correctness tests from business rules, not from current output, for this specific function.

---

### Pitfall 6: The Redundant PHP + Database Conflict Check Gets "Simplified" in a Way That Removes the Safety Net

**What goes wrong:**
CONCERNS.md notes the booking conflict check currently runs twice — once in PHP validation, once in the database trigger/advisory lock. A well-intentioned "performance improvement" or "dedup" pass might remove the PHP-side check as "redundant," reasoning that the DB trigger is authoritative. But the PHP check currently provides the user-friendly error message and the pre-flight UX (avoiding a raw `PDOException` conflict); removing it without replacing that UX layer means conflicts still get blocked at the database level, but users now see a generic/raw error instead of "this slot is taken," which is a regression that looks like "the app broke" rather than "the check moved."

**Why it happens:**
Refactoring passes conflate "this logic is duplicated" with "this logic is unnecessary" — the two checks serve different purposes (UX pre-check vs. hard data-integrity guarantee) even though they overlap in what they detect.

**How to avoid:**
If simplifying, keep the PHP-side check explicitly as an "advisory, better-error-message" layer and keep the DB trigger as the single source of truth for data integrity — never remove the DB-level check, and don't remove the PHP-level check without confirming the resulting `PDOException` from a real conflict is still caught and translated into the same friendly message the PHP pre-check used to produce.

**Warning signs:**
- A "reduce duplication" PR touches booking-creation conflict logic and reduces the number of SQL round-trips without an accompanying test for "user sees a friendly conflict message" (not just "conflicting booking is rejected")

**Phase to address:**
This is a trap specifically for the helper-deduplication phase — call it out explicitly as an exception ("dedupe helpers, not the two-layer conflict check") in that phase's plan.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|--------------------|-----------------|------------------|
| Editing `conf.php` by hand on the server for credential rotation instead of scripting it | Fast, no tooling needed | Risk of typo-induced outage, no repeatable/auditable process, next rotation repeats the risk | Never for production; acceptable only as a one-time emergency measure with immediate follow-up to script it |
| Writing characterization tests that just capture current output of `materialize_series()` | Gets "coverage" quickly, feels like progress | Locks in undiscovered bugs (DST, boundary dates) as permanent "spec" | Acceptable only as an interim step explicitly followed by business-rule verification, never as the final state |
| IP-only rate limiting on login | Simplest to implement, no schema change | Locks out entire club from a shared network; poor incident recoverability | Never for a small, geographically-concentrated user base like this club |
| Merging duplicated helpers without diffing the two originals first | Faster PR | Silently changes behavior in whichever file's copy didn't "win," reintroducing the exact class of bug the refactor was meant to prevent | Never — the diff step is cheap and should always precede merge |
| Removing the PHP-side conflict pre-check as "redundant" with the DB trigger | Fewer SQL round-trips, less code | Users see raw DB errors instead of friendly conflict messages | Acceptable only if the friendly-message translation is explicitly preserved at the exception-handling layer |
| Introducing PHPUnit via a bare `.phar` download rather than a version-pinned, checksummed install | No package manager setup needed | Upgrade drift, no lockfile, silent version mismatches across dev machines/CI | Acceptable as a deliberate, documented interim measure since the project has decided against Composer for now — but pin the exact phar version and commit a checksum |

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|-------------------|
| PostgreSQL role/password rotation | Changing `ALTER ROLE ... PASSWORD` and `conf.php` as two separate, un-synchronized steps | Script both changes together, or use a dual-role cutover so the app can switch to a new role before the old one's login is revoked |
| Apache + `.htaccess`, HTTPS detection for `Secure` cookie flag | Assuming `$_SERVER['HTTPS']` is reliably set without checking actual reverse-proxy/Apache config | Verify the flag's real value in production before shipping the `Secure` cookie flag; check for `X-Forwarded-Proto` if behind any proxy |
| Session storage (default PHP file-based sessions) | Adding session timeout logic without checking `session.gc_maxlifetime`/`session.gc_probability` are actually enabled on the shared host, which can silently no-op custom expiry logic | Implement expiry checks explicitly in application code (store a `last_activity` timestamp in `$_SESSION` and check it) rather than relying solely on PHP's garbage collector |
| PHPUnit test DB against the real PostgreSQL instance | Running integration tests against the production database "just this once" | Always require a separate test database/connection string, and make it impossible to point tests at prod (e.g., refuse to run if DSN matches a known-prod pattern) |

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|-----------------|
| Adding per-login-attempt rate-limit writes to a file or table with no cleanup | Login gets progressively slower as the attempts log grows unbounded | Add a TTL/cleanup job (cron or lazy delete-on-read of stale rows) from day one | Noticeable after a few months of normal login traffic on a small club-sized table, but the fix is cheap if done early |
| Test suite runs against a real Postgres DB with no transaction rollback per test | Test suite gets slower and flakier as fixtures accumulate; tests start polluting each other's state | Wrap each test in a transaction and roll back (already noted correctly in TESTING.md's proposed pattern) — enforce this from the first test written | Immediately, once more than a handful of tests exist without isolation |

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| Treating credential rotation as "done" once the DB password changes, without confirming `conf.php` file permissions and `.gitignore` coverage are still correct | Old, unrotated credential could still be recoverable from backups, shell history, or an unprotected `.htaccess` gap | After rotation, verify `conf.php` is not web-readable (test with a direct HTTP request) and check that old credentials are fully revoked, not just superseded |
| CSRF token stored once per session and never rotated (current state, per CONCERNS.md) "fixed" by regenerating on every request | Session_regenerate_id() on every request, done naively, creates real race conditions with concurrent AJAX calls (`free_courts`, `series_preview` fire alongside form submissions), causing intermittent CSRF failures for legitimate users | Rotate the token per-session-lifetime or per-authentication-event (login), not per-request; if the token must change after use, return the new token in the response payload so the client can update its next request rather than relying on synchronized page reload |
| Rate limiting resets failure counters on any successful login | Doesn't actually prevent slow, patient brute-force attempts that intersperse occasional correct guesses of *other* accounts | Track failures per-account independently of any other account's success; never let one account's success clear another's counter |
| Assuming HTTPS is universally enforced once cookie flags are added, without also enforcing HTTPS redirect at the Apache/.htaccess level | `Secure` flag makes cookies silently vanish over HTTP rather than erroring, producing confusing "randomly logged out" reports instead of a hard failure that would get caught in testing | Pair the `Secure` cookie flag with an actual HTTP→HTTPS redirect rule in `.htaccess`, and verify both together |

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-------------------|
| Lockout after N failed attempts with no visible countdown or unlock guidance | Member thinks the whole booking site is broken and calls/emails the admin instead of waiting out the lockout | Show a clear "too many attempts, try again in X minutes" message, and give admins a documented override path |
| Replacing the friendly "this slot is already booked" message with a generic error after refactoring the conflict check | Member sees a confusing technical error and may retry repeatedly, worsening rate-limit counters | Preserve the specific, translated conflict message end-to-end through any refactor (see Pitfall 6) |
| Session timeout added without warning before it fires | Member loses an in-progress series creation form with no warning, has to redo it | If adding session timeout, consider a client-side warning/heartbeat before expiry, at least on multi-step forms (series creation) |

## "Looks Done But Isn't" Checklist

- [ ] **Credential rotation:** Often "done" the moment `ALTER ROLE` succeeds — verify the app can still connect immediately after by hitting a real endpoint, not just checking the SQL command didn't error.
- [ ] **Session cookie hardening:** Often verified only in local dev over `localhost` (where `Secure` behaves differently) — verify on the actual production HTTPS deployment, and test login via an external link/referrer, not just a direct visit.
- [ ] **Rate limiting:** Often tested only with a single test account from a single IP — verify the admin override/unlock path actually works before considering it shipped, and verify a shared-IP scenario doesn't lock out unrelated accounts.
- [ ] **Helper deduplication:** Often "done" once the code compiles with no fatal errors — verify by diffing the two original implementations first and confirming no behavior silently changed in either `index.php` or `admin.php`'s flows.
- [ ] **`materialize_series()` completion:** Often "done" once the function runs without error for a simple weekly series — verify explicitly against a DST-boundary date range and an exact series-end-date boundary, since these are the cases CONCERNS.md flags as unverified.
- [ ] **Test coverage on conflict detection:** Often "done" once tests pass — verify the tests encode independently-reasoned business rules (30-min grid, no overlaps, Europe/Berlin DST) rather than just mirroring whatever the code currently outputs.

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|----------------|------------------|
| Credential rotation outage | LOW (if rollback password/role kept) | Revert `conf.php` to prior value or flip back to the old role immediately; keep old credential valid for a short grace window specifically to make this possible |
| Session cookie flag breaks login | LOW | Cookie flags are a one-line config change — revert `session_set_cookie_params()` call and redeploy; keep the change isolated in its own commit for a fast, clean revert |
| Rate limiting locks out whole club | MEDIUM | Requires direct DB/file edit to clear lockout state — document this recovery command *before* shipping the feature, not after the first real incident |
| Helper dedup changes behavior silently | MEDIUM–HIGH | If characterization/regression tests exist (Pitfall 5 addressed first), a failing test pinpoints the change; without tests, requires manually diffing git history against the two original implementations to find the discrepancy |
| Conflict-check UX regression (Pitfall 6) | LOW | Re-add the friendly-message translation layer around the `PDOException` catch block; no data-integrity risk since the DB trigger was never removed |

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|-------------------|----------------|
| Credential rotation outage | Credential & Config Hardening (early, isolated phase) | Scripted rotation run once against a non-prod DB; live rotation followed by an immediate scripted health check hitting a real DB-backed endpoint |
| Session cookie hardening breaks login-from-link / mixed HTTPS | Session Security phase | Manual test matrix: direct visit, bookmark, and simulated external-referrer visit, on the actual production HTTPS config |
| Rate limiting locks out shared-IP/NAT users | Brute-force Protection phase | Test with two different accounts hitting login failures from the same simulated IP; confirm only the offending account is throttled |
| Helper deduplication silently changes behavior | Helper Deduplication phase (sequenced AFTER test-coverage phase) | Full regression suite green before and after extraction; manual diff of original two implementations documented in the PR |
| Retrofitted tests encode current bugs as spec | Test Coverage phase (addressed BEFORE helper dedup and BEFORE any "cleanup" of conflict-check logic) | DST-boundary and series-end-boundary test cases reviewed against hand-calculated expected results, not just current output |
| Conflict-check "simplification" removes friendly error UX | Helper Deduplication phase (explicit carve-out) | Test asserts the specific user-facing conflict message string survives any refactor of the check |

## Sources

- [Rotating PostgreSQL Passwords with no downtime — Jannik Arndt](https://www.jannikarndt.de/blog/2018/08/rotating_postgresql_passwords_with_no_downtime/)
- [PostgreSQL Credentials Rotation — Infisical docs](https://infisical.com/docs/documentation/platform/secret-rotation/postgres-credentials)
- [Zero-Downtime Postgres Credentials Rotation — sheshbabu.com](https://www.sheshbabu.com/posts/implementing-zero-downtime-postgres-credentials-rotation-with-node-js/)
- [Database Credential Rotation in PostgreSQL — kevinhakanson.com](https://kevinhakanson.com/2018-04-09-database-credential-rotation-in-postgresql/)
- [session_regenerate_id() race condition discussion — PHP Externals mailing list](https://externals.io/message/73097)
- [CSRF token race condition after login — Drupal.org issue #2941102](https://www.drupal.org/project/drupal/issues/2941102)
- [Session lost due to concurrent requests — Symfony issue #28314](https://github.com/symfony/symfony/issues/28314)
- [SameSite=Strict breaks one-time login links from email clients — Drupal.org issue #3401709](https://www.drupal.org/project/drupal/issues/3401709)
- [SameSite Cookies: Strict vs Lax vs None — vibeappscanner.com](https://vibeappscanner.com/glossary/samesite-cookies)
- [PHPUnit PHAR install without Composer — GitHub issue #153](https://github.com/sebastianbergmann/phpunit-documentation/issues/153)
- [Rate-limiting web application login attempts — timoh6.github.io](https://timoh6.github.io/2015/05/07/Rate-limiting-web-application-login-attempts.html)
- [Broken brute-force protection / IP block pitfalls — Ahmad Sopyan, Medium](https://medium.com/@AhmadSopyan/authentication-part-6-broken-brute-force-protection-ip-block-ecbb6f63779f)
- [PHP "Cannot redeclare function" error — alvinalexander.com](https://alvinalexander.com/php/php-cannot-redeclare-function-error-message/)
- [Fatal Error cannot redeclare function — MODX blog](https://modx.com/blog/fatal-error-cannot-redeclare-function-part-1)
- Project-specific: `.planning/codebase/CONCERNS.md`, `.planning/codebase/TESTING.md`, `.planning/PROJECT.md` (2026-07-27 codebase audit)

---
*Pitfalls research for: PHP/PostgreSQL court-booking monolith hardening milestone*
*Researched: 2026-07-27*
