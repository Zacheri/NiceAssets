<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class Category
{
    public static function all(): array
    {
        return Database::fetchAll(
            'SELECT c.*, COUNT(a.id) AS asset_count,
                    COALESCE(SUM(CASE WHEN a.status = \'available\' THEN a.sub_quantity ELSE 0 END), 0) AS available_qty
             FROM categories c LEFT JOIN assets a ON a.category_id = c.id
             GROUP BY c.id ORDER BY c.name'
        );
    }

    public static function active(): array
    {
        return Database::fetchAll('SELECT id, name FROM categories WHERE is_active = true ORDER BY name');
    }

    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM categories WHERE id = :id', ['id' => $id]);
    }

    public static function findByName(string $name): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM categories WHERE lower(name) = lower(:n)',
            ['n' => trim($name)]
        );
    }

    public static function store(array $d): void
    {
        if (trim($d['name'] ?? '') === '') {
            throw new RuntimeException('Category name is required.');
        }
        try {
            Database::execute(
                'INSERT INTO categories (name, low_stock_threshold, depreciation_alert_enabled)
                 VALUES (:n, :t, :dep)',
                [
                    'n' => trim($d['name']),
                    't' => max(0, (int) ($d['low_stock_threshold'] ?? 5)),
                    'dep' => empty($d['depreciation_alert_enabled']) ? 0 : 1,
                ]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new RuntimeException('A category with that name already exists.');
            }
            throw $e;
        }
        Audit::log('category.create', 'category', trim($d['name']), []);
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

    public static function update(int $id, array $d): void
    {
        Database::execute(
            'UPDATE categories SET low_stock_threshold = :t, depreciation_alert_enabled = :dep,
             is_active = :active WHERE id = :id',
            [
                't' => max(0, (int) ($d['low_stock_threshold'] ?? 5)),
                'dep' => empty($d['depreciation_alert_enabled']) ? 0 : 1,
                'active' => empty($d['is_active']) ? 0 : 1,
                'id' => $id,
            ]
        );
        Audit::log('category.update', 'category', (string) $id, ['threshold' => $d['low_stock_threshold'] ?? null]);
    }

    public static function delete(int $id): void
    {
        try {
            Database::execute('DELETE FROM categories WHERE id = :id', ['id' => $id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23503') {
                throw new RuntimeException('Assets still use this category. Reassign them first.');
            }
            throw $e;
        }
        Audit::log('category.delete', 'category', (string) $id, []);
    }
}
