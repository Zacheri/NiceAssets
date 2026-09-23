<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Themes;
use RuntimeException;

final class User
{
    public static function all(): array
    {
        return Database::fetchAll(
            'SELECT u.*, r.name AS role_name, d.name AS department_name
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN departments d ON d.id = u.department_id
             ORDER BY u.username'
        );
    }

    public static function activeAll(): array
    {
        return Database::fetchAll(
            'SELECT u.id, u.username, u.full_name, u.email, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = true ORDER BY u.full_name'
        );
    }

    public static function find(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT u.*, r.name AS role_name, d.name AS department_name
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.id = :id',
            ['id' => $id]
        );
    }

    public static function roles(): array
    {
        return Database::fetchAll('SELECT * FROM roles ORDER BY id');
    }

    public static function store(array $d, ?array $currentUser): int
    {
        if (trim($d['username'] ?? '') === '') {
            throw new RuntimeException('Username is required.');
        }
        if (strlen((string) ($d['password'] ?? '')) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }
        if (Database::fetchOne('SELECT id FROM users WHERE username = :u', ['u' => $d['username']])) {
            throw new RuntimeException('That username is already taken.');
        }
        $id = Database::insert(
            'INSERT INTO users (username, password_hash, full_name, email, role_id, department_id)
             VALUES (:u, :p, :f, :e, :r, :d)',
            [
                'u' => trim($d['username']),
                'p' => password_hash($d['password'], PASSWORD_DEFAULT),
                'f' => trim($d['full_name'] ?? '') !== '' ? trim($d['full_name']) : trim($d['username']),
                'e' => trim($d['email'] ?? ''),
                'r' => (int) $d['role_id'],
                'd' => !empty($d['department_id']) ? (int) $d['department_id'] : null,
            ]
        );
        Audit::log('user.create', 'user', (string) $id, ['username' => $d['username'], 'role' => $d['role_id']]);
        return (int) $id;
    }

    public static function update(int $id, array $d, ?array $currentUser): void
    {
        $existing = self::find($id);
        if ($existing === null) {
            throw new RuntimeException('User not found.');
        }
        if ($currentUser !== null && (int) $currentUser['id'] === $id && (int) $d['role_id'] !== (int) $existing['role_id']) {
            throw new RuntimeException('You cannot change your own role.');
        }
        $params = [
            'u' => trim($d['username'] ?? $existing['username']),
            'f' => trim($d['full_name'] ?? $existing['full_name']),
            'e' => trim($d['email'] ?? $existing['email']),
            'r' => (int) $d['role_id'],
            'd' => !empty($d['department_id']) ? (int) $d['department_id'] : null,
            'active' => empty($d['is_active']) ? 0 : 1,
            'id' => $id,
        ];
        Database::execute(
            'UPDATE users SET username = :u, full_name = :f, email = :e, role_id = :r,
             department_id = :d, is_active = :active, updated_at = now() WHERE id = :id',
            $params
        );
        if (!empty($d['password']) && $d['password'] !== '') {
            if (strlen($d['password']) < 8) {
                throw new RuntimeException('New password must be at least 8 characters.');
            }
            Database::execute(
                'UPDATE users SET password_hash = :p, login_failures = 0, locked_until = NULL WHERE id = :id',
                ['p' => password_hash($d['password'], PASSWORD_DEFAULT), 'id' => $id]
            );
        }
        Audit::log('user.update', 'user', (string) $id, ['username' => $params['u'], 'role' => $d['role_id']]);
    }

    public static function setTheme(int $id, array $theme): void
    {
        $normalized = Themes::normalizeUserTheme($theme);
        Database::execute(
            'UPDATE users SET theme = :t, updated_at = now() WHERE id = :id',
            ['t' => json_encode($normalized, JSON_UNESCAPED_SLASHES), 'id' => $id]
        );
    }

    public static function delete(int $id, ?array $currentUser): void
    {
        if ($currentUser !== null && (int) $currentUser['id'] === $id) {
            throw new RuntimeException('You cannot delete your own account.');
        }
        $existing = self::find($id);
        if ($existing === null) {
            throw new RuntimeException('User not found.');
        }
        Database::execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
        Audit::log('user.delete', 'user', (string) $id, ['username' => $existing['username']]);
    }

    public static function log(string $action, string $entity, string $entityId, array $details = []): void
    {
        Audit::log($action, $entity, $entityId, $details);
    }
}
