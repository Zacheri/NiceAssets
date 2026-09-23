# Spec: CSV Import with LLM-Assisted Parsing

Approved design (brainstormed with the user, 2026-09-23). Imports an AssetTiger
CSV export (16 columns, ~2,164 rows in the reference sample) into Nice Assets.
The import must work fully with the LLM off; the local LLM is an optional
value-classifier.

## Decisions locked with the user

1. **Description:** add a `description` text field to assets (schema + form +
   detail + search). The CSV's Description column is the most descriptive text
   in the export and would otherwise be lost.
2. **Photos:** toggle in the import UI, **default off** (skip). When on:
   download the distinct external URLs into local storage and link them; a
   per-URL failure skips that photo and is counted + surfaced in the report.
3. **Missing persons:** auto-create person records for unmatched assignee
   names (full_name only). Non-person values (offices, customers) are excluded
   — by deterministic rules first, LLM classification when AI assist is on.
4. **LLM role:** bounded value-classifier. Deterministic rules do the heavy
   lifting; the LLM gets one batched prompt per ambiguous field with the
   **distinct values** (never row-by-row) and returns JSON classifications.
   Off by default; unavailable LLM degrades to deterministic-only with a notice.

## Reference data profile (from the real sample)

- 2,164 rows, all exactly 16 fields. Header:
  `Asset Tag ID, Purchase Date, Assigned to, Category, Asset Photo, Description,
  Brand, Serial No, Original Cost, Cost, Site, Department, Status, Date Created,
  Created by, Model #`.
- Asset Tag ID: 2,164 distinct (unique). Some quoted to preserve leading zeros
  (`"01896"` vs `1956`) — must be imported as text verbatim.
- Purchase Date: MM/DD/YYYY, 550 empty.
- Assigned to: 1,237 empty, 373 distinct; includes non-people
  ("Huntersville Office (PDS)", "Mercedes-Benz South Orlando", "Facilities").
- Category: 37 distinct, 166 empty, clean.
- Asset Photo: 635 empty, 282 distinct external `assettiger.com` URLs.
- Description: 544 distinct.
- Brand: 301 distinct, 126 empty; case/typo variants ("Acer"/"ACER",
  "CLT"/"CTL").
- Serial No: 356 empty; garbage values: `?` (33), `NA` (22), `N/A` (5),
  `Article #: …` (part numbers).
- Original Cost: 942 empty; Cost: 631 empty. Use Original Cost, fall back to Cost.
- Site: 7 distinct, clean.
- Department: 32 distinct, but 3 values are status-notes, not departments:
  `IN STOCK` (236), `DISPOSED/DONATED/SOLD` (205), `**EMPLOYEE NEVER RETURNED**` (161).
- Status: exactly 8 distinct, all mappable:
  Checked out (927) → `checked_out`, Available (557) → `available`,
  Lost/Missing (410) → `lost`, Disposed (184) → `disposed`, Sold (33) → `sold`,
  Donated (31) → `donated`, Broken (20) → `broken`, Under repair (2) → `in_repair`.
- Date Created / Created by: ignored.
- Model #: garbage values: `0 in stock` (117), `500 in stock` (28), `NA` (30).

## Architecture

### 1. Schema

- `ALTER TABLE assets ADD COLUMN IF NOT EXISTS description text;` in
  `db/schema.sql` (existing idempotent migration style).
- `description` added to: the asset form (input), asset detail (display),
  `Asset::store`/`update`/`params`, and the `search_vector` tsvector (weight C,
  alongside model_number/brand).
- No new tables. Import runs are recorded via
  `Audit::log('import.assets', 'import', 0, <summary details>)`.

### 2. `app/Services/CsvImport.php` (new)

Single service owning the whole pipeline. Pure functions where possible (no
DB access until `import()`).

- `parse(string $path): array` — `fgetcsv` (explicit `','`, `'"', '\\'`
  args), returns `['header' => [...], 'rows' => [[...], ...]]`. Rejects files
  with no header or empty rows.
