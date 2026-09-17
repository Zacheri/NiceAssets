# Installation (Docker)

Nice Assets runs entirely in Docker: one app container (nginx + PHP-FPM +
llama-server + cron) and one Postgres container.

## Prerequisites

- Docker Engine with the Compose plugin (`docker compose version`)
- RAM: ~4 GB baseline, plus 2–8 GB depending on the GGUF model you upload
  (rule of thumb: model file size + ~1 GB)
- Disk: ~1 GB for the image, plus room for models and data

## Install

    git clone https://github.com/Zacheri/NiceAssets.git naims
    cd naims
    docker compose up --build

The first build compiles llama.cpp from source (the tag is pinned in the
Dockerfile) — expect 5–15 minutes. Later starts are fast.

When the `app` container is healthy, open http://localhost:8080.

### First login

On first boot the entrypoint sets the `admin` password:

- If `NAIMS_ADMIN_PASS` is set (environment or `.env`), that password is used.
- Otherwise a random 16-character password is generated and printed once to
  the container logs:

      docker compose logs app | grep "NAIMS admin password"

The password is only set on first boot (marker file
`storage/.admin_initialized`). Change it later under Admin → Users.

## Configuration

Copy `.env.example` to `.env` and adjust. All variables are optional.

| Variable | Default | Purpose |
|---|---|---|
| `NAIMS_PORT` | `8080` | Host port the web UI is published on |
| `NAIMS_DB_PASS` | `naims` | Postgres password (app + db containers must match) |
| `NAIMS_ADMIN_PASS` | *(random)* | Admin password, applied on first boot only |
| `NAIMS_TZ` | `UTC` | Timezone used by the app and cron schedules |

Advanced (usually left alone): `NAIMS_DB_HOST`, `NAIMS_DB_PORT`, `NAIMS_DB_NAME`,
`NAIMS_DB_USER`.

### Using your own Postgres

Point the app at an external Postgres 17 instance:

    NAIMS_DB_HOST=your-host
    NAIMS_DB_PORT=5432
    NAIMS_DB_NAME=naims
    NAIMS_DB_USER=naims
    NAIMS_DB_PASS=...

The entrypoint applies `db/schema.sql` and `db/seed.sql` (both idempotent)
on every start, so the database is created and upgraded automatically. Then
comment out the `db` service in `docker-compose.yml`.

### Bind-mounting storage (optional)

To keep data on the host filesystem (e.g. for external backup tools),
replace the named volume in `docker-compose.yml`:

    volumes:
      - ./data:/var/www/naims/storage

## Firewall / LAN access

The app is designed for a trusted local network. Only the web port (8080 by
default) is published; Postgres and the model server are internal. If you
expose it beyond your LAN, put it behind a TLS-terminating reverse proxy —
the app has no built-in TLS.

## GPU acceleration

By default the assistant runs the llama-server built into the app container,
CPU-only (`GGML_NATIVE=OFF`). That works on every platform with zero
prerequisites, but it is slow — expect a few tokens per second with 4B
models.

### macOS / Apple Silicon

The container cannot use the Apple GPU — Docker Desktop has no Mac GPU
passthrough. Instead, run a native `llama-server` on the host and point Nice
Assets at it:

1. Install llama.cpp on the host: `brew install llama.cpp` (the helper
   script tells you if it is missing).
2. Start the host server in a terminal:

       scripts/macos-llama-server.sh /path/to/model.gguf

   With no argument it picks the first `.gguf` in `./storage/models`.
3. In Nice Assets → Assistant tab → Model panel, enable **External server**
   and set host `host.docker.internal`, port `8082` (or your `PORT`).

The model file must be a local path the host can read — the host server
loads it directly, not from the container.

### Linux with a GPU (Nvidia / AMD / Intel)

In-container GPU builds are planned but not yet available. Until then, use
the stock CPU image (or a host-native server as above).

## Troubleshooting

- **Port already in use** — change `NAIMS_PORT` in `.env`, then
  `docker compose up -d`.
- **`app` container restarts in a loop** — `docker compose logs app`. Most
  often Postgres is unreachable (check the `db` container) or the schema
  apply failed (Postgres version mismatch — use Postgres 17).
- **Model won't load** —
  `docker compose exec app tail -50 /var/www/naims/storage/logs/llama.log`.
  Usually not enough RAM, or a truncated model file (re-upload).
- **Slow replies** — expected on CPU with large models; upload a smaller
  model or lower the context length (Assistant tab → Save settings).
- **Reset the admin password** —
  `docker compose exec app php bin/reset_admin_password.php admin NewPass123`
