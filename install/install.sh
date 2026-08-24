#!/bin/bash
# ATR Inventory — macOS installer (Apple Silicon and Intel)
# Safe to re-run: every step is idempotent.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [ "$(uname -m)" = "arm64" ]; then
    PREFIX="/opt/homebrew"
else
    PREFIX="/usr/local"
fi

PORT="${ATR_PORT:-8080}"
DB_NAME="atr"
DB_USER="atr"
DB_PASS="${ATR_DB_PASS:-}"
NONINTERACTIVE="${ATR_NONINTERACTIVE:-0}"

GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; RED=$'\033[0;31m'; NC=$'\033[0m'
say()  { echo "${GREEN}[ATR]${NC} $*"; }
warn() { echo "${YELLOW}[ATR]${NC} $*"; }
die()  { echo "${RED}[ATR] $*${NC}" >&2; exit 1; }

[ -t 0 ] || NONINTERACTIVE=1
SEED_DEMO="${ATR_SEED:-0}"
if [ "$NONINTERACTIVE" != "1" ]; then
    read -r -p "ATR web port [${PORT}]: " ans; PORT="${ans:-$PORT}"
    if [ -z "$DB_PASS" ]; then
        read -r -p "PostgreSQL password for 'atr' [Enter = generate]: " DB_PASS
    fi
    if [ "$SEED_DEMO" = "0" ]; then
        read -r -p "Load sample demo inventory (for evaluation only)? [y/N] " ans
        case "${ans:-}" in [Yy]*) SEED_DEMO=1 ;; esac
    fi
fi
[ -n "$DB_PASS" ] || DB_PASS="$(php -r 'echo bin2hex(random_bytes(9));')"

say "Project root: $ROOT"
say "Architecture: $(uname -m) (brew prefix $PREFIX)"

# ---------- 1. Homebrew ----------
if ! command -v brew >/dev/null 2>&1; then
    warn "Homebrew not found. Installing (requires network; you may be asked for your Mac password)..."
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
    [ -f "$HOMEBREW_PREFIX/bin/brew" ] && eval "$("$HOMEBREW_PREFIX/bin/brew" shellenv)"
fi
command -v brew >/dev/null 2>&1 || die "Homebrew is required. Install it from https://brew.sh and re-run."
say "Homebrew: $(brew --version | head -1)"

# ---------- 2. Dependencies ----------
ensure_formula() {
    local formula="$1" binary="$2"
    if command -v "$binary" >/dev/null 2>&1; then
        say "$binary already installed"
    else
        say "Installing $formula..."
        brew install "$formula"
    fi
}
ensure_formula nginx nginx
ensure_formula postgresql psql
ensure_formula php php
ensure_formula composer composer

command -v php >/dev/null 2>&1 || die "php not found on PATH"
say "PHP: $(php -v | head -1)"
php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' || die "PHP is missing the pdo_pgsql extension (brew install php)"
php -r 'exit(extension_loaded("gd") ? 0 : 1);'        || warn "gd extension missing — PDF photo rendering and sample photos will not work"

# ---------- 3. PostgreSQL ----------
if "$PREFIX/bin/pg_isready" -h 127.0.0.1 -p 5432 >/dev/null 2>&1; then
    say "PostgreSQL already running on 127.0.0.1:5432"
    if brew services list 2>/dev/null | awk '$1 ~ /^postgresql/ && $2 == "error" { found = 1 } END { exit !found }'; then
        warn "A postgresql launchd agent is in an error state (server runs elsewhere) — stopping the stale agent."
        brew services stop postgresql >/dev/null 2>&1 || true
    fi
else
    say "Starting PostgreSQL (launchd)..."
    brew services start postgresql >/dev/null || true
fi
PGREADY=0
for i in $(seq 1 30); do
    if "$PREFIX/bin/pg_isready" -h 127.0.0.1 -p 5432 >/dev/null 2>&1; then PGREADY=1; break; fi
    sleep 1
done
[ "$PGREADY" = "1" ] || die "PostgreSQL did not become ready on 127.0.0.1:5432"
say "PostgreSQL ready: $("psql" -h 127.0.0.1 -p 5432 -U postgres -tAc 'SELECT version()' 2>/dev/null | cut -d' ' -f2 || echo 'running')"

# Create role + database if missing (via local unix socket).
# The cluster superuser is usually 'postgres', but on macOS Homebrew it may be
# the OS user that first initialized the cluster — detect it.
if psql -U postgres -d postgres -tAc "SELECT 1" >/dev/null 2>&1; then
    SUPERUSER="postgres"
else
    SUPERUSER="$(whoami)"
fi
psql -U "$SUPERUSER" -d postgres -tAc "SELECT 1 FROM pg_roles WHERE rolname = '${DB_USER}'" | grep -q 1 || {
    say "Creating database role '${DB_USER}' (as superuser ${SUPERUSER})..."
    psql -U "$SUPERUSER" -d postgres -c "CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASS}';" >/dev/null
}
psql -U "$SUPERUSER" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '${DB_NAME}'" | grep -q 1 || {
    say "Creating database '${DB_NAME}'..."
    createdb -U "$SUPERUSER" -O "${DB_USER}" "${DB_NAME}"
}
say "Loading schema..."
PGPASSWORD="${DB_PASS}" psql -h 127.0.0.1 -U "${DB_USER}" -d "${DB_NAME}" -v ON_ERROR_STOP=1 -q -f db/schema.sql
say "Loading base seed (roles, users, settings)..."
PGPASSWORD="${DB_PASS}" psql -h 127.0.0.1 -U "${DB_USER}" -d "${DB_NAME}" -v ON_ERROR_STOP=1 -q -f db/seed.sql
if [ "$SEED_DEMO" = "1" ]; then
    say "Loading SAMPLE/DEMO inventory (evaluation only — not for production)..."
    PGPASSWORD="${DB_PASS}" psql -h 127.0.0.1 -U "${DB_USER}" -d "${DB_NAME}" -v ON_ERROR_STOP=1 -q -f db/seed-demo.sql
