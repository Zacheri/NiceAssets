<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Audit;
use App\Models\Category;
use App\Models\Department;
use App\Models\Location;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Backup;
use App\Services\LlmServer;
use RuntimeException;

class AdminController
{
    public function index(): void
    {
        Auth::requireLogin();
        $counts = [
            'assets' => (int) Database::fetchColumn('SELECT COUNT(*) FROM assets'),
            'users' => (int) Database::fetchColumn('SELECT COUNT(*) FROM users'),
            'persons' => (int) Database::fetchColumn('SELECT COUNT(*) FROM persons'),
            'departments' => (int) Database::fetchColumn('SELECT COUNT(*) FROM departments'),
            'sites' => (int) Database::fetchColumn('SELECT COUNT(*) FROM sites'),
            'locations' => (int) Database::fetchColumn('SELECT COUNT(*) FROM locations'),
            'categories' => (int) Database::fetchColumn('SELECT COUNT(*) FROM categories'),
            'work_orders' => (int) Database::fetchColumn("SELECT COUNT(*) FROM work_orders WHERE status <> 'completed'"),
            'audit_entries' => (int) Database::fetchColumn('SELECT COUNT(*) FROM audit_log'),
        ];
        View::output(View::render('admin/index', [
            'title' => 'Administration',
            'counts' => $counts,
        ]));
    }

    public function users(): void
    {
        Auth::requireLogin();
        View::output(View::render('admin/users', [
            'title' => 'Users',
            'users' => User::all(),
            'roles' => User::roles(),
            'departments' => Department::active(),
        ]));
    }

