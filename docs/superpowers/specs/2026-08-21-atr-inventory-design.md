# ATR Inventory Management — Design Spec

Date: 2026-08-21 · Status: Approved (with Composer libraries)

## 1. Context

Local-network inventory management app mimicking AssetTiger, for tracking mixed assets
(electronics, furniture, rewards cards) across multiple sites and sub-locations.
Target: macOS 26 (Apple Silicon), Nginx + PostgreSQL, vanilla PHP 8, 10,000+ asset
records, ~10 transactions/minute, role-based access (Admin / Department Manager /
Viewer), audit logging, barcode/QR scanning, depreciation, alerts, reports with
PDF/Excel export, automated weekly report, daily automated backups.

## 2. Stack

- **PHP 8.2+** vanilla (no framework) — modular MVC-lite, easy to patch and redeploy.
- **PostgreSQL** (Homebrew, launchd-managed via `brew services`) — relational schema,
  indexes + tsvector full-text search sized for 10k+ rows. No Redis/Memcached
  (storage preservation over speed, per requirement).
- **Nginx** (Homebrew) with PHP-FPM; port 8080 by default (no sudo needed).
- **Composer libraries** (only where hand-rolling is weak):
  - `dompdf/dompdf` — PDF export incl. photo thumbnails and QR labels
  - `phpoffice/phpspreadsheet` — Excel export with formula/static toggle
  - `endroid/qr-code` — QR labels (raw asset tag payload, AssetTiger compatible)
- No external CDNs — all CSS/JS served locally for LAN/offline use.

## 3. Architecture

```
ATR/
├── app/
│   ├── Core/         # App (router+dispatch), Database (PDO + retry), Auth (RBAC),
│   │                 # CSRF, View, Request, Response, Mailer (mail()/SMTP), Config
│   ├── Models/       # Asset, User, Department, Site, Location, Category, Photo,
│   │                 # WorkOrder, Audit, Setting, ReportRun, UserPref
│   ├── Controllers/  # Auth, Dashboard, Asset, Photo, WorkOrder, Report, Admin
│   └── Services/     # Depreciation, AlertEngine, Barcode, Export, Replicate,
│                     # WeeklyReport, Backup
├── bin/              # CLI: weekly_report.php (Sat), alert_sweep.php (15 min),
│                     # backup.php (daily), make_sample_photos.php, health.php
├── config/           # app.php (defaults), app.local.php (installer-generated),
│                     # routes.php
├── db/               # schema.sql, seed.sql
├── install/          # install.sh, uninstall.sh, nginx.conf template, launchd plists,
│                     # backup.sh, restore.sh
├── public/           # index.php front controller + assets/ (css, js, img)
├── storage/          # uploads/ (central photo gallery), backups/, logs/, reports/,
│                     # labels/, sessions/
├── templates/        # layout + pages (PHP views)
└── docs/             # README, INSTALL, OPERATIONS, ARCHITECTURE
```

**Request flow:** Nginx → `public/index.php` → `App::run()` → route match
(`config/routes.php`, pattern params) → auth/role gate → controller method →
`Response` (redirect/json/file) or `View::render` (template inside layout).

## 4. Data Model (PostgreSQL)

- `roles` — admin, department_manager, viewer (seeded).
- `departments`, `sites`, `locations` (site → sub-locations).
- `users` — username, password_hash (min 8 chars, no expiry), role_id,
  department_id (scopes managers/viewers; null for admins), is_active.
- `categories` — name, low_stock_threshold, depreciation_alert_enabled.
- `assets` — asset_tag (unique), serial_number, model_number, brand, category_id,
  department_id, site_id, location_id, assigned_to_user_id, assigned_to_department_id,
  purchase_date, purchase_cost, warranty_expiration, due_date, status
  (`available|checked_out|in_repair|broken|lost|disposed|sold|donated`), status_reason,
  sub_quantity, work_order_id, disposal_*/sold_*/donated_* columns,
  created_by, created_at, updated_at, generated tsvector `search_vector` + GIN index.
