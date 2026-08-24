<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function uri(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }

    public static function path(): string
    {
        $path = parse_url(self::uri(), PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $value = $_REQUEST[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::input($key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::input($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::input($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'cli';
    }
}
