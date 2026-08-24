#!/bin/bash
# ATR Inventory — uninstall (keeps your database by default)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AGENTS="$HOME/Library/LaunchAgents"

if [ "$(uname -m)" = "arm64" ]; then PREFIX="/opt/homebrew"; else PREFIX="/usr/local"; fi

for label in com.atr.backup com.atr.weekly-report com.atr.alert-sweep; do
    if launchctl print "gui/$(id -u)/$label" >/dev/null 2>&1; then
        launchctl bootout "gui/$(id -u)/$label" 2>/dev/null || true
    fi
    rm -f "$AGENTS/$label.plist"
    echo "Removed $label"
done

rm -f "$PREFIX/etc/nginx/servers/atr.conf"
nginx -t >/dev/null 2>&1 && nginx -s reload 2>/dev/null || echo "nginx not running; config removed for next start"

echo
read -r -p "Also remove the 'atr' database? (this DELETES all inventory data) [y/N]: " ans
if [ "$ans" = "y" ] || [ "$ans" = "Y" ]; then
    psql -U postgres -c "DROP DATABASE IF EXISTS atr;" || true
    psql -U postgres -c "DROP ROLE IF EXISTS atr;" || true
    echo "Database and role removed."
fi
echo "Done. Application files in $ROOT were left in place (remove the folder if you wish)."
echo "PostgreSQL and Nginx services were left running for other uses. Stop them with: brew services stop postgresql nginx"
