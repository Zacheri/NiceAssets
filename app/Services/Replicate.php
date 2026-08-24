<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Audit;
use RuntimeException;

final class Replicate
{
    public static function replicate(int $id, ?array $user): int
    {
        [$scope, $scopeParams] = Auth::scopeWhere('a');
        $asset = Database::fetchOne('SELECT * FROM assets WHERE id = :id ' . $scope, array_merge(['id' => $id], $scopeParams));
        if ($asset === null) {
            throw new RuntimeException('Asset not found.');
        }

        $newTag = self::uniqueIncrement(self::incrementId($asset['asset_tag']));
        $newSerial = $asset['serial_number'] !== null
            ? self::uniqueIncrement(self::incrementId($asset['serial_number']), 'serial_number')
            : null;

        $departmentId = $asset['department_id'];
        if ($user !== null && $user['role_name'] === 'department_manager') {
            $departmentId = (int) $user['department_id'];
        }

        $newId = Database::insert(
            'INSERT INTO assets (asset_tag, serial_number, model_number, brand, category_id, department_id,
                site_id, location_id, purchase_date, purchase_cost, warranty_expiration, sub_quantity,
                status, created_by)
             VALUES (:t, :sn, :mn, :b, :cat, :dep, :site, :loc, :pd, :cost, :we, :sq, \'available\', :cb)',
            [
                't' => $newTag,
                'sn' => $newSerial,
                'mn' => $asset['model_number'],
                'b' => $asset['brand'],
                'cat' => $asset['category_id'],
                'dep' => $departmentId,
                'site' => $asset['site_id'],
                'loc' => $asset['location_id'],
                'pd' => $asset['purchase_date'],
                'cost' => $asset['purchase_cost'],
                'we' => $asset['warranty_expiration'],
                'sq' => (int) $asset['sub_quantity'],
                'cb' => $user['id'] ?? null,
            ]
        );

        $photoIds = Database::fetchAll('SELECT photo_id FROM asset_photos WHERE asset_id = :id', ['id' => $id]);
        $i = 0;
        foreach ($photoIds as $row) {
            Database::execute(
                'INSERT INTO asset_photos (asset_id, photo_id, position, is_thumbnail)
                 VALUES (:a, :p, :pos, :thumb) ON CONFLICT DO NOTHING',
                [
                    'a' => $newId,
                    'p' => (int) $row['photo_id'],
                    'pos' => $i,
                    'thumb' => $i === 0 ? 1 : 0,
                ]
            );
            $i++;
        }

        Audit::log('asset.replicate', 'asset', (string) $newId, [
            'asset_tag' => $newTag,
            'source_tag' => $asset['asset_tag'],
        ]);
        return (int) $newId;
    }

    private static function incrementId(string $value): string
    {
        if (preg_match('/^(.*?)(\d+)$/', $value, $m)) {
            $prefix = $m[1];
            $num = (string) ((int) $m[2] + 1);
            $pad = strlen($m[2]);
            return $prefix . str_pad($num, $pad, '0', STR_PAD_LEFT);
        }
        return $value . '-1';
    }

    private static function uniqueIncrement(string $candidate, string $column = 'asset_tag'): string
    {
        $value = $candidate;
        $suffix = 2;
        while (Database::fetchOne("SELECT id FROM assets WHERE {$column} = :v", ['v' => $value]) !== null) {
            if (preg_match('/^(.*?)(\d+)$/', $candidate, $m)) {
                $value = $m[1] . str_pad((string) ((int) $m[2] + $suffix), strlen($m[2]), '0', STR_PAD_LEFT);
            } else {
                $value = $candidate . '-' . $suffix;
            }
            $suffix++;
            if ($suffix > 500) {
                throw new RuntimeException('Could not find a unique tag/serial for replication.');
            }
        }
        return $value;
    }
}
