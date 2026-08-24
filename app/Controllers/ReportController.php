<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Department;
use App\Models\Location;
use App\Models\ReportRun;
use App\Models\Site;
use App\Services\AlertEngine;
use App\Services\Depreciation;
use App\Services\Export;

class ReportController
{
    public const TYPES = [
        'inventory' => ['name' => 'Inventory Count', 'description' => 'Complete asset listing with all details', 'photos' => true],
        'low_stock' => ['name' => 'Low Stock Alerts', 'description' => 'Categories below their configured thresholds', 'photos' => false],
        'depreciation' => ['name' => 'Depreciation Report', 'description' => 'Current depreciated values and remaining life', 'photos' => true],
        'assignment' => ['name' => 'User Assignment Report', 'description' => 'Assets assigned to each user or department', 'photos' => false],
        'duration' => ['name' => 'Assignment Duration', 'description' => 'How long assets have been checked out', 'photos' => true],
        'multi_asset' => ['name' => 'Multi-Asset Alert', 'description' => 'Users holding multiple similar assets', 'photos' => false],
    ];

    public function index(): void
    {
        $user = Auth::requireLogin();
        $runs = ReportRun::list('', 15);
        View::output(View::render('reports/index', [
            'title' => 'Reports',
            'types' => self::TYPES,
            'runs' => $runs,
        ]));
    }

    public function run(string $type): void
    {
        if (!isset(self::TYPES[$type]) && $type !== 'custom') {
            Response::notFound('Unknown report type.');
        }
        $user = Auth::requireLogin();
        $filters = $this->readFilters();
        [$columns, $rows, $extra] = $this->build($type, $filters, $user);

        $meta = $type === 'custom'
            ? ['name' => 'Custom Report', 'description' => 'User-defined report', 'photos' => false]
            : self::TYPES[$type];

        $name = $meta['name'] . ($type === 'custom' ? ' (Custom)' : '');
        $filePdf = Export::pdf($name, $type, $columns, $rows, [
            'photos' => !empty($meta['photos']),
            'total_rows' => count($rows),
        ]);
        $fileExcel = Export::excel($name, $type, $columns, $rows, (bool) Request::bool('formulas', false));

        $id = ReportRun::store(
            $name,
            $type,
            array_merge($filters, ['formulas' => (bool) Request::bool('formulas', false)]),
            $filePdf,
            $fileExcel,
            count($rows),
            ['generated_by' => $user['username'], 'extra' => $extra],
            $user
        );
        $run = ReportRun::find($id);

        View::output(View::render('reports/result', [
            'title' => $name,
            'type' => $type,
            'meta' => $meta,
            'columns' => $columns,
            'rows' => array_slice($rows, 0, 200),
            'totalRows' => count($rows),
            'run' => $run,
            'filters' => $filters,
            'categories' => Category::active(),
            'departments' => Department::active(),
            'sites' => Site::active(),
            'locationsBySite' => Location::activeBySite(),
            'showCustomForm' => $type === 'custom',
        ]));
    }

