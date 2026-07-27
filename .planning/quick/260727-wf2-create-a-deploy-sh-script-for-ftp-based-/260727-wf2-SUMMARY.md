---
task: 260727-wf2
subsystem: infra
tags: [deploy, ftp, lftp, bash]

provides:
  - deploy.sh: FTP-based deployment script using lftp mirror --reverse
  - .env.deploy.example: documented template for deploy credentials
affects: [phase-01-configuration-secrets-hardening]

tech-stack:
  added: [lftp (external CLI dependency, not a code dependency)]
  patterns: ["credentials passed to external CLI via stdin heredoc, never as argv, to avoid ps exposure"]

key-files:
  created: [deploy.sh, .env.deploy.example]
  modified: [.gitignore]

key-decisions:
  - "Credentials sourced from optional local .env.deploy file (gitignored) or shell env, following the existing .env/.env.example convention already used for DB config"
  - ".env, .htaccess, conf.php are intentionally included in the mirror upload (protected server-side by existing .htaccess FilesMatch rule) rather than excluded from deploy"

duration: 12min
completed: 2026-07-27
---

# Quick Task 260727-wf2: Create deploy.sh FTP deployment script Summary

**Bash script wrapping `lftp mirror --reverse` for plain-FTP deployment, with credentials sourced from env vars/local `.env.deploy` and passed to lftp exclusively via stdin heredoc.**

## Performance

- **Duration:** ~12 min
- **Tasks:** 2 completed
- **Files modified:** 3 (deploy.sh created, .env.deploy.example created, .gitignore modified)

## Accomplishments
- Created `deploy.sh`, an executable script that mirrors the local project to a remote FTP server via `lftp mirror --reverse`, supporting `--dry-run`.
- Credentials (`DEPLOY_FTP_HOST/PORT/USER/PASS/REMOTE_DIR`) are validated before any FTP connection is attempted, with clear, distinct errors for a missing `lftp` binary vs. missing env vars.
- Documented required variables in a tracked `.env.deploy.example`, and gitignored the real `.env.deploy` file following the existing `conf.php`/`.env` pattern.

## Task Commits

Each task was committed atomically:

1. **Task 1: Create deploy.sh FTP deployment script** - `b9f6a70` (feat)
2. **Task 2: Document env vars and gitignore the local credentials file** - `22a5a0b` (chore)

## Files Created/Modified
- `deploy.sh` - Executable bash script; validates `lftp` presence and required `DEPLOY_FTP_*` env vars, then runs `lftp mirror --reverse` with credentials passed via stdin heredoc (never on the command line), excluding `.git/`, `.planning/`, `.env.deploy`, `deploy.sh`, `node_modules/` from the upload.
- `.env.deploy.example` - Tracked template documenting `DEPLOY_FTP_HOST`, `DEPLOY_FTP_PORT` (default 21), `DEPLOY_FTP_USER`, `DEPLOY_FTP_PASS`, `DEPLOY_FTP_REMOTE_DIR`.
- `.gitignore` - Added `.env.deploy` entry so the real local credentials file is never tracked.

## Decisions Made
- Followed the plan's exact heredoc structure for passing credentials to `lftp` via stdin (`open -u "user,pass"`), verified this keeps credentials out of `ps` output since the script places them only inside the here-doc text, not as CLI arguments.
- No architectural decisions required; this was a standalone utility script with no interaction with existing PHP/PostgreSQL code paths.

## Deviations from Plan

None - plan executed exactly as written. All automated verification commands specified in the plan (syntax check, executable check, missing-lftp error, missing-env-var error, `.gitignore`/`git check-ignore` checks, `--dry-run` flag propagation, unrecognized-argument rejection) were run and passed.

## Known Stubs

None. The script is fully functional pending real FTP credentials, which are an operational/deployment-time concern (not a code stub) — the script correctly refuses to run without them.

## Self-Check: PASSED

- FOUND: deploy.sh (executable, `bash -n` passes)
- FOUND: .env.deploy.example (tracked, not gitignored)
- FOUND: .gitignore updated with `.env.deploy` entry
- FOUND commit: b9f6a70
- FOUND commit: 22a5a0b
