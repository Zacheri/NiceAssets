<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>ATR Inventory</title>'
        . '<body style="font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0;background:#0f172a;color:#e2e8f0">'
        . '<div style="text-align:center;max-width:480px"><h1>ATR Inventory</h1>'
        . '<p>Dependencies are not installed yet.</p>'
        . '<p>Run from the project folder:<br><code style="background:#1e293b;padding:4px 10px;border-radius:6px">composer install</code></p>'
        . '<p>or re-run the installer: <code style="background:#1e293b;padding:4px 10px;border-radius:6px">./install/install.sh</code></p></div></body>';
    exit;
}

require $autoload;

App\Core\App::run();
