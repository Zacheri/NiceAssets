#!/bin/bash
# Restore the ATR database from a backup dump.
# Usage: ./install/restore.sh <path-to-dump> [path-to-uploads-tar.gz]
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[ "$(uname -m)" = "arm64" ] && PREFIX="/opt/homebrew" || PREFIX="/usr/local"

DUMP="${1:?Usage: restore.sh <dump-file> [uploads-tar.gz]}"
UPLOADS="${2:-}"
[ -f "$DUMP" ] || { echo "Dump not found: $DUMP" >&2; exit 1; }

. <(php -r '
$c = require "'"$ROOT"'/config/app.local.php";
echo "DB_HOST=" . $c["db"]["host"] . "\nDB_PORT=" . $c["db"]["port"] . "\nDB_NAME=" . $c["db"]["name"] . "\nDB_USER=" . $c["db"]["user"] . "\nDB_PASS=" . $c["db"]["pass"] . "\n";
')

export PGPASSWORD="$DB_PASS"
PGRESTORE="$PREFIX/bin/pg_restore"
[ -x "$PGRESTORE" ] || PGRESTORE="$(command -v pg_restore)"

echo "Restoring database '$DB_NAME' from $DUMP …"
"$PGRESTORE" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" \
    --clean --if-exists --no-owner -d "$DB_NAME" "$DUMP"

if [ -n "$UPLOADS" ] && [ -f "$UPLOADS" ]; then
    echo "Restoring photo uploads from $UPLOADS …"
    mkdir -p "$ROOT/storage/uploads"
    tar -xzf "$UPLOADS" -C "$ROOT/storage/uploads"
fi
echo "Restore complete."
