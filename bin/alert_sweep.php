<?php

require __DIR__ . '/_bootstrap.php';

use App\Core\Logger;
use App\Services\AlertEngine;

try {
    $sent = AlertEngine::sweep();
    if ($sent === []) {
        echo 'Alert sweep: no new alerts.\n';
    } else {
        echo 'Alert sweep: ' . count($sent) . " alert(s) processed.\n";
        foreach ($sent as $item) {
            echo ' - ' . $item['type']
                . (isset($item['asset']) ? ': ' . $item['asset'] : '')
                . (isset($item['category']) ? ': ' . $item['category'] : '')
                . (isset($item['user']) ? ': ' . $item['user'] : '')
                . ($item['email'] === false ? ' (dashboard only)' : ($item['email'] ? ' (emailed)' : ''))
                . "\n";
        }
    }
} catch (Throwable $e) {
    Logger::error('alert_sweep failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'Alert sweep failed: ' . $e->getMessage() . "\n");
    exit(1);
}
