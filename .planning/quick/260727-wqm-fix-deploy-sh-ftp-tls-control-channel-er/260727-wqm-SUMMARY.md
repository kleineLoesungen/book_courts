---
phase: quick
plan: 260727-wqm
subsystem: infra
tags: [deploy, ftp, lftp, ftps, tls]

# Dependency graph
requires:
  - phase: quick-260727-wf2
    provides: deploy.sh (FTP-based deployment script)
provides:
  - deploy.sh now negotiates explicit FTPS (AUTH TLS) on the control channel
affects: [deployment]

# Tech tracking
tech-stack:
  added: []
  patterns: []

key-files:
  created: []
  modified:
    - deploy.sh

key-decisions:
  - "Kept plain FTP/FTPES on port 21 (not SFTP) per explicit user requirement; only enabled TLS via lftp's ftp:ssl-* settings"
  - "Also set ftp:ssl-protect-data yes (data channel encryption) alongside ssl-force, since some hosts require both once the control channel mandates TLS"

patterns-established: []

requirements-completed: [FIX-01]

# Metrics
duration: 3min
completed: 2026-07-27
---

# Quick Task 260727-wqm: Fix deploy.sh FTP TLS control channel error Summary

**Enabled explicit FTPS (AUTH TLS) in deploy.sh's lftp heredoc so production FTP logins no longer fail with `550 SSL/TLS required on the control channel`.**

## Performance

- **Duration:** 3 min
- **Started:** 2026-07-27T21:33:00Z (approx)
- **Completed:** 2026-07-27T21:35:48Z
- **Tasks:** 1 completed
- **Files modified:** 1

## Accomplishments
- Replaced `set ftp:ssl-allow no` with `set ftp:ssl-allow yes`, `set ftp:ssl-force yes`, and `set ftp:ssl-protect-data yes` in the lftp heredoc, unblocking `./deploy.sh` against production hosts that require TLS on the control channel.
- Verified `bash -n deploy.sh` passes and confirmed the diff touches only the intended lines — no protocol, port, credential-handling, exclude-list, or `--dry-run` logic changed.

## Task Commits

Each task was committed atomically:

1. **Task 1: Enable explicit FTPS (AUTH TLS) in deploy.sh's lftp heredoc** - `681c5f4` (fix)

_Note: single-task quick plan, no plan-metadata commit created (no ROADMAP.md update for quick tasks)._

## Files Created/Modified
- `deploy.sh` - lftp heredoc now sets `ftp:ssl-allow yes`, `ftp:ssl-force yes`, `ftp:ssl-protect-data yes` instead of `ftp:ssl-allow no`, enabling explicit FTPS (AUTH TLS) on the control channel (and data channel) while remaining plain FTP/FTPES on port 21.

## Decisions Made
- Kept protocol as FTP/FTPES on the existing port (no SFTP migration), per explicit plan requirement.
- Added `ftp:ssl-protect-data yes` in addition to the two lines strictly required by the objective, since hosts enforcing control-channel TLS commonly also require data-channel TLS; this was explicitly called out and pre-approved in the plan's task action.

## Deviations from Plan

None - plan executed exactly as written.

## Self-Check: PASSED

- FOUND: deploy.sh (contains `set ftp:ssl-allow yes`, `set ftp:ssl-force yes`, `set ftp:ssl-protect-data yes`; no longer contains `set ftp:ssl-allow no`)
- FOUND: commit 681c5f4
