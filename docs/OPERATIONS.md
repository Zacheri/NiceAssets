# Operations

## Background jobs

Scheduled by cron inside the app container (`/etc/cron.d/atr`, installed
from `docker/cron/atr`):

| Job | Schedule | Command | Log |
|---|---|---|---|
| Daily backup | 02:00 | `php bin/backup.php daily` | `storage/logs/backup-cron.log` |
| Weekly activity report | Saturday 18:00 | `php bin/weekly_report.php` | `storage/logs/weekly-report-cron.log` |
| Alert sweep (email) | every 15 min | `php bin/alert_sweep.php` | `storage/logs/alert-sweep-cron.log` |

All jobs are idempotent and safe to run by hand:

    docker compose exec app php /var/www/atr/bin/backup.php daily

## Backups & restore

A backup is a `pg_dump -Fc` of the database plus a `tar.gz` of
`storage/uploads`, written to `storage/backups/` (14 days kept by default;
`backup_keep` setting).

### Restore

    # 1. Stop the app so nothing writes during the restore
    docker compose stop app

    # 2. Find the backup you want
    docker compose exec app ls -lt /var/www/atr/storage/backups | head

    # 3. Restore the database (fresh public schema, then the dump)
    docker compose exec -e PGPASSWORD=atr app psql -h db -U atr -d atr \
      -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'
    docker compose exec -e PGPASSWORD=atr app pg_restore \
      -h db -U atr -d atr --clean --if-exists \
      /var/www/atr/storage/backups/<dump-file>.dump

    # 4. If the backup includes an uploads archive, extract it
    docker compose exec app tar -xzf /var/www/atr/storage/backups/<uploads-file>.tar.gz \
      -C /var/www/atr/storage

    # 5. Start the app
    docker compose start app

Check the actual file names in `storage/backups/` before running Step 3/4.

## Logs

All logs live in the `atr_storage` volume under `/var/www/atr/storage/logs/`:

| File | Source |
|---|---|
| `app.log` | application log |
| `mail.log` | outbound mail |
| `backup-cron.log`, `weekly-report-cron.log`, `alert-sweep-cron.log` | cron jobs |
| `llama.log` | llama-server stdout/stderr |
| `nginx-access.log`, `nginx-error.log` | nginx |

    docker compose exec app tail -f /var/www/atr/storage/logs/app.log

## Health

    curl http://localhost:8080/healthz

Returns JSON like `{"status":"ok","database":"ok","assets":42,...}` (HTTP
503 when the database is unreachable). The container healthcheck uses the
same endpoint.

## Data layout

| Volume | Container path | Contents |
|---|---|---|
| `atr_storage` | `/var/www/atr/storage` | uploads, backups, logs, reports, labels, sessions, models, run |
| `atr_pgdata` | `/var/lib/postgresql/data` | Postgres data |

Wipe everything: `docker compose down -v`.

## Updating

    git pull
    docker compose up --build

The entrypoint re-applies the (idempotent) schema on start, so schema
changes ship with the code. Rebuilds only recompile llama.cpp when the
pinned tag in the Dockerfile changes (Docker layer cache).

## Timezone

Set `ATR_TZ` in `.env` (e.g. `America/New_York`) and `docker compose up -d`.
Cron schedules use the container's local time, which the entrypoint sets
from `ATR_TZ`.
