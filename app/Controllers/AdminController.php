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
        $host = trim((string) Request::post('host', ''));
        $external = in_array(strtolower((string) Request::post('external', '0')), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
        if ($port < 1024 || $port > 65535) {
            Response::json(['error' => 'Port must be between 1024 and 65535.'], 400);
        }
        if ($context < 2048 || $context > 32768) {
            Response::json(['error' => 'Context must be between 2048 and 32768.'], 400);
        }
        if ($host === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
            Response::json(['error' => 'Host must be an IP address or hostname (letters, digits, dots, dashes, underscores).'], 400);
        }
        $wasManaged = !LlmServer::external();
        Setting::set('llm.port', (string) $port);
        Setting::set('llm.context', (string) $context);
        Setting::set('llm.host', $host);
        Setting::set('llm.external', $external);
        if ($wasManaged) {
            LlmServer::stop();
        }
        $note = $external === '1'
            ? 'Saved. Nice Assets will now use the external server at ' . $host . ':' . $port . '.'
            : 'Saved. The model server restarts with the new settings on next use.';
        Response::json(['ok' => true, 'note' => $note]);
    }

    public function llmUpload(): void
    {
        Auth::requireLogin();
        $file = $_FILES['model'] ?? null;
        if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $code = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            $msg = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the upload limit (12 GB).',
                UPLOAD_ERR_PARTIAL => 'Upload was only partial. Try again.',
                UPLOAD_ERR_NO_FILE => 'No file received.',
                default => 'Upload failed (code ' . $code . ').',
            };
            Response::json(['error' => $msg], 400);
        }
        $name = basename((string) ($file['name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.gguf$/', $name)) {
            Response::json(['error' => 'Invalid file name. Upload a .gguf model file.'], 400);
        }
        $dir = LlmServer::modelsDir();
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            Response::json(['error' => 'Empty file.'], 400);
        }
        if (is_file($dir . '/' . $name)) {
            Response::json(['error' => 'A model with that name already exists. Delete it first.'], 400);
        }
        $free = (int) disk_free_space($dir);
        if ($size > $free) {
            Response::json(['error' => 'Not enough disk space: need ' . round($size / 1048576) . ' MB, have ' . round($free / 1048576) . ' MB.'], 400);
        }
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            Response::json(['error' => 'Could not save the uploaded file.'], 500);
        }
        Audit::log('llm.model_upload', 'llm', $name, ['size' => $size]);
        if (LlmServer::selectedModel() === null) {
            LlmServer::setSelectedModel($name);
        }
        Response::json(['ok' => true, 'model' => $name, 'size' => $size]);
    }

    public function llmDelete(): void
    {
        Auth::requireLogin();
        $name = basename((string) Request::post('model', ''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.gguf$/', $name)) {
            Response::json(['error' => 'Invalid model name.'], 400);
        }
        $dir = LlmServer::modelsDir();
        if (!is_file($dir . '/' . $name)) {
            Response::json(['error' => 'Model file not found.'], 404);
        }
        $st = LlmServer::state();
        if ($st['status'] === 'ready' && $st['model'] === $name) {
            Response::json(['error' => 'Stop the model server before deleting the loaded model.'], 400);
        }
        if (!unlink($dir . '/' . $name)) {
            Response::json(['error' => 'Could not delete the file.'], 500);
        }
        if ((string) Setting::get('llm.selected_model', '') === $name) {
            Setting::set('llm.selected_model', '');
        }
        Audit::log('llm.model_delete', 'llm', $name, []);
        Response::json(['ok' => true]);
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
