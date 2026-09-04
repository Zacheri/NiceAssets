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
