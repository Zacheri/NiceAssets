<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

final class WorkOrder
{
    public static function all(array $f = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['status'])) {
            $where[] = 'wo.status = :status';
            $params['status'] = $f['status'];
        }
        if (!empty($f['q'])) {
            $where[] = '(wo.wo_number ILIKE :q OR a.asset_tag ILIKE :q OR wo.summary ILIKE :q)';
            $params['q'] = '%' . $f['q'] . '%';
        }
        $whereSql = implode(' AND ', $where);
        return Database::fetchAll(
            "SELECT wo.*, a.asset_tag, a.brand, a.model_number, u.full_name AS created_by_name
             FROM work_orders wo
             LEFT JOIN assets a ON a.id = wo.asset_id
             LEFT JOIN users u ON u.id = wo.created_by
             WHERE {$whereSql}
             ORDER BY wo.created_at DESC
             LIMIT 200",
            $params
        );
    }

    public static function find(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT wo.*, a.asset_tag, a.brand, a.model_number, u.full_name AS created_by_name
             FROM work_orders wo
             LEFT JOIN assets a ON a.id = wo.asset_id
             LEFT JOIN users u ON u.id = wo.created_by
             WHERE wo.id = :id",
            ['id' => $id]
        );
    }

    public static function number(int $id): string
    {
        $n = Database::fetchColumn('SELECT wo_number FROM work_orders WHERE id = :id', ['id' => $id]);
        return $n === false ? '' : (string) $n;
    }

    public static function nextNumber(): string
    {
        $prefix = 'WO-' . date('Ymd') . '-';
        $count = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM work_orders WHERE wo_number LIKE :p',
            ['p' => $prefix . '%']
        );
        return $prefix . str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);
    }

    public static function create(?int $assetId, string $summary, string $details, ?array $user): int
    {
        $id = Database::insert(
            'INSERT INTO work_orders (wo_number, asset_id, summary, details, status, created_by)
             VALUES (:n, :a, :s, :d, \'open\', :cb)',
            [
                'n' => self::nextNumber(),
                'a' => $assetId,
                's' => $summary,
                'd' => $details,
                'cb' => $user['id'] ?? null,
            ]
        );
        if ($assetId !== null) {
            Database::execute(
                'UPDATE assets SET status = \'in_repair\', work_order_id = :wo, updated_at = now() WHERE id = :a AND status NOT IN (\'disposed\',\'sold\',\'donated\')',
                ['wo' => $id, 'a' => $assetId]
            );
        }
        Audit::log('work_order.create', 'work_order', (string) $id, ['wo_number' => self::number($id), 'asset_id' => $assetId]);
        return (int) $id;
    }

    public static function store(array $d, ?array $user): int
    {
        $summary = trim((string) ($d['summary'] ?? ''));
        if ($summary === '') {
            throw new RuntimeException('A work order summary is required.');
        }
        $assetId = !empty($d['asset_id']) ? (int) $d['asset_id'] : null;
        if ($assetId !== null) {
            $asset = Database::fetchOne('SELECT asset_tag FROM assets WHERE id = :id', ['id' => $assetId]);
            if ($asset === null) {
                throw new RuntimeException('Selected asset not found.');
            }
        }
        return self::create($assetId, $summary, trim((string) ($d['details'] ?? '')), $user);
    }

    public static function complete(int $id, ?array $user): void
    {
        $wo = self::find($id);
        if ($wo === null) {
            throw new RuntimeException('Work order not found.');
        }
        Database::transaction(function () use ($wo) {
            Database::execute(
                "UPDATE work_orders SET status = 'completed', completed_at = now() WHERE id = :id",
                ['id' => $wo['id']]
            );
            if ($wo['asset_id'] !== null) {
                Database::execute(
                    'UPDATE assets SET status = \'available\', work_order_id = NULL,
                     assigned_to_person_id = NULL, assigned_to_department_id = NULL, due_date = NULL,
                     status_reason = NULL, updated_at = now()
                     WHERE id = :a AND status = \'in_repair\'',
                    ['a' => $wo['asset_id']]
                );
            }
        });
        Audit::log('work_order.complete', 'work_order', (string) $id, [
            'wo_number' => $wo['wo_number'],
            'asset_id' => $wo['asset_id'],
        ]);
    }
}