- `defaultMapping(array $header): array` — CSV column name → target field.
  Known AssetTiger defaults:
  | CSV column | target |
  |---|---|
  | Asset Tag ID | `asset_tag` |
  | Purchase Date | `purchase_date` |
  | Assigned to | `person` |
  | Category | `category` |
  | Asset Photo | `photo_url` |
  | Description | `description` |
  | Brand | `brand` |
  | Serial No | `serial_number` |
  | Original Cost | `purchase_cost` |
  | Cost | `purchase_cost_fallback` |
  | Site | `site` |
  | Department | `department` |
  | Status | `status` |
  | Date Created | (ignored) |
  | Created by | (ignored) |
  | Model # | `model_number` |
  Unknown header names map to `ignore`; the user can change any mapping in the UI.
- `clean(array $row, array $mapping): array` — deterministic per-row cleaning:
  - trim all text values;
  - status via the fixed 8-value map above; unmapped status → row issue
    `status_unknown` (row excluded from import, listed in preview);
  - serial_number: null when in the garbage set (`?`, `NA`, `N/A`, `n/a`,
    `Article #: …` prefix, `Product # …` prefix) or empty;
  - model_number: null when empty or matching `* in stock` / `NA` / `N/A`;
  - purchase_cost: Original Cost when non-empty, else Cost, else `0`;
    non-numeric cost → row issue `cost_invalid` (value kept as 0, flagged);
  - purchase_date: `Y-m-d` via `DateTime::createFromFormat('m/d/Y', …)`;
    unparseable → null + row issue `date_invalid`;
  - department: null when in the status-note set (`IN STOCK`,
    `DISPOSED/DONATED/SOLD`, `**EMPLOYEE NEVER RETURNED**`, case-insensitive);
  - person: trim; empty → null (unassigned);
  - brand: trim only (normalization is an LLM-assist option, never automatic).
- `distinctValues(array $rows, array $mapping): array` — unique non-empty
  values per lookup target (`status`, `department`, `site`, `category`,
  `person`, `brand`), with counts.
- `llmClassify(array $distinct): array` — optional (AI assist on + LLM
  running). One prompt per ambiguous field (`department`, `person`, `brand`):
  system prompt fixes the JSON contract; user prompt lists the distinct values
  with counts and asks for a classification per value:
  - department → `{"value": "real-dept" | "status-note"}`
  - person → `{"value": "person" | "non-person"}`
  - brand → `{"value": <canonical brand string>}` (merge variants; value must
    be one of the input values or a trimmed/case-fixed form of one)
  Implementation: `LlmClient::chat($messages, [], fn() => null, 1, 120)`.
  Response parsed as JSON; **every classification is re-validated**: the key
  must be an input value, the value must be within the allowed set (or, for
  brand, a case/trim variant of an input value). Invalid entries are dropped.
  LLM not running / timeout / bad JSON → return `[]` + an `ai_unavailable`
  notice for the UI; the deterministic path still applies.
- `preview(array $rows, array $mapping, array $options, array $llm): array` —
  per-row issues (`duplicate_tag`, `status_unknown`, `cost_invalid`,
  `date_invalid`, `person_create`, `photo_download`/`photo_skip`), summary
  counts (`to_create`, `duplicate_skip`, `persons_to_create`,
  `photos_to_download`, `issues`), and the first 50 rows rendered-ready.
