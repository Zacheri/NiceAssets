<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Person;
use RuntimeException;

final class Tools
{
    private const ACTIONS = [
        'terminate_person', 'reinstate_person', 'check_in_asset', 'check_in_all_for_person',
        'check_out_asset', 'transfer_asset', 'set_asset_department', 'create_person', 'update_person',
    ];

    public static function isAction(string $name): bool
    {
        return in_array($name, self::ACTIONS, true);
    }

    public static function definitions(): array
    {
        $obj = static fn (array $props, array $required = []): array => [
            'type' => 'object', 'properties' => (object) $props, 'required' => $required,
        ];
        $str = static fn (): array => ['type' => 'string'];
        $int = static fn (): array => ['type' => 'integer'];
        $bool = static fn (): array => ['type' => 'boolean'];
        return [
            ['type' => 'function', 'function' => ['name' => 'find_person',
                'description' => 'Search people by name. Fuzzy fragments work: "chris b" matches Christopher Baldolvsky. Returns matches with id, title, department, terminated flag, held-asset count.',
                'parameters' => $obj(['query' => $str()], ['query'])]],
            ['type' => 'function', 'function' => ['name' => 'find_asset',
                'description' => 'Find an asset by exact tag or serial, else close matches. Returns status, holder, due date.',
                'parameters' => $obj(['query' => $str()], ['query'])]],
            ['type' => 'function', 'function' => ['name' => 'list_persons',
                'description' => 'List people (max 25).',
                'parameters' => $obj(['terminated' => $bool()])]],
            ['type' => 'function', 'function' => ['name' => 'list_assets',
                'description' => 'List assets (max 25) with optional filters.',
                'parameters' => $obj(['assignee_id' => $int(), 'status' => $str(), 'department_id' => $int()])]],
            ['type' => 'function', 'function' => ['name' => 'person_detail',
                'description' => 'Full record of one person incl. assets they currently hold.',
                'parameters' => $obj(['id' => $int()], ['id'])]],
            ['type' => 'function', 'function' => ['name' => 'asset_detail',
                'description' => 'Full record of one asset incl. holder and depreciation.',
                'parameters' => $obj(['id' => $int()], ['id'])]],
            ['type' => 'function', 'function' => ['name' => 'department_list',
                'description' => 'All departments (id + name). Use to resolve a department name to an id.',
                'parameters' => $obj([])]],
            ['type' => 'function', 'function' => ['name' => 'asset_last_holder',
                'description' => 'Who an asset was last checked out to / transferred to (from the audit trail).',
                'parameters' => $obj(['tag' => $str()], ['tag'])]],
            ['type' => 'function', 'function' => ['name' => 'terminate_person',
                'description' => 'Mark a person as terminated (they keep any assets they hold). Admin only. Preview first.',
                'parameters' => $obj(['id' => $int()], ['id'])]],
            ['type' => 'function', 'function' => ['name' => 'reinstate_person',
                'description' => 'Mark a terminated person as active again. Admin only. Preview first.',
                'parameters' => $obj(['id' => $int()], ['id'])]],
            ['type' => 'function', 'function' => ['name' => 'check_in_asset',
                'description' => 'Check one asset back in to available stock.',
                'parameters' => $obj(['id' => $int()], ['id'])]],
            ['type' => 'function', 'function' => ['name' => 'check_in_all_for_person',
                'description' => 'Check in EVERY asset currently checked out to a person. Optional department_id: if given, each asset is also moved to that owning department (resolve the name via department_list first).',
                'parameters' => $obj(['person_id' => $int(), 'department_id' => $int()], ['person_id'])]],
            ['type' => 'function', 'function' => ['name' => 'check_out_asset',
                'description' => 'Check an available asset out to a person OR a department (exactly one). Person must be active.',
                'parameters' => $obj(['asset_id' => $int(), 'person_id' => $int(), 'department_id' => $int(), 'due_date' => $str()], ['asset_id'])]],
            ['type' => 'function', 'function' => ['name' => 'transfer_asset',
                'description' => 'Transfer a checked-out asset to another active person.',
                'parameters' => $obj(['asset_id' => $int(), 'person_id' => $int()], ['asset_id', 'person_id'])]],
            ['type' => 'function', 'function' => ['name' => 'set_asset_department',
                'description' => 'Change an asset\'s owning department.',
                'parameters' => $obj(['asset_id' => $int(), 'department_id' => $int()], ['asset_id', 'department_id'])]],
            ['type' => 'function', 'function' => ['name' => 'create_person',
                'description' => 'Create a person record. Admin only.',
                'parameters' => $obj([
                    'full_name' => $str(), 'job_title' => $str(), 'work_email' => $str(),
                    'personal_email' => $str(), 'phone' => $str(), 'address' => $str(),
                    'department_id' => $int(), 'notes' => $str(),
                ], ['full_name'])]],
            ['type' => 'function', 'function' => ['name' => 'update_person',
                'description' => 'Update fields of a person record. Admin only. Omit fields to leave unchanged.',
                'parameters' => $obj(['id' => $int(), 'full_name' => $str(), 'job_title' => $str(),
                    'work_email' => $str(), 'personal_email' => $str(), 'phone' => $str(),
                    'address' => $str(), 'department_id' => $int(), 'notes' => $str(),
                    'is_terminated' => $bool()], ['id'])]],
        ];
    }

