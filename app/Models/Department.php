<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class Department
{
    public static function all(): array
    {
        return Database::fetchAll(
            'SELECT d.*, COUNT(a.id) AS asset_count
             FROM departments d LEFT JOIN assets a ON a.department_id = d.id
             GROUP BY d.id ORDER BY d.name'
        );
    }

    public static function active(): array
    {
        return Database::fetchAll('SELECT id, name FROM departments WHERE is_active = true ORDER BY name');
    }

    public static function store(array $d): void
    {
        if (trim($d['name'] ?? '') === '') {
            throw new RuntimeException('Department name is required.');
        }
        try {
            Database::execute(
                'INSERT INTO departments (name, description) VALUES (:n, :ds)',
                ['n' => trim($d['name']), 'ds' => trim($d['description'] ?? '')]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new RuntimeException('A department with that name already exists.');
            }
            throw $e;
        }
        Audit::log('department.create', 'department', trim($d['name']), []);
    }

    public static function delete(int $id): void
    {
        try {
            Database::execute('DELETE FROM departments WHERE id = :id', ['id' => $id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23503') {
                throw new RuntimeException('This department is in use by assets, users, or assignments. Reassign them first.');
            }
            throw $e;
        }
        Audit::log('department.delete', 'department', (string) $id, []);
    }
}
