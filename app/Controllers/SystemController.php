<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

class SystemController
{
    public function healthz(): void
    {
        $db = 'ok';
        try {
            $n = (int) Database::fetchColumn('SELECT COUNT(*) FROM assets');
        } catch (\Throwable $e) {
            $db = 'error';
        }
        Response::json([
            'status' => $db === 'ok' ? 'ok' : 'degraded',
            'app' => Config::get('app_name'),
            'version' => Config::get('app_version'),
            'database' => $db,
            'assets' => $db === 'ok' ? $n : null,
            'time' => date('c'),
        ], $db === 'ok' ? 200 : 503);
    }
}
