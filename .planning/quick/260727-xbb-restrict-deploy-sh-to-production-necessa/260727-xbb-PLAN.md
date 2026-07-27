---
phase: quick
plan: 260727-xbb
type: execute
wave: 1
depends_on: []
files_modified: [deploy.sh]
autonomous: true
requirements: [DEPLOY-02]
must_haves:
  truths:
    - "Running deploy.sh (real or --dry-run) uploads exactly index.php, admin.php, config.php, .htaccess, .env and nothing else"
    - "pg.sql, conf.php, README.md, .gitignore, .env.example, .env.deploy.example, .env.deploy, deploy.sh, CLAUDE.md, .git/, .planning/, .claude/, node_modules/ are never uploaded"
    - "Existing safety behavior (stdin-only credential passing, required env var validation, missing-lftp error, --dry-run passthrough, explicit FTPS ftp:ssl-* settings) is unchanged"
  artifacts:
    - path: "deploy.sh"
      provides: "lftp mirror command rewritten as an explicit allowlist (catch-all exclude + per-file includes) instead of an exclude-list"
      contains: "--exclude-glob '*' --include-glob 'index.php'"
  key_links:
    - from: "deploy.sh mirror command"
      to: "lftp glob filter engine"
      via: "--exclude-glob '*' listed BEFORE the five --include-glob entries (lftp uses last-matching-rule-wins, not first-match, so order is correctness-critical)"
      pattern: "exclude-glob '\\*'.*include-glob 'index\\.php'"
---

<objective>
Change `deploy.sh` from an exclude-list mirror (upload everything except a blocklist) to an explicit allowlist that uploads only the five files the live app reads at runtime: `index.php`, `admin.php`, `config.php`, `.htaccess`, `.env`.

Purpose: The current `mirror --reverse --exclude-glob ...` approach uploads everything not explicitly blocked, which means files with zero runtime purpose (`pg.sql`, the retired `conf.php` stub, `README.md`, `.gitignore`, `.env.example`, `.env.deploy.example`) currently get shipped to the production webroot, adding unnecessary attack surface. Flipping to an allowlist guarantees only runtime-necessary files ever leave the local machine.
Output: `deploy.sh` with its `mirror --reverse` line rewritten to use `--exclude-glob '*'` plus five `--include-glob` entries (one per allowed file), empirically verified against a local test tree before being applied, and functionally re-verified against the real project directory afterward.
</objective>

<execution_context>
@~/.claude/get-shit-done/workflows/execute-plan.md
@~/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@deploy.sh
@.planning/STATE.md

Current `mirror` line in `deploy.sh` (inside the `lftp <<LFTP_EOF ... LFTP_EOF` heredoc):
```
mirror --reverse --verbose ${DRY_RUN} --exclude-glob .git/ --exclude-glob .planning/ --exclude-glob .env.deploy --exclude-glob deploy.sh --exclude-glob node_modules/ "${SCRIPT_DIR}/" "${DEPLOY_FTP_REMOTE_DIR}"
```

Runtime-necessary files that MUST be included (the new allowlist):
- `index.php` — public entry point
- `admin.php` — admin entry point
- `config.php` — `.env` loader, required by both entry points
- `.htaccess` — protects `.env`/`conf.php` from direct HTTP access, sets DirectoryIndex
- `.env` — real runtime DB credentials; must be deployed since the app reads it at request time (this is pre-existing, intentional behavior from the original deploy.sh — do not change it)

Everything else at the project root (`pg.sql`, `conf.php`, `README.md`, `.gitignore`, `.env.example`, `.env.deploy.example`, `.env.deploy`, `deploy.sh`, `CLAUDE.md`) plus all directories (`.git/`, `.planning/`, `.claude/`, `node_modules/`) must NOT be uploaded.

IMPORTANT — empirically confirmed lftp filter semantics (do not assume the opposite):
lftp's `mirror` filter rules are evaluated with **last-matching-rule-wins**, not first-match-wins. This means `--exclude-glob '*'` MUST be listed BEFORE the `--include-glob` entries on the command line, not after. Putting the excludes after the includes (or omitting the ordering) causes either (a) nothing to transfer at all, or (b) only the last include to be honored. This was verified locally using lftp's `file:` pseudo-protocol (`open file:$SOME_LOCAL_DIR`, then `mirror --reverse` against a real directory tree) — no FTP server needed for this kind of test.

