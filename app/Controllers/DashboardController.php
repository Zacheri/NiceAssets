<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Models\Asset;
use App\Models\Audit;
use App\Services\AlertEngine;

class DashboardController
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        $stats = Asset::stats($user);
        $alerts = AlertEngine::dashboard($user);
        $activity = Audit::recent(12, $user);

        $categoryRows = Database::fetchAll(
            "SELECT COALESCE(c.name, 'Uncategorized') AS name, COUNT(a.id)::int AS count
             FROM assets a LEFT JOIN categories c ON c.id = a.category_id
             WHERE 1=1 " . Auth::scopeWhere('a')[0] . "
             GROUP BY COALESCE(c.name, 'Uncategorized')
             ORDER BY count DESC LIMIT 8",
            Auth::scopeWhere('a')[1]
        );

        $statusRows = [];
        foreach ($stats['by_status'] as $row) {
            $statusRows[] = ['name' => status_label($row['status']), 'count' => (int) $row['count']];
        }

        View::output(View::render('dashboard/index', [
            'title' => 'Dashboard',
            'stats' => $stats,
            'alerts' => $alerts,
            'activity' => $activity,
            'category_chart' => $categoryRows,
            'status_chart' => $statusRows,
            'important_count' => count(array_filter($alerts, static fn ($a) => $a['severity'] === 'important')),
        ]));
    }
}
