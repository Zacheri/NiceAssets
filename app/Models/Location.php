<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class Location
{
    public static function all(): array
    {
        return Database::fetchAll(
            'SELECT l.*, s.name AS site_name, COUNT(a.id) AS asset_count
             FROM locations l
             JOIN sites s ON s.id = l.site_id
             LEFT JOIN assets a ON a.location_id = l.id
             GROUP BY l.id, s.name ORDER BY s.name, l.name'
        );
    }

    public static function activeBySite(): array
    {
        $rows = Database::fetchAll(
            'SELECT l.id, l.site_id, l.name, l.code
             FROM locations l JOIN sites s ON s.id = l.site_id
             WHERE l.is_active = true AND s.is_active = true
             ORDER BY s.name, l.name'
        );
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['site_id']][] = $row;
        }
        return $grouped;
    }

    public static function store(array $d): void
    {
        if (trim($d['name'] ?? '') === '' || empty($d['site_id'])) {
            throw new RuntimeException('Location name and site are required.');
        }
        try {
            Database::execute(
                'INSERT INTO locations (site_id, name, code, description) VALUES (:s, :n, :c, :ds)',
                [
                    's' => (int) $d['site_id'],
                    'n' => trim($d['name']),
                    'c' => trim($d['code'] ?? '') !== '' ? trim($d['code']) : null,
                    'ds' => trim($d['description'] ?? ''),
                ]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new RuntimeException('That location name already exists in this site.');
            }
            throw $e;
        }
        Audit::log('location.create', 'location', trim($d['name']), ['site_id' => $d['site_id']]);
    }

    public static function delete(int $id): void
    {
        try {
            Database::execute('DELETE FROM locations WHERE id = :id', ['id' => $id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23503') {
                throw new RuntimeException('This location still has assets assigned. Move them first.');
            }
            throw $e;
        }
        Audit::log('location.delete', 'location', (string) $id, []);
    }
}
