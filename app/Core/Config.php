<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static ?array $data = null;

    public static function all(): array
    {
        if (self::$data === null) {
            self::$data = require BASE_PATH . '/config/app.php';
        }
        return self::$data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::all();
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function routes(): array
    {
        static $routes = null;
        if ($routes === null) {
            $routes = require BASE_PATH . '/config/routes.php';
        }
        return $routes;
    }
}