    public static function execute(string $name, array $args, array $user, bool $executeMode): array
    {
        return match ($name) {
            'find_person' => self::findPerson((string) ($args['query'] ?? ''), $user),
            'find_asset' => self::findAsset((string) ($args['query'] ?? ''), $user),
            'list_persons' => self::listPersons(!empty($args['terminated']), $user),
            'list_assets' => self::listAssets($args),
            'person_detail' => self::personDetail((int) ($args['id'] ?? 0), $user),
            'asset_detail' => self::assetDetail((int) ($args['id'] ?? 0), $user),
            'department_list' => self::departmentList(),
            'asset_last_holder' => self::assetLastHolder((string) ($args['tag'] ?? ''), $user),
            'terminate_person' => self::terminatePerson((int) ($args['id'] ?? 0), $user, $executeMode),
            'reinstate_person' => self::reinstatePerson((int) ($args['id'] ?? 0), $user, $executeMode),
            'check_in_asset' => self::checkInAsset((int) ($args['id'] ?? 0), $user, $executeMode),
            'check_in_all_for_person' => self::checkInAllForPerson((int) ($args['person_id'] ?? 0), $args, $user, $executeMode),
            'check_out_asset' => self::checkOutAsset($args, $user, $executeMode),
            'transfer_asset' => self::transferAsset((int) ($args['asset_id'] ?? 0), (int) ($args['person_id'] ?? 0), $user, $executeMode),
            'set_asset_department' => self::setAssetDepartment((int) ($args['asset_id'] ?? 0), (int) ($args['department_id'] ?? 0), $user, $executeMode),
            'create_person' => self::createPerson($args, $user, $executeMode),
            'update_person' => self::updatePerson((int) ($args['id'] ?? 0), $args, $user, $executeMode),
            default => ['error' => 'Unknown tool: ' . $name],
        };
    }

    private static function personRowsFor(array $rows, array $user): array
    {
        if (($user['role_name'] ?? '') !== 'admin') {
            foreach ($rows as &$row) {
                unset($row['work_email'], $row['phone']);
            }
            unset($row);
        }
        return $rows;
    }