Confirmed-working command shape (order matters):
```
mirror --reverse --verbose ${DRY_RUN} --exclude-glob '*' --include-glob 'index.php' --include-glob 'admin.php' --include-glob 'config.php' --include-glob '.htaccess' --include-glob '.env' "${SCRIPT_DIR}/" "${DEPLOY_FTP_REMOTE_DIR}"
```
Since all five runtime files live flat at the project root (no runtime subdirectory needs deploying), the `--exclude-glob '*'` catch-all also naturally blocks descending into `.git/`, `.planning/`, `.claude/`, `node_modules/` — no separate directory excludes are needed anymore, and no empty directories get created on the remote (confirmed empirically).
</context>

<tasks>

<task type="auto">
  <name>Task 1: Empirically verify lftp allowlist ordering, then apply it to deploy.sh</name>
  <files>deploy.sh</files>
  <action>
Step 1 — Empirical verification (do this BEFORE editing deploy.sh, to confirm the filter ordering claim above rather than assuming it):

Build a throwaway local test tree under a temp directory (e.g. `$(mktemp -d)`) that mirrors the real project's file shape: create `index.php`, `admin.php`, `config.php`, `.htaccess`, `.env`, `pg.sql`, `conf.php`, `README.md`, `.gitignore`, `.env.example`, `.env.deploy.example`, `deploy.sh`, `CLAUDE.md` as empty files, plus `.git/`, `.planning/`, `.claude/`, `node_modules/` as directories each containing one dummy file.

Using lftp's local `file:` pseudo-protocol (no real FTP server required), run against a second empty temp directory as the "remote" target:
```
lftp -c "open file:\$TESTDST; mirror --reverse --verbose --exclude-glob '*' --include-glob 'index.php' --include-glob 'admin.php' --include-glob 'config.php' --include-glob '.htaccess' --include-glob '.env' '\$TESTSRC/' ."
```
Confirm the destination directory contains EXACTLY these 5 files and nothing else (no other files, no empty directories for `.git`/`.planning`/`.claude`/`node_modules`). If it does not match exactly, adjust flag ordering/quoting until it does before proceeding to Step 2 — do not apply an unverified command to the real deploy.sh.

Also confirm reversing the order (`--include-glob` before `--exclude-glob '*'`) does NOT produce the correct allowlist result (it produces zero transferred files) — this proves the ordering is load-bearing, not incidental, and guards against a future edit accidentally reordering the flags.

Step 2 — Apply to deploy.sh:

In the `lftp <<LFTP_EOF ... LFTP_EOF` heredoc in `deploy.sh`, replace the current mirror line:
```
mirror --reverse --verbose ${DRY_RUN} --exclude-glob .git/ --exclude-glob .planning/ --exclude-glob .env.deploy --exclude-glob deploy.sh --exclude-glob node_modules/ "${SCRIPT_DIR}/" "${DEPLOY_FTP_REMOTE_DIR}"
```
with:
```
mirror --reverse --verbose ${DRY_RUN} --exclude-glob '*' --include-glob 'index.php' --include-glob 'admin.php' --include-glob 'config.php' --include-glob '.htaccess' --include-glob '.env' "${SCRIPT_DIR}/" "${DEPLOY_FTP_REMOTE_DIR}"
```
Change nothing else in the file — leave the shebang, argument parsing, `.env.deploy` sourcing, `lftp` presence check, env var validation, status message, `set ftp:ssl-*` lines, and `open -u ...` line exactly as they are.

