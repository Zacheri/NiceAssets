# Docker Containerization (Design Spec)

Status: approved in conversation 2026-09-02 · Scope: ATR Inventory v1.1

## Problem

ATR Inventory is currently installable only on macOS via `install/install.sh`
(Homebrew + launchd). This blocks deployment on Linux/Windows and makes it
hard for the open-source community to run, test, and iterate on the project
without a Mac.

## Goals

- Docker becomes the **only** distribution path (macOS installer removed).
- One-command start: `docker compose up --build` → app on `http://localhost:8080`.
- Local LLM capability is **always available** in the container; no model is
  bundled. The user downloads a GGUF model of their choice and **uploads it
  through the web UI** (Assistant tab), so cloning the repo never forces a
  multi-GB model download.
- Community-friendly: reproducible image, CI that verifies the image builds,
  complete docs (AGENTS.md, README, INSTALL, OPERATIONS), MIT LICENSE file.
- Multi-arch image: amd64 and arm64 (Apple Silicon).

## Non-goals

- No GPU/CUDA support in the image (CPU-only llama.cpp; documented escape
  hatch for rebuilding with `GGML_NATIVE=ON` or a CUDA image later).
- No image publishing to a registry (CI builds but does not push).
- No Kubernetes/Helm, no orchestration beyond a single `docker-compose.yml`.
- No changes to app features other than the model upload/delete UI.
- No automated test suite in this effort (none exists today; CI runs
  `php -l` + image build, matching the project's current verification bar).

## Decisions (from conversation)

1. **Docker-only** — `install/install.sh`, launchd plists, and the
   Homebrew-based backup/restore/uninstall scripts are deleted.
2. **LLM always active, models user-uploaded via the Assistant tab.**
3. **CI**: GitHub Actions — `php -l` lint + `docker build` on push/PR.

## Architecture

### Topology

```
Browser ──▶ app container ──────────────┐
                 │  nginx :8080         │  same container
                 │  php-fpm :9000       │
                 │  llama-server :8082  │  (app-managed PID, as today)
                 │  cron (3 jobs)       │
                 │  postgresql-client-17│  (pg_dump/pg_restore for backups)
                 ▼                      ▼
            db container            storage volume (atr_storage)
            postgres:17             uploads, backups, logs, reports,
            (atr_pgdata volume)     labels, sessions, models, run
```

Two services, two named volumes. Only port 8080 is published.

**Why llama-server lives in the app container (not a sidecar):**
`App\Services\LlmServer` owns the llama-server lifecycle — it spawns the
process with `nohup`, tracks a PID file at `storage/run/llama.pid`, and
restarts the server whenever the selected model changes. A separate
container cannot be restarted by the app, so the sidecar approach would
require rewriting that lifecycle. In-container keeps the existing (working)
code path intact.

### Dockerfile (multi-stage)

**Stage 1 — `llama-builder`:**
- Base: `php:8.4-cli-bookworm` (any glibc base works; PHP only for the toolchain).
- Installs `cmake g++ git ca-certificates`.
- Clones `ggml-org/llama.cpp` at a pinned release tag (build arg
  `LLAMA_CPP_TAG`, default: a recent stable tag chosen at implementation time).
- Builds only the `llama-server` target:
  `cmake -DCMAKE_BUILD_TYPE=Release -DGGML_NATIVE=OFF -DBUILD_SHARED_LIBS=OFF`
  then `cmake --build ... --target llama-server -j$(nproc)`.
- `GGML_NATIVE=OFF` → portable binary (works on any amd64/arm64 CPU; slightly
  slower than native on newest CPUs — documented).

**Stage 2 — final image:**
- Base: `php:8.4-fpm-bookworm`. PHP 8.4 is the minimum because the locked
  `endroid/qr-code 6.x` requires `php ^8.4` (`composer.json` is bumped from
  `>=8.2` to `>=8.4` to make this explicit).
- apt: `nginx`, `cron`, `curl`, `ca-certificates`, plus
  `postgresql-client-17` from the PostgreSQL APT repo (pgdg) — version-matched
  to the `postgres:17` server so `pg_dump -Fc` / `pg_restore` are compatible
  (Debian bookworm's stock client is PG 15, which cannot read PG 17 dumps).
- Copies `llama-server` from stage 1 to `/usr/local/bin/llama-server`.
- Copies app source (minus `.dockerignore`d paths) to `/var/www/atr`.
- `composer install --no-dev --no-interaction` (vendor/ is git-ignored today;
  it is installed at build time).
- PHP ini overrides (conf.d): `upload_max_filesize=12G`, `post_max_size=12G`,
  `memory_limit=2G`, `max_execution_time=0` (FPM chat requests can run
  minutes; CLI jobs need it too), `upload_tmp_dir=/tmp`.
- Installs `docker/nginx.conf` → `/etc/nginx/sites-enabled/atr` (and removes
  the default site), `docker/php-fpm/atr.conf` (pool: listen
  127.0.0.1:9000, `upload_max_filesize` mirrored, `memory_limit`), cron file
  `docker/cron/atr` → `/etc/cron.d/atr`, entrypoint `docker/entrypoint.sh`.
- `EXPOSE 8080`, `HEALTHCHECK` via
  `php -r 'exit(strpos((string)@file_get_contents("http://127.0.0.1:8080/healthz"), "\"status\":\"ok\"") === false ? 1 : 0);'`
  (php is always present; no curl dependency).
- Runs as the default `www-data` user for FPM; entrypoint runs as root only
  long enough to start services (standard for single-container nginx setups).

### `.dockerignore`

Excludes: `.git`, `vendor/`, `storage/*` (all data), `docs/`, `.superpowers/`,
`.github/`, `*.md` (except none needed at runtime), `.DS_Store`, `install/`
(after removal, gone anyway). Keeps the build context small.

### Entrypoint (`docker/entrypoint.sh`)

Runs on every container start, in order:

1. **Render config** — generate `config/app.local.php` from environment:
   - `ATR_DB_HOST` (default `db`), `ATR_DB_PORT` (5432), `ATR_DB_NAME` (atr),
     `ATR_DB_USER` (atr), `ATR_DB_PASS` (atr)
   - `ATR_TZ` (default `UTC`; the old default `America/New_York` was a
     single-machine choice and is wrong as an OSS default)
   Note: the container always listens on 8080 internally; `ATR_PORT` only
   controls the host-side port mapping in compose.
   Regenerated on every boot so env changes take effect.
2. **Wait for Postgres** — loop `pg_isready -h $ATR_DB_HOST -p $ATR_DB_PORT`
   (up to ~60s).
3. **Apply schema** — `psql ... -f db/schema.sql -f db/seed.sql` (both are
   idempotent: `CREATE TABLE IF NOT EXISTS` / upsert-style seeds). Running in
   the app entrypoint (rather than postgres initdb scripts) means schema
   upgrades apply on later boots and the app works against any Postgres 17
   instance, not just the compose one.
4. **First-boot admin password** — if `storage/.admin_initialized` does not
   exist: set the `admin` user's password to `ATR_ADMIN_PASS` if that env var
   is set, otherwise generate a random 16-char password, print
   `ATR admin password: <pw>` to stdout (visible in `docker logs`), then write
   the marker file. Later boots never touch the password (users may change it
   in the UI).
5. **Start services** — `cron`, `php-fpm`, `nginx` (foreground; entrypoint
   `exec`s nginx so signals propagate).

### Nginx config (`docker/nginx.conf`)

Based on the existing `install/nginx.conf`, with placeholders resolved for
the container: listen `8080` (via `ATR_PORT`), root `/var/www/atr/public`,
FPM `127.0.0.1:9000`, access/error logs to `storage/logs/`,
`client_max_body_size 12g` (was 50m — model uploads), same security headers,
same `/uploads/` alias and PHP deny rules.

### Background jobs (cron, replacing launchd)

`/etc/cron.d/atr` (same schedules as the launchd plists):

| Job | Schedule | Command |
|---|---|---|
| Daily backup | `0 2 * * *` | `php /var/www/atr/bin/backup.php daily` |
| Weekly report | `0 18 * * 6` | `php /var/www/atr/bin/weekly_report.php` |
| Alert sweep | `*/15 * * * *` | `php /var/www/atr/bin/alert_sweep.php` |

All output appended to `storage/logs/*-cron.log`. All three scripts are
already idempotent CLI entry points.

### Model upload feature (new)

**Routes** (admin role, CSRF-protected like all POSTs):
- `POST /admin/llm/upload` — multipart field `model` (a `.gguf` file).
- `POST /admin/llm/delete` — field `model` (filename).

**Server behavior (upload):**
- Reject unless `Auth` role is `admin` and CSRF token valid (existing core).
- Validate: `is_uploaded_file`, `error === UPLOAD_ERR_OK`, basename matches
  `^[A-Za-z0-9][A-Za-z0-9._-]*\.gguf$` (path traversal impossible),
  size > 0 and ≤ 12 GiB, `disk_free_space(modelsDir) > filesize`.
- Reject if a file with the same name already exists (user deletes first).
- `move_uploaded_file()` into `LlmServer::modelsDir()`.
- `Audit::log('llm.model_upload', ...)` with filename + size.
- On success, if no model was selected yet, auto-select the uploaded model
  (convenience; user still clicks Start).

**Server behavior (delete):**
- Admin + CSRF; `basename()` + same filename regex; refuse to delete the
  currently loaded model (stop first); `unlink`; audit log.

**UI — Assistant tab becomes the home of the AI:**
- New **Model panel** on `/assistant`, rendered only for `admin` role:
  - Upload: file input + XHR (`XMLHttpRequest` for `upload.onprogress`) with
    a progress bar and % — multi-GB uploads need visible progress.
  - Model list: name, size (MB), mtime; per-row Delete button (confirm()).
  - Selected model, status pill (stopped/loading/ready), Start/Stop buttons.
  - Context-length and port settings move here too (from the System card).
- Non-admin users see the chat UI unchanged (plus the existing
  "no model" guidance, now pointing at an admin).
- **Admin → System** AI card is reduced to a compact status summary
  (binary present, model loaded, status) with a link to the Assistant tab.
  The `Install llama.cpp (brew)` button and `/admin/llm/install` route are
  removed — the binary is always present in the image.

**Limits:** nginx `client_max_body_size 12g`; PHP
`upload_max_filesize=12G`, `post_max_size=12G`; `fastcgi_read_timeout`
already 300s (raise to 3600s for very large uploads on slow links).

### Code changes (containerization)

| File | Change |
|---|---|
| `app/Services/LlmServer.php` | Remove `install()` (brew). Simplify `binary()` to `command -v llama-server` + `/usr/local/bin/llama-server` fallback. Error messages drop brew references. |
| `app/Services/Backup.php` | `pgBinary()` → `command -v pg_dump`/`pg_restore` only (drop Homebrew/Cellar path candidates). |
| `app/Controllers/AdminController.php` | Add `llmUpload()`, `llmDelete()`; remove `llmInstall()`. |
| `config/routes.php` | Add upload/delete routes; remove `/admin/llm/install`. |
| `templates/assistant/index.php` | Model panel (admin) with upload progress, list, delete, select, start/stop, settings. |
| `templates/admin/system.php` | AI card → compact status + link. |
| `composer.json` | `"php": ">=8.4"` (matches locked deps). |
| `config/app.php` | No structural change (env → `app.local.php` at entrypoint). |
| `app/Core/Database.php` | Unchanged (`gssencmode=disable` is harmless everywhere). |

### Files added

- `Dockerfile`
- `docker-compose.yml`
- `.dockerignore`
- `.env.example` (documented env vars; compose reads `.env` automatically)
- `docker/entrypoint.sh`
- `docker/nginx.conf`
- `docker/cron/atr`
- `docker/php-fpm/atr.conf` (pool tuning: upload size, listen address)
- `AGENTS.md`
- `LICENSE` (MIT — `composer.json` already declares MIT but no file exists)
- `.github/workflows/ci.yml`

### Files removed

- `install/install.sh`, `install/launchd/*.plist` (3), `install/backup.sh`,
  `install/restore.sh`, `install/uninstall.sh`
- `install/nginx.conf` (superseded by `docker/nginx.conf`)
- `config/app.local.example.php` (superseded by `.env.example`)

### `docker-compose.yml`

```yaml
services:
  app:
    build:
      context: .
      args:
        LLAMA_CPP_TAG: <pinned tag>
    ports:
      - "${ATR_PORT:-8080}:8080"
    environment:
      ATR_DB_HOST: db
      ATR_DB_PORT: "5432"
      ATR_DB_NAME: atr
      ATR_DB_USER: atr
      ATR_DB_PASS: ${ATR_DB_PASS:-atr}
      ATR_ADMIN_PASS: ${ATR_ADMIN_PASS:-}
      ATR_TZ: ${ATR_TZ:-UTC}
    volumes:
      - atr_storage:/var/www/atr/storage
    depends_on:
      db:
        condition: service_healthy
    restart: unless-stopped

  db:
    image: postgres:17
    environment:
      POSTGRES_DB: atr
      POSTGRES_USER: atr
      POSTGRES_PASSWORD: ${ATR_DB_PASS:-atr}
    volumes:
      - atr_pgdata:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U atr -d atr"]
    restart: unless-stopped

volumes:
  atr_storage:
  atr_pgdata:
```

### CI (`.github/workflows/ci.yml`)

Triggers: push to `main`, pull_request. Two jobs:
1. **lint** — `shivammathur/setup-php` (8.4), `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`.
2. **image** — `docker build .` (builds both stages; verifies llama.cpp
   compiles and the final image assembles). No push.

### Documentation

- **AGENTS.md** (repo root) — project overview, stack, directory map,
  commands (`docker compose up --build`, `php -l`, `bin/health.php`,
  manual job runs), conventions (vanilla PHP, no framework; SQL lives in
  `app/Models`; routes in `config/routes.php`; views in `templates/`;
  config precedence app.php → app.local.php → settings table; audit-log
  every mutation; CSRF on all POSTs), container layout (entrypoint steps,
  cron jobs, LLM lifecycle), and pointers to docs/.
- **README.md** — rewritten quickstart: prerequisites (Docker + Compose,
  RAM guidance for models), `docker compose up --build`, first login
  (password from `docker logs` or `ATR_ADMIN_PASS`), uploading a model in
  the Assistant tab, ports/volumes/env table.
- **docs/INSTALL.md** — Docker install, env var reference, bind-mount
  alternative for `storage/`, firewall/port notes.
- **docs/OPERATIONS.md** — backups (`bin/backup.php daily`, restore
  procedure with `pg_restore` + uploads archive), job schedules, log
  locations, updating the app (rebuild + up), data volume layout,
  llama-server troubleshooting (`storage/logs/llama.log`).
- **docs/ARCHITECTURE.md** — replace launchd/Homebrew sections with the
  container topology; add Dockerfile/entrypoint description.

## Error handling

- DB not ready at boot → entrypoint waits up to 60s, then exits non-zero
  (compose `restart: unless-stopped` retries; depends_on healthcheck makes
  this rare).
- Schema apply failure → entrypoint exits non-zero with psql output (app
  does not start half-migrated).
- Upload failure (disk full, bad file, duplicate) → JSON error rendered in
  the model panel; no partial files left behind (temp file removed).
- llama-server crash mid-session → existing behavior: `state()` reports
  `stopped`, chat returns a clear error, user clicks Start.
- Postgres version drift (user swaps the db image) → pg_dump client is 17;
  docs pin `postgres:17`.

## Testing / verification

No test suite exists; verification is manual + CI, matching current practice:

1. `php -l` clean on all app files.
2. `docker compose up --build` → both containers healthy.
3. `GET /healthz` → `status: ok`, database reachable.
4. Log in with the generated (or `ATR_ADMIN_PASS`) admin password.
5. Assistant tab (admin): upload a small GGUF (e.g. Qwen2.5-0.5B-Instruct
   Q4, ~400 MB) → progress bar completes → model appears in list → select →
   Start → status pill turns `ready`.
6. Send a chat message that triggers a read tool (e.g. "how many assets do
   we have?") → correct answer.
7. Delete the model → list empty; re-upload works.
8. Run `docker exec atr-app php /var/www/atr/bin/backup.php daily` →
   `.dump` + uploads archive appear in `storage/backups/`.
9. `crontab -l` / `/etc/cron.d/atr` shows the three jobs; cron log written
   after a manual `alert_sweep.php` run.
10. Restart the stack (`docker compose restart`) → data intact (volumes),
    admin password unchanged (marker file), schema re-apply is a no-op.
