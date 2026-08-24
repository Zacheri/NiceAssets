<?php

declare(strict_types=1);

$local = __DIR__ . '/app.local.php';
$base = [
    'app_version' => '1.0.0',
    'app_name' => 'ATR Inventory',
    'base_url' => '',
    'timezone' => 'America/New_York',
    'db' => [
        'host' => '127.0.0.1',
        'port' => '5432',
        'name' => 'atr',
        'user' => 'atr',
        'pass' => 'atr',
    ],
    'session' => [
        'path' => dirname(__DIR__) . '/storage/sessions',
        'name' => 'atr_session',
        'cookie_lifetime' => 43200,
    ],
    'storage' => [
        'root' => dirname(__DIR__) . '/storage',
        'uploads' => dirname(__DIR__) . '/storage/uploads',
        'backups' => dirname(__DIR__) . '/storage/backups',
        'logs' => dirname(__DIR__) . '/storage/logs',
        'reports' => dirname(__DIR__) . '/storage/reports',
        'labels' => dirname(__DIR__) . '/storage/labels',
    ],
    'mail' => [
        'from' => 'atr-inventory@localhost',
        'from_name' => 'ATR Inventory',
        'smtp_host' => '',
        'smtp_port' => 587,
        'smtp_user' => '',
        'smtp_pass' => '',
    ],
    'limits' => [
        'per_page' => 24,
        'per_page_max' => 200,
        'backup_keep' => 14,
        'photo_max_mb' => 20,
        'report_pdf_photo_rows' => 200,
    ],
    'alerts' => [
        'multi_asset_threshold' => 2,
        'warranty_windows_days' => [90, 60, 30],
    ],
];

if (is_file($local)) {
    $overrides = require $local;
    if (is_array($overrides)) {
        $base = array_replace_recursive($base, $overrides);
    }
}

return $base;
