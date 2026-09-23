<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Services\Depreciation;
use RuntimeException;

final class Asset
{
    public const STATUSES = ['available', 'checked_out', 'in_repair', 'broken', 'lost', 'disposed', 'sold', 'donated'];
    public const TERMINAL = ['disposed', 'sold', 'donated'];

    public const VALUE_SQL = "GREATEST(0, a.purchase_cost - (a.purchase_cost / 60.0) * LEAST(60,
        CASE WHEN a.purchase_date IS NULL THEN 0
             ELSE (EXTRACT(YEAR FROM (age(a.purchase_date))) * 12)
                  + EXTRACT(MONTH FROM (age(a.purchase_date))) END))";

    public static function search(array $f, int $page, int $perPage, ?array $user): array
    {
        [$scope, $scopeParams] = Auth::scopeWhere('a');
        $where = ['1=1' . $scope];
        $params = $scopeParams;

        if (!empty($f['q'])) {
            $q = trim($f['q']);
            $where[] = '(a.search_vector @@ plainto_tsquery(\'english\', :q)
                        OR a.asset_tag ILIKE :like OR a.serial_number ILIKE :like
                        OR a.brand ILIKE :like OR a.model_number ILIKE :like)';
            $params['q'] = $q;
            $params['like'] = '%' . $q . '%';
        }
        if (!empty($f['category_id'])) {
            $where[] = 'a.category_id = :category';
            $params['category'] = (int) $f['category_id'];
        }
        if (!empty($f['department_id'])) {
            $where[] = 'a.department_id = :department';
            $params['department'] = (int) $f['department_id'];
        }
        if (!empty($f['site_id'])) {
            $where[] = 'a.site_id = :site';
            $params['site'] = (int) $f['site_id'];
        }
        if (!empty($f['location_id'])) {
            $where[] = 'a.location_id = :location';
            $params['location'] = (int) $f['location_id'];
        }
        if (!empty($f['status'])) {
            $where[] = 'a.status = :status';
            $params['status'] = $f['status'];
        }
        if (!empty($f['brand'])) {
            $where[] = 'a.brand ILIKE :brand';
            $params['brand'] = '%' . $f['brand'] . '%';
        }
        if (!empty($f['model'])) {
            $where[] = 'a.model_number ILIKE :model';
            $params['model'] = '%' . $f['model'] . '%';
        }
        if (!empty($f['assigned_person_id'])) {
            $where[] = 'a.assigned_to_person_id = :assigned_person';
            $params['assigned_person'] = (int) $f['assigned_person_id'];
        }
        if (!empty($f['purchased_from'])) {
            $where[] = 'a.purchase_date >= :p_from';
            $params['p_from'] = $f['purchased_from'];
        }
        if (!empty($f['purchased_to'])) {
            $where[] = 'a.purchase_date <= :p_to';
            $params['p_to'] = $f['purchased_to'];
        }
        if (!empty($f['warranty_from'])) {
            $where[] = 'a.warranty_expiration >= :w_from';
            $params['w_from'] = $f['warranty_from'];
        }
        if (!empty($f['warranty_to'])) {
            $where[] = 'a.warranty_expiration <= :w_to';
            $params['w_to'] = $f['warranty_to'];
        }
        if (!empty($f['overdue_only'])) {
            $where[] = "a.status = 'checked_out' AND a.due_date IS NOT NULL AND a.due_date < CURRENT_DATE";
        }
        $whereSql = implode(' AND ', $where);

        $order = match ($f['sort'] ?? 'newest') {
            'oldest' => 'a.created_at ASC',
            'tag' => 'a.asset_tag ASC',
            'cost_desc' => 'a.purchase_cost DESC',
            'warranty' => 'a.warranty_expiration ASC NULLS LAST',
            default => 'a.created_at DESC',
        };

        $base = "FROM assets a
             LEFT JOIN categories c ON c.id = a.category_id
             LEFT JOIN departments d ON d.id = a.department_id
             LEFT JOIN sites s ON s.id = a.site_id
             LEFT JOIN locations l ON l.id = a.location_id
             LEFT JOIN persons au ON au.id = a.assigned_to_person_id
             LEFT JOIN departments ad ON ad.id = a.assigned_to_department_id";

        $total = (int) Database::fetchColumn("SELECT COUNT(*) {$base} WHERE {$whereSql}", $params);

        $offset = max(0, ($page - 1) * $perPage);
        $rowParams = $params;
        $rowParams['limit'] = $perPage;
        $rowParams['offset'] = $offset;
        $rows = Database::fetchAll(
            "SELECT a.id, a.asset_tag, a.serial_number, a.model_number, a.brand,
                    c.name AS category_name, d.name AS department_name,
                    s.name AS site_name, l.name AS location_name, l.code AS location_code,
                    au.full_name AS assigned_name, ad.name AS assigned_dept_name,
                    a.status, a.status_reason, a.purchase_cost, a.purchase_date,
                    a.warranty_expiration, a.due_date, a.sub_quantity, a.created_at, a.updated_at,
                    (SELECT p.filename FROM asset_photos ap JOIN photos p ON p.id = ap.photo_id
                      WHERE ap.asset_id = a.id
                      ORDER BY (ap.is_thumbnail = false) ASC, ap.position ASC LIMIT 1) AS thumb
             {$base}
             WHERE {$whereSql}
             ORDER BY {$order}
             LIMIT :limit OFFSET :offset",
            $rowParams
        );

        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    public static function find(int $id, ?array $user = null): ?array
    {
        [$scope, $scopeParams] = $user !== null ? Auth::scopeWhere('a') : ['', []];
        $row = Database::fetchOne(
            "SELECT a.*, c.name AS category_name, c.depreciation_alert_enabled, c.low_stock_threshold,
                    d.name AS department_name, s.name AS site_name, s.address AS site_address,
                    l.name AS location_name, l.code AS location_code,
                    au.full_name AS assigned_name,
                    COALESCE(NULLIF(au.work_email, ''), au.personal_email) AS assigned_email,
                    ad.name AS assigned_dept_name,
                    wo.wo_number, wo.summary AS wo_summary, wo.status AS wo_status, wo.details AS wo_details,
                    wo.created_at AS wo_created_at,
                    u.full_name AS created_by_name
             FROM assets a
             LEFT JOIN categories c ON c.id = a.category_id
             LEFT JOIN departments d ON d.id = a.department_id
             LEFT JOIN sites s ON s.id = a.site_id
             LEFT JOIN locations l ON l.id = a.location_id
             LEFT JOIN persons au ON au.id = a.assigned_to_person_id
             LEFT JOIN departments ad ON ad.id = a.assigned_to_department_id
             LEFT JOIN work_orders wo ON wo.id = a.work_order_id
             LEFT JOIN users u ON u.id = a.created_by
             WHERE a.id = :id {$scope}",
            array_merge(['id' => $id], $scopeParams)
        );
        if ($row === null) {
            return null;
        }
        $row['photos'] = self::photosFor($id);
        $row['depreciation'] = Depreciation::calc((float) $row['purchase_cost'], $row['purchase_date']);
        $row['audit'] = Audit::query(['entity' => 'asset', 'entity_id' => (string) $id], 1, 8)['rows'];
        return $row;
    }

    public static function photosFor(int $assetId): array
    {
        return Database::fetchAll(
            'SELECT p.*, ap.position, ap.is_thumbnail
             FROM asset_photos ap JOIN photos p ON p.id = ap.photo_id
             WHERE ap.asset_id = :id
             ORDER BY (ap.is_thumbnail = false) ASC, ap.position ASC',
            ['id' => $assetId]
        );
    }

    public static function suggestTag(): string
    {
        $next = (int) Database::fetchColumn('SELECT COALESCE(MAX(id), 0) + 1 FROM assets') + 1;
        return 'AST-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private static function clean(array $d, ?array $user): array
    {
        $clean = [
            'asset_tag' => trim((string) ($d['asset_tag'] ?? '')),
            'serial_number' => trim((string) ($d['serial_number'] ?? '')) ?: null,
            'model_number' => trim((string) ($d['model_number'] ?? '')) ?: null,
            'brand' => trim((string) ($d['brand'] ?? '')) ?: null,
            'description' => mb_substr(trim((string) ($d['description'] ?? '')), 0, 500),
            'category_id' => !empty($d['category_id']) ? (int) $d['category_id'] : null,
            'department_id' => !empty($d['department_id']) ? (int) $d['department_id'] : null,
            'site_id' => !empty($d['site_id']) ? (int) $d['site_id'] : null,
            'location_id' => !empty($d['location_id']) ? (int) $d['location_id'] : null,
            'assigned_to_person_id' => !empty($d['assigned_to_person_id']) ? (int) $d['assigned_to_person_id'] : null,
            'assigned_to_department_id' => !empty($d['assigned_to_department_id']) ? (int) $d['assigned_to_department_id'] : null,
            'purchase_date' => !empty($d['purchase_date']) ? $d['purchase_date'] : null,
            'purchase_cost' => max(0, (float) str_replace([',', '$'], '', (string) ($d['purchase_cost'] ?? '0'))),
            'warranty_expiration' => !empty($d['warranty_expiration']) ? $d['warranty_expiration'] : null,
            'due_date' => !empty($d['due_date']) ? $d['due_date'] : null,
            'sub_quantity' => max(1, (int) ($d['sub_quantity'] ?? 1)),
        ];
        if ($user !== null && $user['role_name'] === 'department_manager') {
            $clean['department_id'] = (int) $user['department_id'];
        }
        if ($clean['location_id'] !== null && $clean['site_id'] !== null) {
            $loc = Database::fetchOne('SELECT site_id FROM locations WHERE id = :id', ['id' => $clean['location_id']]);
            if ($loc === null || (int) $loc['site_id'] !== $clean['site_id']) {
                $clean['location_id'] = null;
            }
        }
        return $clean;
    }

    public static function store(array $d, ?array $user): int
    {
        $c = self::clean($d, $user);
        if ($c['asset_tag'] === '') {
            throw new RuntimeException('Asset tag number is required.');
        }
        $exists = Database::fetchOne('SELECT id FROM assets WHERE asset_tag = :t', ['t' => $c['asset_tag']]);
        if ($exists !== null) {
            throw new RuntimeException('Asset tag ' . $c['asset_tag'] . ' already exists.');
        }
        $id = Database::insert(
            'INSERT INTO assets (asset_tag, serial_number, model_number, brand, description, category_id, department_id,
                site_id, location_id, purchase_date, purchase_cost, warranty_expiration, sub_quantity,
                status, created_by)
             VALUES (:t, :sn, :mn, :b, :desc, :cat, :dep, :site, :loc, :pd, :cost, :we, :sq, \'available\', :cb)',
            [
                't' => $c['asset_tag'],
                'sn' => $c['serial_number'],
                'mn' => $c['model_number'],
                'b' => $c['brand'],
                'desc' => $c['description'],
                'cat' => $c['category_id'],
                'dep' => $c['department_id'],
                'site' => $c['site_id'],
                'loc' => $c['location_id'],
                'pd' => $c['purchase_date'],
                'cost' => $c['purchase_cost'],
                'we' => $c['warranty_expiration'],
                'sq' => $c['sub_quantity'],
                'cb' => $user['id'] ?? null,
            ]
        );
        Audit::log('asset.create', 'asset', (string) $id, ['asset_tag' => $c['asset_tag'], 'status' => 'available']);
        return (int) $id;
    }

    public static function update(int $id, array $d, ?array $user): void
    {
        $existing = Database::fetchOne('SELECT * FROM assets WHERE id = :id', ['id' => $id]);
        if ($existing === null) {
            throw new RuntimeException('Asset not found.');
        }
        $c = self::clean($d, $user);
        if ($c['asset_tag'] === '' || $c['asset_tag'] !== $existing['asset_tag']) {
            $dup = Database::fetchOne('SELECT id FROM assets WHERE asset_tag = :t AND id <> :id', ['t' => $c['asset_tag'], 'id' => $id]);
            if ($c['asset_tag'] === '' || $dup !== null) {
                throw new RuntimeException('Asset tag is required and must be unique.');
            }
        }
        Database::execute(
            'UPDATE assets SET asset_tag = :t, serial_number = :sn, model_number = :mn, brand = :b,
                description = :desc, category_id = :cat, department_id = :dep, site_id = :site, location_id = :loc,
                purchase_date = :pd, purchase_cost = :cost, warranty_expiration = :we,
                sub_quantity = :sq, updated_at = now()
             WHERE id = :id',
            array_merge([
                't' => $c['asset_tag'],
                'sn' => $c['serial_number'],
                'mn' => $c['model_number'],
                'b' => $c['brand'],
                'desc' => $c['description'],
                'cat' => $c['category_id'],
                'dep' => $c['department_id'],
                'site' => $c['site_id'],
                'loc' => $c['location_id'],
                'pd' => $c['purchase_date'],
                'cost' => $c['purchase_cost'],
                'we' => $c['warranty_expiration'],
                'sq' => $c['sub_quantity'],
            ], ['id' => $id])
        );
        Audit::log('asset.update', 'asset', (string) $id, ['asset_tag' => $c['asset_tag']]);
    }

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

    public static function setStatus(int $id, string $status, array $extra, ?array $user): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Unknown status.');
        }
        Database::transaction(function () use ($id, $status, $extra, $user) {
            $asset = Database::fetchOne('SELECT * FROM assets WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($asset === null) {
                throw new RuntimeException('Asset not found.');
            }
            $tag = $asset['asset_tag'];

            switch ($status) {
                case 'available':
                    if ($asset['work_order_id'] !== null) {
                        Database::execute(
                            "UPDATE work_orders SET status = 'completed', completed_at = now() WHERE id = :id AND status <> 'completed'",
                            ['id' => $asset['work_order_id']]
                        );
                    }
                    Database::execute(
                        'UPDATE assets SET status = :st, assigned_to_person_id = NULL, assigned_to_department_id = NULL,
                         due_date = NULL, status_reason = NULL, work_order_id = NULL, updated_at = now() WHERE id = :id',
                        ['st' => $status, 'id' => $id]
                    );
                    Audit::log('asset.check_in', 'asset', (string) $id, ['asset_tag' => $tag, 'from_status' => $asset['status']]);
                    return;

                case 'checked_out':
                    $toPerson = !empty($extra['assigned_to_person_id']) ? (int) $extra['assigned_to_person_id'] : null;
                    $toDept = !empty($extra['assigned_to_department_id']) ? (int) $extra['assigned_to_department_id'] : null;
                    if ($toPerson === null && $toDept === null) {
                        throw new RuntimeException('Choose a person or department to check out to.');
                    }
                    $who = $toPerson !== null
                        ? Database::fetchColumn('SELECT full_name FROM persons WHERE id = :id AND is_terminated = false', ['id' => $toPerson])
                        : Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => $toDept]);
                    if ($who === false || $who === null) {
                        throw new RuntimeException($toPerson !== null ? 'Person not found or terminated.' : 'Department not found.');
                    }
                    Database::execute(
                        'UPDATE assets SET status = :st, assigned_to_person_id = :u, assigned_to_department_id = :d,
                         due_date = :due, status_reason = NULL, updated_at = now() WHERE id = :id',
                        [
                            'st' => $status,
                            'u' => $toPerson,
                            'd' => $toPerson !== null ? null : $toDept,
                            'due' => !empty($extra['due_date']) ? $extra['due_date'] : null,
                            'id' => $id,
                        ]
                    );
                    Audit::log('asset.check_out', 'asset', (string) $id, [
                        'asset_tag' => $tag,
                        'to' => $who,
                        'due_date' => $extra['due_date'] ?? null,
                        'from_status' => $asset['status'],
                    ]);
                    return;

                case 'in_repair':
                    $woId = $asset['work_order_id'];
                    if ($woId === null) {
                        $woId = WorkOrder::create(
                            $id,
                            trim($extra['summary'] ?? '') !== '' ? trim($extra['summary']) : 'IT repair',
                            trim($extra['details'] ?? ''),
                            $user
                        );
                    }
                    Database::execute(
                        'UPDATE assets SET status = :st, work_order_id = :wo, status_reason = :reason, updated_at = now() WHERE id = :id',
                        ['st' => $status, 'wo' => $woId, 'reason' => trim($extra['summary'] ?? '') ?: null, 'id' => $id]
                    );
                    Audit::log('asset.repair', 'asset', (string) $id, ['asset_tag' => $tag, 'work_order' => WorkOrder::number($woId)]);
                    return;

                case 'broken':
                case 'lost':
                    $reason = trim((string) ($extra['status_reason'] ?? ''));
                    if ($reason === '') {
                        throw new RuntimeException('A reason is required for ' . $status . ' assets.');
                    }
                    Database::execute(
                        'UPDATE assets SET status = :st, status_reason = :reason, due_date = NULL, updated_at = now() WHERE id = :id',
                        ['st' => $status, 'reason' => $reason, 'id' => $id]
                    );
                    Audit::log('asset.' . $status, 'asset', (string) $id, [
                        'asset_tag' => $tag,
                        'reason' => $reason,
                        'last_assigned_person_id' => $asset['assigned_to_person_id'],
                    ]);
                    return;

                case 'disposed':
                    $location = trim((string) ($extra['disposal_location'] ?? ''));
                    if ($location === '') {
                        throw new RuntimeException('Disposal location is required.');
                    }
                    Database::execute(
                        'UPDATE assets SET status = :st, disposal_location = :loc, disposal_date = COALESCE(:dd, CURRENT_DATE),
                         disposal_remaining_cost = :rc, assigned_to_person_id = NULL, assigned_to_department_id = NULL,
                         due_date = NULL, status_reason = :reason, updated_at = now() WHERE id = :id',
                        [
                            'st' => $status,
                            'loc' => $location,
                            'dd' => $extra['disposal_date'] ?? null,
                            'rc' => isset($extra['disposal_remaining_cost']) && $extra['disposal_remaining_cost'] !== ''
                                ? (float) $extra['disposal_remaining_cost'] : null,
                            'reason' => trim((string) ($extra['status_reason'] ?? '')) ?: null,
                            'id' => $id,
                        ]
                    );
                    Audit::log('asset.dispose', 'asset', (string) $id, [
                        'asset_tag' => $tag,
                        'disposal_location' => $location,
                        'remaining_cost' => $extra['disposal_remaining_cost'] ?? null,
                    ]);
                    return;

                case 'sold':
                    $soldTo = trim((string) ($extra['sold_to'] ?? ''));
                    if ($soldTo === '') {
                        throw new RuntimeException('Sold to is required.');
                    }
                    Database::execute(
                        'UPDATE assets SET status = :st, sold_to = :to, sold_price = :price,
                         sold_date = COALESCE(:sd, CURRENT_DATE), assigned_to_person_id = NULL,
                         assigned_to_department_id = NULL, due_date = NULL, updated_at = now() WHERE id = :id',
                        [
                            'st' => $status,
                            'to' => $soldTo,
                            'price' => isset($extra['sold_price']) && $extra['sold_price'] !== '' ? (float) $extra['sold_price'] : null,
                            'sd' => $extra['sold_date'] ?? null,
                            'id' => $id,
                        ]
                    );
                    Audit::log('asset.sold', 'asset', (string) $id, [
                        'asset_tag' => $tag,
                        'sold_to' => $soldTo,
                        'sold_price' => $extra['sold_price'] ?? null,
                    ]);
                    return;

                case 'donated':
                    $donatedTo = trim((string) ($extra['donated_to'] ?? ''));
                    if ($donatedTo === '') {
                        throw new RuntimeException('Donation recipient is required.');
                    }
                    Database::execute(
                        'UPDATE assets SET status = :st, donated_to = :to, donated_value = :value,
                         donated_date = COALESCE(:dd, CURRENT_DATE), assigned_to_person_id = NULL,
                         assigned_to_department_id = NULL, due_date = NULL, updated_at = now() WHERE id = :id',
                        [
                            'st' => $status,
                            'to' => $donatedTo,
                            'value' => isset($extra['donated_value']) && $extra['donated_value'] !== '' ? (float) $extra['donated_value'] : null,
                            'dd' => $extra['donated_date'] ?? null,
                            'id' => $id,
                        ]
                    );
                    Audit::log('asset.donate', 'asset', (string) $id, [
                        'asset_tag' => $tag,
                        'donated_to' => $donatedTo,
                        'donated_value' => $extra['donated_value'] ?? null,
                    ]);
                    return;
            }
        });
    }

    public static function transfer(int $id, int $toPersonId, ?array $user): void
    {
        Database::transaction(function () use ($id, $toPersonId, $user) {
            $asset = Database::fetchOne('SELECT * FROM assets WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($asset === null) {
                throw new RuntimeException('Asset not found.');
            }
            if ($asset['status'] !== 'checked_out') {
                throw new RuntimeException('Only checked-out assets can be transferred.');
            }
            $from = Database::fetchColumn(
                'SELECT full_name FROM persons WHERE id = :id',
                ['id' => $asset['assigned_to_person_id'] ?? $toPersonId]
            ) ?: 'Unassigned';
            $to = Database::fetchColumn(
                'SELECT full_name FROM persons WHERE id = :id AND is_terminated = false',
                ['id' => $toPersonId]
            );
            if ($to === false || $to === null) {
                throw new RuntimeException('Transfer target person not found or terminated.');
            }
            Database::execute(
                'UPDATE assets SET assigned_to_person_id = :u, assigned_to_department_id = NULL, updated_at = now() WHERE id = :id',
                ['u' => $toPersonId, 'id' => $id]
            );
            Audit::log('asset.transfer', 'asset', (string) $id, [
                'asset_tag' => $asset['asset_tag'],
                'from' => $from,
                'to' => $to,
            ]);
        });
    }

    public static function setDepartment(int $id, ?int $departmentId, ?array $user): void
    {
        $asset = self::find($id, $user);
        if ($asset === null) {
            throw new RuntimeException('Asset not found.');
        }
        if ($departmentId !== null) {
            $dept = Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => $departmentId]);
            if ($dept === false || $dept === null) {
                throw new RuntimeException('Department not found.');
            }
        }
        Database::execute(
            'UPDATE assets SET department_id = :d, updated_at = now() WHERE id = :id',
            ['d' => $departmentId, 'id' => $id]
        );
        Audit::log('asset.department_change', 'asset', (string) $id, [
            'asset_tag' => $asset['asset_tag'],
            'department_id' => $departmentId,
        ]);
    }

    public static function delete(int $id, ?array $user): void
    {
        $asset = Database::fetchOne('SELECT * FROM assets WHERE id = :id', ['id' => $id]);
        if ($asset === null) {
            throw new RuntimeException('Asset not found.');
        }
        Database::transaction(function () use ($asset) {
            Database::execute('DELETE FROM assets WHERE id = :id', ['id' => $asset['id']]);
        });
        Audit::log('asset.delete', 'asset', (string) $id, [
            'asset_tag' => $asset['asset_tag'],
            'brand' => $asset['brand'],
            'model' => $asset['model_number'],
        ]);
    }

    public static function stats(?array $user): array
    {
        [$scope, $params] = Auth::scopeWhere('a');
        $row = Database::fetchOne(
            "SELECT COUNT(*)::int AS total,
                    COALESCE(SUM(a.sub_quantity), 0)::int AS total_qty,
                    COALESCE(SUM(CASE WHEN a.status = 'available' THEN a.sub_quantity ELSE 0 END), 0)::int AS available_qty,
                    COALESCE(SUM(CASE WHEN a.status = 'checked_out' THEN 1 ELSE 0 END), 0)::int AS checked_out,
                    COALESCE(SUM(CASE WHEN a.status = 'in_repair' THEN 1 ELSE 0 END), 0)::int AS in_repair,
                    COALESCE(SUM(CASE WHEN a.status IN ('broken','lost') THEN 1 ELSE 0 END), 0)::int AS broken_lost,
                    COALESCE(SUM(CASE WHEN a.status IN ('disposed','sold','donated') THEN 1 ELSE 0 END), 0)::int AS terminal,
                    COALESCE(SUM(CASE WHEN a.status NOT IN ('disposed','sold','donated') THEN a.purchase_cost ELSE 0 END), 0)::numeric AS total_cost,
                    COALESCE(SUM(CASE WHEN a.status NOT IN ('disposed','sold','donated') THEN " . self::VALUE_SQL . " ELSE 0 END), 0)::numeric AS current_value,
                    COALESCE(SUM(CASE WHEN a.status NOT IN ('disposed','sold','donated') AND a.purchase_date IS NOT NULL
                        AND (EXTRACT(YEAR FROM (age(a.purchase_date))) * 12 + EXTRACT(MONTH FROM (age(a.purchase_date)))) >= 60
                        THEN 1 ELSE 0 END), 0)::int AS fully_depreciated,
                    COALESCE(SUM(CASE WHEN a.status = 'checked_out' AND a.due_date < CURRENT_DATE THEN 1 ELSE 0 END), 0)::int AS overdue
             FROM assets a WHERE 1=1 {$scope}",
            $params
        );
        $byStatus = Database::fetchAll(
            "SELECT a.status, COUNT(*)::int AS count,
                    COALESCE(SUM(CASE WHEN a.status <> 'available' THEN 1 ELSE a.sub_quantity END), 0)::int AS qty
             FROM assets a WHERE 1=1 {$scope} GROUP BY a.status",
            $params
        );
        $row['by_status'] = $byStatus;
        return $row;
    }

    public static function availableQtyByCategory(): array
    {
        return Database::fetchAll(
            'SELECT c.id, c.name, c.low_stock_threshold,
                    COALESCE(SUM(CASE WHEN a.status = \'available\' THEN a.sub_quantity ELSE 0 END), 0)::int AS qty
             FROM categories c LEFT JOIN assets a ON a.category_id = c.id
             WHERE c.is_active = true
             GROUP BY c.id ORDER BY c.name'
        );
    }

    public static function warrantyExpiring(?array $user = null): array
    {
        [$scope, $params] = Auth::scopeWhere('a');
        return Database::fetchAll(
            "SELECT a.id, a.asset_tag, a.brand, a.model_number, a.warranty_expiration,
                    (a.warranty_expiration - CURRENT_DATE)::int AS days_left,
                    c.name AS category_name, au.full_name AS assigned_name
             FROM assets a
             LEFT JOIN categories c ON c.id = a.category_id
             LEFT JOIN persons au ON au.id = a.assigned_to_person_id
             WHERE a.warranty_expiration IS NOT NULL
               AND a.warranty_expiration >= CURRENT_DATE
               AND a.warranty_expiration <= CURRENT_DATE + INTERVAL '90 days'
               AND a.status NOT IN ('disposed','sold','donated')
               {$scope}
             ORDER BY a.warranty_expiration",
            $params
        );
    }

    public static function multiAssignments(int $threshold, ?array $user = null): array
    {
        [$scope, $params] = Auth::scopeWhere('a');
        $params['threshold'] = $threshold;
        return Database::fetchAll(
            "SELECT au.id AS person_id, au.full_name, a.brand, a.model_number,
                    COUNT(*)::int AS count,
                    string_agg(a.asset_tag, ', ' ORDER BY a.asset_tag) AS tags
             FROM assets a JOIN persons au ON au.id = a.assigned_to_person_id
             WHERE a.status = 'checked_out'
               AND a.model_number IS NOT NULL AND a.model_number <> ''
               {$scope}
             GROUP BY au.id, au.full_name, a.brand, a.model_number
             HAVING COUNT(*) >= :threshold
             ORDER BY COUNT(*) DESC, au.full_name",
            $params
        );
    }

    public static function overdue(?array $user = null): array
    {
        [$scope, $params] = Auth::scopeWhere('a');
        return Database::fetchAll(
            "SELECT a.id, a.asset_tag, a.brand, a.model_number, a.due_date,
                    (CURRENT_DATE - a.due_date)::int AS days_overdue,
                    au.full_name AS assigned_name
             FROM assets a LEFT JOIN persons au ON au.id = a.assigned_to_person_id
             WHERE a.status = 'checked_out' AND a.due_date IS NOT NULL AND a.due_date < CURRENT_DATE
               {$scope}
             ORDER BY a.due_date",
            $params
        );
    }

    public static function fullyDepreciAssets(?array $user = null): array
    {
        [$scope, $params] = Auth::scopeWhere('a');
        return Database::fetchAll(
            "SELECT a.id, a.asset_tag, a.brand, a.model_number, a.purchase_cost, a.purchase_date,
                    c.name AS category_name
             FROM assets a
             LEFT JOIN categories c ON c.id = a.category_id
             WHERE a.status NOT IN ('disposed','sold','donated')
               AND a.purchase_date IS NOT NULL AND a.purchase_cost > 0
               AND (EXTRACT(YEAR FROM (age(a.purchase_date))) * 12 + EXTRACT(MONTH FROM (age(a.purchase_date)))) >= 60
               AND (c.depreciation_alert_enabled = true OR c.id IS NULL)
               {$scope}
             ORDER BY a.purchase_date",
            $params
        );
    }
}
