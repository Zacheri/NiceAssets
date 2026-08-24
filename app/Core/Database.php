<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;
    private static bool $available = true;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $cfg = Config::get('db');
        // gssencmode=disable: with the default ("prefer"), libpq probes the GSS/Kerberos
        // credential cache on connect, which segfaults PHP-FPM workers on macOS
        // (CorePreferences fork in a launchd-supervised process). This app is LAN-local.
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;gssencmode=disable', $cfg['host'], $cfg['port'], $cfg['name']);
        $attempts = 3;
        $lastError = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                return self::$pdo;
            } catch (PDOException $e) {
                $lastError = $e;
                Logger::error('Database connection failed, retrying', ['attempt' => $i + 1, 'error' => $e->getMessage()]);
                sleep(2);
            }
        }
        self::$available = false;
        throw new RuntimeException('Database unavailable: ' . $lastError->getMessage(), 0, $lastError);
    }

    public static function isConnected(): bool
    {
        return self::$pdo !== null || self::$available;
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$available = true;
    }

    private static function isConnectionError(PDOException $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'server closed the connection')
            || str_contains($msg, 'connection to server was lost')
            || str_contains($msg, 'connection refused')
            || str_contains($msg, 'ssl symlist')
            || str_contains($msg, 'could not connect')
            || str_contains($msg, 'terminating connection')
            || str_contains($msg, 'no connection to the server');
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            if (self::isConnectionError($e)) {
                Logger::error('Lost database connection mid-query, reconnecting', ['error' => $e->getMessage()]);
                self::reset();
                $stmt = self::pdo()->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            throw $e;
        }
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $value = self::query($sql, $params)->fetchColumn($column);
        return $value === false ? null : $value;
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function insert(string $sql, array $params = []): string
    {
        self::query($sql, $params);
        return self::pdo()->lastInsertId();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn(self::pdo());
        }
        $pdo->beginTransaction();
        try {
            $result = $fn(self::pdo());
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