else
    say "No sample data loaded (start with ATR_SEED=1 if you ever want the demo dataset)."
fi

# ---------- 4. PHP app ----------
say "Installing Composer dependencies..."
composer install --no-dev --no-interaction --quiet || composer install --no-dev --no-interaction

for d in uploads sessions backups logs reports labels; do
    mkdir -p "storage/$d"
done
chmod -R u+rwX storage

if [ ! -f config/app.local.php ]; then
    cat > config/app.local.php <<EOF
<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => '5432',
        'name' => '${DB_NAME}',
        'user' => '${DB_USER}',
        'pass' => '${DB_PASS}',
    ],
];
EOF
    say "Wrote config/app.local.php"
else
    warn "config/app.local.php already exists — keeping it (update DB credentials there if you changed them)"
fi

if [ "$SEED_DEMO" = "1" ]; then
    say "Creating sample photos..."
    php bin/make_sample_photos.php || warn "Sample photo creation failed (gd extension required)"
fi

# ---------- 5. PHP-FPM + Nginx ----------
brew services start php >/dev/null || true
for i in $(seq 1 15); do
    if nc -z 127.0.0.1 9000 >/dev/null 2>&1; then break; fi
    sleep 1
done
nc -z 127.0.0.1 9000 >/dev/null 2>&1 || warn "PHP-FPM not listening on 127.0.0.1:9000 yet — starting it manually"
brew services restart php >/dev/null || true

# Homebrew's stock nginx.conf ships a default `server { listen 8080; }` block
# that would collide with us (or steal 8080). Disable it (idempotent).
NGINX_CONF="$PREFIX/etc/nginx/nginx.conf"
if grep -Eq '^[[:space:]]*server[[:space:]]*\{' "$NGINX_CONF"; then
    say "Disabling Homebrew's default nginx server block..."
    [ -f "${NGINX_CONF}.bak-atr" ] || cp "$NGINX_CONF" "${NGINX_CONF}.bak-atr"
    awk '
        /^[[:space:]]*server[[:space:]]*\{/ { inblk = 1; depth = 0 }
        inblk {
            depth += gsub(/{/, "{"); depth -= gsub(/}/, "}");
            if ($0 !~ /^[[:space:]]*#/) print "# " $0; else print $0;
            if (depth <= 0) inblk = 0;
            next;
        }
        { print }
    ' "$NGINX_CONF" > "${NGINX_CONF}.tmp" && mv "${NGINX_CONF}.tmp" "$NGINX_CONF"
fi

mkdir -p "$PREFIX/etc/nginx/servers"
sed -e "s|__PORT__|${PORT}|g" \
    -e "s|__ROOT__|${ROOT}|g" \
    -e "s|__FPM__|127.0.0.1:9000|g" \
    install/nginx.conf > "$PREFIX/etc/nginx/servers/atr.conf"
say "Wrote nginx server config (port ${PORT})"

nginx -t >/dev/null
if pgrep -x nginx >/dev/null 2>&1; then
    nginx -s reload
else
    # Unload a stale launchd agent if one is still registered (bootstrap would fail with EIO).
    launchctl bootout "gui/$(id -u)/homebrew.mxcl.nginx" 2>/dev/null || true
    brew services start nginx >/dev/null
fi

# ---------- 6. Background jobs (launchd) ----------
AGENTS="$HOME/Library/LaunchAgents"
mkdir -p "$AGENTS"
install_plist() {
    local src="$1" label
    label="$(basename "$src" .plist)"
    local dst="$AGENTS/com.atr.${label#atr-}.plist"
    [ "$label" = "atr-backup" ] && dst="$AGENTS/com.atr.backup.plist"
    sed -e "s|__PHP__|$PREFIX/bin/php|g" \
        -e "s|__ROOT__|${ROOT}|g" \
        -e "s|__PREFIX__|${PREFIX}|g" \
        "$src" > "$dst"
    if launchctl print "gui/$(id -u)/$(basename "$dst" .plist)" >/dev/null 2>&1; then
        launchctl bootout "gui/$(id -u)/$(basename "$dst" .plist)" 2>/dev/null || true
    fi
    launchctl bootstrap "gui/$(id -u)" "$dst" && say "Scheduled $(basename "$dst")"
}
install_plist install/launchd/atr-backup.plist
install_plist install/launchd/atr-weekly-report.plist
install_plist install/launchd/atr-alert-sweep.plist

# ---------- 7. Verify ----------
say "Running health check..."
php bin/health.php || warn "Health check reported issues (see above)"

URL="http://$(ipconfig getifaddr en0 2>/dev/null || echo 127.0.0.1):${PORT}"
echo
say "=============================================================="
say " ATR Inventory is installed."
say "   Local:    http://127.0.0.1:${PORT}"
say "   Network:  ${URL}   (for other devices on your LAN)"
say "   Sign in:  admin / Admin1234"
say "   (change this password in Admin → Users)"
say "=============================================================="
warn "Unattended background services are active: daily backup (02:00), weekly report (Sat 18:00), alert sweep (every 15 min)."
