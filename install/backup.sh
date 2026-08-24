#!/bin/bash
# Manual backup from the command line (the daily 02:00 job does this automatically).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PREFIX="/opt/homebrew"
[ -x "/usr/local/bin/php" ] && PREFIX="/usr/local"
exec "$PREFIX/bin/php" "$ROOT/bin/backup.php" "${1:-manual}"
