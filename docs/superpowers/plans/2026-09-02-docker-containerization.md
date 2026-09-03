# Docker Containerization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the macOS-only Homebrew/launchd install with a Docker deployment (app container + Postgres container), add web-UI model upload for the local LLM, and ship OSS docs + CI.

**Architecture:** Multi-stage Dockerfile (stage 1 compiles llama.cpp CPU-only from a pinned tag; stage 2 is `php:8.4-fpm-bookworm` + nginx + cron + postgresql-client-17 + the llama-server binary). A bash entrypoint renders `config/app.local.php` from env, waits for Postgres, applies the idempotent schema+seed, handles the first-boot admin password, then starts cron + php-fpm + nginx. `docker-compose.yml` runs `app` + `db` (postgres:17) with two named volumes. The app's existing `LlmServer` PID-based lifecycle keeps working because llama-server runs in the same container.

**Tech Stack:** PHP 8.4 (vanilla, no framework), PostgreSQL 17, nginx, PHP-FPM, cron, llama.cpp v0.3.0 (pinned), Docker Compose v2, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-02-docker-containerization-design.md`

## Global Constraints

- PHP floor is **8.4** (locked `endroid/qr-code 6.x` requires `^8.4`). Image, CI, and `composer.json` all use 8.4.
- llama.cpp is pinned to tag **`v0.3.0`** via the `LLAMA_CPP_TAG` build arg; built CPU-only with `GGML_NATIVE=OFF`.
- Postgres server image is **`postgres:17`**; the app image installs **`postgresql-client-17`** (pgdg repo) so `pg_dump -Fc`/`pg_restore` are version-compatible.
- App code lives at **`/var/www/atr`** in the container; web port inside the container is always **8080** (host mapping via `ATR_PORT`).
- Model upload limit is **12 GiB** everywhere: nginx `client_max_body_size 12g`, PHP `upload_max_filesize`/`post_max_size 12G`.
- Admin password is set **first boot only** (marker file `storage/.admin_initialized`); from `ATR_ADMIN_PASS` or a random 16-char value printed to `docker compose logs app`.
- There is **no automated test suite**. Verification bar: `php -l` on all files, `docker compose up --build` healthy, `curl /healthz` ok, plus the manual flows listed per task.
- Conventions: vanilla PHP, no framework; ALL SQL lives in `app/Models/*`; routes in `config/routes.php` (role restrictions via `'roles' => [...]`); CSRF on every POST is enforced by the router (`_token`); every mutation is audit-logged via `Audit::log(...)`; frontend is vanilla JS IIFEs + one CSS file, no build step, no CDN.
- Commit after every task. Commit message style matches the repo: `<area>: <description>` (e.g. `docker: ...`, `docs: ...`).
- Never commit secrets; `.env` is git-ignored (only `.env.example` is committed).

---

### Task 1: Container image + compose stack

**Files:**
- Create: `Dockerfile`
- Create: `.dockerignore`
- Create: `docker-compose.yml`
- Create: `.env.example`
- Create: `docker/entrypoint.sh`
- Create: `docker/nginx.conf`
- Create: `docker/cron/atr`
- Create: `docker/php/99-atr.ini`

**Interfaces:**
- Consumes: existing app as-is (no app code changes in this task), `db/schema.sql`, `db/seed.sql`, `bin/reset_admin_password.php`, `GET /healthz`.
- Produces: a running stack (`docker compose up --build`) with service `app` (web on host port `${ATR_PORT:-8080}`) and `db`; app dir `/var/www/atr`; env vars `ATR_DB_HOST/PORT/NAME/USER/PASS`, `ATR_ADMIN_PASS`, `ATR_TZ`, `ATR_PORT`; marker file `storage/.admin_initialized`; cron file `/etc/cron.d/atr`; `llama-server` on PATH inside `app`; named volumes `atr_storage`, `atr_pgdata`. Later tasks rely on all of this.

- [ ] **Step 1: Create `.dockerignore`**

```
.git
.github
.superpowers
docs
install
vendor
storage/*
*.md
.DS_Store
.env
docker-compose.yml
```

- [ ] **Step 2: Create `docker/php/99-atr.ini`**

```ini
upload_max_filesize = 12G
post_max_size = 12G
memory_limit = 2G
max_execution_time = 0
upload_tmp_dir = /tmp
```

- [ ] **Step 3: Create `docker/cron/atr`**

```
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# Daily backup (DB dump + uploads archive) — 02:00
0 2 * * * www-data php /var/www/atr/bin/backup.php daily >> /var/www/atr/storage/logs/backup-cron.log 2>&1

# Weekly activity report — Saturday 18:00
0 18 * * 6 www-data php /var/www/atr/bin/weekly_report.php >> /var/www/atr/storage/logs/weekly-report-cron.log 2>&1

# Alert sweep (warranty / low stock / overdue emails) — every 15 minutes
*/15 * * * * www-data php /var/www/atr/bin/alert_sweep.php >> /var/www/atr/storage/logs/alert-sweep-cron.log 2>&1
```

- [ ] **Step 4: Create `docker/nginx.conf`**

Based on the existing `install/nginx.conf` (kept as reference until Task 5 deletes it), with container values resolved: port 8080, root `/var/www/atr/public`, FPM `127.0.0.1:9000`, 12g body limit, 3600s fastcgi read timeout (multi-GB model uploads).

```nginx
server {
    listen 8080;
    listen [::]:8080;
    server_name _;

    root /var/www/atr/public;
    index index.php;

    access_log /var/www/atr/storage/logs/nginx-access.log;
    error_log /var/www/atr/storage/logs/nginx-error.log warn;

    client_max_body_size 12g;
    sendfile on;

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;
    gzip_min_length 1024;

    location = /healthz {
        try_files $uri /index.php;
    }

    location /uploads/ {
        alias /var/www/atr/storage/uploads/;
        try_files $uri =404;
        expires 7d;
        add_header Cache-Control "public";
        location ~ \.php$ {
            deny all;
        }
    }

    location /theme/ {
        expires 30d;
        add_header Cache-Control "public";
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 3600s;
        fastcgi_connect_timeout 10s;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy same-origin always;
}
```

- [ ] **Step 5: Create `docker/entrypoint.sh`**

```bash
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
```

Then make it executable: `chmod +x docker/entrypoint.sh` (the Dockerfile also installs it with mode 0755, but git stores the exec bit — set it now).

- [ ] **Step 6: Create `Dockerfile`**

```dockerfile
# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — build llama-server (CPU-only, portable across amd64/arm64)
# ---------------------------------------------------------------------------
FROM php:8.4-cli-bookworm AS llama-builder

ARG LLAMA_CPP_TAG=v0.3.0

RUN apt-get update \
 && apt-get install -y --no-install-recommends cmake g++ git ca-certificates \
 && rm -rf /var/lib/apt/lists/* \
 && git clone --depth 1 --branch "${LLAMA_CPP_TAG}" https://github.com/ggml-org/llama.cpp /src/llama.cpp \
 && cmake -B /src/llama.cpp/build -S /src/llama.cpp \
      -DCMAKE_BUILD_TYPE=Release \
      -DGGML_NATIVE=OFF \
      -DBUILD_SHARED_LIBS=OFF \
 && cmake --build /src/llama.cpp/build --config Release -j"$(nproc)" --target llama-server

# ---------------------------------------------------------------------------
# Stage 2 — runtime: nginx + php-fpm + llama-server + cron + pg client
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm

# PostgreSQL APT repo (pgdg) — postgresql-client-17 is version-matched to the
# postgres:17 server so pg_dump -Fc / pg_restore stay compatible (Debian
# bookworm's stock client is PG 15).
RUN apt-get update \
 && apt-get install -y --no-install-recommends curl ca-certificates gnupg \
 && install -m 0755 -d /etc/apt/keyrings \
 && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
      | gpg --dearmor -o /etc/apt/keyrings/pgdg.gpg \
 && echo "deb [signed-by=/etc/apt/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
      > /etc/apt/sources.list.d/pgdg.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends nginx cron postgresql-client-17 \
 && rm -rf /var/lib/apt/lists/*

COPY --from=llama-builder /src/llama.cpp/build/bin/llama-server /usr/local/bin/llama-server

WORKDIR /var/www/atr
COPY . .
COPY docker/php/99-atr.ini /usr/local/etc/php/conf.d/99-atr.ini

RUN composer install --no-dev --no-interaction --optimize-autoloader \
 && mkdir -p storage/uploads storage/backups storage/logs storage/reports \
             storage/labels storage/sessions storage/run storage/models \
 && chown -R www-data:www-data storage \
 && rm -f /etc/nginx/sites-enabled/default \
 && ln -s /var/www/atr/docker/nginx.conf /etc/nginx/sites-enabled/atr \
 && install -m 0644 docker/cron/atr /etc/cron.d/atr \
 && install -m 0755 docker/entrypoint.sh /usr/local/bin/atr-entrypoint

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
  CMD php -r 'exit(strpos((string)@file_get_contents("http://127.0.0.1:8080/healthz"), "\"status\":\"ok\"") === false ? 1 : 0);'

ENTRYPOINT ["atr-entrypoint"]
```

Notes:
- `COPY . .` respects `.dockerignore` (no vendor/, storage data, docs, .git).
- The default php-fpm pool in the official image already listens on `127.0.0.1:9000` as `www-data`; nginx runs as `www-data` too, so no custom pool file is needed (spec deviation: `docker/php-fpm/atr.conf` dropped as redundant — upload limits come from the global ini in Step 2).
- `config/app.local.php` is written at runtime into the image filesystem (not the volume) — that's intentional: it is regenerated every boot from env.

- [ ] **Step 7: Create `docker-compose.yml`**

```yaml
services:
  app:
    build:
      context: .
      args:
        LLAMA_CPP_TAG: v0.3.0
    image: atr-inventory:latest
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
      interval: 5s
      timeout: 5s
      retries: 10
    restart: unless-stopped

volumes:
  atr_storage:
  atr_pgdata:
```

- [ ] **Step 8: Create `.env.example`**

```
# Copy to .env and adjust. All variables are optional; defaults shown.

# Host port for the web UI (the container always listens on 8080 internally).
ATR_PORT=8080

# Postgres password (app + db containers must match).
ATR_DB_PASS=atr

# Admin password applied on FIRST boot only. If empty, a random password is
# generated and printed once to `docker compose logs app`.
ATR_ADMIN_PASS=

# Timezone (app + cron schedules).
ATR_TZ=UTC
```

- [ ] **Step 9: Build the image**

Run: `docker compose build`
Expected: both stages build. Stage 1 compiles llama.cpp (5–15 min). Final image contains `llama-server`, nginx, cron, pg client. (If Docker is not running, start Docker Desktop first.)

- [ ] **Step 10: Start the stack and wait for healthy**

Run: `docker compose up -d` then `docker compose ps`
Expected: `db` becomes `healthy`; `app` becomes `healthy` (healthcheck: `/healthz` contains `"status":"ok"`). If `app` fails, inspect with `docker compose logs app` — the entrypoint prints each step; a Postgres or schema error will be visible.

- [ ] **Step 11: Verify health endpoint and first-boot password**

Run:
```bash
curl -s http://localhost:8080/healthz
docker compose logs app | grep "ATR admin password"
```
Expected: healthz returns `{"status":"ok","app":"ATR Inventory","version":"1.0.0","database":"ok","assets":0,...}`; the log line shows the generated 16-hex-char password.

- [ ] **Step 12: Verify login works**

Run (substitute the password from Step 11):
```bash
cd /tmp && rm -f atr-cookies
TOKEN=$(curl -s -c atr-cookies http://localhost:8080/login | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" -b atr-cookies -c atr-cookies \
  -d "_token=$TOKEN&username=admin&password=<ADMIN_PASS>" http://localhost:8080/login
```
Expected: `302 /` (login redirects to the intended URL, default `/` = dashboard).

- [ ] **Step 13: Verify container internals**

Run:
```bash
docker compose exec app command -v llama-server
docker compose exec app command -v pg_dump
docker compose exec app cat /etc/cron.d/atr
docker compose exec app ls /var/www/atr/storage
```
Expected: `/usr/local/bin/llama-server`; `/usr/lib/postgresql/17/bin/pg_dump` (or similar); the three cron lines; all eight storage subdirectories present.

- [ ] **Step 14: Verify restart persistence**

Run: `docker compose restart app` then `curl -s http://localhost:8080/healthz`
Expected: healthy again; `docker compose logs app` shows NO new "ATR admin password" line (marker file worked); schema re-apply is silent (idempotent).

- [ ] **Step 15: Commit**

```bash
git add Dockerfile .dockerignore docker-compose.yml .env.example docker/
git commit -m "docker: multi-stage image (nginx + php-fpm + llama-server + cron) and compose stack"
```

---

### Task 2: Remove macOS-specific code paths

**Files:**
- Modify: `app/Services/LlmServer.php` (remove `install()`, simplify `binary()`, fix error message)
- Modify: `app/Services/Backup.php:16-43` (simplify `pgBinary()`)
- Modify: `app/Controllers/AdminController.php:411-419` (remove `llmInstall()`)
- Modify: `config/routes.php:106` (remove `/admin/llm/install` route)
- Modify: `templates/admin/system.php` (remove install button + JS handler, update "Background services" text)
- Modify: `composer.json` (`"php": ">=8.2"` → `">=8.4"`)

**Interfaces:**
- Consumes: Task 1's running stack (for verification).
- Produces: `LlmServer` with NO `install()` method and `binary(): ?string` resolved via `command -v`; `Backup::pgBinary(string $name): string` resolved via `command -v`; no `/admin/llm/install` route; System tab renders without the install button. Tasks 3–4 build on the cleaned-up `LlmServer`.

- [ ] **Step 1: Simplify `LlmServer::binary()` and remove `install()`**

In `app/Services/LlmServer.php`, replace the current `binary()` (lines 61–73):

```php
    public static function binary(): ?string
    {
        $out = trim((string) shell_exec('command -v llama-server 2>/dev/null'));
        if ($out !== '') {
            return $out;
        }
        foreach (['/opt/homebrew/bin/llama-server', '/usr/local/bin/llama-server'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
```

with:

```php
    public static function binary(): ?string
    {
        $out = trim((string) shell_exec('command -v llama-server 2>/dev/null'));
        return $out !== '' ? $out : null;
    }
```

Delete the entire `install()` method (lines 209–213):

```php
    public static function install(): array
    {
        $out = (string) shell_exec('brew install llama.cpp 2>&1');
        return ['ok' => self::binary() !== null, 'output' => $out];
    }
```

In `start()` (line 138), replace the message:

```php
            throw new RuntimeException('llama-server not found. Install llama.cpp from the System tab or run: brew install llama.cpp');
```

with:

```php
            throw new RuntimeException('llama-server binary not found.');
```

- [ ] **Step 2: Simplify `Backup::pgBinary()`**

In `app/Services/Backup.php`, replace the entire current `pgBinary()` (lines 16–43, the candidate-list + glob + `command -v` version) with:

```php
    public static function pgBinary(string $name): string
    {
        $which = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        return $which !== '' && is_executable($which) ? $which : $name;
    }
```

- [ ] **Step 3: Remove `AdminController::llmInstall()`**

Delete the method at `app/Controllers/AdminController.php:411-419`:

```php
    public function llmInstall(): void
    {
        Auth::requireLogin();
        $res = LlmServer::install();
        if (!$res['ok']) {
            Response::json(['error' => 'llama.cpp install failed. Try: brew install llama.cpp', 'output' => substr((string) $res['output'], 0, 2000)], 500);
        }
        Response::json(['ok' => true, 'message' => 'llama.cpp installed.', 'output' => substr((string) $res['output'], 0, 2000)]);
    }
```

- [ ] **Step 4: Remove the install route**

In `config/routes.php`, delete line 106:

```php
    ['method' => 'POST', 'path' => '/admin/llm/install',   'controller' => 'Admin', 'action' => 'llmInstall', 'roles' => ['admin']],
```

- [ ] **Step 5: Update `templates/admin/system.php`**

a) Delete the install-button block (lines 79–81):

```php
      <?php if (($llm['state']['binary'] ?? null) === null): ?>
        <button type="button" class="btn btn-primary" id="llm-install">Install llama.cpp (brew)</button>
      <?php endif; ?>
```

b) In the inline `<script>`, change the selector list (line 129):

```js
  Array.prototype.forEach.call(document.querySelectorAll('#llm-select,#llm-config,#llm-start,#llm-stop,#llm-install'), function (btn) {
```

to:

```js
  Array.prototype.forEach.call(document.querySelectorAll('#llm-select,#llm-config,#llm-start,#llm-stop'), function (btn) {
```

c) Delete the install handler branch (lines 141–144):

```js
       } else if (btn.id === 'llm-install') {
         say('Installing llama.cpp via brew — this can take a few minutes…');
         post('/admin/llm/install', {}, function (r) { btn.disabled = false; say(r.json && (r.json.message || r.json.error), !r.ok); refresh(); });
       }
```

(keep the closing `}` of the preceding `llm-stop` branch and the `});` that ends the forEach).

d) Replace the "Background services" checklist (lines 43–49):

```php
    <ul class="checklist">
      <li>Nginx + PHP-FPM — managed by launchd (brew services), starts at boot</li>
      <li>PostgreSQL — managed by launchd (brew services), starts at boot</li>
      <li>Daily backup — 02:00 (launchd agent)</li>
      <li>Weekly activity report — Saturday 18:00 (launchd agent)</li>
      <li>Alert sweep (email notifications) — every 15 minutes (launchd agent)</li>
    </ul>
```

with:

```php
    <ul class="checklist">
      <li>Web (nginx + PHP-FPM) — container entrypoint, starts with the app</li>
      <li>PostgreSQL — separate container, starts with the app</li>
      <li>Daily backup — 02:00 (cron)</li>
      <li>Weekly activity report — Saturday 18:00 (cron)</li>
      <li>Alert sweep (email notifications) — every 15 minutes (cron)</li>
    </ul>
```

- [ ] **Step 6: Bump the PHP floor in `composer.json`**

```json
    "require": {
      "php": ">=8.4",
```

(was `">=8.2"`).

- [ ] **Step 7: Lint all changed PHP**

Run:
```bash
php -l app/Services/LlmServer.php && php -l app/Services/Backup.php && php -l app/Controllers/AdminController.php && php -l templates/admin/system.php
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 8: Rebuild and verify the stack**

Run:
```bash
docker compose up -d --build app
curl -s http://localhost:8080/healthz
docker compose exec app grep -rn "brew\|homebrew" app/ config/ templates/ bin/ || echo "no brew references"
```
Expected: healthz ok; `no brew references` (none left in app code).

- [ ] **Step 9: Verify the System tab renders (no install button, no PHP errors)**

Run (using the cookie jar from Task 1 Step 12, re-login if the session expired):
```bash
curl -s -b /tmp/atr-cookies http://localhost:8080/admin/system | grep -c "llm-install"
curl -s -b /tmp/atr-cookies http://localhost:8080/admin/system | grep -o "container entrypoint"
```
Expected: `0` (no install button); `container entrypoint` present.

- [ ] **Step 10: Commit**

```bash
git add app/Services/LlmServer.php app/Services/Backup.php app/Controllers/AdminController.php config/routes.php templates/admin/system.php composer.json
git commit -m "docker: drop macOS-specific paths (brew install, Homebrew lookups, launchd copy)"
```

---

### Task 3: Model upload/delete backend

**Files:**
- Modify: `config/routes.php` (add 2 routes after the existing `/admin/llm/*` block, ~line 105)
- Modify: `app/Controllers/AdminController.php` (add `llmUpload()`, `llmDelete()` where `llmInstall()` was removed)

**Interfaces:**
- Consumes: `LlmServer::modelsDir(): string`, `LlmServer::selectedModel(): ?string`, `LlmServer::setSelectedModel(string)`, `LlmServer::state(): array` (keys `status`, `model`), `Setting::set(string, string)`, `Audit::log(string, string, string, array)`, `Request::post(string, mixed)`, `Response::json(mixed, int)` — all existing.
- Produces (HTTP contract used by Task 4's UI):
  - `POST /admin/llm/upload` — multipart, fields: `_token`, `model` (file). Success: `200 {"ok":true,"model":"<name>","size":<bytes>}`. Errors: `400 {"error":"..."}` (bad name, duplicate, empty, disk full, upload error) / `500 {"error":"..."}` (move failed).
  - `POST /admin/llm/delete` — form fields: `_token`, `model` (filename). Success: `200 {"ok":true}`. Errors: `400` (bad name, model is loaded), `404` (not found), `500` (unlink failed).
  - Both routes are admin-role + CSRF-enforced by the router. Uploads land in `LlmServer::modelsDir()`; audit actions `llm.model_upload` / `llm.model_delete` in table `audit_log`.

- [ ] **Step 1: Add the routes**

In `config/routes.php`, after the line `['method' => 'POST', 'path' => '/admin/llm/config', ...]` (line 105), add:

```php
    ['method' => 'POST', 'path' => '/admin/llm/upload',   'controller' => 'Admin', 'action' => 'llmUpload',  'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/llm/delete',   'controller' => 'Admin', 'action' => 'llmDelete',  'roles' => ['admin']],
```

- [ ] **Step 2: Add `llmUpload()` to `AdminController`**

Insert after `llmConfig()` in `app/Controllers/AdminController.php` (all needed imports — `Audit`, `Request`, `Response`, `LlmServer` — already exist in the file):

```php
    public function llmUpload(): void
    {
        Auth::requireLogin();
        $file = $_FILES['model'] ?? null;
        if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $code = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            $msg = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the upload limit (12 GB).',
                UPLOAD_ERR_PARTIAL => 'Upload was only partial. Try again.',
                UPLOAD_ERR_NO_FILE => 'No file received.',
                default => 'Upload failed (code ' . $code . ').',
            };
            Response::json(['error' => $msg], 400);
        }
        $name = basename((string) ($file['name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.gguf$/', $name)) {
            Response::json(['error' => 'Invalid file name. Upload a .gguf model file.'], 400);
        }
        $dir = LlmServer::modelsDir();
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            Response::json(['error' => 'Empty file.'], 400);
        }
        if (is_file($dir . '/' . $name)) {
            Response::json(['error' => 'A model with that name already exists. Delete it first.'], 400);
        }
        $free = (int) disk_free_space($dir);
        if ($size > $free) {
            Response::json(['error' => 'Not enough disk space: need ' . round($size / 1048576) . ' MB, have ' . round($free / 1048576) . ' MB.'], 400);
        }
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            Response::json(['error' => 'Could not save the uploaded file.'], 500);
        }
        Audit::log('llm.model_upload', 'llm', $name, ['size' => $size]);
        if (LlmServer::selectedModel() === null) {
            LlmServer::setSelectedModel($name);
        }
        Response::json(['ok' => true, 'model' => $name, 'size' => $size]);
    }
```

- [ ] **Step 3: Add `llmDelete()` to `AdminController`**

Insert after `llmUpload()`:

```php
    public function llmDelete(): void
    {
        Auth::requireLogin();
        $name = basename((string) Request::post('model', ''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.gguf$/', $name)) {
            Response::json(['error' => 'Invalid model name.'], 400);
        }
        $dir = LlmServer::modelsDir();
        if (!is_file($dir . '/' . $name)) {
            Response::json(['error' => 'Model file not found.'], 404);
        }
        $st = LlmServer::state();
        if ($st['status'] === 'ready' && $st['model'] === $name) {
            Response::json(['error' => 'Stop the model server before deleting the loaded model.'], 400);
        }
        if (!unlink($dir . '/' . $name)) {
            Response::json(['error' => 'Could not delete the file.'], 500);
        }
        if ((string) Setting::get('llm.selected_model', '') === $name) {
            Setting::set('llm.selected_model', '');
        }
        Audit::log('llm.model_delete', 'llm', $name, []);
        Response::json(['ok' => true]);
    }
```

- [ ] **Step 4: Lint**

Run: `php -l app/Controllers/AdminController.php && php -l config/routes.php`
Expected: `No syntax errors detected` for each.

- [ ] **Step 5: Rebuild the app container**

Run: `docker compose up -d --build app`
Expected: healthy within ~30s (no llama.cpp rebuild — layer cache).

- [ ] **Step 6: Verify upload + audit + delete via curl**

Run (re-login first if the session expired; use the password from Task 1):
```bash
cd /tmp && rm -f atr-cookies
TOKEN=$(curl -s -c atr-cookies http://localhost:8080/login | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -o /dev/null -b atr-cookies -c atr-cookies -d "_token=$TOKEN&username=admin&password=<ADMIN_PASS>" http://localhost:8080/login
TOKEN=$(curl -s -b atr-cookies http://localhost:8080/assistant | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')

head -c 1048576 /dev/urandom > /tmp/test-model.gguf

echo "--- upload:"
curl -s -b atr-cookies -F "_token=$TOKEN" -F "model=@/tmp/test-model.gguf" http://localhost:8080/admin/llm/upload
echo "--- file in container:"
docker compose exec app ls -la /var/www/atr/storage/models/
echo "--- audit row:"
docker compose exec app psql -h db -U atr -d atr -t -c "SELECT action, entity_id, details FROM audit_log WHERE action = 'llm.model_upload' ORDER BY id DESC LIMIT 1;"
echo "--- duplicate upload (expect 400):"
curl -s -w "\n%{http_code}\n" -b atr-cookies -F "_token=$TOKEN" -F "model=@/tmp/test-model.gguf" http://localhost:8080/admin/llm/upload
echo "--- bad filename (expect 400):"
curl -s -w "\n%{http_code}\n" -b atr-cookies -F "_token=$TOKEN" -F "model=@/tmp/test-model.gguf;filename=.hidden.gguf" http://localhost:8080/admin/llm/upload
echo "--- models dir (only test-model.gguf):"
docker compose exec app ls /var/www/atr/storage/models/
echo "--- delete:"
curl -s -b atr-cookies -d "_token=$TOKEN&model=test-model.gguf" http://localhost:8080/admin/llm/delete
echo "--- file gone:"
docker compose exec app ls /var/www/atr/storage/models/
```
Expected: upload → `{"ok":true,"model":"test-model.gguf","size":1048576}`; `test-model.gguf` listed; audit row `llm.model_upload | test-model.gguf | {"size": 1048576}`; duplicate → 400 with "already exists"; `.hidden.gguf` (leading dot) → 400 with "Invalid file name" and the models dir still contains only `test-model.gguf` (path-traversal filenames are neutralized by `basename()` — files can only ever land inside `storage/models/`); delete → `{"ok":true}`; models dir empty.

- [ ] **Step 7: Verify non-admin is rejected**

Run (log in as `viewer` — password `Viewer1234` from the seed, unchanged by the first-boot admin password step):
```bash
cd /tmp && rm -f atr-viewer
VTOKEN=$(curl -s -c atr-viewer http://localhost:8080/login | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -o /dev/null -b atr-viewer -c atr-viewer -d "_token=$VTOKEN&username=viewer&password=Viewer1234" http://localhost:8080/login
curl -s -w "\n%{http_code}\n" -b atr-viewer -F "_token=$VTOKEN" -F "model=@/tmp/test-model.gguf" http://localhost:8080/admin/llm/upload
```
Expected: non-200 (403/404 from the router's role check) and no file created.

- [ ] **Step 8: Commit**

```bash
git add config/routes.php app/Controllers/AdminController.php
git commit -m "assistant: model upload/delete endpoints (admin, audited, 12GB cap)"
```

---

### Task 4: Assistant-tab model panel + System card slim-down

**Files:**
- Modify: `app/Services/LlmServer.php` (`state()` gains `port` + `context` keys)
- Modify: `templates/assistant/index.php` (admin-only Model panel)
- Modify: `public/theme/js/assistant.js` (panel logic, XHR upload progress, updated state-chip copy)
- Modify: `templates/admin/system.php` (AI card → compact status + link)
- Modify: `public/theme/css/app.css` (progress bar styles)

**Interfaces:**
- Consumes: Task 3's `POST /admin/llm/upload` / `POST /admin/llm/delete` contracts; existing `POST /admin/llm/select|start|stop|config`; `GET /assistant/state` (returns `LlmServer::state()`).
- Produces: `LlmServer::state()` additionally returns `'port' => int` and `'context' => int` (additive JSON keys — existing consumers unaffected). Admins get model management on the Assistant tab; the System tab keeps a status-only AI card.

- [ ] **Step 1: Extend `LlmServer::state()`**

In `app/Services/LlmServer.php`, in the `return` array of `state()` (lines 115–123), add two keys after `'models' => self::models(),`:

```php
            'port' => self::port(),
            'context' => self::context(),
```

- [ ] **Step 2: Add progress-bar CSS**

Append to the end of `public/theme/css/app.css`:

```css
.progress { height: 8px; background: var(--slate-bg); border-radius: 999px; overflow: hidden; margin-top: 8px; }
.progress-bar { height: 100%; width: 0; background: var(--primary); border-radius: 999px; transition: width .2s ease; }
```

- [ ] **Step 3: Add the Model panel to `templates/assistant/index.php`**

Insert between the closing `</div>` of `.page-head` (line 9) and `<div class="assistant-wrap ...">` (line 11):

```php
<?php if (\App\Core\Auth::isAdmin()): ?>
<section class="panel animate-fadeup" style="margin-bottom:16px">
  <div class="panel-head"><h2>Model</h2><span class="pill pill-gray" id="model-pill">…</span></div>
  <div class="panel-body">
    <div class="form-grid">
      <label class="field"><span>Upload a .gguf model (max 12 GB)</span>
        <input type="file" id="model-file" accept=".gguf">
      </label>
      <div class="field" style="align-self:end">
        <button type="button" class="btn btn-primary" id="model-upload">Upload</button>
      </div>
    </div>
    <div id="model-progress-wrap" hidden>
      <div class="progress"><div class="progress-bar" id="model-progress"></div></div>
      <div class="table-note" id="model-progress-label">0%</div>
    </div>

    <table class="table" style="margin-top:12px">
      <thead><tr><th>Model</th><th>Size</th><th>Updated</th><th></th></tr></thead>
      <tbody id="model-tbody"></tbody>
    </table>

    <div class="form-grid" style="margin-top:12px">
      <label class="field"><span>Selected model</span>
        <select id="model-select"></select>
      </label>
      <label class="field"><span>Port</span><input type="number" id="model-port" min="1024" max="65535"></label>
      <label class="field"><span>Context</span><input type="number" id="model-context" min="2048" max="32768" step="1024"></label>
    </div>

    <div class="page-actions" style="margin-top:12px">
      <button type="button" class="btn" id="model-select-btn">Select model</button>
      <button type="button" class="btn" id="model-config">Save settings</button>
      <button type="button" class="btn btn-primary" id="model-start">Start</button>
      <button type="button" class="btn btn-ghost" id="model-stop">Stop</button>
    </div>
    <div class="table-note" id="model-msg" style="margin-top:10px"></div>
  </div>
</section>
<?php endif; ?>
```

- [ ] **Step 4: Update `public/theme/js/assistant.js`**

a) After the line `var clearEl = document.getElementById('assistant-clear');` add:

```js
  var modelFile = document.getElementById('model-file');
```

b) In `renderState(st)`, replace the two stale strings:

```js
      stateEl.textContent = 'LLM not installed — an admin can install it from System';
```
→
```js
      stateEl.textContent = 'Model server unavailable — ask an admin to check the container';
```

```js
      stateEl.textContent = 'No model found — drop a .gguf into the models folder (Admin → System)';
```
→
```js
      stateEl.textContent = 'No model uploaded yet — an admin can upload one from the Model panel';
```

c) Replace the `pollState` function:

```js
  function pollState() {
    fetch(base + '/assistant/state', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(renderState)
      .catch(function () { renderState(null); });
  }
```

with:

```js
  function pollState() {
    fetch(base + '/assistant/state', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (st) { renderState(st); renderModels(st); })
      .catch(function () { renderState(null); renderModels(null); });
  }
```

d) Insert the model-panel block immediately BEFORE the `pollState();` call (i.e. between the new `pollState` definition and the first `pollState();` line):

```js
  /* ---------- model panel (admin only) ---------- */
  function say(t, bad) {
    var el = document.getElementById('model-msg');
    el.textContent = t;
    el.style.color = bad ? 'var(--red)' : 'var(--muted)';
  }
  function postForm(path, data, done) {
    var fd = new FormData();
    fd.append('_token', token);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    fetch(base + path, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, json: j }; });
      })
      .then(done)
      .catch(function () { done({ ok: false, json: { error: 'Network error.' } }); });
  }

  function renderModels(st) {
    if (!modelFile) return;
    var models = (st && st.models) || [];
    var tbody = document.getElementById('model-tbody');
    tbody.innerHTML = '';
    models.forEach(function (m) {
      var tr = document.createElement('tr');
      var tdName = document.createElement('td');
      tdName.textContent = m.name;
      var tdSize = document.createElement('td');
      tdSize.textContent = Math.round(m.size / 1048576) + ' MB';
      var tdDate = document.createElement('td');
      tdDate.textContent = new Date(m.mtime * 1000).toLocaleDateString();
      var tdAct = document.createElement('td');
      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn btn-ghost btn-sm';
      del.textContent = 'Delete';
      del.addEventListener('click', function () {
        if (!confirm('Delete ' + m.name + '?')) return;
        postForm('/admin/llm/delete', { model: m.name }, function (res) {
          say(res.ok ? 'Deleted.' : ((res.json && res.json.error) || 'Delete failed.'), !res.ok);
          pollState();
        });
      });
      tdAct.appendChild(del);
      tr.appendChild(tdName); tr.appendChild(tdSize); tr.appendChild(tdDate); tr.appendChild(tdAct);
      tbody.appendChild(tr);
    });
    if (models.length === 0) {
      var tr = document.createElement('tr');
      var td = document.createElement('td');
      td.colSpan = 4;
      td.className = 'table-note';
      td.textContent = 'No models yet — upload a .gguf file above.';
      tr.appendChild(td);
      tbody.appendChild(tr);
    }
    var select = document.getElementById('model-select');
    select.innerHTML = models.length
      ? models.map(function (m) {
          return '<option value="' + esc(m.name) + '"' + (st && st.selected === m.name ? ' selected' : '') + '>' + esc(m.name) + ' (' + Math.round(m.size / 1048576) + ' MB)</option>';
        }).join('')
      : '<option value="">No models</option>';
    if (st) {
      document.getElementById('model-port').value = st.port || 8082;
      document.getElementById('model-context').value = st.context || 8192;
      var pill = document.getElementById('model-pill');
      pill.textContent = st.status;
      pill.className = 'pill ' + (st.status === 'ready' && st.matches_selected ? 'pill-green' : st.status === 'loading' ? 'pill-amber' : 'pill-gray');
    }
  }

  if (modelFile) {
    document.getElementById('model-upload').addEventListener('click', function () {
      var file = modelFile.files && modelFile.files[0];
      if (!file) { say('Choose a .gguf file first.', true); return; }
      var wrap = document.getElementById('model-progress-wrap');
      var bar = document.getElementById('model-progress');
      var label = document.getElementById('model-progress-label');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', base + '/admin/llm/upload');
      xhr.upload.onprogress = function (e) {
        if (!e.lengthComputable) return;
        var pct = Math.round((e.loaded / e.total) * 100);
        bar.style.width = pct + '%';
        label.textContent = pct + '%';
      };
      function done(ok, msg) {
        wrap.hidden = true;
        bar.style.width = '0';
        label.textContent = '0%';
        say(msg, !ok);
        if (ok) { modelFile.value = ''; pollState(); }
      }
      xhr.onload = function () {
        var j = {};
        try { j = JSON.parse(xhr.responseText); } catch (e2) {}
        if (xhr.status >= 200 && xhr.status < 300 && j.ok) done(true, 'Uploaded ' + j.model + '.');
        else done(false, (j && j.error) || 'Upload failed.');
      };
      xhr.onerror = function () { done(false, 'Upload failed — network error.'); };
      wrap.hidden = false;
      var fd = new FormData();
      fd.append('_token', token);
      fd.append('model', file);
      xhr.send(fd);
    });

    document.getElementById('model-select-btn').addEventListener('click', function () {
      postForm('/admin/llm/select', { model: document.getElementById('model-select').value }, function (res) {
        say(res.ok ? 'Model selected.' : ((res.json && res.json.error) || 'Failed.'), !res.ok);
        pollState();
      });
    });
    document.getElementById('model-config').addEventListener('click', function () {
      postForm('/admin/llm/config', {
        port: document.getElementById('model-port').value,
        context: document.getElementById('model-context').value
      }, function (res) {
        say(res.ok ? ((res.json && res.json.note) || 'Saved.') : ((res.json && res.json.error) || 'Failed.'), !res.ok);
      });
    });
    document.getElementById('model-start').addEventListener('click', function () {
      postForm('/admin/llm/start', {}, function (res) {
        say(res.ok ? ((res.json && res.json.note) || 'Starting…') : ((res.json && res.json.error) || 'Failed.'), !res.ok);
        pollState();
      });
    });
    document.getElementById('model-stop').addEventListener('click', function () {
      postForm('/admin/llm/stop', {}, function (res) {
        say(res.ok ? 'Stopped.' : ((res.json && res.json.error) || 'Failed.'), !res.ok);
        pollState();
      });
    });
  }
