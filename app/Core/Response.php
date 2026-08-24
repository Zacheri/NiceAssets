<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function redirect(string $to, int $status = 302): never
    {
        header('Location: ' . url($to), true, $status);
        exit;
    }

    public static function back(): never
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        self::redirect(contains_host($referer) ? $referer : (Config::get('base_url') . '/'));
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function file(string $path, string $name, string $mime): never
    {
        if (!is_file($path)) {
            http_response_code(404);
            echo 'File not found';
            exit;
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public static function raw(string $body, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $body;
        exit;
    }

    public static function notFound(string $message = 'Page not found.'): never
    {
        http_response_code(404);
        echo '<!doctype html><meta charset="utf-8"><title>404</title>'
            . '<body style="font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0;background:#0f172a;color:#e2e8f0">'
            . '<div style="text-align:center"><h1 style="font-size:64px;margin:0">404</h1><p>' . htmlspecialchars($message) . '</p>'
            . '<a href="/" style="color:#38bdf8">Back to dashboard</a></div></body>';
        exit;
    }
}
