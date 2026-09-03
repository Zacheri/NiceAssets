#!/bin/bash
# ATR Inventory — container entrypoint.
# Runs on every start: sets the timezone, renders config/app.local.php from
# the environment, waits for Postgres, applies the idempotent schema + seed,
# handles the first-boot admin password, then starts cron + php-fpm + nginx
# (nginx in the foreground).
set -euo pipefail

APP=/var/www/atr
DB_HOST="${ATR_DB_HOST:-db}"
DB_PORT="${ATR_DB_PORT:-5432}"
DB_NAME="${ATR_DB_NAME:-atr}"
DB_USER="${ATR_DB_USER:-atr}"
DB_PASS="${ATR_DB_PASS:-atr}"

# 0. Timezone (affects cron schedules and system time).
export TZ="${ATR_TZ:-UTC}"
if [ -f "/usr/share/zoneinfo/${TZ}" ]; then
  ln -sf "/usr/share/zoneinfo/${TZ}" /etc/localtime
fi

# 1. Render config/app.local.php from the environment (every boot, so env
#    changes take effect without rebuilding).
php -r '
$cfg = [
    "db" => [
        "host" => getenv("ATR_DB_HOST") ?: "db",
        "port" => getenv("ATR_DB_PORT") ?: "5432",
        "name" => getenv("ATR_DB_NAME") ?: "atr",
        "user" => getenv("ATR_DB_USER") ?: "atr",
        "pass" => getenv("ATR_DB_PASS") ?: "atr",
    ],
    "timezone" => getenv("ATR_TZ") ?: "UTC",
];
file_put_contents("/var/www/atr/config/app.local.php", "<?php\nreturn " . var_export($cfg, true) . ";\n");
'

# 2. Wait for PostgreSQL (up to 60s).
for _ in $(seq 1 60); do
  if pg_isready -h "$DB_HOST" -p "$DB_PORT" -q; then
    break
  fi
  sleep 1
done
if ! pg_isready -h "$DB_HOST" -p "$DB_PORT" -q; then
  echo "ERROR: PostgreSQL never became ready at $DB_HOST:$DB_PORT" >&2
  exit 1
fi

# 3. Apply schema + base seed. Both are idempotent (CREATE TABLE IF NOT
#    EXISTS / ON CONFLICT DO NOTHING), so this is safe on every boot and
#    applies schema upgrades automatically.
export PGPASSWORD="$DB_PASS"
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -f "$APP/db/schema.sql"
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -f "$APP/db/seed.sql"

# 4. First boot only: set the admin password once. If ATR_ADMIN_PASS is set
#    use it; otherwise generate a random one and print it to the logs. The
#    marker file prevents later boots from clobbering a password the user
#    changed in the UI.
if [ ! -f "$APP/storage/.admin_initialized" ]; then
  if [ -n "${ATR_ADMIN_PASS:-}" ]; then
    NEWPASS="$ATR_ADMIN_PASS"
    echo "ATR admin password set from ATR_ADMIN_PASS."
  else
    NEWPASS="$(php -r 'echo bin2hex(random_bytes(8));')"
    echo "ATR admin password: $NEWPASS"
  fi
  php "$APP/bin/reset_admin_password.php" admin "$NEWPASS"
  touch "$APP/storage/.admin_initialized"
fi

# 5. Start services. cron and php-fpm daemonize; nginx runs in the
#    foreground so signals propagate to the container.
cron
php-fpm
exec nginx -g "daemon off;"
