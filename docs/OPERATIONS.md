# Operations

## Background jobs

Scheduled by cron inside the app container (`/etc/cron.d/naims`, installed
from `docker/cron/naims`):

| Job | Schedule | Command | Log |
|---|---|---|---|
| Daily backup | 02:00 | `php bin/backup.php daily` | `storage/logs/backup-cron.log` |
| Weekly activity report | Saturday 18:00 | `php bin/weekly_report.php` | `storage/logs/weekly-report-cron.log` |
| Alert sweep (email) | every 15 min | `php bin/alert_sweep.php` | `storage/logs/alert-sweep-cron.log` |

All jobs are idempotent and safe to run by hand:

    docker compose exec app php /var/www/naims/bin/backup.php daily

## Backups & restore

A backup is a `pg_dump -Fc` of the database plus a `tar.gz` of
`storage/uploads`, written to `storage/backups/` (14 days kept by default;
`backup_keep` setting).

### Restore

The usual path is the UI: **Admin → Backups** lists every backup (dump
name, size, created). Click **Restore** on the row you want and confirm
the prompt. It restores that backup's database dump and, when one exists,
its uploads archive, overwriting the current data. It runs with the app
up, so make sure no one is working.

CLI fallback, if the web UI is not usable:

    # 1. Find the backup you want
    docker compose exec app ls -lt /var/www/naims/storage/backups | head

    # 2. If the backup includes an uploads archive, extract it (app still up)
    docker compose exec app tar -xzf /var/www/naims/storage/backups/<uploads-file>.tar.gz \
      -C /var/www/naims/storage/uploads

    # 3. Stop the app so nothing writes during the DB restore
    docker compose stop app

    # 4. Copy the dump into the db container (storage volume is only on app)
    docker compose cp app:/var/www/naims/storage/backups/<dump-file>.dump db:/tmp/restore.dump

    # 5. Restore the database from the db container (fresh public schema, then the dump)
    docker compose exec -e PGPASSWORD=naims db psql -h 127.0.0.1 -U naims -d naims \
      -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'
    docker compose exec -e PGPASSWORD=naims db pg_restore \
      -h 127.0.0.1 -U naims -d naims --clean --if-exists /tmp/restore.dump

    # 6. Start the app and drop the copied dump
    docker compose start app
    docker compose exec db rm /tmp/restore.dump

Check the actual file names in `storage/backups/` before running Steps
2–5. If you set `NAIMS_DB_PASS` in `.env`, use that value for `PGPASSWORD`.

## Logs

All logs live in the `naims_storage` volume under `/var/www/naims/storage/logs/`:

| File | Source |
|---|---|
| `app.log` | application log |
| `mail.log` | outbound mail |
| `backup-cron.log`, `weekly-report-cron.log`, `alert-sweep-cron.log` | cron jobs |
| `llama.log` | llama-server stdout/stderr |
| `nginx-access.log`, `nginx-error.log` | nginx |

    docker compose exec app tail -f /var/www/naims/storage/logs/app.log

## Health

    curl http://localhost:8080/healthz

Returns JSON like `{"status":"ok","database":"ok","assets":42,...}` (HTTP
503 when the database is unreachable). The container healthcheck uses the
same endpoint.

## Data layout

| Volume | Container path | Contents |
|---|---|---|
| `naims_storage` | `/var/www/naims/storage` | uploads, backups, logs, reports, labels, sessions, models, run |
| `naims_pgdata` | `/var/lib/postgresql/data` | Postgres data |

Wipe everything: `docker compose down -v`.

## Updating

    git pull
    docker compose up --build

The entrypoint re-applies the (idempotent) schema on start, so schema
changes ship with the code. Rebuilds only recompile llama.cpp when the
pinned tag in the Dockerfile changes (Docker layer cache).

## Timezone

Set `NAIMS_TZ` in `.env` (e.g. `America/New_York`) and `docker compose up -d`.
Cron schedules use the container's local time, which the entrypoint sets
from `NAIMS_TZ`.
