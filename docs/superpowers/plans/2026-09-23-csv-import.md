# CSV Import with LLM-Assisted Parsing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import an AssetTiger CSV export (16 columns, ~2,164 rows) into Nice Assets via an admin wizard, with deterministic cleaning, an optional LLM value-classifier, and optional photo download.

**Architecture:** A new `App\Services\CsvImport` service owns the pipeline (parse → map → clean → optional LLM classify → preview → transactional import). New model methods keep all SQL in `app/Models/*`. A new admin-only `ImportController` renders a 3-step server-rendered wizard (upload → preview → report). The import works fully with the LLM off.

**Tech Stack:** Vanilla PHP 8.4 (no framework), PostgreSQL 17, `fgetcsv`, cURL for photo download, `LlmClient::chat()` for the optional classifier, vanilla HTML/CSS (one `app.css`).

**Spec:** `docs/superpowers/specs/2026-09-23-csv-import-design.md` — read it first; it is the authority this plan argues from.

## Global Constraints

- Vanilla PHP 8.4 + PostgreSQL, no frameworks, no JS build step. PSR-4 `app/`, SQL in models, thin controllers, `e()` escaping, `url()` links, CSRF on every POST (router-enforced `_token`), vanilla JS IIFEs.
- All schema changes idempotent in `db/schema.sql` (the entrypoint applies it every boot with `ON_ERROR_STOP=1`).
- No automated test suite. Verification bar per task: `php -l` on every changed/created PHP file, `node --check` if JS changes, and CLI smoke tests (`php -r` with `vendor/autoload.php`) for pure functions. **Do NOT run `docker compose`** — the live stack on port 8080 belongs to the main checkout; the controller rebuilds and live-verifies after merge.
- The reference sample CSV is at `/Users/zacheri/Documents/NAIMS/sample-import.csv` (readable from the worktree via that absolute path). Its profile (2,164 rows × 16 fields; 927 Checked out / 557 Available / 410 Lost-Missing / 184 Disposed / 33 Sold / 31 Donated / 20 Broken / 2 Under repair; 236 `IN STOCK` + 205 `DISPOSED/DONATED/SOLD` + 161 `**EMPLOYEE NEVER RETURNED**` departments; 356 empty + 33 `?` + 22 `NA` + 5 `N/A` serials; 117 `0 in stock` + 28 `500 in stock` + 30 `NA` models) is the acceptance dataset for the deterministic path.
- Uploads: `.csv` extension, ≤ 10 MB, stored in `storage/imports/` (volume-mounted, NOT webroot); filename never used in a URL.
- LLM output: parsed as JSON; every key re-validated against the input value set; every value against the allowed domain; invalid entries dropped; parse failure = `ai_unavailable`, never a crash.
- The import must work with the LLM off and with the LLM server not running.
- Audit: `Audit::log(action, entity, entityId, details)`; import runs log `import.assets`.
- Commit style: `feat: …` / `ui: …` / `fix: …` one-line messages, one commit per task.

## File Structure

- `db/schema.sql` — modify: `assets.description` column (migration v1.4) + `description` term in the `search_vector` expression.
- `app/Models/Asset.php` — modify: `description` in `clean()`/`store()`/`update()`; new `importRow()`, `linkPhoto()`.
- `app/Models/Photo.php` — modify: new `createFromPath()` (downloaded-image variant of `upload()`).
- `app/Models/Person.php` — modify: new `findByName()`.
- `app/Models/Category.php`, `app/Models/Department.php`, `app/Models/Site.php` — modify: new `findByName()` + `create(string $name): int`.
- `app/Services/CsvImport.php` — create: the whole pipeline.
- `app/Controllers/ImportController.php` — create: `index()`, `analyze()`, `run()`.
- `config/routes.php` — modify: 3 admin-only routes.
- `templates/admin/import.php` — create: 3-step wizard.
- `templates/assets/form.php`, `templates/assets/show.php` — modify: description field.
- `templates/layout.php` — modify: "Import" nav item in the Admin section.
- `public/theme/css/app.css` — modify: wizard styles (step cards, mapping table, issues list, report).

---

### Task 1: Schema + model prep (description field + import model methods)

**Files:**
- Modify: `db/schema.sql` (migration block near line 189; `search_vector` expression at lines 141-148)
- Modify: `app/Models/Asset.php` (`clean()` ~line 180-213, `store()` ~215-248, `update()` ~250-285)
- Modify: `app/Models/Photo.php` (after `upload()`, ~line 108)
- Modify: `app/Models/Person.php` (after `find()`, ~line 44)
- Modify: `app/Models/Category.php`, `app/Models/Department.php`, `app/Models/Site.php` (after `find()`/`store()`)
- Modify: `templates/assets/form.php` (fields area ~lines 18-30), `templates/assets/show.php` (page-head ~lines 10-16)

**Interfaces:**
- Consumes: `Database::insert/execute/fetchOne/fetchAll` (app/Core/Database.php — read it first), `Audit::log`, `Config::get('storage.uploads')`, `Config::get('limits.photo_max_mb')`.
- Produces (exact signatures later tasks rely on):
  - `Asset::importRow(array $c, int $userId): int` — `$c` keys: `asset_tag, serial_number, model_number, brand, description, category_id, department_id, site_id, assigned_to_person_id, purchase_date, purchase_cost, status`.
  - `Asset::linkPhoto(int $assetId, int $photoId, int $position = 0, bool $isThumbnail = false): void`
  - `Photo::createFromPath(string $path, string $originalName, string $variety, ?array $user, string $kind = 'asset'): int`
  - `Person::findByName(string $name): ?array`
  - `Category::findByName(string $name): ?array`, `Category::create(string $name): int` (same pair for `Department`, `Site`)

- [ ] **Step 1: schema — description column + search term**

In `db/schema.sql`, after the migration v1.3 block (the `users.theme` ALTER, ~line 189-190), add:

```sql
-- Migration v1.4: asset description (CSV import).
ALTER TABLE assets ADD COLUMN IF NOT EXISTS description text;
```

In the existing `search_vector` expression (lines 141-148 — the block already `DROP COLUMN IF EXISTS search_vector` then re-ADDs it every boot, so editing the expression is safe), add one term after the `brand` line:

```sql
        setweight(to_tsvector('english', coalesce(description, '')),   'C') ||
```

- [ ] **Step 2: Asset model — description in clean/store/update**

In `Asset::clean()`'s `$clean` array add:

```php
'description' => mb_substr(trim((string) ($d['description'] ?? '')), 0, 500),
```

In `Asset::store()`'s INSERT add `description` to the column list and `:desc` to the values, with param `'desc' => $c['description']`.
In `Asset::update()`'s UPDATE add `description = :desc` and the same param.

- [ ] **Step 3: Asset model — importRow + linkPhoto**

Add to `app/Models/Asset.php`:

```php
    /**
     * Bulk-import insert: full field set, status taken from the caller,
     * no per-row audit (the import service audits the run as a whole).
     */
    public static function importRow(array $c, int $userId): int
    {
        $id = Database::insert(
            'INSERT INTO assets (asset_tag, serial_number, model_number, brand, description,
                category_id, department_id, site_id, assigned_to_person_id,
                purchase_date, purchase_cost, status, created_by)
             VALUES (:t, :sn, :mn, :b, :desc, :cat, :dep, :site, :person, :pd, :cost, :status, :cb)',
            [
                't' => $c['asset_tag'],
                'sn' => $c['serial_number'],
                'mn' => $c['model_number'],
                'b' => $c['brand'],
                'desc' => $c['description'],
                'cat' => $c['category_id'],
                'dep' => $c['department_id'],
                'site' => $c['site_id'],
                'person' => $c['assigned_to_person_id'],
                'pd' => $c['purchase_date'],
                'cost' => $c['purchase_cost'],
                'status' => $c['status'],
                'cb' => $userId,
            ]
        );
        return (int) $id;
    }

    public static function linkPhoto(int $assetId, int $photoId, int $position = 0, bool $isThumbnail = false): void
    {
        Database::execute(
            'INSERT INTO asset_photos (asset_id, photo_id, position, is_thumbnail)
             VALUES (:a, :p, :pos, :t) ON CONFLICT DO NOTHING',
            ['a' => $assetId, 'p' => $photoId, 'pos' => $position, 't' => $isThumbnail ? 1 : 0]
        );
    }
```

- [ ] **Step 4: Photo::createFromPath**