    private static function findPerson(string $query, array $user): array
    {
        $tokens = array_values(array_filter(array_map('trim', preg_split('/\s+/', $query) ?: [])));
        if ($tokens === []) {
            return ['matches' => [], 'note' => 'Provide a name fragment to search.'];
        }
        $where = [];
        $params = [];
        foreach ($tokens as $i => $t) {
            $where[] = 'p.full_name ILIKE :t' . $i;
            $params['t' . $i] = '%' . str_replace(['%', '_'], ['\%', '\_'], $t) . '%';
        }
        $rows = Database::fetchAll(
            'SELECT p.id, p.full_name, p.job_title, p.work_email, p.phone, p.is_terminated,
                    d.name AS department,
                    (SELECT COUNT(*) FROM assets a WHERE a.assigned_to_person_id = p.id AND a.status = \'checked_out\')::int AS held
             FROM persons p LEFT JOIN departments d ON d.id = p.department_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.full_name LIMIT 5',
            $params
        );
        return $rows === []
            ? ['matches' => [], 'note' => 'No person matches "' . $query . '".']
            : ['matches' => self::personRowsFor($rows, $user)];
    }

    private static function findAsset(string $query, array $user): array
    {
        $select = 'SELECT a.id, a.asset_tag, a.serial_number, a.brand, a.model_number, a.status, a.due_date,
                    p.full_name AS holder_person, d.name AS holder_department
              FROM assets a
              LEFT JOIN persons p ON p.id = a.assigned_to_person_id
              LEFT JOIN departments d ON d.id = a.assigned_to_department_id';
        [$scope, $scopeParams] = Auth::scopeWhere('a');
        $row = Database::fetchOne($select . ' WHERE (a.asset_tag = :q OR a.serial_number = :q)' . $scope, array_merge(['q' => $query], $scopeParams));
        if ($row !== null) {
            return ['matches' => [$row]];
        }
        $rows = Database::fetchAll(
            $select . ' WHERE (a.asset_tag ILIKE :q OR a.serial_number ILIKE :q)' . $scope . ' ORDER BY a.asset_tag LIMIT 5',
            array_merge(['q' => '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%'], $scopeParams)
        );
        return $rows === []
            ? ['matches' => [], 'note' => 'No asset matches "' . $query . '".']
            : ['matches' => $rows];
    }

    private static function listPersons(bool $terminated, array $user): array
    {
        $rows = Database::fetchAll(
            'SELECT p.id, p.full_name, p.job_title, p.work_email, p.phone, p.is_terminated, d.name AS department,
                    (SELECT COUNT(*) FROM assets a WHERE a.assigned_to_person_id = p.id AND a.status = \'checked_out\')::int AS held
              FROM persons p LEFT JOIN departments d ON d.id = p.department_id
              WHERE p.is_terminated = :t ORDER BY p.full_name LIMIT 25',
            ['t' => $terminated ? 1 : 0]
        );
        return ['persons' => self::personRowsFor($rows, $user)];
    }

    private static function listAssets(array $args): array
    {
        [$scope, $params] = Auth::scopeWhere('a');
        $where = ['1=1'];
        if (!empty($args['assignee_id'])) {
            $where[] = 'a.assigned_to_person_id = :pid';
            $params['pid'] = (int) $args['assignee_id'];
        }
        if (!empty($args['status'])) {
            $where[] = 'a.status = :st';
            $params['st'] = (string) $args['status'];
        }
        if (!empty($args['department_id'])) {
            $where[] = 'a.department_id = :did';
            $params['did'] = (int) $args['department_id'];
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) Database::fetchColumn("SELECT COUNT(*) FROM assets a WHERE {$whereSql} {$scope}", $params);
        $rows = Database::fetchAll(
            "SELECT a.id, a.asset_tag, a.brand, a.model_number, a.status, a.due_date,
                    c.name AS category, p.full_name AS holder_person, d.name AS holder_department
             FROM assets a
             LEFT JOIN categories c ON c.id = a.category_id
             LEFT JOIN persons p ON p.id = a.assigned_to_person_id
             LEFT JOIN departments d ON d.id = a.assigned_to_department_id
             WHERE {$whereSql} {$scope}
             ORDER BY a.asset_tag LIMIT 25",
            $params
        );
        return ['total' => $total, 'assets' => $rows];
    }

    private static function personDetail(int $id, array $user): array
    {
        if ($deny = self::requireRole($user, ['admin'], 'view a full person record')) {
            return $deny;
        }
        $p = Person::find($id);
        if ($p === null) {
            return ['error' => 'Person not found (id ' . $id . ').'];
        }
        $assets = Database::fetchAll(
            'SELECT asset_tag, status, due_date FROM assets
             WHERE assigned_to_person_id = :id AND status = \'checked_out\' ORDER BY asset_tag',
            ['id' => $id]
        );
        return ['person' => $p, 'checked_out_assets' => $assets];
    }

    private static function assetDetail(int $id, array $user): array
    {
        $a = Asset::find($id, $user);
        if ($a === null) {
            return ['error' => 'Asset not found (id ' . $id . ').'];
        }
        unset($a['photos'], $a['audit']);
        return ['asset' => $a];
    }

    private static function departmentList(): array
    {
        return ['departments' => Department::all()];
    }

    private static function assetLastHolder(string $tag, array $user): array
    {
        $candidates = [$tag];
        if (ctype_digit($tag)) {
            $candidates[] = str_pad($tag, 5, '0', STR_PAD_LEFT);
        }
        $whereSql = implode(' OR ', array_map(static fn (string $c, int $i): string => 'a.asset_tag = :c' . $i, $candidates, array_keys($candidates)));
        $params = [];
        foreach ($candidates as $i => $c) {
            $params['c' . $i] = $c;
        }
        [$scope, $scopeParams] = Auth::scopeWhere('a');
        $assetId = Database::fetchColumn('SELECT a.id FROM assets a WHERE (' . $whereSql . ')' . $scope, array_merge($params, $scopeParams));
        if ($assetId === null) {
            return ['error' => 'No asset with tag "' . $tag . '".'];
        }
        $row = Database::fetchOne(
            'SELECT al.details->>"to" AS holder, al.action, al.created_at
             FROM audit_log al
             WHERE al.entity = \'asset\' AND al.entity_id = :aid
               AND al.action IN (\'asset.check_out\', \'asset.transfer\')
             ORDER BY al.created_at DESC LIMIT 1',
            ['aid' => (string) $assetId]
        );
        if ($row === null) {
            return ['tag' => $tag, 'holder' => null, 'note' => 'No check-out or transfer recorded for this asset.'];
        }
        return ['tag' => $tag, 'holder' => $row['holder'], 'when' => (string) $row['created_at'], 'via' => $row['action']];
    }

    private static function requireRole(array $user, array $allowed, string $label): ?array
    {
        if (!in_array($user['role_name'] ?? '', $allowed, true)) {
            return ['error' => "You don't have permission to {$label} (requires " . implode('/', $allowed) . ').'];
        }
        return null;
    }

    private static function terminatePerson(int $id, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin'], 'terminate a person')) {
            return $deny;
        }
        $p = Person::find($id);
        if ($p === null) {
            return ['error' => 'Person not found (id ' . $id . ').'];
        }
        if ($p['is_terminated']) {
            return ['error' => $p['full_name'] . ' is already terminated.'];
        }
        if (!$executeMode) {
            return ['preview' => 'Mark ' . $p['full_name'] . ' as terminated.', 'op' => 'terminate_person', 'args' => ['id' => $id]];
        }
        Person::toggleTerminated($id);
        return ['ok' => true, 'message' => $p['full_name'] . ' marked as terminated.'];
    }

    private static function reinstatePerson(int $id, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin'], 'reinstate a person')) {
            return $deny;
        }
        $p = Person::find($id);
        if ($p === null) {
            return ['error' => 'Person not found (id ' . $id . ').'];
        }
        if (empty($p['is_terminated'])) {
            return ['error' => $p['full_name'] . ' is already active.'];
        }
        if (!$executeMode) {
            return ['preview' => 'Reinstate ' . $p['full_name'] . ' (mark active).', 'op' => 'reinstate_person', 'args' => ['id' => $id]];
        }
        Person::toggleTerminated($id);
        return ['ok' => true, 'message' => $p['full_name'] . ' reinstated.'];
    }

    private static function checkInAsset(int $id, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin', 'department_manager'], 'check in assets')) {
            return $deny;
        }
        $a = Asset::find($id, $user);
        if ($a === null) {
            return ['error' => 'Asset not found (id ' . $id . ').'];
        }
        if ($a['status'] === 'available') {
            return ['error' => $a['asset_tag'] . ' is already available.'];
        }
        if (in_array($a['status'], ['disposed', 'sold', 'donated'], true)) {
            return ['error' => $a['asset_tag'] . ' is ' . $a['status'] . ' and cannot be checked in.'];
        }
        if (!$executeMode) {
            return ['preview' => 'Check in ' . $a['asset_tag'] . ' (currently ' . $a['status'] . ').', 'op' => 'check_in_asset', 'args' => ['id' => $id]];
        }
        try {
            Asset::setStatus($id, 'available', [], $user);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => $a['asset_tag'] . ' checked in and available.'];
    }

