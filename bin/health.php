<?php

require __DIR__ . '/_bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$checks = [];
$fail = 0;

try {
    $assets = (int) Database::fetchColumn('SELECT COUNT(*) FROM assets');
    $checks['database'] = "ok ({$assets} assets)";
} catch (Throwable $e) {
    $checks['database'] = 'FAIL: ' . $e->getMessage();
    $fail = 1;
}

$dirs = [
    'storage:uploads' => Config::get('storage.uploads'),
    'storage:backups' => Config::get('storage.backups'),
    'storage:logs' => Config::get('storage.logs'),
    'storage:reports' => Config::get('storage.reports'),
    'storage:labels' => Config::get('storage.labels'),
    'storage:sessions' => Config::get('session.path'),
];
foreach ($dirs as $name => $dir) {
    $dir = (string) $dir;
    $checks[$name] = is_dir($dir) && is_writable($dir) ? 'ok' : 'FAIL: not writable (' . $dir . ')';
    if ($checks[$name] !== 'ok') {
        $fail = 1;
    }
}

$checks['php'] = PHP_VERSION . ' (' . (extension_loaded('pdo_pgsql') ? 'pdo_pgsql ok' : 'pdo_pgsql MISSING') . ')';
if (!extension_loaded('pdo_pgsql')) {
    $fail = 1;
}
$checks['version'] = (string) Config::get('app_version');

foreach ($checks as $name => $result) {
    printf("%-18s %s\n", $name . ':', $result);
}
exit($fail);
