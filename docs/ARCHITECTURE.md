# Architecture & Extension Guide

How ATR is put together, and how to add features with confidence (this file is
written to be fed to an AI coding assistant or read by a new developer).

## Request lifecycle

```
Browser → Nginx (public/) → public/index.php → App\Core\App::run()
  → bootstrap (timezone, errors, session, security headers)
  → route match (config/routes.php: METHOD + /path/{param})
  → auth gate  (Auth::requireLogin → redirect /login if anonymous)
  → role gate  (route 'roles' → 403 if role missing)
  → CSRF gate  (every POST must carry a valid _token)
  → controller method  (app/Controllers/*Controller.php)
  → View::render('template/path', $data)  → templates/layout.php
```

There is **no framework**. Dependencies are only:

- `dompdf` — PDF rendering (photos/QR embed by absolute file path, chroot = project root)
- `phpoffice/phpspreadsheet` — XLSX export
- `endroid/qr-code` — QR generation (GD)

## Layers

| Layer | Where | Rule |
|-------|-------|------|
| Core | `app/Core/` | Framework: routing, PDO wrapper, auth, views, mail. No business rules. |
| Models | `app/Models/` | One class per table. All SQL lives here. Static methods. |
| Services | `app/Services/` | Cross-cutting logic: depreciation, alerts, exports, backup, replication. No HTTP knowledge. |
| Controllers | `app/Controllers/` | Thin. Read input → call model/service → render or redirect. Catch `RuntimeException` and flash the message. |
| Templates | `templates/` | PHP + HTML. No queries. Data comes in as `$` variables. Escape everything with `e()`. |

**Adding a feature** (example: "vendor" field on assets):

1. `db/schema.sql` — add the column (keep it idempotent: `ADD COLUMN IF NOT EXISTS`).
2. `app/Models/Asset.php` — add to the SELECT list, `clean()`, `store()`, `update()`.
3. `templates/assets/form.php` — add a field (name `asset[vendor]`).
4. `templates/assets/show.php` — display it in the detail grid.
5. Re-run `psql -f db/schema.sql` (safe) — done.

## Key invariants

- **Department scoping:** every asset query for a non-admin must apply
  `Auth::scopeWhere('a')`, which returns `AND a.department_id = :scope_dept`.
  Admins get an empty fragment. New asset queries (search, reports, alerts) all
  use it.
- **Lifecycle changes** go through `Asset::setStatus()` — a single transactional
  state machine that writes the audit row in the same transaction. Don't UPDATE
  `assets.status` elsewhere (work-order completion is the one documented
  exception, mirrored in `WorkOrder::complete`).
- **Audit log** is append-only and is the source of truth for the weekly report
  and Assignment Duration. Action names: `asset.<verb>` (`check_out`,
  `check_in`, `transfer`, `repair`, `broken`, `lost`, `dispose`, `sold`,
  `donate`, `create`, `update`, `delete`, `email`, `photo`, `replicate`),
  `work_order.*`, `photo.*`, `user.*`, `auth.*`, `backup.*`, `report.generate`.
- **Settings** are key/value in the `settings` table (`Setting::get/set`),
  seeded by defaults from `config/app.php` and overridable in Admin → Settings.
  Prefer settings over code changes for anything an admin should tune.
- **Alerts** are computed, not stored (`AlertEngine::dashboard()` runs on every
  dashboard/assets render). `alert_log` exists only to dedupe *emails* once per
  day per item.
- **Depreciation is never stored.** `Depreciation::calc()` and the SQL constant
  `Asset::VALUE_SQL` must stay in sync: `cost/60` per whole month, capped at 60.
- **Photos** live in the `photos` table (gallery) and are linked via
  `asset_photos` (junction). Deleting a photo unlinks it everywhere and removes
  the file. The first position (or `is_thumbnail`) is the card thumbnail.

## Concurrency & resilience

- `Database::pdo()` retries the connection 3× (2 s apart); mid-query connection
  losses trigger one reconnect-and-retry for read queries.
- Mutations that could race (check-out/transfer/status) lock the row with
  `SELECT … FOR UPDATE` inside a transaction.
- ~10 transactions/minute is the design target; nothing here needs caching.
  If you later see slow queries, the levers are: the existing indexes in
  `db/schema.sql`, `EXPLAIN ANALYZE`, and avoiding `SELECT *` in hot paths.

