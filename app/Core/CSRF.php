<?php

declare(strict_types=1);

namespace App\Core;

final class CSRF
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validate(?string $token): bool
    {
        $session = $_SESSION['csrf_token'] ?? '';
        return is_string($token) && $token !== '' && hash_equals($session, $token);
    }
}