    public function export(string $type): void
    {
        $user = Auth::requireLogin();
        if (!isset(self::TYPES[$type]) && $type !== 'custom') {
            Response::notFound('Unknown report type.');
        }
        $format = (string) Request::get('format', 'pdf');
        $filters = $this->readFilters();
        [$columns, $rows, $extra] = $this->build($type, $filters, $user);
        $meta = $type === 'custom' ? ['photos' => false] : self::TYPES[$type];
        $name = ($type === 'custom' ? 'Custom Report' : $meta['name']);

        if ($format === 'excel' || $format === 'xlsx') {
            $file = Export::excel($name, $type, $columns, $rows, (bool) Request::bool('formulas', false));
            Response::file($file, $name . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        $file = Export::pdf($name, $type, $columns, $rows, ['photos' => !empty($meta['photos']), 'total_rows' => count($rows)]);
        Response::file($file, $name . '.pdf', 'application/pdf');
    }

    public function weekly(): void
    {
        Auth::requireLogin();
        $runs = ReportRun::list('weekly', 20);
        View::output(View::render('reports/weekly', [
            'title' => 'Weekly Reports',
            'runs' => $runs,
        ]));
    }

    public function weeklyGenerate(): void
    {
        $user = Auth::requireLogin();
        $run = \App\Services\WeeklyReport::generate($user);
        Auth::flash('success', 'Weekly report generated (version ' . $run['version'] . ').');
        Response::redirect('/reports/weekly');
    }

    public function download(string $runId): void
    {
        Auth::requireLogin();
        $run = ReportRun::find((int) $runId);
        if ($run === null) {
            Response::notFound('Report run not found.');
        }
        $format = (string) Request::get('format', 'pdf');
        $file = $format === 'excel' ? $run['file_excel'] : $run['file_pdf'];
        if ($file === null || !is_file($file)) {
            Auth::flash('error', 'That report file is no longer on disk.');
            Response::redirect('/reports');
        }
        Response::file($file, basename($file), $format === 'excel'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/pdf');
    }

    private function readFilters(): array
    {
        return [
            'category_id' => Request::get('category_id', ''),
            'department_id' => Request::get('department_id', ''),
            'site_id' => Request::get('site_id', ''),
            'location_id' => Request::get('location_id', ''),
            'model' => Request::get('model', ''),
            'brand' => Request::get('brand', ''),
            'status' => Request::get('status', ''),
            'q' => Request::get('q', ''),
        ];
    }

    private function build(string $type, array $f, array $user): array
    {
        switch ($type) {
            case 'low_stock':
                $rows = [];
                foreach (Asset::availableQtyByCategory() as $c) {
                    if ((int) $c['qty'] < (int) $c['low_stock_threshold']) {
                        $rows[] = [
                            'category' => $c['name'],
                            'available' => (int) $c['qty'],
                            'threshold' => (int) $c['low_stock_threshold'],
                            'shortfall' => (int) $c['low_stock_threshold'] - (int) $c['qty'],
                        ];
                    }
                }
                $columns = [
                    ['key' => 'category', 'label' => 'Category'],
                    ['key' => 'available', 'label' => 'Available Qty', 'type' => 'number'],
                    ['key' => 'threshold', 'label' => 'Threshold', 'type' => 'number'],
                    ['key' => 'shortfall', 'label' => 'Shortfall', 'type' => 'number'],
                ];
                return [$columns, $rows, []];

            case 'depreciation':
                [$scope, $params] = Auth::scopeWhere('a');
                $where = "a.status NOT IN ('disposed','sold','donated') AND a.purchase_date IS NOT NULL";
                if (!empty($f['category_id'])) {
                    $where .= ' AND a.category_id = :cat';
                    $params['cat'] = $f['category_id'];
                }
                $raw = Database::fetchAll(
                    "SELECT a.id, a.asset_tag, a.brand, a.model_number, c.name AS category_name,
                            a.purchase_date, a.purchase_cost,
                            (SELECT p.filename FROM asset_photos ap JOIN photos p ON p.id = ap.photo_id
                              WHERE ap.asset_id = a.id ORDER BY (ap.is_thumbnail = false) ASC, ap.position ASC LIMIT 1) AS thumb
                     FROM assets a LEFT JOIN categories c ON c.id = a.category_id
                     WHERE {$where} {$scope}
                     ORDER BY a.purchase_date ASC",
                    $params
                );
                $rows = [];
                foreach ($raw as $r) {
                    $dep = Depreciation::calc((float) $r['purchase_cost'], $r['purchase_date']);
                    $rows[] = [
                        'thumb' => $r['thumb'],
                        'tag' => $r['asset_tag'],
                        'brand' => $r['brand'],
                        'model' => $r['model_number'],
                        'category' => $r['category_name'],
                        'purchase_date' => $r['purchase_date'],
                        'purchase_cost' => (float) $r['purchase_cost'],
                        'annual' => $dep['annual'],
                        'current_value' => $dep['value'],
                        'remaining_months' => $dep['remaining_months'],
                        'fully' => $dep['fully'] ? 'Yes' : 'No',
                    ];
                }
                $columns = [
                    ['key' => 'tag', 'label' => 'Asset Tag'],
                    ['key' => 'brand', 'label' => 'Brand'],
                    ['key' => 'model', 'label' => 'Model'],
                    ['key' => 'category', 'label' => 'Category'],
                    ['key' => 'purchase_date', 'label' => 'Purchase Date', 'type' => 'date'],
                    ['key' => 'purchase_cost', 'label' => 'Purchase Cost', 'type' => 'money'],
                    ['key' => 'annual', 'label' => 'Annual Depreciation', 'type' => 'money'],
                    ['key' => 'current_value', 'label' => 'Current Value', 'type' => 'money'],
                    ['key' => 'remaining_months', 'label' => 'Months Remaining', 'type' => 'number'],
                    ['key' => 'fully', 'label' => 'Fully Depreciated'],
                ];
                return [$columns, $rows, []];

            case 'assignment':
                [$scope, $params] = Auth::scopeWhere('a');
                $rows = Database::fetchAll(
                    "SELECT COALESCE(au.full_name, ad.name) AS holder,
                            CASE WHEN au.id IS NOT NULL THEN 'Person' ELSE 'Department' END AS holder_type,
                            COUNT(a.id)::int AS asset_count,
                            COALESCE(SUM(a.purchase_cost), 0)::numeric AS total_cost,
                            COALESCE(SUM(" . Asset::VALUE_SQL . "), 0)::numeric AS current_value,
                            string_agg(DISTINCT c.name, ', ' ORDER BY c.name) AS categories
                     FROM assets a
                     LEFT JOIN persons au ON au.id = a.assigned_to_person_id
                     LEFT JOIN departments ad ON ad.id = a.assigned_to_department_id
                     LEFT JOIN categories c ON c.id = a.category_id
                     WHERE a.status = 'checked_out' AND (au.id IS NOT NULL OR ad.id IS NOT NULL) {$scope}
                     GROUP BY CASE WHEN au.id IS NOT NULL THEN au.id ELSE ad.id END, au.full_name, ad.name
                     ORDER BY asset_count DESC, holder",
                    $params
                );
                $columns = [
                    ['key' => 'holder', 'label' => 'Assigned To'],
                    ['key' => 'holder_type', 'label' => 'Type'],
                    ['key' => 'asset_count', 'label' => 'Assets', 'type' => 'number'],
                    ['key' => 'total_cost', 'label' => 'Total Cost', 'type' => 'money'],
                    ['key' => 'current_value', 'label' => 'Current Value', 'type' => 'money'],
                    ['key' => 'categories', 'label' => 'Categories'],
                ];
                return [$columns, $rows, []];

            case 'duration':
                [$scope, $params] = Auth::scopeWhere('a');
                $raw = Database::fetchAll(
                    "SELECT a.id, a.asset_tag, a.brand, a.model_number, c.name AS category_name,
                            au.full_name AS holder,
                            COALESCE((SELECT MAX(al2.created_at) FROM audit_log al2
                                      WHERE al2.entity = 'asset' AND al2.entity_id = a.id::text
                                        AND al2.action IN ('asset.check_out','asset.transfer','asset.create')),
                                    a.created_at) AS checked_out_at
                      FROM assets a
                      LEFT JOIN persons au ON au.id = a.assigned_to_person_id
                      LEFT JOIN categories c ON c.id = a.category_id
                      WHERE a.status = 'checked_out' {$scope}
                     ORDER BY checked_out_at ASC",
                    $params
                );
                $rows = [];
                foreach ($raw as $r) {
                    $start = strtotime((string) $r['checked_out_at']);
                    $days = max(0, (int) floor((time() - $start) / 86400));
                    $rows[] = [
                        'tag' => $r['asset_tag'],
                        'brand' => $r['brand'],
                        'model' => $r['model_number'],
                        'category' => $r['category_name'],
                        'holder' => $r['holder'] ?? 'Unassigned',
                        'checked_out_at' => date('Y-m-d', $start),
                        'days' => $days,
                        'due_date' => null,
                        'overdue' => '',
                    ];
                }
                $columns = [
                    ['key' => 'tag', 'label' => 'Asset Tag'],
                    ['key' => 'brand', 'label' => 'Brand'],
                    ['key' => 'model', 'label' => 'Model'],
                    ['key' => 'category', 'label' => 'Category'],
                    ['key' => 'holder', 'label' => 'Assigned To'],
                    ['key' => 'checked_out_at', 'label' => 'Checked Out', 'type' => 'date'],
                    ['key' => 'days', 'label' => 'Days Checked Out', 'type' => 'number'],
                ];
                return [$columns, $rows, []];

            case 'multi_asset':
                $threshold = (int) \App\Models\Setting::get('multi_asset_threshold', 2);
                $rows = Asset::multiAssignments($threshold, $user);
                $columns = [
                    ['key' => 'full_name', 'label' => 'User'],
                    ['key' => 'brand', 'label' => 'Brand'],
                    ['key' => 'model_number', 'label' => 'Model'],
                    ['key' => 'count', 'label' => 'Count', 'type' => 'number'],
                    ['key' => 'tags', 'label' => 'Asset Tags'],
                ];
                return [$columns, $rows, ['threshold' => $threshold]];

            case 'custom':
            case 'inventory':
            default:
                $searchFilters = [
                    'q' => (string) ($f['q'] ?? ''),
                    'category_id' => (int) ($f['category_id'] ?? 0),
                    'department_id' => (int) ($f['department_id'] ?? 0),
                    'site_id' => (int) ($f['site_id'] ?? 0),
                    'location_id' => (int) ($f['location_id'] ?? 0),
                    'brand' => (string) ($f['brand'] ?? ''),
                    'model' => (string) ($f['model'] ?? ''),
                    'status' => (string) ($f['status'] ?? ''),
                ];
                $result = Asset::search($searchFilters, 1, 100000, $user);
                $rows = [];
                foreach ($result['rows'] as $r) {
                    $dep = Depreciation::calc((float) $r['purchase_cost'], $r['purchase_date']);
                    $rows[] = [
                        'thumb' => $r['thumb'],
                        'tag' => $r['asset_tag'],
                        'serial' => $r['serial_number'] ?? '',
                        'model' => $r['model_number'] ?? '',
                        'brand' => $r['brand'] ?? '',
                        'category' => $r['category_name'] ?? '',
                        'department' => $r['department_name'] ?? '',
                        'site' => $r['site_name'] ?? '',
                        'location' => $r['location_name'] ?? '',
                        'status' => status_label($r['status']),
                        'holder' => $r['assigned_name'] ?? ($r['assigned_dept_name'] ?? ''),
                        'purchase_date' => $r['purchase_date'],
                        'purchase_cost' => (float) $r['purchase_cost'],
                        'current_value' => $dep['value'],
                        'warranty' => $r['warranty_expiration'],
                        'due_date' => $r['due_date'],
                        'sub_quantity' => (int) $r['sub_quantity'],
                        'created' => date('Y-m-d', strtotime((string) $r['created_at'])),
                    ];
                }
                $columns = [
                    ['key' => 'tag', 'label' => 'Asset Tag'],
                    ['key' => 'serial', 'label' => 'Serial'],
                    ['key' => 'model', 'label' => 'Model'],
                    ['key' => 'brand', 'label' => 'Brand'],
                    ['key' => 'category', 'label' => 'Category'],
                    ['key' => 'department', 'label' => 'Department'],
                    ['key' => 'site', 'label' => 'Site'],
                    ['key' => 'location', 'label' => 'Location'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'holder', 'label' => 'Assigned To'],
                    ['key' => 'purchase_date', 'label' => 'Purchase Date', 'type' => 'date'],
                    ['key' => 'purchase_cost', 'label' => 'Purchase Cost', 'type' => 'money'],
                    ['key' => 'current_value', 'label' => 'Current Value', 'type' => 'money'],
                    ['key' => 'warranty', 'label' => 'Warranty', 'type' => 'date'],
                    ['key' => 'due_date', 'label' => 'Due Date', 'type' => 'date'],
                    ['key' => 'sub_quantity', 'label' => 'Qty', 'type' => 'number'],
                    ['key' => 'created', 'label' => 'Created', 'type' => 'date'],
                ];
                if ($type === 'custom') {
                    $visible = array_filter(array_map('trim', explode(',', (string) Request::get('columns', ''))));
                    if ($visible !== []) {
                        $columns = array_values(array_filter($columns, static fn ($c) => in_array($c['key'], $visible, true)));
                    }
                }
                return [$columns, $rows, []];
        }
    }
}
