<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class UserPref
{
    public static function get(int $userId, string $key, mixed $default = null): mixed
    {
        $value = Database::fetchColumn(
            'SELECT value FROM user_prefs WHERE user_id = :u AND key = :k',
            ['u' => $userId, 'k' => $key]
        );
        return $value === false || $value === null ? $default : $value;
    }

    public static function set(int $userId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO user_prefs (user_id, key, value) VALUES (:u, :k, :v)
             ON CONFLICT (user_id, key) DO UPDATE SET value = :v2',
            ['u' => $userId, 'k' => $key, 'v' => $value, 'v2' => $value]
        );
    }
}