Add to `app/Models/Photo.php` (mirror `upload()`'s validation — same MIME whitelist, same filename pattern, same size cap — but `copy()` instead of `move_uploaded_file()` since the source is a local download, not an upload):

```php
    /**
     * Create a photo row from an already-downloaded local file
     * (CSV import photo download). Same validation as upload().
     */
    public static function createFromPath(string $path, string $originalName, string $variety, ?array $user, string $kind = 'asset'): int
    {
        $maxBytes = (int) ((float) Config::get('limits.photo_max_mb')) * 1024 * 1024;
        $size = (int) filesize($path);
        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Photo too large (max ' . Config::get('limits.photo_max_mb') . ' MB).');
        }
        $info = @getimagesize($path);
        if ($info === false) {
            throw new RuntimeException('Downloaded file is not a valid image.');
        }
        $ext = match ($info['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
        if ($ext === null) {
            throw new RuntimeException('Unsupported image type: ' . $info['mime']);
        }
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = Config::get('storage.uploads') . '/' . $filename;
        if (!copy($path, $dest)) {
            throw new RuntimeException('Could not save downloaded photo.');
        }
        $id = Database::insert(
            'INSERT INTO photos (filename, original_name, variety, mime, size, kind, created_by)
             VALUES (:f, :o, :v, :m, :s, :k, :cb)',
            [
                'f' => $filename,
                'o' => basename($originalName),
                'v' => trim($variety),
                'm' => $info['mime'],
                's' => $size,
                'k' => $kind,
                'cb' => $user['id'] ?? null,
            ]
        );
        Audit::log('photo.upload', 'photo', (string) $id, [
            'variety' => trim($variety),
            'kind' => $kind,
            'files' => [$filename],
            'source' => 'import_download',
        ]);
        return (int) $id;
    }
```

- [ ] **Step 5: lookup match-or-create model methods**

Add to `Person.php`:

```php
    public static function findByName(string $name): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM persons WHERE lower(full_name) = lower(:n)',
            ['n' => trim($name)]
        );
    }
```

Add to each of `Category.php`, `Department.php`, `Site.php` (adjust table name):

```php
    public static function findByName(string $name): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM categories WHERE lower(name) = lower(:n)',
            ['n' => trim($name)]
        );
    }

    /** Create by name; on race (23505) returns the existing row's id. */
    public static function create(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }
        try {
            return (int) Database::insert(
                'INSERT INTO categories (name) VALUES (:n)',
                ['n' => $name]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                $existing = self::findByName($name);
                if ($existing !== null) {
                    return (int) $existing['id'];
                }
            }
            throw $e;
        }
    }
```

Note: `categories`/`departments`/`sites` all have `name text NOT NULL UNIQUE` — the 23505 race path is correct for all three. `Department::store`/`Site::store`/`Category::store` already exist for the admin UI; do not modify them.

- [ ] **Step 6: asset form + detail — description**

In `templates/assets/form.php`, after the brand field (~line 29), add a description field matching the existing field markup pattern (copy the structure of the model_number field, use a `<textarea rows="2">`, `name="asset[description]"`, value `e($v('description'))`).
In `templates/assets/show.php`, after the existing `.page-sub` line (~line 15), add:

```php
    <?php if (!empty($asset['description'])): ?>
      <div class="page-sub"><?= e($asset['description']) ?></div>
    <?php endif; ?>
```

- [ ] **Step 7: Verify**

Run: `php -l` on every changed PHP file — expect "No syntax errors detected" for each.
Run (CLI smoke, no DB needed): `php -r 'require "vendor/autoload.php"; var_dump(method_exists(\App\Models\Asset::class, "importRow"), method_exists(\App\Models\Asset::class, "linkPhoto"), method_exists(\App\Models\Photo::class, "createFromPath"), method_exists(\App\Models\Person::class, "findByName"), method_exists(\App\Models\Category::class, "create"));'` — expect all `true`.

- [ ] **Step 8: Commit**

```bash
git add db/schema.sql app/Models/Asset.php app/Models/Photo.php app/Models/Person.php app/Models/Category.php app/Models/Department.php app/Models/Site.php templates/assets/form.php templates/assets/show.php
git commit -m "feat: asset description field + import model methods"
```

---

### Task 2: CsvImport service — deterministic core

**Files:**
- Create: `app/Services/CsvImport.php`

**Interfaces:**
- Consumes: Task 1's `Asset::importRow`, `Asset::linkPhoto`, `Person::findByName`/`Person::create`, `Category|Department|Site::findByName`/`create`; `Database::fetchAll/insert/execute` + `Database::transaction(callable)` (read app/Core/Database.php for the exact transaction API — if there is no `transaction()` helper, use `PDO` begin/commit/rollback via the `Database` connection accessor it exposes).
- Produces (Task 3/4/5 rely on these exact signatures):
  - `CsvImport::parse(string $path): array` → `['header' => string[], 'rows' => string[][]]`
  - `CsvImport::defaultMapping(array $header): array` → `csv column name => target` where target ∈ `asset_tag, purchase_date, person, category, photo_url, description, brand, serial_number, purchase_cost, purchase_cost_fallback, site, department, status, model_number, ignore`
  - `CsvImport::clean(array $row, array $mapping): array` → per-row cleaned array + `'issues' => string[]` (keys: `asset_tag, serial_number, model_number, brand, description, purchase_cost (float), purchase_date (?string Y-m-d), status (?string enum), category, department, site, person, photo_url, issues`)
  - `CsvImport::distinctValues(array $rows, array $mapping): array` → `['status' => [value => count], 'department' => …, 'site' => …, 'category' => …, 'person' => …, 'brand' => …]` (non-empty values only)
  - `CsvImport::preview(array $rows, array $mapping, array $options, array $llm = []): array` → `['summary' => array, 'issues' => array, 'rows' => array (first 50), 'total' => int]`
  - `CsvImport::import(array $rows, array $mapping, array $options, array $llm = [], ?array $user = null): array` → report `['created' => int, 'skipped' => int, 'errors' => int, 'persons_created' => string[], 'photos_downloaded' => int, 'photos_failed' => int, 'categories_created' => string[], 'departments_created' => string[], 'sites_created' => string[], 'duplicate_skip' => int]`
  - `$options` keys: `photos` (bool), `create_persons` (bool). `$llm` shape (Task 3): `['departments' => [value => 'real-dept'|'status-note'], 'persons' => [value => 'person'|'non-person'], 'brands' => [value => canonical], 'ai_unavailable' => bool]`.

- [ ] **Step 1: class skeleton + constants**

Create `app/Services/CsvImport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Department;
use App\Models\Person;
use App\Models\Site;
use App\Models\Audit;
use App\Core\Database;
use RuntimeException;

final class CsvImport
{
    public const MAX_FILE_MB = 10;
    public const PREVIEW_ROWS = 50;

    /** AssetTiger status label (lowercased) -> app status enum. */
    public const STATUS_MAP = [
        'checked out' => 'checked_out',
        'available' => 'available',
        'lost/missing' => 'lost',
        'disposed' => 'disposed',
        'sold' => 'sold',
        'donated' => 'donated',
        'broken' => 'broken',
        'under repair' => 'in_repair',
    ];

    /** Serial-number values that mean "no serial" (lowercased). */
    public const GARBAGE_SERIALS = ['?', 'na', 'n/a'];

    /** Department values that are really status notes (lowercased). */
    public const DEPT_STATUS_NOTES = ['in stock', 'disposed/donated/sold', '**employee never returned**'];
```

- [ ] **Step 2: parse()**

```php
    public static function parse(string $path): array
    {
        $in = @fopen($path, 'r');
        if ($in === false) {
            throw new RuntimeException('Could not read the uploaded file.');
        }
        $header = fgetcsv($in, 0, ',', '"', '\\');
        if ($header === false || $header === [null]) {
            fclose($in);
            throw new RuntimeException('The file has no header row.');
        }
        $rows = [];
        while (($r = fgetcsv($in, 0, ',', '"', '\\')) !== false) {
            if ($r === [null]) {
                continue; // blank line
            }
            $rows[] = array_map(fn($v) => $v === null ? '' : (string) $v, $r);
        }
        fclose($in);
        if ($rows === []) {
            throw new RuntimeException('The file has no data rows.');
        }
        return ['header' => array_map(fn($h) => trim((string) $h), $header), 'rows' => $rows];
    }
```

- [ ] **Step 3: defaultMapping()**

```php
    /** Known AssetTiger export column names -> target fields. */
    public static function defaultMapping(array $header): array
    {
        $known = [
            'asset tag id' => 'asset_tag',
            'purchase date' => 'purchase_date',
            'assigned to' => 'person',
            'category' => 'category',
            'asset photo' => 'photo_url',
            'description' => 'description',
            'brand' => 'brand',
            'serial no' => 'serial_number',
            'original cost' => 'purchase_cost',
            'cost' => 'purchase_cost_fallback',
            'site' => 'site',
            'department' => 'department',
            'status' => 'status',
            'model #' => 'model_number',
            'date created' => 'ignore',
            'created by' => 'ignore',
        ];
        $map = [];
        foreach ($header as $h) {
            $map[$h] = $known[strtolower($h)] ?? 'ignore';
        }
        return $map;
    }
```

- [ ] **Step 4: clean()**

```php
    /**
     * Deterministic per-row cleaning. Returns the cleaned row plus
     * 'issues' (subset of: tag_empty, status_unknown, cost_invalid, date_invalid).
     */
    public static function clean(array $row, array $mapping): array
    {
        $get = fn(string $target) => self::valueFor($row, $mapping, $target);
        $issues = [];

        $tag = trim($get('asset_tag'));
        if ($tag === '') {
            $issues[] = 'tag_empty';
        }

        $statusRaw = trim($get('status'));
        $status = self::STATUS_MAP[strtolower($statusRaw)] ?? null;
        if ($statusRaw !== '' && $status === null) {
            $issues[] = 'status_unknown';
        }

        $serial = trim($get('serial_number'));
        if ($serial === '' || in_array(strtolower($serial), self::GARBAGE_SERIALS, true)
            || str_starts_with($serial, 'Article #:') || str_starts_with($serial, 'Product #')) {
            $serial = null;
        }

        $model = trim($get('model_number'));
        if ($model === '' || in_array(strtolower($model), ['na', 'n/a'], true)
            || (bool) preg_match('/^\d+ in stock$/i', $model)) {
            $model = null;
        }

        $costRaw = trim($get('purchase_cost'));
        if ($costRaw === '') {
            $costRaw = trim($get('purchase_cost_fallback'));
        }
        $cost = 0.0;
        if ($costRaw !== '') {
            $num = (float) str_replace([',', '$'], '', $costRaw);
            if (!is_numeric(str_replace([',', '$'], '', $costRaw))) {
                $issues[] = 'cost_invalid';
            } else {
                $cost = max(0.0, $num);
            }
        }

        $dateRaw = trim($get('purchase_date'));
        $date = null;
        if ($dateRaw !== '') {
            $dt = DateTime::createFromFormat('m/d/Y', $dateRaw);
            if ($dt === false) {
                $issues[] = 'date_invalid';
            } else {
                $date = $dt->format('Y-m-d');
            }
        }

        $dept = trim($get('department'));
        if ($dept === '' || in_array(strtolower($dept), self::DEPT_STATUS_NOTES, true)) {
            $dept = null;
        }

        return [
            'asset_tag' => $tag,
            'serial_number' => $serial,
            'model_number' => $model,
            'brand' => trim($get('brand')) !== '' ? trim($get('brand')) : null,
            'description' => trim($get('description')) !== '' ? trim($get('description')) : null,
            'purchase_cost' => $cost,
            'purchase_date' => $date,
            'status' => $status,
            'category' => trim($get('category')) !== '' ? trim($get('category')) : null,
            'department' => $dept,
            'site' => trim($get('site')) !== '' ? trim($get('site')) : null,
            'person' => trim($get('person')) !== '' ? trim($get('person')) : null,
            'photo_url' => trim($get('photo_url')) !== '' ? trim($get('photo_url')) : null,
            'issues' => $issues,
        ];
    }

    /** First column mapped to $target wins (purchase_cost vs fallback handled in clean). */
    private static function valueFor(array $row, array $mapping, string $target): string
    {
        foreach ($mapping as $col => $t) {
            if ($t === $target) {
                $i = array_search($col, array_keys($mapping), true);
                return (string) ($row[$i] ?? '');
            }
        }
        return '';
    }
```

Note: `valueFor` iterates the mapping in header order (the mapping is built from the header), so the first matching column wins — which is why `purchase_cost` (Original Cost) beats `purchase_cost_fallback` (Cost) only because clean() checks the primary first; keep that logic in clean(), not valueFor.

- [ ] **Step 5: distinctValues()**

```php
    public static function distinctValues(array $rows, array $mapping): array
    {
        $targets = ['status', 'department', 'site', 'category', 'person', 'brand'];
        $out = array_fill_keys($targets, []);
        foreach ($rows as $row) {
            $c = self::clean($row, $mapping);
            foreach ($targets as $t) {
                $v = $c[$t];
                if ($v !== null && $v !== '') {
                    $out[$t][$v] = ($out[$t][$v] ?? 0) + 1;
                }
            }
        }
        return $out;
    }
```

- [ ] **Step 6: preview()**

```php
    /**
     * $llm: Task 3's classification output (may be []).
     * Returns summary counts, the issue list, and the first PREVIEW_ROWS rows.
     */
    public static function preview(array $rows, array $mapping, array $options, array $llm = []): array
    {
        $existingTags = array_map('strtolower', Database::fetchAll('SELECT asset_tag FROM assets'));
        $existingPersons = array_map(fn($p) => strtolower($p['full_name']), Database::fetchAll('SELECT full_name FROM persons'));
        $summary = ['to_create' => 0, 'duplicate_skip' => 0, 'persons_to_create' => 0,
                    'photos_to_download' => 0, 'issues' => 0];
        $issues = [];
        $previewRows = [];
        $personNames = [];
        foreach ($rows as $i => $row) {
            $c = self::clean($row, $mapping);
            $line = $i + 2; // 1-based + header
            $rowIssues = [];
            if ($c['asset_tag'] === '') {
                $rowIssues[] = 'missing asset tag';
            } elseif (in_array(strtolower($c['asset_tag']), $existingTags, true)) {
                $summary['duplicate_skip']++;
                $rowIssues[] = 'duplicate tag (will be skipped)';
            }
            foreach ($c['issues'] as $k) {
                $rowIssues[] = match ($k) {
                    'status_unknown' => 'unknown status "' . $row[self::colFor($mapping, 'status')] . '"',
                    'cost_invalid' => 'invalid cost',
                    'date_invalid' => 'invalid purchase date',
                    default => $k,
                };
            }
            if ($c['status'] === null && !in_array('status_unknown', $c['issues'], true) && $c['asset_tag'] !== '') {
                // empty status -> defaults to available at import; not an issue
            }
            if ($c['person'] !== null) {
                $key = strtolower($c['person']);
                $nonPerson = ($llm['persons'][$c['person']] ?? null) === 'non-person';
                if ($nonPerson) {
                    $rowIssues[] = 'assignee not a person (will be left unassigned)';
                } elseif (!in_array($key, $existingPersons, true) && !isset($personNames[$key])) {
                    $personNames[$key] = $c['person'];
                    if (!empty($options['create_persons'])) {
                        $summary['persons_to_create']++;
                    }
                }
            }
            if (!empty($options['photos']) && $c['photo_url'] !== null) {
                $summary['photos_to_download']++;
            }
            if ($rowIssues !== []) {
                $summary['issues']++;
                $issues[] = ['line' => $line, 'tag' => $c['asset_tag'], 'notes' => $rowIssues];
            }
            if ($c['asset_tag'] !== '' && !in_array(strtolower($c['asset_tag']), $existingTags, true)) {
                $summary['to_create']++;
            }
            if (count($previewRows) < self::PREVIEW_ROWS) {
                $previewRows[] = ['line' => $line, 'row' => $c, 'notes' => $rowIssues];
            }
        }
        return ['summary' => $summary, 'issues' => $issues, 'rows' => $previewRows, 'total' => count($rows)];
    }

    /** Column name mapped to $target (first match) — for issue messages. */
    private static function colFor(array $mapping, string $target): int|string
    {
        foreach ($mapping as $col => $t) {
            if ($t === $target) {
                return array_search($col, array_keys($mapping), true);
            }
        }
        return 0;
    }
```

- [ ] **Step 7: import()**

```php
    /**
     * Transactional import. $llm: Task 3's classification (may be []).
     * $options: ['photos' => bool, 'create_persons' => bool].
     * Photo DOWNLOAD is implemented in Task 5 — when $options['photos'] is on,
     * Task 5 replaces the photos block below.
     */
    public static function import(array $rows, array $mapping, array $options, array $llm = [], ?array $user = null): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $report = ['created' => 0, 'skipped' => 0, 'errors' => 0, 'duplicate_skip' => 0,
                   'persons_created' => [], 'photos_downloaded' => 0, 'photos_failed' => 0,
                   'categories_created' => [], 'departments_created' => [], 'sites_created' => []];

        $existingTags = array_flip(array_map('strtolower', Database::fetchAll('SELECT asset_tag FROM assets')));
        $persons = [];
        foreach (Database::fetchAll('SELECT id, full_name FROM persons') as $p) {
            $persons[strtolower($p['full_name'])] = (int) $p['id'];
        }
        $cats = [];
        foreach (Database::fetchAll('SELECT id, name FROM categories') as $c) {
            $cats[strtolower($c['name'])] = (int) $c['id'];
        }
        $depts = [];
        foreach (Database::fetchAll('SELECT id, name FROM departments') as $d) {
            $depts[strtolower($d['name'])] = (int) $d['id'];
        }
        $sites = [];
        foreach (Database::fetchAll('SELECT id, name FROM sites') as $s) {
            $sites[strtolower($s['name'])] = (int) $s['id'];
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $c = self::clean($row, $mapping);
                if ($c['asset_tag'] === '') {
                    $report['skipped']++;
                    continue;
                }
                if (isset($existingTags[strtolower($c['asset_tag'])])) {
                    $report['duplicate_skip']++;
                    $report['skipped']++;
                    continue;
                }
                if ($c['status'] === null) {
                    if (in_array('status_unknown', $c['issues'], true)) {
                        $report['errors']++;
                        continue; // unknown status: never guess
                    }
                    $c['status'] = 'available'; // empty status
                }
                $c['category_id'] = $c['category'] !== null ? self::matchOrCreate('categories', $c['category'], $cats, $report['categories_created']) : null;
                $c['department_id'] = $c['department'] !== null ? self::matchOrCreate('departments', $c['department'], $depts, $report['departments_created']) : null;
                $c['site_id'] = $c['site'] !== null ? self::matchOrCreate('sites', $c['site'], $sites, $report['sites_created']) : null;

                $c['assigned_to_person_id'] = null;
                if ($c['person'] !== null) {
                    $key = strtolower($c['person']);
                    if (($llm['persons'][$c['person']] ?? null) === 'non-person') {
                        $c['person'] = null; // classified non-person: leave unassigned
                    } elseif (isset($persons[$key])) {
                        $c['assigned_to_person_id'] = $persons[$key];
                    } elseif (!empty($options['create_persons'])) {
                        $pid = Person::create(['full_name' => $c['person']]);
                        $persons[$key] = $pid;
                        $c['assigned_to_person_id'] = $pid;
                        $report['persons_created'][] = $c['person'];
                        $c['person'] = null;
                    }
                }

                $id = Asset::importRow([
                    'asset_tag' => $c['asset_tag'],
                    'serial_number' => $c['serial_number'],
                    'model_number' => $c['model_number'],
                    'brand' => $c['brand'],
                    'description' => $c['description'],
                    'category_id' => $c['category_id'],
                    'department_id' => $c['department_id'],
                    'site_id' => $c['site_id'],
                    'assigned_to_person_id' => $c['assigned_to_person_id'],
                    'purchase_date' => $c['purchase_date'],
                    'purchase_cost' => $c['purchase_cost'],
                    'status' => $c['status'],
                ], $userId);
                $existingTags[strtolower($c['asset_tag'])] = $id;
                $report['created']++;

                // Photos: Task 5 implements the download. Until then, the
                // option is a no-op counted in the report.
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException('Import failed and was rolled back: ' . $e->getMessage(), 0, $e);
        }

        Audit::log('import.assets', 'import', '0', [
            'created' => $report['created'],
            'skipped' => $report['skipped'],
            'duplicate_skip' => $report['duplicate_skip'],
            'errors' => $report['errors'],
            'persons_created' => count($report['persons_created']),
            'photos_downloaded' => $report['photos_downloaded'],
            'photos_failed' => $report['photos_failed'],
        ]);
        return $report;
    }

    /** Case-insensitive match-or-create against the loaded name=>id map. */
    private static function matchOrCreate(string $table, string $name, array &$map, array &$created): int
    {
        $key = strtolower($name);
        if (isset($map[$key])) {
            return $map[$key];
        }
        $model = match ($table) {
            'categories' => Category::class,
            'departments' => Department::class,
            'sites' => Site::class,
        };
        $id = $model::create($name);
        $map[$key] = $id;
        $created[] = $name;
        return $id;
    }
```

**IMPORTANT for the implementer:** before writing `import()`, read `app/Core/Database.php` and confirm the connection accessor name (the plan assumes `Database::connection(): \PDO`; if it differs — e.g. `Database::pdo()` — use the real one and note it in your report). If there is no raw-PDO accessor at all, add a minimal `public static function connection(): \PDO` to `Database` returning the shared PDO (that is the only allowed change to app/Core in this task).

- [ ] **Step 8: Verify (CLI smoke against the real sample)**

Run: `php -l app/Services/CsvImport.php` — clean.

Run this CLI smoke (no DB needed for parse/clean/distinctValues):

```bash
php -r '
require "vendor/autoload.php";
use App\Services\CsvImport;
$p = CsvImport::parse("/Users/zacheri/Documents/NAIMS/sample-import.csv");
$map = CsvImport::defaultMapping($p["header"]);
assert(count($p["rows"]) === 2164);
$statuses = []; $deptNotes = 0; $serialNull = 0; $modelNull = 0; $tagEmpty = 0;
foreach ($p["rows"] as $r) {
  $c = CsvImport::clean($r, $map);
  $statuses[$c["status"] ?? "(null)"] = ($statuses[$c["status"] ?? "(null)"] ?? 0) + 1;
  if (in_array($c["department"], ["IN STOCK","DISPOSED/DONATED/SOLD","**EMPLOYEE NEVER RETURNED**"], true)) $deptNotes++;
  if ($c["serial_number"] === null) $serialNull++;
  if ($c["model_number"] === null && preg_match("/in stock/i", (string) $r[15])) $modelNull++;
  if ($c["asset_tag"] === "") $tagEmpty++;
}
echo json_encode($statuses), "\n";
echo "dept_notes_leaked=$deptNotes serial_null=$serialNull model_null_instock=$modelNull tag_empty=$tagEmpty\n";
'
```

Expected: `{"checked_out":927,"available":557,"lost":410,"disposed":184,"sold":33,"donated":31,"broken":20,"in_repair":2}`, `dept_notes_leaked=0`, `serial_null=416` (356 empty + 33 `?` + 22 `NA` + 5 `N/A`), `model_null_instock=145` (117 + 28), `tag_empty=0`. If any number differs, debug clean() before committing — the reference profile in Global Constraints is the acceptance set.

- [ ] **Step 9: Commit**

```bash
git add app/Services/CsvImport.php
git commit -m "feat: CsvImport deterministic pipeline (parse, map, clean, preview, import)"
```

---

### Task 3: CsvImport — LLM value classification

**Files:**
- Modify: `app/Services/CsvImport.php`

**Interfaces:**
- Consumes: `LlmClient::chat(array $messages, array $toolDefs, callable $toolHandler, int $maxRounds = 8, int $timeout = 300): array` (app/Services/LlmClient.php — read it to see the exact return shape; the final assistant text is what you parse), `LlmServer::state(): array` (`['status' => 'ready'|'loading'|'stopped', …]`).
- Produces:
  - `CsvImport::llmClassify(array $distinct, bool $enabled): array` → `['departments' => [value => 'real-dept'|'status-note'], 'persons' => [value => 'person'|'non-person'], 'brands' => [value => canonical-string], 'ai_unavailable' => bool, 'ai_error' => ?string]`
  - `CsvImport::parseClassification(string $raw, string $field, array $values): array` (pure — the testable unit)

- [ ] **Step 1: parseClassification (pure, testable)**

```php
    /**
     * Parse + validate one field's LLM classification. Pure function.
     * $values: the distinct input values (the only legal keys).
     * Returns [value => classification] with invalid entries dropped.
     */
    public static function parseClassification(string $raw, string $field, array $values): array
    {
        $json = self::extractJson($raw);
        if ($json === null || !is_array($json)) {
            return [];
        }
        $out = [];
        foreach ($json as $key => $val) {
            $key = (string) $key;
            if (!array_key_exists($key, $values)) {
                continue; // LLM invented a value: drop
            }
            $val = is_array($val) ? ($val['class'] ?? $val['value'] ?? null) : $val;
            if (!is_string($val)) {
                continue;
            }
            $val = trim($val);
            $ok = match ($field) {
                'departments' => in_array($val, ['real-dept', 'status-note'], true),
                'persons' => in_array($val, ['person', 'non-person'], true),
                'brands' => $val !== '' && self::isBrandVariant($val, $values),
                default => false,
            };
            if ($ok) {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    /** Brand canonical must be a trim/case variant of one of the input values. */
    private static function isBrandVariant(string $candidate, array $values): bool
    {
        $lc = strtolower($candidate);
        foreach ($values as $v) {
            if (strtolower(trim($v)) === $lc) {
                return true;
            }
        }
        return false;
    }

    /** Tolerate code fences / prose around the JSON object. */
    private static function extractJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        return json_decode(substr($raw, $start, $end - $start + 1), true);
    }
```

- [ ] **Step 2: llmClassify**

```php
    /**
     * Optional LLM classification of distinct values. One prompt per field.
     * $distinct: distinctValues() output. $enabled: the AI-assist toggle.
     * Never throws: any failure yields ai_unavailable=true with the
     * deterministic path still intact.
     */
    public static function llmClassify(array $distinct, bool $enabled): array
    {
        $out = ['departments' => [], 'persons' => [], 'brands' => [],
                'ai_unavailable' => false, 'ai_error' => null];
        if (!$enabled) {
            return $out;
        }
        $state = LlmServer::state();
        if ($state['status'] !== 'ready') {
            $out['ai_unavailable'] = true;
            $out['ai_error'] = 'LLM server is not running (status: ' . $state['status'] . ').';
            return $out;
        }
        $fields = [
            'departments' => ['values' => array_keys($distinct['department']),
                              'column' => 'Department',
                              'instruction' => 'Classify each value as "real-dept" (a genuine department/team name) or "status-note" (a status or note that was mistakenly stored in the department field, e.g. "IN STOCK", "DISPOSED/DONATED/SOLD", "**EMPLOYEE NEVER RETURNED**").'],
            'persons' => ['values' => array_keys($distinct['person']),
                          'column' => 'Assigned to',
                          'instruction' => 'Classify each value as "person" (a real employee name) or "non-person" (an office, location, customer, or other non-person entity).'],
            'brands' => ['values' => array_keys($distinct['brand']),
                         'column' => 'Brand',
                         'instruction' => 'For each value, return the canonical brand spelling (fix case and obvious typos, e.g. "ACER" -> "Acer"). The returned value MUST be one of the input values or a case/trim variant of one.'],
        ];
        foreach ($fields as $field => $spec) {
            if ($spec['values'] === []) {
                continue;
            }
            $system = 'You are a data-cleaning assistant for an asset inventory import. '
                . 'You receive distinct values from the "' . $spec['column'] . '" column of a CSV export. '
                . $spec['instruction'] . ' Respond with a JSON object only — no prose, no markdown — '
                . 'mapping each input value to its classification.';
            $user = json_encode(array_map(fn($v) => ['value' => $v, 'count' => $distinct[/* field key */ $field === 'departments' ? 'department' : $field === 'persons' ? 'person' : 'brand'][$v]], $spec['values']));
            try {
                $res = LlmClient::chat(
                    [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    [],
                    fn() => null,
                    1,
                    120
                );
                $text = $res['content'] ?? (is_array($res) ? json_encode($res) : (string) $res);
                $out[$field] = self::parseClassification((string) $text, $field, $spec['values']);
            } catch (\Throwable $e) {
                $out['ai_unavailable'] = true;
                $out['ai_error'] = $field . ': ' . $e->getMessage();
            }
        }
        return $out;
    }
```

**IMPORTANT for the implementer:** read `LlmClient::chat()`'s actual return shape first and adapt the `$text` extraction to it (the plan assumes a `content` key; if it returns e.g. `['reply' => …]` or a string, use the real one and note it in your report). The `$distinct[$fieldKey]` lookup for counts: build a small local `$keyOf = ['departments' => 'department', 'persons' => 'person', 'brands' => 'brand']` array instead of the inline ternary — cleaner and less error-prone.

- [ ] **Step 3: Verify**

Run: `php -l app/Services/CsvImport.php` — clean.

CLI smoke of the pure validator (no LLM needed):

```bash
php -r '
require "vendor/autoload.php";
use App\Services\CsvImport;
$values = ["IN STOCK", "Call Center", "QA"];
$good = json_encode(["IN STOCK" => "status-note", "Call Center" => "real-dept", "QA" => "real-dept"]);
$r = CsvImport::parseClassification("```json\n$good\n```", "departments", $values);
var_dump($r); // all three, correct classes
$r2 = CsvImport::parseClassification($good, "departments", $values);
var_dump($r2); // same (plain JSON also works)
$r3 = CsvImport::parseClassification(json_encode(["IN STOCK" => "bogus", "GHOST" => "real-dept", "QA" => "real-dept"]), "departments", $values);
var_dump($r3); // only QA survives (bogus class dropped, invented key dropped)
$bp = ["Acer", "ACER", "CLT", "CTL"];
$r4 = CsvImport::parseClassification(json_encode(["ACER" => "Acer", "CTL" => "CLT", "Acer" => "Acer"]), "brands", $bp);
var_dump($r4); // all three: case variants + cross-variant canonical are legal
$r5 = CsvImport::parseClassification("not json at all", "persons", ["Bob"]);
var_dump($r5); // []
'
```

Expected: `$r` and `$r2` = 3 entries each; `$r3` = only `QA`; `$r4` = 3 entries; `$r5` = `[]`.

If the host LLM server is running (`curl -s localhost:8082/v1/models`), additionally run a live `llmClassify` with the sample's real distinct departments and confirm it returns a valid classification without `ai_unavailable`. If the server is not running, that is fine — record it in your report.

- [ ] **Step 4: Commit**

```bash
git add app/Services/CsvImport.php
git commit -m "feat: LLM value classification for CSV import (optional, validated)"
```

---

### Task 4: Import wizard — controller, routes, UI

**Files:**
- Create: `app/Controllers/ImportController.php`
- Create: `templates/admin/import.php`
- Modify: `config/routes.php` (admin section, ~line 96)
- Modify: `templates/layout.php` (Admin nav section, ~line 72-90)
- Modify: `public/theme/css/app.css` (wizard styles)

**Interfaces:**
- Consumes: `CsvImport::parse/defaultMapping/clean/distinctValues/preview/import/llmClassify/MAX_FILE_MB` (Tasks 2-3); `Auth::requireLogin()`; `View::render('admin/import', …)`; `Response::redirect`; `csrf_field()`; existing `.dropdown`/`.btn`/`.input`/`.badge` CSS classes.
- Produces: routes `GET /admin/import`, `POST /admin/import/analyze`, `POST /admin/import/run` (all `'roles' => ['admin']`).

- [ ] **Step 1: routes**

In `config/routes.php`'s admin section add:

```php
    ['method' => 'GET',  'path' => '/admin/import',            'controller' => 'Import', 'action' => 'index',    'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/import/analyze',    'controller' => 'Import', 'action' => 'analyze',  'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/import/run',        'controller' => 'Import', 'action' => 'run',      'roles' => ['admin']],
```

Confirm the router resolves `'controller' => 'Import'` to `App\Controllers\ImportController` the same way other entries do (read app/Core/App.php's dispatch — match the existing convention exactly).

- [ ] **Step 2: ImportController**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\CsvImport;
use RuntimeException;

final class ImportController
{
    private function importsDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/imports';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public function index(): void
    {
        Auth::requireLogin();
        View::output(View::render('admin/import', [
            'title' => 'Import Assets',
            'step' => 'upload',
            'llmReady' => \App\Services\LlmServer::state()['status'] === 'ready',
        ]));
    }

    /** Validate + store the upload, parse, clean, optional LLM, render preview. */
    public function analyze(): void
    {
        $user = Auth::requireLogin();
        $path = $this->storeUpload();
        try {
            $parsed = CsvImport::parse($path);
        } catch (RuntimeException $e) {
            @unlink($path);
            $this->uploadError($e->getMessage());
            return;
        }
        $mapping = $this->mappingFromPost($parsed['header']);
        $options = $this->optionsFromPost();
        $llm = CsvImport::llmClassify(CsvImport::distinctValues($parsed['rows'], $mapping), !empty($options['ai_assist']));
        $preview = CsvImport::preview($parsed['rows'], $mapping, $options, $llm);
        View::output(View::render('admin/import', [
            'title' => 'Import Assets',
            'step' => 'preview',
            'file' => basename($path),
            'path' => $path,
            'header' => $parsed['header'],
            'mapping' => $mapping,
            'targets' => CsvImport::TARGETS,
            'options' => $options,
            'llm' => $llm,
            'distinct' => CsvImport::distinctValues($parsed['rows'], $mapping),
            'preview' => $preview,
        ]));
    }

    /** Re-parse the stored file with the (possibly edited) mapping, then import. */
    public function run(): void
    {
        $user = Auth::requireLogin();
        $path = (string) Request::post('file_path', '');
        if ($path === '' || !is_file($this->importsDir() . '/' . basename($path))) {
            $this->uploadError('The import file is no longer available. Please upload it again.');
            return;
        }
        $full = $this->importsDir() . '/' . basename($path);
        $parsed = CsvImport::parse($full);
        $mapping = $this->mappingFromPost($parsed['header']);
        $options = $this->optionsFromPost();
        $llm = CsvImport::llmClassify(CsvImport::distinctValues($parsed['rows'], $mapping), !empty($options['ai_assist']));
        try {
            $report = CsvImport::import($parsed['rows'], $mapping, $options, $llm, $user);
        } catch (RuntimeException $e) {
            $this->uploadError($e->getMessage());
            return;
        }
        @unlink($full);
        View::output(View::render('admin/import', [
            'title' => 'Import Assets',
            'step' => 'report',
            'report' => $report,
        ]));
    }

    private function storeUpload(): string
    {
        $f = $_FILES['csv'] ?? null;
        if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->uploadError('Choose a .csv file to import.');
            return '';
        }
        if ((int) $f['size'] > CsvImport::MAX_FILE_MB * 1024 * 1024) {
            $this->uploadError('File too large (max ' . CsvImport::MAX_FILE_MB . ' MB).');
            return '';
        }
        $name = (string) ($f['name'] ?? '');
        if (!preg_match('/\.csv$/i', $name)) {
            $this->uploadError('Only .csv files are supported.');
            return '';
        }
        $dest = $this->importsDir() . '/' . bin2hex(random_bytes(8)) . '.csv';
        if (!move_uploaded_file((string) $f['tmp_name'], $dest)) {
            $this->uploadError('Could not store the uploaded file.');
            return '';
        }
        return $dest;
    }

    /** mapping[<csv column>] = target, validated against TARGETS. */
    private function mappingFromPost(array $header): array
    {
        $post = Request::post('mapping') ?? [];
        $map = [];
        foreach ($header as $h) {
            $t = (string) ($post[$h] ?? '');
            $map[$h] = in_array($t, CsvImport::TARGETS, true) ? $t : 'ignore';
        }
        return $map;
    }

    private function optionsFromPost(): array
    {
        return [
            'photos' => (bool) Request::post('opt_photos'),
            'create_persons' => (bool) Request::post('opt_create_persons', 1),
            'ai_assist' => (bool) Request::post('opt_ai_assist'),
        ];
    }

    private function uploadError(string $msg): void
    {
        Auth::flash('error', $msg);
        Response::redirect('/admin/import');
    }
}
```

**IMPORTANT for the implementer:**
- Add `public const TARGETS = ['asset_tag', 'purchase_date', 'person', 'category', 'photo_url', 'description', 'brand', 'serial_number', 'purchase_cost', 'purchase_cost_fallback', 'site', 'department', 'status', 'model_number', 'ignore'];` to `CsvImport` (Task 2's file — this is the one allowed edit there).
- `Request::post('opt_create_persons', 1)` — default ON per the spec; confirm `Request::post`'s default-argument signature in app/Core/Request.php and adapt if it differs.
- `Auth::flash` — confirm the exact flash API (grep an existing controller for `flash(`) and match it.
- The preview step re-POSTs the file path (hidden input) + mapping + options to `/admin/import/run`; the mapping selects are editable there and resubmit to `/admin/import/analyze` first (two forms: "Re-analyze" and "Import now" — or one form where changing a select and clicking Import resubmits to analyze; pick the simpler robust option and document it in your report).

- [ ] **Step 3: wizard template**

Create `templates/admin/import.php` with three steps switched on `$step`:

- **upload:** `enctype="multipart/form-data"` form to `/admin/import/analyze`: file input `name="csv" accept=".csv"`, checkboxes `opt_photos` (label "Download asset photos from the export (default: off)"), `opt_create_persons` (checked by default, label "Create missing persons"), `opt_ai_assist` (label "AI assist (classify ambiguous values with the local LLM)" + a hint line when `!$llmReady`: "LLM server is not running — AI assist will be skipped."). Submit button "Analyze file".
- **preview:** hidden inputs `file_path` (basename), the option checkboxes (same names), a mapping table (one row per `$header` column: `<select name="mapping[<?= e($col) ?>]">` with all `CsvImport::TARGETS` options, selected = current mapping), summary cards from `$preview['summary']` (To create / Duplicate tags skipped / Persons to create / Photos to download / Rows with issues), an AI panel when `$llm['ai_unavailable']` (notice with `$llm['ai_error']`) or when classifications exist (per-field tables: value, count, classification — for departments/persons a select to override `real-dept`/`status-note` and `person`/`non-person` named `llm_override[<field>][<value>]`, for brands a text input `llm_override[brands][<value>]` prefilled with the canonical), the issues list (line, tag, notes), the first-50-rows table (line, tag, brand+model, description, status, cost, date, person, department, site, notes), and two submit buttons: "Re-analyze" (formaction `/admin/import/analyze`) and "Import now" (formaction `/admin/import/run`).
- **report:** summary cards from `$report` (Created / Skipped (incl. duplicates) / Errors / Persons created / Photos downloaded / Photos failed), lists (persons created; categories/departments/sites created), and a link button to `/assets`.

All dynamic values escaped with `e()`; links via `url()`. Follow the existing admin template structure (read `templates/admin/settings.php` for the page-head/section pattern).

- [ ] **Step 4: nav item**

In `templates/layout.php`'s Admin nav section (after the Persons link, ~line 79), add:

```php
        <a href="<?= e(url('/admin/import')) ?>" class="<?= $nav_active === 'admin/import' ? 'active' : '' ?>">
          <?= $icon('<path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>') ?> Import
        </a>
```

(Match the exact `$icon(...)` + class pattern of the neighboring links; check how `$nav_active` is set for admin pages and use the right value.)

- [ ] **Step 5: CSS**

In `public/theme/css/app.css` add a small `.import-*` block (step cards, summary-card grid reusing existing card styles, mapping table, issues list, report lists) — reuse existing tokens (`var(--surface)`, `var(--line)`, `var(--muted)`, `var(--radius)`, `var(--shadow-sm)`) and existing component classes (`.btn`, `.input`, `.badge`, `.card` if present) rather than new visual language.

- [ ] **Step 6: Verify**

Run: `php -l` on `app/Controllers/ImportController.php`, `app/Services/CsvImport.php` (TARGETS const), `config/routes.php`, `templates/admin/import.php` — clean.
Run: `node --check` if you added JS (expect none needed).
CLI smoke: `php -r 'require "vendor/autoload.php"; var_dump(in_array("purchase_cost_fallback", \App\Services\CsvImport::TARGETS, true));'` — `true`.

- [ ] **Step 7: Commit**

```bash
git add app/Controllers/ImportController.php app/Services/CsvImport.php config/routes.php templates/admin/import.php templates/layout.php public/theme/css/app.css
git commit -m "ui: admin import wizard (upload, mapping, preview, report)"
```

---

### Task 5: Photo download option

**Files:**
- Modify: `app/Services/CsvImport.php` (the photos block in `import()`)
- Modify: `templates/admin/import.php` (report line for photo failures, if not already present)

**Interfaces:**
- Consumes: `Photo::createFromPath()` (Task 1), `Asset::linkPhoto()` (Task 1), `Config::get('storage.uploads')`.
- Produces: `CsvImport::downloadPhotos(array $urls, array $byUrl, ?array $user): array` → `['downloaded' => int, 'failed' => int, 'failures' => [url => reason]]` (internal helper; `import()`'s report shape is unchanged).

- [ ] **Step 1: download helper**

Add to `CsvImport`:

```php
    /**
     * Download distinct photo URLs once each; return per-URL photo ids.
     * $byUrl: [url => true] for the URLs present in the import.
     * Failures never throw — they are counted and reported.
     */
    public static function downloadPhotos(array $byUrl, ?array $user): array
    {
        $result = ['downloaded' => 0, 'failed' => 0, 'failures' => [], 'ids' => []];
        $tmp = tempnam(sys_get_temp_dir(), 'naims_import_');
        if ($tmp === false) {
            return $result;
        }
        foreach (array_keys($byUrl) as $url) {
            $ok = false;
            $reason = '';
            if (!preg_match('#^https?://#i', (string) $url)) {
                $reason = 'not an http(s) URL';
            } else {
                $ch = curl_init((string) $url);
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $tmp,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_MAXFILESIZE => 10 * 1024 * 1024,
                    CURLOPT_USERAGENT => 'NiceAssets-Import/1.0',
                    CURLOPT_RETURNTRANSFER => false,
                ]);
                $code = curl_exec($ch) ? (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) : 0;
                $reason = curl_error($ch);
                curl_close($ch);
                if ($code !== 200) {
                    $reason = 'HTTP ' . $code . ($reason !== '' ? ' (' . $reason . ')' : '');
                } elseif (!is_file($tmp) || (int) filesize($tmp) === 0) {
                    $reason = 'empty response';
                } else {
                    try {
                        $result['ids'][$url] = \App\Models\Photo::createFromPath(
                            $tmp, basename((string) parse_url((string) $url, PHP_URL_PATH) ?: 'photo.jpg'),
                            'import', $user
                        );
                        $result['downloaded']++;
                        $ok = true;
                    } catch (\Throwable $e) {
                        $reason = $e->getMessage();
                    }
                }
            }
            if (!$ok) {
                $result['failed']++;
                $result['failures'][$url] = $reason;
            }
            @unlink($tmp);
        }
        return $result;
    }
