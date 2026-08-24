<?php

require __DIR__ . '/_bootstrap.php';

use App\Core\Logger;
use App\Models\Setting;
use App\Services\WeeklyReport;

if (Setting::get('weekly_report_enabled', '1') === '0') {
    echo "Weekly report job is disabled in settings. Nothing to do.\n";
    exit(0);
}

try {
    $run = WeeklyReport::generate(null);
    echo sprintf(
        "Weekly report generated: %s (v%s, %d entries)\n",
        $run['name'],
        $run['version'],
        $run['row_count']
    );
} catch (Throwable $e) {
    Logger::error('weekly_report failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'Weekly report failed: ' . $e->getMessage() . "\n");
    exit(1);
}