Step 3 — Final functional re-verification against the REAL project directory (not the synthetic test tree), to confirm the edited line behaves correctly on the actual repo:
```
TMPDST=$(mktemp -d)
lftp -c "open file:\$TMPDST; mirror --reverse --verbose --exclude-glob '*' --include-glob 'index.php' --include-glob 'admin.php' --include-glob 'config.php' --include-glob '.htaccess' --include-glob '.env' '$(pwd)/' ."
find "$TMPDST" -mindepth 1 | sort
rm -rf "$TMPDST"
```
Confirm the output is exactly the 5 basenames (`.env`, `.htaccess`, `admin.php`, `config.php`, `index.php`) at the destination root, with no `pg.sql`, `conf.php`, `README.md`, `.gitignore`, `.env.example`, `.env.deploy*`, `deploy.sh`, `CLAUDE.md`, or any subdirectory present.
  </action>
  <verify>
    <automated>bash -n deploy.sh && grep -qF "exclude-glob '*' --include-glob 'index.php' --include-glob 'admin.php' --include-glob 'config.php' --include-glob '.htaccess' --include-glob '.env'" deploy.sh && ! grep -q "exclude-glob .git/" deploy.sh && ! grep -q "exclude-glob .planning/" deploy.sh && ! grep -q "exclude-glob node_modules/" deploy.sh && TMPDST=$(mktemp -d) && lftp -c "open file:$TMPDST; mirror --reverse --verbose --exclude-glob '*' --include-glob 'index.php' --include-glob 'admin.php' --include-glob 'config.php' --include-glob '.htaccess' --include-glob '.env' '$(pwd)/' ." && [ "$(find "$TMPDST" -mindepth 1 | xargs -n1 basename | sort | tr '\n' ' ')" = ".env .htaccess admin.php config.php index.php " ] && rm -rf "$TMPDST" && echo VERIFIED</automated>
  </verify>
  <done>
`deploy.sh` passes `bash -n`. The mirror line now reads `--exclude-glob '*'` followed by the five `--include-glob` entries (`index.php`, `admin.php`, `config.php`, `.htaccess`, `.env`) instead of the old directory/file exclude list. Running the real mirror command locally via lftp's `file:` protocol against the actual project root transfers exactly those 5 files and nothing else — no `pg.sql`, `conf.php`, `README.md`, `.gitignore`, `.env.example`, `.env.deploy*`, `deploy.sh`, `CLAUDE.md`, or any directory. All other lines in `deploy.sh` (credential handling, env var validation, `lftp` check, `--dry-run` flag parsing, FTPS `set` lines) are unchanged.
  </done>
</task>

</tasks>

<verification>
1. `bash -n deploy.sh` passes (no syntax errors introduced).
2. `git diff deploy.sh` shows only the single `mirror --reverse ...` line changed — no other line touched (credential stdin heredoc, env var validation, missing-lftp check, `--dry-run` flag parsing, `set ftp:ssl-*` lines, `open -u ...` line all identical to before).
3. The empirical local test (via lftp's `file:` pseudo-protocol against a synthetic test tree, then re-run against the real project root) confirms the new mirror line transfers exactly `index.php`, `admin.php`, `config.php`, `.htaccess`, `.env` — nothing more, nothing less.
4. Manual read-through confirms `pg.sql`, `conf.php`, `README.md`, `.gitignore`, `.env.example`, `.env.deploy.example`, `.env.deploy`, `deploy.sh`, `CLAUDE.md`, `.git/`, `.planning/`, `.claude/`, `node_modules/` are all absent from the transferred set.
</verification>

<success_criteria>
- `deploy.sh`'s `mirror --reverse` line uses an explicit allowlist (`--exclude-glob '*'` followed by five `--include-glob` entries) instead of the prior exclude-list.
- Order is `--exclude-glob '*'` BEFORE the `--include-glob` entries (verified empirically as load-bearing due to lftp's last-matching-rule-wins filter semantics).
- Only `index.php`, `admin.php`, `config.php`, `.htaccess`, `.env` are transferred by the mirror command — confirmed via a real local lftp run against the actual project root, not just static inspection.
- `pg.sql`, `conf.php`, `README.md`, `.gitignore`, `.env.example`, `.env.deploy.example` are never transferred (previously they were, since they weren't on the old exclude list).
- All prior safety behavior is preserved unchanged: credentials passed to lftp only via stdin heredoc (never `-u` on the command line), required `DEPLOY_FTP_*` env var validation, clear missing-lftp error message, `--dry-run` passthrough, explicit FTPS (`ftp:ssl-allow yes` / `ftp:ssl-force yes` / `ftp:ssl-protect-data yes`).
- `bash -n deploy.sh` succeeds.

Before running a real deploy against production, the user should still run `./deploy.sh --dry-run` with real credentials once to visually confirm the verbose lftp output lists only the 5 expected files against the actual remote host.
</success_criteria>

<output>
After completion, create `.planning/quick/260727-xbb-restrict-deploy-sh-to-production-necessa/260727-xbb-SUMMARY.md`
</output>
