# Operations Guide

Day-to-day operation, backup/restore, updates, and secondary-server notes.

## Background services

| Service | Managed by | Schedule | Log |
|---------|-----------|----------|-----|
| Nginx + PHP-FPM | `brew services` (launchd) | always | `storage/logs/nginx-*.log` |
| PostgreSQL | `brew services` (launchd) | always | system logs |
| Daily backup | launchd agent `com.atr.backup` | 02:00 daily | `storage/logs/backup.log` |
| Weekly report | launchd agent `com.atr.weekly-report` | Sat 18:00 | `storage/logs/weekly-report.log` |
| Alert sweep | launchd agent `com.atr.alert-sweep` | every 15 min | `storage/logs/alert-sweep.log` |

All services start automatically at boot and survive reboots. If the Mac was
asleep during a scheduled job, the job runs at the next wake.

Useful launchd commands (from Terminal):

```bash
launchctl list | grep com.atr                       # are the agents loaded?
launchctl kickstart gui/$(id -u)/com.atr.backup     # run the backup now
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.atr.backup.plist  # re-add if missing
```

## Backups

Backups are PostgreSQL **custom-format dumps** (`.dump`, compressed, restored
faster than plain SQL) plus a tar of the photo uploads. The newest **14** are
kept automatically.

- Automatic: daily at 02:00.
- Manual: Admin → Backups → **Run backup now**, or `./install/backup.sh`.

### Where to keep off-server copies

Do not rely on backups that live only on the same machine as the data. After
each day (or from the same schedule), copy the backups folder to another
machine or network share:

```bash
scp -r ~/Documents/ATR/storage/backups you@backup-host:~/atr-backups/
```

### Restore

1. From Admin → Backups, click **Restore** on the dump you want, **or**
   `./install/restore.sh storage/backups/atr_db_20260820_020000_daily.dump`
2. Confirm when prompted. The restore is done inside the app's own credentials.
3. Photos: if the same timestamp has an `atr_uploads_*.tar.gz`, the restore
   script also unpacks it (pass it as the second argument for the CLI version).

> Restore replaces current data. Run a fresh backup first if you might need it.

### Database replication / secondary server (future)

The app is built so a second machine can be added later:

1. Install ATR on the second Mac (its own database).
2. Nightly: `pg_dump` on the primary → copy the `.dump` over → `restore.sh`
   on the secondary. This gives you a warm standby and an off-site backup.
3. Point a spare browser at the secondary's `/healthz` to monitor liveness.
4. For live replication, run `pg_basebackup` + streaming replication between the
   two PostgreSQL instances (standard PostgreSQL procedure). The app needs no
   changes — it only ever talks to one database host at a time.

## Updating / patching the app

The app is versioned in `config/app.php` (`app_version`) and shown in the UI
footer and Admin → System. Reports record the version of the build that made
them.

To deploy an update (git-based):

```bash
cd ~/Documents/ATR
git pull                      # or copy the updated files over
composer install --no-dev     # only if composer.json changed
./install/install.sh          # safe to re-run; refreshes services & jobs
```

The installer never drops your database. Schema changes ship as
`db/schema.sql` (idempotent) — re-running it after an update is safe.

## Email delivery

By default the app uses the local `mail()` function. On a typical Mac with no
mail server this fails silently (and is logged to `storage/logs/mail.log`),
which is fine — dashboard alerts still appear. To enable real email, open
**Admin → Settings** and enter your SMTP server (host, port, user, password),
e.g. your office relay or a transactional provider. Per-role email toggles are
on the same page.

## Scanners & labels

- Any USB **keyboard-wedge** scanner works: scan a QR label anywhere and the
  asset page opens. Nothing to configure.
- QR payloads are the **raw asset tag number** (AssetTiger compatible).
- Print from the asset page: **QR label** (just the code) or **Print sheet**
  (full asset card with photo, QR, and financial detail).
- Custom label layouts later: extend `app/Services/Barcode.php` — the rest of
  the app only ever asks it for a file path, so adding Code128 or a label
  template touches one file.

## Storage & performance notes

- No Redis/Memcached by design: PostgreSQL indexes + a tsvector search column
  keep 10k+ assets responsive at this transaction rate.
- Grid pages are paginated (default 24, up to 200/page) so the browser never
  loads 10,000 cards.
- PDF reports cap at 2,000 rows (noted in the document); use Excel for full
  listings — it handles the entire table comfortably.
- Large photo uploads are allowed up to 20 MB each (Nginx `client_max_body_size`).

## Health monitoring

`GET /healthz` (no login) returns JSON: app version, database status, asset
count, timestamp. Example:

```bash
curl -s http://127.0.0.1:8080/healthz
# {"status":"ok","app":"ATR Inventory","version":"1.0.0","database":"ok","assets":18,"time":"..."}
```

Point a watchdog (e.g. a cron `curl` on a management machine, or a future
secondary server) at this endpoint to detect outages.

## Log files

| File | Contents |
|------|----------|
| `storage/logs/app.log` | Application errors and job output |
| `storage/logs/mail.log` | Failed email sends |
| `storage/logs/backup.log` | Daily backup job |
| `storage/logs/weekly-report.log` | Saturday report job |
| `storage/logs/alert-sweep.log` | 15-minute alert job |
| `storage/logs/nginx-*.log` | Web server access/error |

Logs are plain text; truncate or archive them freely.