```

Note: `say`, `postForm`, and `renderModels` are all top-level (hoisted) function declarations in the IIFE; `renderModels` is guarded by `if (!modelFile) return;` so `pollState` can call it safely for non-admins. Only the event-listener wiring lives inside the `if (modelFile)` block.

- [ ] **Step 5: Slim the System tab AI card**

In `templates/admin/system.php`, replace the entire AI Assistant section — from `<section class="panel animate-fadeup" style="margin-top:16px;animation-delay:.2s">` (line 54) through the end of its inline `<script>…</script>` (line 149) — with:

```php
<section class="panel animate-fadeup" style="margin-top:16px;animation-delay:.2s">
  <div class="panel-head"><h2>AI Assistant <span class="pill pill-gray" id="llm-pill">…</span></div>
  <div class="panel-body">
    <div class="detail-grid">
      <div><span class="dk">llama.cpp binary</span><span class="dv" id="llm-binary">…</span></div>
      <div><span class="dk">Loaded model</span><span class="dv" id="llm-model">—</span></div>
      <div><span class="dk">Models folder</span><span class="dv"><code><?= e($llm['models_dir']) ?></code></span></div>
    </div>
    <p class="table-note" style="margin-top:12px">Upload models and manage the model server from the <a href="<?= e(url('/assistant')) ?>">Assistant tab</a>.</p>
  </div>