## Frontend

- Single CSS file (`public/theme/css/app.css`) with CSS custom properties;
  no build step, no CDN, no framework.
- `public/theme/js/app.js` handles: mobile nav, toasts, dropdowns, the action
  modal system (all lifecycle buttons post to `/assets/{id}/{action}`),
  grid column count (persisted in `user_prefs` + localStorage), dependent
  site→location selects, dashboard canvas charts, and USB scanner capture
  (fast keystrokes + Enter outside any input → `/assets?q=…`).
- The modal field definitions live in `ACTIONS` in app.js — keep them in sync
  with the fields the controller reads (`Request::post(...)`).
- `window.ATR` (set in `templates/layout.php`, in `<head>` so page scripts can
  extend it) carries `token`, `persons`, `departments`, and page-specific data
  (`locationsBySite`, `charts`, `asset`).

## Reports

`ReportController::build()` maps a report type to `[columns, rows]`:

- `columns` = `[{key, label, type: 'date'|'money'|'number'}]` — the key must
  exist in each row array.
- PDF: `Export::pdf()` (thumbnail column only if a column with key `thumb` is
  first and row count ≤ `limits.report_pdf_photo_rows`).
- Excel: `Export::excel()` — the special key `current_value` becomes a live
  `=ROUND(MAX(0,cost-(cost/60)*MIN(60,DATEDIF(purchase,TODAY(),"M"))),2)`
  formula when the formulas toggle is on (needs `purchase_cost` and
  `purchase_date` columns present).
- Every run is stored in `report_runs` with an auto-incrementing **version**
  per report type per ISO week.

## Scheduled jobs (bin/)

All jobs bootstrap via `bin/_bootstrap.php` and log to `storage/logs/`.
They are idempotent and safe to run by hand. The launchd plists in
`install/launchd/` are regenerated from templates by `install.sh` (placeholders
`__PHP__`, `__ROOT__`, `__PREFIX__`).

## Configuration precedence

`config/app.php` (defaults, versioned) ← overridden by `config/app.local.php`
(installer-generated, git-ignored) ← overridden at runtime by the `settings`
table for anything admin-tunable.

## Testing approach

There is no automated test suite. Verification:

- `php -l` on every file (syntax)
- `php bin/health.php` (DB + storage reachability)
- Seeding: `db/seed.sql` (base, always loaded) creates only roles, the default
  logins, and settings, so a production install starts empty. `db/seed-demo.sql`
  (opt-in: installer prompt or `ATR_SEED=1`) exercises every status, an open work
  order, warranty alert tiers, low stock, multi-assignment, full depreciation,
  and a previous Mon–Fri audit trail — for evaluation only.

## AI Assistant (local LLM)

- `app/Services/LlmServer.php` — app-managed `llama-server` on 127.0.0.1 (port from `llm.port`, default 8082). Models = `.gguf` files in `storage/models` (override `llm.models_dir`); selection = `llm.selected_model`. PID in `storage/run/llama.pid`, log in `storage/logs/llama.log`. Never reachable from outside localhost.
- `app/Services/LlmClient.php` — OpenAI-compatible `/v1/chat/completions` loop (tools, ≤8 rounds, 120 s budget) → `{text, trace}`.
- `app/Services/Assistant/Tools.php` — the ONLY path from the model to the DB. Asset reads are department-scoped via `Auth::scopeWhere`; person name lookups strip PII (email/phone) for non-admins; `person_detail` is admin-only (mirrors the UI). Action tools run in two modes: dry-run (returns `{preview, op, args}`) and execute (calls the normal model layer). RBAC is enforced per-tool with `requireRole()` — never trust the model.
- `app/Controllers/AssistantController.php` — `/assistant*` routes. Session: `llm_history` (last 12 messages) and `assistant_plan` (pending ops). Audit: `assistant.query` (every prompt) and `assistant.execute` (every confirm).
- Two-phase safety: a model can only *propose*; the UI renders a confirm card; `POST /assistant/confirm` executes the stored plan through `Tools::execute(..., executeMode: true)` and audits each result.
- Settings keys (all in `settings`, no migrations): `llm.models_dir`, `llm.port`, `llm.context`, `llm.selected_model`. Admin card: Admin → System → "AI Assistant".
