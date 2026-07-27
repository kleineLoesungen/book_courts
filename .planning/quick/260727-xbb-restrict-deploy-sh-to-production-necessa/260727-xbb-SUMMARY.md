---
phase: quick
plan: 260727-xbb
subsystem: infra
tags: [lftp, deploy, ftp, hardening]

# Dependency graph
requires:
  - phase: quick-260727-wf2
    provides: deploy.sh FTP deployment script
provides:
  - deploy.sh mirror command rewritten from exclude-list to explicit allowlist
affects: [deployment, production-hardening]

# Tech tracking
tech-stack:
  added: []
  patterns: ["lftp mirror allowlist: --exclude-glob '*' followed by per-file --include-glob entries, relying on last-matching-rule-wins filter semantics"]

key-files:
  created: []
  modified: [deploy.sh]

key-decisions:
  - "Flipped deploy.sh from an exclude-list mirror to an explicit allowlist so only runtime-necessary files (index.php, admin.php, config.php, .htaccess, .env) can ever be transferred, regardless of what new files get added to the repo root in the future"
  - "Verified empirically (not assumed) that lftp evaluates mirror filter rules with last-matching-rule-wins, so --exclude-glob '*' must precede the --include-glob entries; confirmed the reversed order transfers zero files"

requirements-completed: [DEPLOY-02]

# Metrics
duration: 8min
completed: 2026-07-27
---

# Quick Task 260727-xbb: Restrict deploy.sh to Production-Necessary Files Summary

**Rewrote deploy.sh's lftp mirror command from an exclude-list to an explicit five-file allowlist (index.php, admin.php, config.php, .htaccess, .env), empirically verifying lftp's last-matching-rule-wins filter ordering before and after the change.**

## Performance

- **Duration:** 8 min
- **Started:** 2026-07-27T22:02:00Z
- **Completed:** 2026-07-27T22:09:42Z
- **Tasks:** 1
- **Files modified:** 1

## Accomplishments
- `deploy.sh`'s mirror line now uses `--exclude-glob '*'` plus five `--include-glob` entries instead of a five-item exclude list, so only runtime-necessary files can ever be uploaded
- Empirically confirmed (via lftp's `file:` pseudo-protocol, no FTP server needed) that the correct flag order (`--exclude-glob '*'` before the `--include-glob` entries) transfers exactly the 5 intended files against both a synthetic test tree and the real project root
- Empirically confirmed the reversed flag order transfers zero files, proving the ordering is load-bearing and guarding against a future accidental reorder
- Files that previously leaked to the production webroot under the old exclude-list approach (`pg.sql`, `conf.php`, `README.md`, `.gitignore`, `.env.example`, `.env.deploy.example`) are now structurally excluded by the allowlist design, not just by an incomplete blocklist

## Task Commits

Each task was committed atomically:

1. **Task 1: Empirically verify lftp allowlist ordering, then apply it to deploy.sh** - `ecd0404` (fix)

**Plan metadata:** (this commit, docs: complete quick task)

## Files Created/Modified
- `deploy.sh` - `mirror --reverse` line changed from an exclude-list (`--exclude-glob .git/ --exclude-glob .planning/ --exclude-glob .env.deploy --exclude-glob deploy.sh --exclude-glob node_modules/`) to an allowlist (`--exclude-glob '*' --include-glob 'index.php' --include-glob 'admin.php' --include-glob 'config.php' --include-glob '.htaccess' --include-glob '.env'`); no other line changed

## Decisions Made
- Kept the allowlist scoped to exactly the 5 files the plan specified (index.php, admin.php, config.php, .htaccess, .env) since all live flat at the project root and no runtime subdirectory needs deploying — the `--exclude-glob '*'` catch-all naturally also blocks descending into `.git/`, `.planning/`, `.claude/`, `node_modules/`, so no separate directory excludes were needed
- Preserved `.env` in the upload set unchanged (pre-existing, intentional behavior — the app reads `.env` from the deployed webroot at request time)

## Deviations from Plan

None - plan executed exactly as written. Both empirical verification steps (synthetic test tree ordering proof, and final re-verification against the real project directory) passed on the first attempt with the flag ordering specified in the plan's context section.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

Per the plan's success criteria: before running a real deploy against production, the user should still run `./deploy.sh --dry-run` with real credentials once to visually confirm the verbose lftp output lists only the 5 expected files against the actual remote host.

## Next Phase Readiness
- `deploy.sh` now structurally guarantees only runtime-necessary files reach the production webroot on any future deploy, independent of what new non-runtime files get added to the repo root
- No blockers introduced

---
*Phase: quick*
*Completed: 2026-07-27*

## Self-Check: PASSED

- FOUND: `.planning/quick/260727-xbb-restrict-deploy-sh-to-production-necessa/260727-xbb-SUMMARY.md`
- FOUND: commit `ecd0404`