</section>

<script>
(function () {
  'use strict';
  var ATR = window.ATR || {};
  var base = ATR.base || '';
  function $(id) { return document.getElementById(id); }
  function refresh() {
    fetch(base + '/admin/llm/state', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (s) {
        var bin = $('llm-binary');
        if (bin) bin.textContent = s.binary || 'not found';
        var m = $('llm-model');
        if (m) m.textContent = s.model || '—';
        var pill = $('llm-pill');
        if (pill) {
          pill.textContent = s.status;
          pill.className = 'pill ' + (s.status === 'ready' && s.matches_selected ? 'pill-green' : s.status === 'loading' ? 'pill-amber' : 'pill-gray');
        }
      })
      .catch(function () {});
  }
  refresh();
  setInterval(refresh, 5000);
})();
</script>
```

(The `AdminController::system()` view data keeps passing `llm` — `models_dir` is still used; `port`/`context`/`state` keys simply go unused by this template.)

- [ ] **Step 6: Lint**

Run:
```bash
php -l app/Services/LlmServer.php && php -l templates/assistant/index.php && php -l templates/admin/system.php
node --check public/theme/js/assistant.js
```
Expected: `No syntax errors detected` ×3 and no output from `node --check` (if `node` is unavailable, skip that check and verify in Step 7).

- [ ] **Step 7: Rebuild and verify in a browser**

Run: `docker compose up -d --build app`

Then, in a browser at `http://localhost:8080`:
1. Log in as `admin` → open **Assistant** → the **Model** panel is visible (pill, upload row, empty model table, select/port/context, Start/Stop).
2. Log in as `viewer` (second browser profile) → **Assistant** shows NO Model panel; chat still works.
3. As admin, open **Admin → System** → AI card shows binary path, status pill, and the "Assistant tab" link; no upload/select/start controls; no install button.
4. Upload a small real GGUF (≥100 MB so progress is visible — see Task 7 for sourcing one) via the file picker → progress bar advances to 100% → "Uploaded …" → row appears in the table with size/date → Delete button removes it (with confirm).
5. Select a model → Select model → Start → pill cycles `loading` → `ready` (green).