    public function userStore(): void
    {
        $user = Auth::requireLogin();
        try {
            User::store(Request::post('user') ?? [], $user);
            Auth::flash('success', 'User created.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/users');
    }

    public function userUpdate(string $id): void
    {
        $user = Auth::requireLogin();
        try {
            User::update((int) $id, Request::post('user') ?? [], $user);
            Auth::flash('success', 'User updated.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/users');
    }

    public function userDelete(string $id): void
    {
        $user = Auth::requireLogin();
        try {
            User::delete((int) $id, $user);
            Auth::flash('success', 'User deleted.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/users');
    }

    public function departments(): void
    {
        Auth::requireLogin();
        View::output(View::render('admin/departments', [
            'title' => 'Departments',
            'departments' => Department::all(),
        ]));
    }

    public function deptStore(): void
    {
        try {
            Department::store(Request::post('department') ?? []);
            Auth::flash('success', 'Department added.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/departments');
    }

    public function deptDelete(string $id): void
    {
        try {
            Department::delete((int) $id);
            Auth::flash('success', 'Department deleted.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/departments');
    }

    public function sites(): void
    {
        Auth::requireLogin();
        View::output(View::render('admin/sites', [
            'title' => 'Sites',
            'sites' => Site::all(),
        ]));
    }

    public function siteStore(): void
    {
        try {
            Site::store(Request::post('site') ?? []);
            Auth::flash('success', 'Site added.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/sites');
    }

    public function siteDelete(string $id): void
    {
        try {
            Site::delete((int) $id);
            Auth::flash('success', 'Site deleted.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/sites');
    }

    public function locations(): void
    {
        Auth::requireLogin();
        View::output(View::render('admin/locations', [
            'title' => 'Locations',
            'locations' => Location::all(),
            'sites' => Site::active(),
        ]));
    }

    public function locationStore(): void
    {
        try {
            Location::store(Request::post('location') ?? []);
            Auth::flash('success', 'Location added.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/locations');
    }

    public function locationDelete(string $id): void
    {
        try {
            Location::delete((int) $id);
            Auth::flash('success', 'Location deleted.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/locations');
    }

    public function categories(): void
    {
        Auth::requireLogin();
        View::output(View::render('admin/categories', [
            'title' => 'Categories',
            'categories' => Category::all(),
        ]));
    }

    public function categoryStore(): void
    {
        try {
            Category::store(Request::post('category') ?? []);
            Auth::flash('success', 'Category added.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/categories');
    }

    public function categoryUpdate(string $id): void
    {
        try {
            Category::update((int) $id, Request::post('category') ?? []);
            Auth::flash('success', 'Category updated.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/categories');
    }

    public function categoryDelete(string $id): void
    {
        try {
            Category::delete((int) $id);
            Auth::flash('success', 'Category deleted.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/categories');
    }

    public function settings(): void
    {
        Auth::requireLogin();
        View::output(View::render('admin/settings', [
            'title' => 'Settings',
            'settings' => [
                'mail_from' => Setting::get('mail_from', Config::get('mail.from')),
                'smtp_host' => Setting::get('smtp_host', ''),
                'smtp_port' => Setting::get('smtp_port', Config::get('mail.smtp_port')),
                'smtp_user' => Setting::get('smtp_user', ''),
                'smtp_pass' => Setting::get('smtp_pass', ''),
                'smtp_secure' => Setting::get('smtp_secure', 'tls'),
                'email_role_admin' => Setting::get('email_role_admin', '1'),
                'email_role_department_manager' => Setting::get('email_role_department_manager', '1'),
                'email_role_viewer' => Setting::get('email_role_viewer', '0'),
                'weekly_report_enabled' => Setting::get('weekly_report_enabled', '1'),
                'multi_asset_threshold' => Setting::get('multi_asset_threshold', '2'),
            ],
        ]));
    }

    public function settingsUpdate(): void
    {
        $s = Request::post('settings') ?? [];
        Setting::setMany([
            'mail_from' => (string) ($s['mail_from'] ?? ''),
            'smtp_host' => (string) ($s['smtp_host'] ?? ''),
            'smtp_port' => (string) (int) ($s['smtp_port'] ?? 587),
            'smtp_user' => (string) ($s['smtp_user'] ?? ''),
            'smtp_pass' => (string) ($s['smtp_pass'] ?? ''),
            'smtp_secure' => (string) ($s['smtp_secure'] ?? 'tls'),
            'email_role_admin' => empty($s['email_role_admin']) ? '0' : '1',
            'email_role_department_manager' => empty($s['email_role_department_manager']) ? '0' : '1',
            'email_role_viewer' => empty($s['email_role_viewer']) ? '0' : '1',
            'weekly_report_enabled' => empty($s['weekly_report_enabled']) ? '0' : '1',
            'multi_asset_threshold' => (string) max(2, (int) ($s['multi_asset_threshold'] ?? 2)),
        ]);
        Auth::flash('success', 'Settings saved.');
        Response::redirect('/admin/settings');
    }

    public function audit(): void
    {
        Auth::requireLogin();
        $f = [
            'user' => (string) Request::get('user', ''),
            'action' => (string) Request::get('action', ''),
            'entity' => (string) Request::get('entity', ''),
            'from' => (string) Request::get('from', ''),
            'to' => (string) Request::get('to', ''),
        ];
        $result = Audit::query($f, max(1, Request::int('page', 1)), 40);
        View::output(View::render('admin/audit', [
            'title' => 'Audit Log',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => max(1, (int) ceil($result['total'] / 40)),
            'filters' => $f,
            'actions' => Audit::distinctActions(),
        ]));
    }

    public function backups(): void
    {
        Auth::requireLogin();
        View::output(View::render('admin/backups', [
            'title' => 'Backups',
            'backups' => Backup::all(),
        ]));
    }

    public function backupRun(): void
    {
        try {
            Backup::run('manual');
            Auth::flash('success', 'Backup completed.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/backups');
    }

    public function backupRestore(string $id): void
    {
        try {
            Backup::restore($id);
            Auth::flash('success', 'Restore completed.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/backups');
    }

    public function backupDownload(string $id): void
    {
        $file = Config::get('storage.backups') . '/' . basename($id) . '.dump';
        if (!is_file($file)) {
            Response::notFound('Backup not found.');
        }
        Response::file($file, basename($file), 'application/octet-stream');
    }

    public function system(): void
    {
        Auth::requireLogin();
        View::output(View::render('admin/system', [
            'title' => 'System',
            'version' => Config::get('app_version'),
            'php' => PHP_VERSION,
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'postgres' => (string) Database::fetchColumn('SELECT version()'),
            'db_size' => (string) Database::fetchColumn(
                'SELECT pg_size_pretty(pg_database_size(:d))',
                ['d' => Config::get('db.name')]
            ),
            'storage' => [
                'uploads' => $this->dirSize(Config::get('storage.uploads')),
                'backups' => $this->dirSize(Config::get('storage.backups')),
                'reports' => $this->dirSize(Config::get('storage.reports')),
            ],
            'llm' => [
                'state' => LlmServer::state(),
                'port' => LlmServer::port(),
                'context' => LlmServer::context(),
                'models_dir' => LlmServer::modelsDir(),
            ],
        ]));
    }

    public function llmState(): void
    {
        Auth::requireLogin();
        Response::json(LlmServer::state());
    }

    public function llmSelect(): void
    {
        Auth::requireLogin();
        $file = basename((string) Request::post('model', ''));
        if ($file === '' || !is_file(LlmServer::modelsDir() . '/' . $file)) {
            Response::json(['error' => 'Model file not found.'], 400);
        }
        LlmServer::setSelectedModel($file);
        Response::json(['ok' => true]);
    }

    public function llmStart(): void
    {
        Auth::requireLogin();
        try {
            LlmServer::start();
        } catch (RuntimeException $e) {
            Response::json(['error' => $e->getMessage()], 500);
        }
        Response::json(['ok' => true, 'note' => 'Model is loading. Replies may be slower until it is ready.']);
    }

    public function llmStop(): void
    {
        Auth::requireLogin();
        LlmServer::stop();
        Response::json(['ok' => true]);
    }

    public function llmConfig(): void
    {
        Auth::requireLogin();
        $port = (int) Request::post('port', 0);
        $context = (int) Request::post('context', 0);
        if ($port < 1024 || $port > 65535) {
            Response::json(['error' => 'Port must be between 1024 and 65535.'], 400);
        }
        if ($context < 2048 || $context > 32768) {
            Response::json(['error' => 'Context must be between 2048 and 32768.'], 400);
        }
        Setting::set('llm.port', (string) $port);
        Setting::set('llm.context', (string) $context);
        LlmServer::stop();
        Response::json(['ok' => true, 'note' => 'Saved. The model server restarts with the new settings on next use.']);
    }

    public function llmInstall(): void
    {
        Auth::requireLogin();
        $res = LlmServer::install();
        if (!$res['ok']) {
            Response::json(['error' => 'llama.cpp install failed. Try: brew install llama.cpp', 'output' => substr((string) $res['output'], 0, 2000)], 500);
        }
        Response::json(['ok' => true, 'message' => 'llama.cpp installed.', 'output' => substr((string) $res['output'], 0, 2000)]);
    }

    private function dirSize(string $dir): string
    {
        $size = 0;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                $size += filesize($file);
            }
        }
        return match (true) {
            $size > 1048576 => round($size / 1048576, 1) . ' MB',
            $size > 1024 => round($size / 1024, 1) . ' KB',
            default => $size . ' B',
        };
    }
}
