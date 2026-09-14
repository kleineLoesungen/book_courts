#!/usr/bin/env bash
set -euo pipefail

# Resolve the absolute directory containing this script (local source dir mirrored to server).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Parse arguments
DRY_RUN=""
for arg in "$@"; do
  case "$arg" in
    --dry-run)
      DRY_RUN="--dry-run"
      ;;
    *)
      echo "Error: unrecognized argument: $arg" >&2
      echo "Usage: $0 [--dry-run]" >&2
      exit 1
      ;;
  esac
done

# Optionally load local, gitignored credentials file
if [ -f "$SCRIPT_DIR/.env.deploy" ]; then
  set -a
  source "$SCRIPT_DIR/.env.deploy"
  set +a
fi

# Ensure lftp is available before validating env vars
if ! command -v lftp >/dev/null 2>&1; then
  echo "Error: lftp is not installed. Install it with: brew install lftp" >&2
  exit 1
fi

# Default port
: "${DEPLOY_FTP_PORT:=21}"

# Validate required environment variables
missing=()
[ -z "${DEPLOY_FTP_HOST:-}" ] && missing+=("DEPLOY_FTP_HOST")
[ -z "${DEPLOY_FTP_USER:-}" ] && missing+=("DEPLOY_FTP_USER")
[ -z "${DEPLOY_FTP_PASS:-}" ] && missing+=("DEPLOY_FTP_PASS")
[ -z "${DEPLOY_FTP_REMOTE_DIR:-}" ] && missing+=("DEPLOY_FTP_REMOTE_DIR")

if [ "${#missing[@]}" -gt 0 ]; then
  echo "Error: missing required environment variables: ${missing[*]}" >&2
  echo "Set them in your shell, or create a local .env.deploy file (see .env.deploy.example)." >&2
  exit 1
fi

# Status message (never print DEPLOY_FTP_PASS)
if [ -n "$DRY_RUN" ]; then
  dry_label=" [dry-run]"
else
  dry_label=""
fi
echo "Deploying to ${DEPLOY_FTP_HOST}:${DEPLOY_FTP_REMOTE_DIR}${dry_label}"

# Deploy via lftp; credentials are passed only via stdin heredoc, never as CLI args.
lftp <<LFTP_EOF
set ftp:ssl-allow yes
set ftp:ssl-force yes
set ftp:ssl-protect-data yes
open -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" -p "${DEPLOY_FTP_PORT}" "${DEPLOY_FTP_HOST}"
mirror --reverse --verbose ${DRY_RUN} --exclude-glob '*' --include-glob 'index.php' --include-glob 'admin.php' --include-glob 'config.php' --include-glob '.htaccess' --include-glob '.env' --include-glob 'assets/' --include-glob 'assets/*.js' "${SCRIPT_DIR}/" "${DEPLOY_FTP_REMOTE_DIR}"
bye
LFTP_EOF
