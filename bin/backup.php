<?php

require __DIR__ . '/_bootstrap.php';

use App\Core\Logger;
use App\Services\Backup;

try {
    $result = Backup::run($argv[1] ?? 'cli');
    echo 'Backup complete: ' . $result['dump'] . "\n";
    if (!empty($result['uploads'])) {
        echo 'Uploads archive: ' . $result['uploads'] . "\n";
    }
} catch (Throwable $e) {
    Logger::error('backup failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
