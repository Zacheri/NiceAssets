<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class App
{
    public static function run(): void
    {
        self::bootstrap();
        try {
            self::dispatch();
        } catch (Throwable $e) {
            Logger::error('Unhandled exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => array_map(static fn (array $f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? '?'), array_slice($e->getTrace(), 0, 8)),
            ]);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<!doctype html><meta charset="utf-8"><title>500</title>'
                . '<body style="font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0;background:#0f172a;color:#e2e8f0">'
                . '<div style="text-align:center"><h1 style="font-size:64px;margin:0">500</h1>'
                . '<p>Something went wrong. The error was logged.</p>'
                . '<a href="/" style="color:#38bdf8">Back to dashboard</a></div></body>';
        }
    }

    private static function bootstrap(): void
    {
        date_default_timezone_set((string) Config::get('timezone'));
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('memory_limit', '512M');

        $sessionPath = (string) Config::get('session.path');
        if (!is_dir($sessionPath)) {
            @mkdir($sessionPath, 0775, true);
        }
        session_save_path($sessionPath);
        session_name((string) Config::get('session.name'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }

    private static function dispatch(): void
    {
        $method = Request::method();
        $path = Request::path();

        foreach (Config::routes() as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route['path']) . '$#';
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            self::handle($route, $params);
            return;
        }
        Response::notFound('The page ' . e($path) . ' does not exist.');
    }

    private static function handle(array $route, array $params): void
    {
        $authRequired = $route['auth'] ?? true;
        $user = $authRequired ? Auth::requireLogin() : Auth::user();

        $requiredRoles = $route['roles'] ?? null;
        if ($requiredRoles !== null) {
            if ($user === null) {
                $_SESSION['intended'] = Request::uri();
                Response::redirect('/login');
            }
            if (!in_array($user['role_name'], $requiredRoles, true)) {
                self::forbidden();
            }
        }

        if (Request::method() === 'POST' && !CSRF::validate(Request::post('_token'))) {
            Auth::flash('error', 'Your session token was invalid or expired. Please try again.');
            Response::back();
        }

        $class = 'App\\Controllers\\' . $route['controller'] . 'Controller';
        if (!class_exists($class)) {
            self::forbidden('Unknown controller.');
        }
        $instance = new $class();
        $action = $route['action'];
        if (!method_exists($instance, $action)) {
            self::forbidden('Unknown action.');
        }
        $instance->{$action}(...array_values($params));
    }

    private static function forbidden(string $message = 'You do not have permission to perform this action.'): void
    {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>403</title>'
            . '<body style="font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0;background:#0f172a;color:#e2e8f0">'
            . '<div style="text-align:center"><h1 style="font-size:64px;margin:0">403</h1>'
            . '<p>' . e($message) . '</p>'
            . '<a href="' . e(url('/')) . '" style="color:#38bdf8">Back to dashboard</a></div></body>';
        exit;
    }
}
