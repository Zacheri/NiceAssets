<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class Site
{
    public static function all(): array
    {
        return Database::fetchAll(
            'SELECT s.*, COUNT(l.id) AS location_count, COUNT(a.id) AS asset_count
             FROM sites s
             LEFT JOIN locations l ON l.site_id = s.id
             LEFT JOIN assets a ON a.site_id = s.id
             GROUP BY s.id ORDER BY s.name'
        );
    }

    public static function active(): array
    {
        return Database::fetchAll('SELECT id, name FROM sites WHERE is_active = true ORDER BY name');
    }

    public static function store(array $d): void
    {
        if (trim($d['name'] ?? '') === '') {
            throw new RuntimeException('Site name is required.');
        }
        try {
            Database::execute(
                'INSERT INTO sites (name, address) VALUES (:n, :a)',
                ['n' => trim($d['name']), 'a' => trim($d['address'] ?? '')]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new RuntimeException('A site with that name already exists.');
            }
            throw $e;
        }
        Audit::log('site.create', 'site', trim($d['name']), []);
    }

    public static function delete(int $id): void
    {
        try {
            Database::execute('DELETE FROM sites WHERE id = :id', ['id' => $id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23503') {
                throw new RuntimeException('This site still has locations or assets. Remove those first.');
            }
            throw $e;
        }
        Audit::log('site.delete', 'site', (string) $id, []);
    }
}