- `work_orders` — wo_number, asset_id, summary, status, timestamps.
- `photos` (central gallery, reusable across assets) + `asset_photos`
  (asset_id, photo_id, position, is_thumbnail → first photo = thumbnail).
- `audit_log` — user, action (`asset.check_out`, `user.create`, ...), entity,
  entity_id, details jsonb, ip, created_at. Source of truth for the weekly report.
- `settings` — key/value store (SMTP, per-role email toggles, multi-asset threshold,
  app version stamp).
- `alert_log` — dedupe keys so email alerts don't repeat daily.
- `report_runs` — name, type, params, generated_by, generated_at, version, file paths
  (versioned reports per requirement).
- `user_prefs` — per-user UI prefs (grid column count, page size).

## 5. Feature Decisions

- **RBAC:** Admin = everything; Department Manager = view/modify assets in their
  department; Viewer = read-only, own department. Unassigned-department assets are
  admin-only. Enforced at route (role list) + query scope (department filter).
  Every mutation writes an `audit_log` row in the same transaction.
- **Depreciation:** computed live, never stored: monthly linear = cost/60, capped at
  60 months; shows current value, remaining months, fully-depreciated flag.
  Full-depreciation alert toggleable per category.
- **Lifecycle ops:** check-out (to user or department, optional due date), check-in
  (IT → stock available, closes work order), direct transfer (user→user, no
  check-in cycle), repair (auto-creates work order), broken/lost (reason + keeps
  last-assigned), disposed (location + remaining cost), sold (to/price/date),
  donated (to/value/date), replicate (copy all fields + photos, increment numeric
  suffix of tag and serial), delete (admin), email (asset summary).
- **Search:** full-text (tsvector) with ILIKE fallback on tag/serial/brand;
  advanced filters (category, department, site, location, status, brand, model,
  assigned user, purchase/warranty date range); pagination; quick fields
  (tag/serial/name). Grid view default with adjustable column count (1–6, per-user pref).
- **Scanner:** USB keyboard-wedge scanner captured by global JS → opens asset by tag
  (search/view only, no form auto-fill), per spec.
- **Alerts** (dashboard always; email per spec): warranty ≤90d (email on entry to
  window, once/day), ≤60d / ≤30d dashboard tiers; low stock (category thresholds,
  email per-role toggle); multi-assignment (2+ same brand+model per user, dashboard
  only); depreciation complete (per-category toggle, email). Severity split:
  important vs informational.
- **Reports:** six standard (inventory count, low stock, depreciation, user
  assignment, assignment duration, multi-asset) + custom builder (category,
  department, site, location, model, brand) + weekly auto report (Saturday launchd
  job; summarizes previous Mon–Fri audit activity). Exports: PDF (with photo
  thumbnails when row count ≤ 200), Excel (formula/static toggle). All runs recorded
  in `report_runs` with version + timestamp.
- **Backup:** daily `pg_dump -Fc` (launchd 02:00) + on-demand in admin panel;
  restore script (`pg_restore --clean --if-exists`); keep last 14 dumps; uploads
  included in a combined tar for full restore. Secondary-server/replication is a
  documented procedure (future), not auto-built.
- **Resilience:** PDO reconnect with retry (3×, 2s) for network outages; jobs and
  mail wrapped in try/catch with file logging; services are launchd-managed
  (survive reboot); no polling caches.
- **Versioning:** `APP_VERSION` in config; shown in UI footer + admin system page;
  report versions in DB; git repo for code.

## 6. Out of Scope (v1, documented as future)

- Custom label designer / modular label templates (Barcode service is the extension point).
- Live DB replication / standby monitoring (procedure only).
- 2FA, password expiration (excluded by spec).
- Code128 generation (QR implemented; service interface ready).

## 7. Verification

- `php -l` on every PHP file; `composer validate`.
- Installer is idempotent; `bin/health.php` checks DB/webroot/writable storage.
- Seed data exercises every status, alert tier, and depreciation state.
