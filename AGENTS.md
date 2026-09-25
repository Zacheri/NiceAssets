# Nice Assets — Agent & Contributor Guide

Nice Assets is a local-network asset-management web app in the spirit of
AssetTiger: asset lifecycle tracking (available → checked out → in repair /
broken / lost / disposed / sold / donated), 5-year linear depreciation,
warranty / low-stock / overdue alerts (bell on every page), PDF & Excel
reports, QR labels, RBAC (Admin / Department Manager / Viewer), a full audit
trail, daily backups, an admin people directory with portraits, asset photos
and a company logo, customizable themes (presets + per-user colors), an
admin CSV importer for AssetTiger exports (deterministic cleaning, optional
LLM-assisted value classification, optional photo download) — and a
local-LLM AI assistant that reads the inventory and (with explicit
confirmation) performs actions.

## Current state

- Feature-complete for its intended use; every feature has been exercised
  live in the Docker stack, including a real 2,164-row AssetTiger export
  through the CSV importer (see "History" below).
- Schema is at **v1.4** — `db/schema.sql` is a single idempotent script
  (baseline + `ALTER … IF NOT EXISTS` migrations v1.1–v1.4) applied by the
  entrypoint on every boot. There is no migration runner; add migrations as
  commented, idempotent blocks at the end of the file.
- Known limitations: no automated test suite (see Verification bar); the
  in-container LLM is CPU-only (Apple Silicon needs the external host
  server, `docs/INSTALL.md`); no TLS (LAN app — put a reverse proxy in
  front if you expose it); single-node, Postgres 17.

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
    app/Controllers/  thin controllers: Asset, Person, Photo, WorkOrder,
                      Report, Import (CSV wizard), Assistant (LLM), Account
                      (profile + theme), Admin (settings/users/departments/
                      categories/sites/backups/audit), Auth, Dashboard, Pref,
                      System
    app/Models/       one class per table; ALL SQL lives here
    app/Services/     AlertEngine, Backup, Barcode, CsvImport, Depreciation,
                      Export, LlmClient, LlmServer, WeeklyReport, Replicate,
                      Assistant/Tools (the LLM tool registry)
    app/Themes.php    theme presets + resolution (static, no DB of its own)
    bin/              CLI jobs (backup, weekly_report, alert_sweep, health, …)
    config/           app.php (defaults) + app.local.php (generated in Docker)
                      + routes.php (all routes, with role restrictions)
    db/               schema.sql (v1.4, idempotent) + seed.sql
    docker/           entrypoint.sh, nginx.conf, cron/naims, php/99-naims.ini
    public/           webroot: index.php front controller + theme
                      (css/app.css, js/app.js + assistant.js + photo-picker.js)
    templates/        PHP views, one dir per area: admin/ (incl. import.php
                      and persons/), assets/, assistant/, auth/, dashboard/,
                      photos/, reports/, work_orders/, partials/ (incl.
                      photo_picker_modal.php), layout.php
    storage/          runtime data (volume-mounted in Docker): uploads,
                      backups, logs, reports, labels, sessions, models, run,
                      imports (ephemeral CSV uploads, deleted after import)

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
  currently v0.4.1, `GGML_NATIVE=OFF`, CPU-only) in a builder stage; the
  runtime image is `php:8.4-fpm-bookworm` + nginx + cron +
  postgresql-client-17.
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
- The LLM endpoint is configurable at runtime (`llm.host`, `llm.port`,
  `llm.external` settings — Assistant tab → Model panel): external mode
  talks to a host-native server (e.g. macOS Metal, which the container
  cannot reach); `scripts/macos-llama-server.sh` starts one.
- Env vars: `NAIMS_PORT` (host port), `NAIMS_DB_*`, `NAIMS_ADMIN_PASS` (first boot
  only), `NAIMS_TZ`. See `.env.example`.

## Verification bar

There is no automated test suite. Before declaring work done:

    find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
    docker compose up --build && curl -s localhost:8080/healthz

then exercise the changed flow in a browser (or curl for headless checks:
login, capture the CSRF `_token`, POST the flow, inspect the DB). CI
(`.github/workflows/ci.yml`) runs the same lint plus the image build on
every push/PR.

## History: why this exists and how it was built

**Why.** The project started as "ATR Inventory": a self-hosted, LAN-only
alternative to AssetTiger for a small business tracking mixed assets
(electronics, furniture, reward cards) across several sites. Vanilla PHP +
PostgreSQL was chosen deliberately — no framework, no build step, no CDN —
so the whole app is readable, patchable, and deployable on a cheap always-on
box. The AI assistant is local (llama.cpp + GGUF) because the inventory
contains employee names and asset detail that should never leave the
network. Docker packaging exists so deployment is one command with zero host
prerequisites. The app was renamed **Nice Assets (NAIMS)** for public
release. The later feature items came from using the app against real
AssetTiger data:

- **Notifications** — alerts were dashboard-only; the business wanted them
  visible on every page (bell + floating panel, layout-level).
- **People directory** — checkouts are assigned to people, not accounts
  (schema v1.1); people needed a visual directory (cards, portraits,
  status, their assets).
- **Photos suite** — asset galleries already existed; added photo *kinds*
  (v1.2): person portraits and a company logo, plus a shared picker modal.
- **Theming** — branding for the business: a company-default preset plus
  per-user overrides (v1.3), with a small set of user-customizable color
  tokens.
- **CSV import** — migrating the existing AssetTiger export. The export is
  messy (garbage serials like `?`/`NA`/`Article #:`, `N in stock` models,
  status notes written into the department column), so the design is: a
  deterministic cleaning pipeline that always works, with the local LLM as
  an *optional* assist that classifies distinct values (person vs
  non-person, brand canonicals, department vs status-note) — never required,
  never trusted unvalidated.

**How.** Built iteratively with an AI coding agent (opencode) using the
"superpowers" workflow: brainstorm → written spec → implementation plan →
subagent-driven development (one implementer subagent per task, each task's
diff reviewed by a separate reviewer subagent, fix waves for findings) →
merge → live end-to-end verification against real data. Specs and plans
live in `docs/superpowers/` (gitignored — they are working documents, not
release docs). There is no automated test suite by design at this scale;
the verification bar above plus live E2E checks is the quality gate. The
CSV importer, for example, was verified against the real 2,164-row export:
the resulting status distribution matched a hand-computed profile exactly,
re-imports are idempotent (all rows `duplicate_skip`), LLM classification
and photo download were verified with the model running, and non-admin
access returns 403.
