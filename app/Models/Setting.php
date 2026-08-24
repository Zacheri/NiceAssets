<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Setting
{
    private static array $cache = [];
    private static bool $loaded = false;

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        foreach (Database::fetchAll('SELECT key, value FROM settings') as $row) {
            self::$cache[$row['key']] = $row['value'];
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        Database::execute(
            'INSERT INTO settings (key, value, updated_at) VALUES (:k, :v, now())
             ON CONFLICT (key) DO UPDATE SET value = :v2, updated_at = now()',
            ['k' => $key, 'v' => (string) $value, 'v2' => (string) $value]
        );
        self::$cache[$key] = (string) $value;
        self::$loaded = true;
    }

    public static function setMany(array $map): void
    {
        foreach ($map as $key => $value) {
            self::set($key, $value);
        }
    }

    public static function emailEnabledForRole(string $roleName): bool
    {
        $key = 'email_role_' . str_replace(' ', '_', strtolower($roleName));
        $value = self::get($key);
        return $value === null ? $roleName === 'admin' : $value === '1';
    }
}
