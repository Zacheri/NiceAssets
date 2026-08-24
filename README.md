# ATR Inventory

A local-network inventory management application for tracking mixed assets — electronics,
furniture, rewards cards — across multiple sites and sub-locations. Built to mimic
AssetTiger core functionality: lifecycle tracking, depreciation, alerts, reports,
barcode/QR scanning, and role-based access, with a modern responsive dashboard.

Stack: **PHP 8 (vanilla, no framework) · PostgreSQL · Nginx + PHP-FPM** on macOS.
Designed for 10,000+ asset records and ~10 transactions/minute, with no external
cache layer (PostgreSQL query optimization instead).

## Features

- **Asset lifecycle** — Available → Checked Out → In Repair / Broken / Lost /
  Disposed / Sold / Donated. Check-outs go to a person or a department, with
  direct person-to-person transfer (no check-in cycle). Work orders with
  auto-numbering; completing a WO returns the asset to stock.
- **Persons** — employee records (name, job title, personal/work email, phone,
  address, department, notes) with an active/terminated toggle on each card and
  in the edit form. Terminated persons stay on assets they hold but are hidden
  from check-out and transfer pickers.
- **Full asset record** — tag, serial, model, brand, category, department,
  site + sub-location, purchase date/cost, warranty, due date, sub-quantity,
  created-by tracking, status reason.
- **Depreciation** — 5-year linear, computed live (cost/60 per month). Current
  value, accumulated depreciation, and remaining months shown on every asset
  and in the dashboard.
- **Photos** — centralized gallery, unlimited per asset, reusable across assets
  of the same variety, first photo = thumbnail, thumbnails in UI and PDF reports.
- **Search** — full-text (PostgreSQL tsvector) + ILIKE fallback, advanced filters
  (category, department, site, location, status, brand, model, assigned person,
  purchase/warranty date ranges, overdue-only), sortable, paginated grid with
  adjustable column count (1–6).
- **Barcode / QR** — AssetTiger-compatible QR labels (raw asset tag payload).
  USB keyboard-wedge scanners work out of the box: scan anywhere and the asset
  opens. Scanning is search/view only.
- **Alerts** — warranty (90/60/30-day tiers), low stock (per-category thresholds),
  multi-asset assignment (2+ similar assets per person), full depreciation
  (per-category toggle), overdue checkouts. Dashboard always; email where the
  spec requires, with per-role email toggles and once-per-day dedupe.
- **Reports** — Inventory Count, Low Stock, Depreciation, Person Assignment,
  Assignment Duration, Multi-Asset Alert, plus a custom report builder.
  PDF export (with photo thumbnails) and Excel export with a **live-formulas or
  static-values** toggle. Every run is versioned (date/time/version) and
  re-downloadable.
- **Weekly report** — automatic every Saturday (launchd) summarizing the
  previous Mon–Fri: check-ins, check-outs, transfers, and status changes.
- **RBAC + audit** — Admin / Department Manager / Viewer, department scoping,
  and a complete audit trail of every action.
- **Resilience** — PDO auto-reconnect on dropped connections, launchd-managed
  services (survive reboot), daily automated backups (pg_dump -Fc, keep 14),
  one-click restore in the admin panel.

## Quick start

```bash
cd ATR
./install/install.sh
```

That's it. The installer:

1. Installs Homebrew packages if missing (nginx, postgresql, php, composer)
2. Creates the `atr` database, loads the schema + base seed (users and settings)
3. Runs `composer install`
4. Writes Nginx config (default port **8080**, no sudo needed) and starts it
5. Schedules background jobs (daily backup, Saturday weekly report, 15-min alert sweep)

Then open **http://127.0.0.1:8080** (or the LAN URL the installer prints) and sign in:

| Username | Password    | Role               |
|----------|-------------|--------------------|
| admin    | Admin1234   | Admin              |
| manager  | Manager1234 | Department Manager |
| viewer   | Viewer1234  | Viewer             |

**Change these passwords immediately** (Admin → Users, or
`php bin/reset_admin_password.php admin <new-password>`).

The app starts **empty** — no sample assets or history are loaded. Add your
categories, sites, locations, and departments under **Admin**, then create
assets under **Assets → New Asset**. (A demo dataset exists for evaluation:
re-run the installer and answer `y` to the sample-data prompt, or pass
`ATR_SEED=1`.)

Full step-by-step for non-technical users: [docs/INSTALL.md](docs/INSTALL.md)
Operations, backup/restore, secondary server: [docs/OPERATIONS.md](docs/OPERATIONS.md)
Architecture and how to extend: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)

## Directory layout

```
app/            Core framework, models, controllers, services
bin/            CLI jobs (weekly report, alert sweep, backup, health check)
config/         app.php (defaults), app.local.php (local creds), routes.php
db/             schema.sql, seed.sql (base), seed-demo.sql (opt-in)
install/        install.sh, nginx.conf, launchd plists, backup/restore
public/         webroot (index.php front controller + css/js/img)
storage/        uploads, backups, logs, reports, labels, sessions
templates/      PHP view templates
docs/           user + developer documentation
```

## Useful commands

```bash
php bin/health.php                  # system self-check
./install/backup.sh                 # manual backup now
./install/restore.sh <dump> [uploads.tar.gz]   # restore
./install/uninstall.sh              # remove services/config (DB kept unless asked)
curl http://127.0.0.1:8080/healthz  # JSON health endpoint for monitoring
```