- [ ] **Step 8: Commit**

```bash
git add app/Services/LlmServer.php templates/assistant/index.php public/theme/js/assistant.js templates/admin/system.php public/theme/css/app.css
git commit -m "assistant: model panel on Assistant tab (upload w/ progress, list, delete, lifecycle)"
```

---

### Task 5: Remove the macOS installer; write OSS docs

**Files:**
- Delete: `install/` (entire directory: `install.sh`, `nginx.conf`, `backup.sh`, `restore.sh`, `uninstall.sh`, `launchd/*.plist`)
- Delete: `config/app.local.example.php`
- Create: `LICENSE` (MIT)
- Create: `AGENTS.md`
- Rewrite: `README.md`
- Rewrite: `docs/INSTALL.md`
- Rewrite: `docs/OPERATIONS.md`
- Modify: `docs/ARCHITECTURE.md` (4 targeted edits)

**Interfaces:**
- Consumes: everything from Tasks 1–4 (docs describe the final state).
- Produces: no `install/` directory; MIT `LICENSE`; `AGENTS.md` at repo root; Docker-based docs. No code changes — verification is grep-based.

- [ ] **Step 1: Delete the macOS installer**

```bash
git rm -r install
git rm config/app.local.example.php
```

- [ ] **Step 2: Create `LICENSE`**

