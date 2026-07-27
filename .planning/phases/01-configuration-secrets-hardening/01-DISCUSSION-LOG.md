# Phase 1: Configuration & Secrets Hardening - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-07-27
**Phase:** 01-configuration-secrets-hardening
**Areas discussed:** .env location & protection, Credential rotation mechanics, conf.php disposition, .env scope
**Mode:** `--auto` — all questions auto-resolved to the recommended option, no interactive prompts

---

## .env location & protection

| Option | Description | Selected |
|--------|-------------|----------|
| Inside webroot, gitignored + chmod 600 | Works regardless of hosting tier; matches current file-based-only deployment model | ✓ |
| Outside webroot (needs shell/vhost access) | Removes even a misconfigured-Apache risk entirely, but requires confirmed shell access research couldn't verify | |

**User's choice:** N/A (auto mode) — recommended default selected
**Notes:** STACK.md flagged hosting shell/vhost access level as an open question. In-webroot option is safe under either hosting tier and can be moved outside webroot later at zero cost once access is confirmed.

---

## Credential rotation mechanics

| Option | Description | Selected |
|--------|-------------|----------|
| Coordinated single-step rotation | Update Postgres role password + `.env` in the same maintenance action; documented runbook, brief acceptable downtime | ✓ |
| Automated zero-downtime dual-role cutover | Two Postgres roles alternated to avoid any downtime during rotation | |

**User's choice:** N/A (auto mode) — recommended default selected
**Notes:** PITFALLS.md's warning is about the two systems (Postgres role, `.env`) drifting out of sync, not about avoiding all downtime. A single-club, low-traffic app doesn't need dual-role cutover tooling — a documented runbook step addresses the actual risk.

---

## conf.php disposition

| Option | Description | Selected |
|--------|-------------|----------|
| Delete/replace entirely | No compatibility shim; both `index.php` and `admin.php` updated together in this phase | ✓ |
| Keep as thin backward-compatible shim | Preserve `conf.php` as an include point for any code that might reference it | |

**User's choice:** N/A (auto mode) — recommended default selected
**Notes:** No external code depends on `conf.php`'s specific filename or variable names outside the two entry points, both touched in this same phase — no shim needed.

---

## .env scope

| Option | Description | Selected |
|--------|-------------|----------|
| Only existing DB config | Migrate exactly what `conf.php` defines today | ✓ |
| Scaffold future config keys too (e.g., mailer settings) | Provision keys for v2 features like password-reset email ahead of time | |

**User's choice:** N/A (auto mode) — recommended default selected
**Notes:** Scope guardrail — provisioning keys for undecided v2 features (AUTH-03 mailer config) would be scope creep beyond this phase's boundary.

---

## Claude's Discretion

- Exact `.env` parsing implementation (hand-rolled `parse_ini_file()`-based loader, per STACK.md)
- Whether to fully delete `conf.php` or leave it empty/comment-only
- Exact `.env.example` formatting/comments

## Deferred Ideas

None — discussion stayed within phase scope.
