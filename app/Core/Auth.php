<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private const MAX_FAILURES = 5;
    private const LOCK_MINUTES = 5;

    public static function attempt(string $username, string $password): bool
    {
        $user = Database::fetchOne(
            'SELECT * FROM users WHERE username = :u',
            ['u' => $username]
        );
        if ($user === null) {
            return false;
        }
        if (!$user['is_active']) {
            return false;
        }
        if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            $failures = (int) $user['login_failures'] + 1;
            $locked = $failures >= self::MAX_FAILURES;
            Database::execute(
                'UPDATE users SET login_failures = :f, locked_until = :locked WHERE id = :id',
                [
                    'f' => $failures,
                    'locked' => $locked
                        ? (new \DateTimeImmutable('+' . self::LOCK_MINUTES . ' minutes'))->format('Y-m-d\TH:i:sP')
                        : null,
                    'id' => $user['id'],
                ]
            );
            return false;
        }
        Database::execute(
            'UPDATE users SET login_failures = 0, locked_until = NULL, updated_at = now() WHERE id = :id',
            ['id' => $user['id']]
        );
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        \App\Models\User::log('auth.login', 'user', (string) $user['id'], ['username' => $user['username']]);
        return true;
    }

    public static function user(): ?array
    {
        static $cached = false;
        static $row = null;
        if ($cached) {
            return $row;
        }
        $cached = true;
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id > 0) {
            $row = Database::fetchOne(
                'SELECT u.*, r.name AS role_name, d.name AS department_name
                 FROM users u
                 JOIN roles r ON r.id = u.role_id
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE u.id = :id AND u.is_active = true',
                ['id' => $id]
            );
            if ($row === null) {
                self::clear();
            }
        }
        return $row;
    }

    public static function clear(): void
    {
        unset($_SESSION['user_id']);
    }

    public static function requireLogin(): ?array
    {
        $user = self::user();
        if ($user === null) {
            $_SESSION['intended'] = Request::uri();
            Response::redirect('/login');
        }
        return $user;
    }

    public static function hasRole(array $roles): bool
    {
        $user = self::user();
        return $user !== null && in_array($user['role_name'], $roles, true);
    }

    public static function isAdmin(): bool
    {
        return self::hasRole(['admin']);
    }

    public static function canModify(): bool
    {
        return self::hasRole(['admin', 'department_manager']);
    }

    public static function scopeWhere(string $alias = 'a'): array
    {
        $user = self::user();
        if ($user === null || $user['role_name'] === 'admin') {
            return ['', []];
        }
        return [
            " AND {$alias}.department_id = :scope_dept",
            ['scope_dept' => (int) $user['department_id']],
        ];
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][$type] = $message;
    }

    public static function takeFlash(): array
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $flash;
    }
}