```
MIT License

Copyright (c) 2026 ATR Inventory contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

- [ ] **Step 3: Create `AGENTS.md`**

```markdown
# ATR Inventory — Agent & Contributor Guide

ATR Inventory is a local-network asset-management web app in the spirit of
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

App: http://localhost:8080 (host port: `ATR_PORT`). Health: `GET /healthz`.

Useful one-liners:

    docker compose exec app php bin/health.php            # DB + storage check
    docker compose exec app php bin/backup.php daily      # run a backup now
    docker compose exec app php bin/alert_sweep.php       # run the alert sweep now
    docker compose exec app php bin/weekly_report.php     # send the weekly report now
    docker compose exec app ls /var/www/atr/storage/models  # uploaded LLM models

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
    docker/           entrypoint.sh, nginx.conf, cron/atr, php/99-atr.ini
    public/           webroot: index.php front controller + theme (css/js/img)
    templates/        PHP views, one dir per area
    storage/          runtime data (volume-mounted in Docker): uploads,
                      backups, logs, reports, labels, sessions, models, run

## Conventions

- PSR-4 `App\` → `app/` via Composer. No frameworks, no autoloading tricks.
- SQL belongs in `app/Models/*`. Controllers stay thin.
- Every POST is CSRF-protected: the router validates `_token` automatically.
  Forms use `csrf_field()`; fetch/XHR bodies append `_token` from
  `window.ATR.token`.
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
- Background jobs are cron (`/etc/cron.d/atr`, from `docker/cron/atr`):
  backup daily 02:00, weekly report Sat 18:00, alert sweep every 15 min.
- The app manages the llama-server lifecycle itself
  (`App\Services\LlmServer`: spawn/kill by PID, restart on model change).
  Models are user-uploaded `.gguf` files in `storage/models` (Assistant tab,
  admin only, 12 GB cap). No model is bundled.
- Env vars: `ATR_PORT` (host port), `ATR_DB_*`, `ATR_ADMIN_PASS` (first boot
  only), `ATR_TZ`. See `.env.example`.

## Verification bar

There is no automated test suite. Before declaring work done:

    find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
    docker compose up --build && curl -s localhost:8080/healthz

then exercise the changed flow in a browser. CI (`.github/workflows/ci.yml`)
runs the same lint plus the image build on every push/PR.
```

- [ ] **Step 4: Rewrite `README.md`**

Replace the entire file with:

```markdown
# ATR Inventory

A local-network inventory management web application in the spirit of
AssetTiger: asset lifecycle tracking (available → checked out → in repair /
broken / lost / disposed / sold / donated), 5-year linear depreciation,
warranty / low-stock / overdue alerts, PDF & Excel reports,
AssetTiger-compatible QR/barcode labels, role-based access (Admin /
Department Manager / Viewer), a full audit trail, daily backups with
one-click restore — and a local-LLM AI assistant that can read the inventory
and (with your confirmation) perform actions.

Everything runs in Docker. No model is bundled: you upload a GGUF model of
your choice from the Assistant tab.

## Requirements

- Docker Engine with the Compose plugin (`docker compose version`)
- ~4 GB free RAM for the app; add 2–8 GB depending on the model you upload
  (rule of thumb: model file size + ~1 GB)
- ~1 GB disk for the image, plus room for models and data

## Quickstart

    git clone https://github.com/Zacheri/NiceAssets.git atr && cd atr
    docker compose up --build     # first build compiles llama.cpp (5–15 min)

Wait until `docker compose ps` shows `app` as healthy, then read the
generated admin password:

    docker compose logs app | grep "ATR admin password"

Open http://localhost:8080 and log in as `admin`.

To choose your own admin password on first boot, create a `.env` file (or
copy `.env.example`):

    ATR_ADMIN_PASS=YourLongPassword

## Using the AI assistant

1. Download a GGUF model you like (any size up to 12 GB) — e.g. from
   Hugging Face.
2. Open the **Assistant** tab and upload the `.gguf` file (admin role).
3. Select the model and press **Start**. The status pill turns green when
   it is loaded.
4. Chat. Read answers are instant; actions show a plan you confirm first.

Nothing leaves your network — the model runs locally in the container.

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `ATR_PORT` | `8080` | Host port for the web UI |
| `ATR_DB_PASS` | `atr` | Postgres password (app + db container) |
| `ATR_ADMIN_PASS` | *(random, printed to logs)* | Admin password on first boot |
| `ATR_TZ` | `UTC` | Timezone (app + cron schedules) |

See `.env.example` and `docs/INSTALL.md` for the full reference, including
how to use your own Postgres or bind-mount the data.

## Data & backups

- App data (uploads, backups, logs, reports, labels, models, sessions) lives
  in the `atr_storage` Docker volume at `/var/www/atr/storage`.
- Postgres data lives in the `atr_pgdata` volume.
- The app takes a daily backup (DB dump + uploads archive) at 02:00, keeping
  14 days. Restore procedure: `docs/OPERATIONS.md`.

## Documentation

- `docs/INSTALL.md` — installation and configuration
- `docs/OPERATIONS.md` — backups, restore, jobs, logs, updates
- `docs/ARCHITECTURE.md` — how the app is put together
- `AGENTS.md` — guide for AI agents and contributors

## Development

See `AGENTS.md`. Quick loop:

    docker compose up --build
    find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l

## License

MIT — see `LICENSE`.
```

- [ ] **Step 5: Rewrite `docs/INSTALL.md`**

Replace the entire file with:

```markdown
# Installation (Docker)

ATR Inventory runs entirely in Docker: one app container (nginx + PHP-FPM +
llama-server + cron) and one Postgres container.

## Prerequisites

- Docker Engine with the Compose plugin (`docker compose version`)
- RAM: ~4 GB baseline, plus 2–8 GB depending on the GGUF model you upload
  (rule of thumb: model file size + ~1 GB)
- Disk: ~1 GB for the image, plus room for models and data

## Install

    git clone https://github.com/Zacheri/NiceAssets.git atr
    cd atr
    docker compose up --build

The first build compiles llama.cpp from source (the tag is pinned in the
Dockerfile) — expect 5–15 minutes. Later starts are fast.

When the `app` container is healthy, open http://localhost:8080.

### First login

On first boot the entrypoint sets the `admin` password:

- If `ATR_ADMIN_PASS` is set (environment or `.env`), that password is used.
- Otherwise a random 16-character password is generated and printed once to
  the container logs:

      docker compose logs app | grep "ATR admin password"

The password is only set on first boot (marker file
`storage/.admin_initialized`). Change it later under Admin → Users.

## Configuration

Copy `.env.example` to `.env` and adjust. All variables are optional.

| Variable | Default | Purpose |
|---|---|---|
| `ATR_PORT` | `8080` | Host port the web UI is published on |
| `ATR_DB_PASS` | `atr` | Postgres password (app + db containers must match) |
| `ATR_ADMIN_PASS` | *(random)* | Admin password, applied on first boot only |
| `ATR_TZ` | `UTC` | Timezone used by the app and cron schedules |

Advanced (usually left alone): `ATR_DB_HOST`, `ATR_DB_PORT`, `ATR_DB_NAME`,
`ATR_DB_USER`.

### Using your own Postgres

Point the app at an external Postgres 17 instance:

    ATR_DB_HOST=your-host
    ATR_DB_PORT=5432
    ATR_DB_NAME=atr
    ATR_DB_USER=atr
    ATR_DB_PASS=...

The entrypoint applies `db/schema.sql` and `db/seed.sql` (both idempotent)
on every start, so the database is created and upgraded automatically. Then
comment out the `db` service in `docker-compose.yml`.

### Bind-mounting storage (optional)

To keep data on the host filesystem (e.g. for external backup tools),
replace the named volume in `docker-compose.yml`:

    volumes:
      - ./data:/var/www/atr/storage

## Firewall / LAN access

The app is designed for a trusted local network. Only the web port (8080 by
default) is published; Postgres and the model server are internal. If you
expose it beyond your LAN, put it behind a TLS-terminating reverse proxy —
the app has no built-in TLS.

## GPU inference (not included)

The bundled llama-server is built CPU-only (`GGML_NATIVE=OFF`). For GPU
inference you would build a custom image with a CUDA-enabled llama.cpp and
adjust the spawn flags in `app/Services/LlmServer.php` — out of scope for
the stock image.

## Troubleshooting

- **Port already in use** — change `ATR_PORT` in `.env`, then
  `docker compose up -d`.
- **`app` container restarts in a loop** — `docker compose logs app`. Most
  often Postgres is unreachable (check the `db` container) or the schema
  apply failed (Postgres version mismatch — use Postgres 17).
- **Model won't load** —
  `docker compose exec app tail -50 /var/www/atr/storage/logs/llama.log`.
  Usually not enough RAM, or a truncated model file (re-upload).
- **Slow replies** — expected on CPU with large models; upload a smaller
  model or lower the context length (Assistant tab → Save settings).
- **Reset the admin password** —
  `docker compose exec app php bin/reset_admin_password.php admin NewPass123`
```

- [ ] **Step 6: Rewrite `docs/OPERATIONS.md`**

Replace the entire file with:

```markdown
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
```

- [ ] **Step 7: Update `docs/ARCHITECTURE.md` (4 targeted edits)**

a) Replace the "Scheduled jobs" paragraph (lines 110–115):

```markdown
## Scheduled jobs (bin/)

All jobs bootstrap via `bin/_bootstrap.php` and log to `storage/logs/`.
They are idempotent and safe to run by hand. The launchd plists in
`install/launchd/` are regenerated from templates by `install.sh` (placeholders
`__PHP__`, `__ROOT__`, `__PREFIX__`).
```

with:

```markdown
## Scheduled jobs (bin/)

All jobs bootstrap via `bin/_bootstrap.php` and log to `storage/logs/`.
They are idempotent and safe to run by hand. In Docker they are scheduled by
cron (`/etc/cron.d/atr`, installed from `docker/cron/atr`): daily backup
02:00, weekly report Saturday 18:00, alert sweep every 15 minutes.
```

b) Replace the "Configuration precedence" paragraph (lines 117–121):

```markdown
`config/app.php` (defaults, versioned) ← overridden by `config/app.local.php`
(installer-generated, git-ignored) ← overridden at runtime by the `settings`
table for anything admin-tunable.
```

with:

```markdown
`config/app.php` (defaults, versioned) ← overridden by `config/app.local.php`
(generated by the Docker entrypoint from environment variables on every boot)
← overridden at runtime by the `settings` table for anything admin-tunable.
```

c) Replace the "Testing approach" section (lines 123–133):

```markdown
## Testing approach

There is no automated test suite. Verification:

- `php -l` on every file (syntax)
- `php bin/health.php` (DB + storage reachability)
- Seeding: `db/seed.sql` (base, always loaded) creates only roles, the default
  logins, and settings, so a production install starts empty. `db/seed-demo.sql`
  (opt-in: installer prompt or `ATR_SEED=1`) exercises every status, an open work
  order, warranty alert tiers, low stock, multi-assignment, full depreciation,
  and a previous Mon–Fri audit trail — for evaluation only.
```

with:

```markdown
## Testing approach

There is no automated test suite. CI (`.github/workflows/ci.yml`) runs
`php -l` over the codebase and builds the Docker image on every push/PR.
Local verification:

- `php -l` on every file (syntax)
- `docker compose up --build` + `curl localhost:8080/healthz`
- `php bin/health.php` (DB + storage reachability)
- Seeding: `db/seed.sql` (base, applied by the entrypoint on every boot)
  creates only roles, the default logins, and settings, so a fresh install
  starts empty. `db/seed-demo.sql` (opt-in: load it manually with psql)
  exercises every status, an open work order, warranty alert tiers, low
  stock, multi-assignment, full depreciation, and a previous Mon–Fri audit
  trail — for evaluation only.
```

d) Replace the last bullet of "AI Assistant (local LLM)" (line 142):

```markdown
- Settings keys (all in `settings`, no migrations): `llm.models_dir`, `llm.port`, `llm.context`, `llm.selected_model`. Admin card: Admin → System → "AI Assistant".
```

with:

```markdown
- Settings keys (all in `settings`, no migrations): `llm.models_dir`, `llm.port`, `llm.context`, `llm.selected_model`. Model management (upload, select, start/stop) lives on the Assistant tab (admin role); the `llama-server` binary ships in the image.
```

- [ ] **Step 8: Verify no stale references remain**

Run:
```bash
grep -rn "brew \|launchd\|launchctl\|Homebrew\|install\.sh\|app\.local\.example" --include="*.php" --include="*.md" --include="*.yml" --include="*.sh" --include="*.json" . | grep -v "^\./vendor/" | grep -v "^\./docs/superpowers/" | grep -v "^\./\.git/"
```
Expected: no output (historical design docs under `docs/superpowers/` intentionally keep their old wording — they are dated records).

- [ ] **Step 9: Verify the stack still works after removal**

Run:
```bash
docker compose up -d --build app
curl -s http://localhost:8080/healthz
```
Expected: healthy, `{"status":"ok",...}`.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "docs: Docker-only distribution — remove macOS installer, add AGENTS.md, LICENSE, rewritten docs"
```

---

### Task 6: CI workflow

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: the Dockerfile (Task 1) and the PHP codebase.
- Produces: CI that runs on every push to `main` and every PR: a `lint` job (PHP 8.4, `php -l` over all non-vendor files) and an `image` job (full `docker build`). No registry push (per spec).

- [ ] **Step 1: Create `.github/workflows/ci.yml`**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  lint:
    name: PHP lint
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v3
        with:
          php-version: '8.4'
      - name: Lint all PHP files
        run: |
          find . -name '*.php' -not -path './vendor/*' -print0 \
            | xargs -0 -n1 php -l

  image:
    name: Docker image
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Build image
        run: docker build -t atr-inventory:ci .
```

- [ ] **Step 2: Validate the YAML**

Run: `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci.yml')); print('yaml ok')"`
Expected: `yaml ok` (if PyYAML is missing: `pip3 install pyyaml` or use `ruby -ryaml -e "YAML.load_file('.github/workflows/ci.yml'); puts 'yaml ok'"`).

- [ ] **Step 3: Run the lint job's command locally**

Run:
```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l | grep -v "No syntax errors" || echo "lint clean"
```
Expected: `lint clean`.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: php -l lint + docker image build on push/PR"
```

Note: the workflow only runs on GitHub once the repo is pushed there; local verification is Steps 2–3 plus the image build already proven in Task 1.

---

### Task 7: End-to-end verification (clean slate)

**Files:** none (verification only; fix anything broken, then commit fixes).

**Interfaces:**
- Consumes: all of Tasks 1–6.
- Produces: a verified clean-slate deployment; any fixes committed.

- [ ] **Step 1: Wipe everything and rebuild from scratch**

```bash
docker compose down -v
docker compose up --build
```
Expected: fresh volumes; both containers healthy within a few minutes (llama.cpp layer is cached from Task 1, so this is fast).

- [ ] **Step 2: Verify first-boot behavior on the fresh volume**

```bash
curl -s http://localhost:8080/healthz
docker compose logs app | grep "ATR admin password"
docker compose exec app ls -a /var/www/atr/storage/ | grep admin_initialized
```
Expected: healthz ok; a NEW random password printed (different from Task 1's — the volume was wiped); marker file present.

- [ ] **Step 3: Source a small real GGUF model**

Use an existing model if available (e.g. the Qwen3-4B GGUF currently in the host's `storage/models/`), or download any small GGUF (≤1 GB) from Hugging Face. Then copy it into the volume:

```bash
docker compose cp /path/to/model.gguf app:/var/www/atr/storage/models/model.gguf
docker compose exec app chown www-data:www-data /var/www/atr/storage/models/model.gguf
```

- [ ] **Step 4: Full UI flow (admin)**

In a browser at http://localhost:8080, logged in as `admin` with the password from Step 2:
1. **Assistant** tab → Model panel shows `model.gguf` in the table (size, date).
2. Select it → **Select model** → **Start** → pill goes `loading` → `ready` (green). (First load of a 4B model on CPU can take a minute or two.)
3. Chat: ask "how many assets do we have?" → a number (0 on a fresh install) — proves the read tool round-trip through llama-server.
4. Ask an action question, e.g. "create a person called Test User" → a confirm card appears → Confirm → success row. (Verifies the two-phase action path end-to-end. Use `create_person` — the tool registry in `app/Services/Assistant/Tools.php` has no department-creation tool.)
5. **Admin → System** → AI card shows the loaded model name and a green pill.

- [ ] **Step 5: Verify backup job and cron**

```bash
docker compose exec app php /var/www/atr/bin/backup.php daily
docker compose exec app ls /var/www/atr/storage/backups/
docker compose exec app cat /etc/cron.d/atr
docker compose exec app php /var/www/atr/bin/alert_sweep.php && echo "sweep ok"
```
Expected: a `.dump` file (and uploads archive) in `backups/`; the three cron lines; `sweep ok`.

- [ ] **Step 6: Verify restart persistence and password stability**

```bash
docker compose restart
sleep 20
curl -s http://localhost:8080/healthz
docker compose logs app | grep -c "ATR admin password"
```
Expected: healthz ok after restart; the grep count is **1** (the password was printed exactly once, on first boot — restarts do not re-generate or clobber it). Log in again with the Step 2 password to confirm it still works.

- [ ] **Step 7: Final lint + full status**

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l | grep -v "No syntax errors" || echo "lint clean"
docker compose ps
git status --short
```
Expected: `lint clean`; both containers healthy; working tree clean (or only intended uncommitted fixes).

- [ ] **Step 8: Commit any fixes found during verification**

If Steps 4–7 required code/doc fixes:

```bash
git add -A
git commit -m "docker: e2e verification fixes"
```

If nothing needed fixing, no commit — the stack is done.