- `import(array $rows, array $mapping, array $options, array $llm, array $user): array` —
  one DB transaction:
  - match-or-create (case-insensitive exact) `category`, `site`, `department`
    (only non-null, LLM-validated values);
  - persons: match existing case-insensitively; auto-create when the option is
    on and the value is not classified non-person; otherwise leave unassigned;
  - skip rows whose `asset_tag` already exists (reported as
    `duplicate_skip`, not an error);
  - insert assets (description, purchase_cost, dates, status, lookups, person);
  - photos (option on): download each distinct URL once
    (cURL, 20s timeout, image content-type check, ≤ 10 MB) into
    `storage/uploads/` with the standard `date('Ymd_His')_hex.ext` filename,
    insert a `photos` row (kind `asset`), link via `asset_photos`;
    per-URL failure → counted, photo skipped, asset still imported;
  - commit; on any exception → rollback, report the error;
  - `Audit::log('import.assets', 'import', 0, <summary>)`;
  - returns the report (`created`, `skipped`, `errors`, `persons_created`,
    `photos_downloaded`, `photos_failed`, `categories_created`, …).

### 3. Controller + routes

- `app/Controllers/ImportController.php` (new), **admin-only**:
  - `GET /admin/import` — wizard page (step state in the form; no server-side
    session state — each step re-POSTs the file + mapping + options).
  - `POST /admin/import/analyze` — upload validated (`.csv` extension,
    `text/csv`/`application/csv`/octet-stream MIME, ≤ 10 MB, stored in
    `storage/imports/` — volume-mounted, NOT webroot), parse, clean, optional
    `llmClassify`, `preview` → render preview step.
  - `POST /admin/import/run` — same inputs + confirmed mapping/options →
    `import()` → render report step.
- `config/routes.php`: three routes, `'roles' => ['admin']`.
- The uploaded file is deleted after a successful run (and on analyze-error).

### 4. UI — Admin → "Import" (new nav item, admin-only)

One page, three rendered steps (server-rendered, no SPA):
1. **Upload:** file input + toggles: "Download photos" (default off),
   "Create missing persons" (default on), "AI assist" (default off; shows the
   LLM server state — if not running, the toggle is disabled with a hint).
2. **Preview (after Analyze):** mapping table (each CSV column → target
   `<select>`, pre-filled from `defaultMapping`, changeable — changing it
   resubmits the form and re-runs analyze server-side), summary cards (to create /
   duplicate skip / persons to create / photos to download), AI classification
   results with per-value override selects (department real/note, person
   non-person, brand canonical), issues list (row number + column + value),
   first-50-rows table.
3. **Report (after Import):** created / skipped / errors, persons created
   (list), photos downloaded/failed, categories/departments/sites created,
   link to the asset list.

### 5. Security

- Upload: extension + MIME + size validation; stored outside the webroot
  (`storage/imports/`); filename never used in a URL.
- LLM output: parsed as JSON, every key re-validated against the input value
  set, every value against the allowed set — the LLM can classify, never
  inject. On parse failure: treat as `ai_unavailable`.
- All preview/report rendering escaped with `e()`; links via `url()`.
- CSRF on both POSTs (router-enforced); admin role gate on all three routes.

### 6. Verification bar

- `php -l` on all changed files; `node --check` if JS changes.
- Live, in the real environment:
  1. AI off: import the 2,164-row sample → verify summary counts against the
     reference profile (927 checked_out, 557 available, 410 lost, 184
     disposed, 33 sold, 31 donated, 20 broken, 2 in_repair; 236+205+161
     departments nulled; 356+33+22+5+… serials nulled; duplicate tags skipped
     on a second run).
  2. Spot-check 5 imported assets in the UI (tag, description, cost, dates,
     status, person, department).
  3. AI on (host Metal server running): classification step returns valid JSON
     for departments/persons/brands; overrides work.
  4. Photos toggle on a 5-row slice: downloads succeed or fail gracefully with
     counts.
  5. Non-admin (department_manager) gets 403 on `/admin/import`.

## Out of scope

- Editing/deleting imported assets (existing asset UI covers it).
- Incremental/re-run-with-update semantics (re-run = skip existing tags).
- Excel/other formats.
- Background-job handling of files beyond the 10 MB / ~50k-row practical cap
  (synchronous import is fine at the reference scale).