```

- [ ] **Step 2: wire into import()**

In `import()`, replace the photos no-op block: before the row loop, collect `$photoUrls[$c['photo_url']] = true` for all rows when `$options['photos']` is on; after the row loop (still inside the transaction is fine — downloads are I/O, not DB; alternatively move the download BEFORE `beginTransaction()` to keep the transaction short — pick the latter and note it), call `self::downloadPhotos($photoUrls, $user)` and store `$photoIds = $result['ids']`; inside the row loop, after `Asset::importRow`, add:

```php
                if (!empty($options['photos']) && $c['photo_url'] !== null && isset($photoIds[$c['photo_url']])) {
                    Asset::linkPhoto($id, $photoIds[$c['photo_url']], 0, true);
                }
```

and set `$report['photos_downloaded']` / `$report['photos_failed']` from the result (count distinct URLs, not rows — document this in the report's meaning: one photo per distinct URL, linked to every row that referenced it).

- [ ] **Step 3: Verify**

Run: `php -l app/Services/CsvImport.php` — clean.
CLI smoke (no network needed): `php -r 'require "vendor/autoload.php"; $r = \App\Services\CsvImport::downloadPhotos(["ftp://bad" => true], null); var_dump($r["failed"] === 1, isset($r["failures"]["ftp://bad"]));'` — both `true`.
If network egress is available, additionally test one real assettiger.com URL from the sample and confirm a photo row is created (then delete the test photo row + file via the app's delete endpoint or direct SQL, and note it in your report).

- [ ] **Step 4: Commit**

```bash
git add app/Services/CsvImport.php templates/admin/import.php
git commit -m "feat: optional photo download in CSV import"
```

---

## Self-Review (run by the plan author)

1. **Spec coverage:** description field (Task 1) ✓; photos toggle default-off + per-URL tolerance (Tasks 4-5) ✓; auto-create persons + non-person exclusion (Tasks 2-3) ✓; LLM bounded classifier, off by default, degrades gracefully (Task 3) ✓; 8-value status map + garbage nulls + cost fallback (Task 2) ✓; wizard steps upload/preview/report (Task 4) ✓; admin-only routes (Task 4) ✓; upload validation + storage/imports (Task 4) ✓; LLM output re-validation (Task 3) ✓; audit `import.assets` (Task 2) ✓; search_vector description term (Task 1) ✓; verification bar incl. the 2,164-row acceptance counts (Task 2 Step 8) ✓; out-of-scope items untouched ✓.
2. **Placeholder scan:** no TBD/TODO; every code step carries real code; the two "IMPORTANT for the implementer" notes name the exact files to read and the exact adaptation to make (not vague "handle errors").
3. **Type consistency:** `importRow`'s `$c` keys match Task 2's call site; `createFromPath` signature matches Task 5's call; `TARGETS` const referenced by Task 4 is defined in Task 4 Step 2's note; `llmClassify`'s return shape matches Tasks 2/4's `$llm` consumption; `preview()`/`import()` report keys match the template's fields.