    private static function checkInAllForPerson(int $personId, array $args, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin', 'department_manager'], 'check in assets')) {
            return $deny;
        }
        $p = Person::find($personId);
        if ($p === null) {
            return ['error' => 'Person not found (id ' . $personId . ').'];
        }
        $deptId = !empty($args['department_id']) ? (int) $args['department_id'] : null;
        if (($user['role_name'] ?? '') === 'department_manager' && $deptId !== null) {
            return ['error' => 'You can check assets in, but only an admin can move them to another department.'];
        }
        $deptName = null;
        if ($deptId !== null) {
            $deptName = Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => $deptId]);
            if ($deptName === false || $deptName === null) {
                return ['error' => 'Department not found (id ' . $deptId . '). Use department_list to see valid departments.'];
            }
        }
        [$scope, $scopeParams] = Auth::scopeWhere('a');
        $assets = Database::fetchAll(
            'SELECT a.id, a.asset_tag, a.status FROM assets a
             WHERE a.assigned_to_person_id = :pid AND a.status = \'checked_out\' ' . $scope . '
             ORDER BY a.asset_tag',
            array_merge(['pid' => $personId], $scopeParams)
        );
        if ($assets === []) {
            return ['note' => $p['full_name'] . ' has no assets currently checked out.'];
        }
        $suffix = $deptName !== null ? ' and move each to the ' . $deptName . ' department' : '';
        if (!$executeMode) {
            return [
                'preview' => 'Check in ' . count($assets) . ' asset(s) held by ' . $p['full_name']
                    . ' (' . implode(', ', array_column($assets, 'asset_tag')) . ')' . $suffix . '.',
                'op' => 'check_in_all_for_person',
                'args' => ['person_id' => $personId, 'department_id' => $deptId],
            ];
        }
        $items = [];
        foreach ($assets as $a) {
            try {
                Asset::setStatus((int) $a['id'], 'available', [], $user);
                if ($deptId !== null) {
                    Asset::setDepartment((int) $a['id'], $deptId, $user);
                }
                $items[] = ['tag' => $a['asset_tag'], 'ok' => 1, 'reason' => 'checked in' . ($deptId !== null ? ' → ' . $deptName : '')];
            } catch (\Throwable $e) {
                $items[] = ['tag' => $a['asset_tag'], 'ok' => 0, 'reason' => $e->getMessage()];
            }
        }
        $okCount = count(array_filter($items, static fn (array $i) => $i['ok'] === 1));
        return ['ok' => $okCount === count($items), 'checked_in' => $okCount, 'total' => count($items), 'items' => $items];
    }

    private static function checkOutAsset(array $args, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin', 'department_manager'], 'check out assets')) {
            return $deny;
        }
        $assetId = (int) ($args['asset_id'] ?? 0);
        $a = Asset::find($assetId, $user);
        if ($a === null) {
            return ['error' => 'Asset not found (id ' . $assetId . ').'];
        }
        if ($a['status'] !== 'available') {
            return ['error' => $a['asset_tag'] . ' is ' . $a['status'] . ' and cannot be checked out.'];
        }
        $personId = !empty($args['person_id']) ? (int) $args['person_id'] : null;
        $deptId = !empty($args['department_id']) ? (int) $args['department_id'] : null;
        if ($personId === null && $deptId === null) {
            return ['error' => 'Choose a person or a department to check out to.'];
        }
        $who = null;
        if ($personId !== null) {
            $person = Person::find($personId);
            if ($person === null || !empty($person['is_terminated'])) {
                return ['error' => 'Person not found or terminated (id ' . $personId . ').'];
            }
            $who = $person['full_name'];
        } else {
            $deptName = Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => $deptId]);
            if ($deptName === false || $deptName === null) {
                return ['error' => 'Department not found (id ' . $deptId . ').'];
            }
            $who = $deptName;
        }
        if ((string) ($args['due_date'] ?? '') !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['due_date'])) {
            return ['error' => 'due_date must be a date like 2026-09-15.'];
        }
        $extra = [
            'assigned_to_person_id' => $personId,
            'assigned_to_department_id' => $personId !== null ? null : $deptId,
            'due_date' => (string) ($args['due_date'] ?? ''),
        ];
        if (!$executeMode) {
            return [
                'preview' => 'Check out ' . $a['asset_tag'] . ' to ' . $who . '.',
                'op' => 'check_out_asset',
                'args' => [
                    'asset_id' => $assetId,
                    'person_id' => $personId,
                    'department_id' => $deptId,
                    'due_date' => (string) ($args['due_date'] ?? ''),
                ],
            ];
        }
        try {
            Asset::setStatus($assetId, 'checked_out', $extra, $user);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => $a['asset_tag'] . ' checked out to ' . $who . '.'];
    }

    private static function transferAsset(int $assetId, int $personId, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin', 'department_manager'], 'transfer assets')) {
            return $deny;
        }
        $a = Asset::find($assetId, $user);
        if ($a === null) {
            return ['error' => 'Asset not found (id ' . $assetId . ').'];
        }
        if ($a['status'] !== 'checked_out') {
            return ['error' => $a['asset_tag'] . ' is not checked out, so it cannot be transferred.'];
        }
        $person = Person::find($personId);
        if ($person === null || !empty($person['is_terminated'])) {
            return ['error' => 'Transfer target person not found or terminated (id ' . $personId . ').'];
        }
        if (!$executeMode) {
            return ['preview' => 'Transfer ' . $a['asset_tag'] . ' to ' . $person['full_name'] . '.', 'op' => 'transfer_asset', 'args' => ['asset_id' => $assetId, 'person_id' => $personId]];
        }
        try {
            Asset::transfer($assetId, $personId, $user);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => $a['asset_tag'] . ' transferred to ' . $person['full_name'] . '.'];
    }

    private static function setAssetDepartment(int $assetId, int $deptId, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin'], 'change an asset department')) {
            return $deny;
        }
        $a = Asset::find($assetId, $user);
        if ($a === null) {
            return ['error' => 'Asset not found (id ' . $assetId . ').'];
        }
        $deptName = Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => $deptId]);
        if ($deptName === false || $deptName === null) {
            return ['error' => 'Department not found (id ' . $deptId . ').'];
        }
        if (!$executeMode) {
            return ['preview' => 'Move ' . $a['asset_tag'] . ' to the ' . $deptName . ' department.', 'op' => 'set_asset_department', 'args' => ['asset_id' => $assetId, 'department_id' => $deptId]];
        }
        try {
            Asset::setDepartment($assetId, $deptId, $user);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => $a['asset_tag'] . ' moved to ' . $deptName . '.'];
    }

    private static function createPerson(array $args, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin'], 'create a person')) {
            return $deny;
        }
        $name = trim((string) ($args['full_name'] ?? ''));
        if ($name === '') {
            return ['error' => 'full_name is required.'];
        }
        $clean = [
            'full_name' => $name,
            'job_title' => (string) ($args['job_title'] ?? ''),
            'personal_email' => (string) ($args['personal_email'] ?? ''),
            'work_email' => (string) ($args['work_email'] ?? ''),
            'phone' => (string) ($args['phone'] ?? ''),
            'address' => (string) ($args['address'] ?? ''),
            'department_id' => !empty($args['department_id']) ? (int) $args['department_id'] : null,
            'notes' => (string) ($args['notes'] ?? ''),
            'is_terminated' => !empty($args['is_terminated']),
        ];
        if (!empty($clean['department_id'])) {
            $dept = Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => (int) $clean['department_id']]);
            if ($dept === false || $dept === null) {
                return ['error' => 'Department not found (id ' . $clean['department_id'] . '). Use department_list to see valid departments.'];
            }
        }
        $existing = Database::fetchColumn('SELECT id FROM persons WHERE full_name = :n', ['n' => $name]);
        if ($existing !== false && $existing !== null) {
            return ['error' => 'A person named "' . $name . '" already exists.'];
        }
        if (!$executeMode) {
            return ['preview' => 'Create person "' . $name . '" (' . ($clean['job_title'] !== '' ? $clean['job_title'] : 'no title') . ').', 'op' => 'create_person', 'args' => $clean];
        }
        try {
            $id = Person::create($clean);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        return ['ok' => true, 'id' => $id, 'message' => $name . ' created.'];
    }

    private static function updatePerson(int $id, array $args, array $user, bool $executeMode): array
    {
        if ($deny = self::requireRole($user, ['admin'], 'update a person')) {
            return $deny;
        }
        $p = Person::find($id);
        if ($p === null) {
            return ['error' => 'Person not found (id ' . $id . ').'];
        }
        $clean = [
            'full_name' => (string) ($args['full_name'] ?? $p['full_name']),
            'job_title' => (string) ($args['job_title'] ?? $p['job_title']),
            'personal_email' => (string) ($args['personal_email'] ?? $p['personal_email']),
            'work_email' => (string) ($args['work_email'] ?? $p['work_email']),
            'phone' => (string) ($args['phone'] ?? $p['phone']),
            'address' => (string) ($args['address'] ?? $p['address']),
            'department_id' => array_key_exists('department_id', $args)
                ? (!empty($args['department_id']) ? (int) $args['department_id'] : null)
                : (empty($p['department_id']) ? null : (int) $p['department_id']),
            'notes' => (string) ($args['notes'] ?? $p['notes']),
            'is_terminated' => array_key_exists('is_terminated', $args) ? !empty($args['is_terminated']) : !empty($p['is_terminated']),
        ];
        if (trim($clean['full_name']) === '') {
            return ['error' => 'full_name cannot be empty.'];
        }
        if ($clean['department_id'] !== null) {
            $dept = Database::fetchColumn('SELECT name FROM departments WHERE id = :id', ['id' => (int) $clean['department_id']]);
            if ($dept === false || $dept === null) {
                return ['error' => 'Department not found (id ' . $clean['department_id'] . '). Use department_list to see valid departments.'];
            }
        }
        $existing = Database::fetchColumn('SELECT id FROM persons WHERE full_name = :n AND id <> :id', ['n' => $clean['full_name'], 'id' => $id]);
        if ($existing !== false && $existing !== null) {
            return ['error' => 'Another person is already named "' . $clean['full_name'] . '".'];
        }
        if (!$executeMode) {
            $changed = [];
            foreach (['full_name', 'job_title', 'personal_email', 'work_email', 'phone', 'address', 'notes', 'is_terminated'] as $f) {
                if ((string) $clean[$f] !== (string) $p[$f]) {
                    $changed[] = $f;
                }
            }
            if ($changed === []) {
                return ['note' => 'No changes requested for ' . $p['full_name'] . '.'];
            }
            return ['preview' => 'Update ' . $p['full_name'] . ' — change: ' . implode(', ', $changed) . '.', 'op' => 'update_person', 'args' => array_merge(['id' => $id], $clean)];
        }
        try {
            Person::update($id, $clean);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => $p['full_name'] . ' updated.'];
    }
}
