# Nice Assets — Agent & Contributor Guide

Nice Assets is a local-network asset-management web app in the spirit of
AssetTiger: asset lifecycle tracking (available → checked out → in repair /
broken / lost / disposed / sold / donated), 5-year linear depreciation,
warranty / low-stock / overdue alerts, PDF & Excel reports, QR labels, RBAC
(Admin / Department Manager / Viewer), a full audit trail, daily backups —
and a local-LLM AI assistant that reads the inventory and (with explicit
confirmation) performs actions.

## Stack

- PHP 8.4+ — vanilla, no framework; the micro-framework lives in `app/Core/`
- PostgreSQL 17
- Nginx + PHP-FPM
- llama.cpp `llama-server` (built into the image, CPU-only) for the AI assistant
- Composer for PHP deps; no JS build step (vanilla HTML/CSS/JS, no CDN)

## Running it

Everything runs in Docker:

    docker compose up --build        # first run compiles llama.cpp (5–15 min)
    docker compose logs -f app       # first-boot admin password is printed here
    docker compose down -v           # stop and wipe all data

App: http://localhost:8080 (host port: `NAIMS_PORT`). Health: `GET /healthz`.

Useful one-liners:

    docker compose exec app php bin/health.php            # DB + storage check
    docker compose exec app php bin/backup.php daily      # run a backup now
    docker compose exec app php bin/alert_sweep.php       # run the alert sweep now
    docker compose exec app php bin/weekly_report.php     # send the weekly report now
    docker compose exec app ls /var/www/naims/storage/models  # uploaded LLM models

## Layout

    app/Core/         hand-rolled framework: App (router), Auth, Config, CSRF,
                      Database (PDO), Logger, Mailer, Request, Response, View
    app/Controllers/  thin controllers, one per area
    app/Models/       one class per table; ALL SQL lives here
    app/Services/     AlertEngine, Backup, Barcode, Depreciation, Export,
                      LlmClient, LlmServer, WeeklyReport, Replicate,
                      Assistant/Tools (the LLM tool registry)
    bin/              CLI jobs (backup, weekly_report, alert_sweep, health, …)
    config/           app.php (defaults) + app.local.php (generated in Docker)
                      + routes.php (all routes, with role restrictions)
    db/               schema.sql + seed.sql (both idempotent)
    docker/           entrypoint.sh, nginx.conf, cron/naims, php/99-naims.ini
    public/           webroot: index.php front controller + theme (css/js/img)
    templates/        PHP views, one dir per area
    storage/          runtime data (volume-mounted in Docker): uploads,
                      backups, logs, reports, labels, sessions, models, run

## Conventions

- PSR-4 `App\` → `app/` via Composer. No frameworks, no autoloading tricks.
- SQL belongs in `app/Models/*`. Controllers stay thin.
- Every POST is CSRF-protected: the router validates `_token` automatically.
  Forms use `csrf_field()`; fetch/XHR bodies append `_token` from
  `window.NAIMS.token`.
- Roles: `admin`, `department_manager`, `viewer`. Restrict routes with
  `'roles' => ['admin']` in `config/routes.php`; check `Auth::hasRole()` /
  `Auth::isAdmin()` in controllers; department managers are row-scoped via
  `Auth::scopeWhere()`.
- Audit every mutation: `Audit::log(action, entity, entityId, details)`.
- Views: `View::render('area/template', $data)`; escape with `e()`; links
  with `url()`; assets with `asset_url()`.
- Frontend: vanilla JS IIFEs in `public/theme/js/`, one CSS file
  (`public/theme/css/app.css`). No bundler, no CDN, no framework.
- Config precedence: `config/app.php` → `config/app.local.php` (Docker
  entrypoint generates it from env on every boot) → `settings` DB table at
  runtime (`Setting::get/set`).
- Error responses: `Response::json(['error' => '…'], 4xx)`; user-facing
  messages are plain sentences.

## Container notes

- The image builds llama.cpp from source (pinned tag in the Dockerfile,
  `GGML_NATIVE=OFF`, CPU-only) in a builder stage; the runtime image is
  `php:8.4-fpm-bookworm` + nginx + cron + postgresql-client-17.
- Entrypoint (`docker/entrypoint.sh`) on every boot: sets TZ → renders
  `config/app.local.php` from env → waits for Postgres → applies
  `db/schema.sql` + `db/seed.sql` (idempotent) → first-boot admin password
  (marker: `storage/.admin_initialized`) → starts cron, php-fpm, nginx.
- Background jobs are cron (`/etc/cron.d/naims`, from `docker/cron/naims`):
  backup daily 02:00, weekly report Sat 18:00, alert sweep every 15 min.
- The app manages the llama-server lifecycle itself
  (`App\Services\LlmServer`: spawn/kill by PID, restart on model change).
  Models are user-uploaded `.gguf` files in `storage/models` (Assistant tab,
  admin only, 12 GB cap). No model is bundled.
- Env vars: `NAIMS_PORT` (host port), `NAIMS_DB_*`, `NAIMS_ADMIN_PASS` (first boot
  only), `NAIMS_TZ`. See `.env.example`.

## Verification bar

There is no automated test suite. Before declaring work done:

    find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
    docker compose up --build && curl -s localhost:8080/healthz

then exercise the changed flow in a browser. CI (`.github/workflows/ci.yml`)
runs the same lint plus the image build on every push/PR.
