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
            $dt = \DateTime::createFromFormat('m/d/Y', $dateRaw);
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
        try {
            $state = LlmServer::state();
        } catch (\Throwable $e) {
            $out['ai_unavailable'] = true;
            $out['ai_error'] = 'Could not check LLM server state: ' . $e->getMessage();
            return $out;
        }
        if ($state['status'] !== 'ready') {
            $out['ai_unavailable'] = true;
            $out['ai_error'] = 'LLM server is not running (status: ' . $state['status'] . ').';
            return $out;
        }
        $keyOf = ['departments' => 'department', 'persons' => 'person', 'brands' => 'brand'];
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
            $user = json_encode(array_map(fn($v) => ['value' => $v, 'count' => $distinct[$keyOf[$field]][$v]], $spec['values']));
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
                $text = is_array($res) ? (string) ($res['text'] ?? '') : (string) $res;
                $out[$field] = self::parseClassification($text, $field, $spec['values']);
            } catch (\Throwable $e) {
                $out['ai_unavailable'] = true;
                $out['ai_error'] = $field . ': ' . $e->getMessage();
            }
        }
        return $out;
    }

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
            if (!in_array($key, $values, true)) {
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

        $pdo = Database::pdo();
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
}
