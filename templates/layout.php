<?php
/** @var array $user */
/** @var array $flash */
/** @var string $content */
/** @var string $title */
/** @var string $app_name */
/** @var string $app_version */
/** @var string $page */
$nav_active = $page ?? '';
$naimsPersons = empty($user) ? [] : \App\Models\Person::picker();
$naimsDepts = empty($user) ? [] : \App\Models\Department::active();
$icon = static function (string $paths): string {
    return '<svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($app_name) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
<link rel="icon" href="<?= e(asset_url('img/favicon.svg')) ?>">
<script>
window.NAIMS = {
  token: '<?= e(\App\Core\CSRF::token()) ?>',
  base: '<?= e(url('')) ?>',
  persons: <?= json_encode(array_map(static fn ($p) => ['id' => (int) $p['id'], 'name' => $p['full_name']], $naimsPersons), JSON_UNESCAPED_SLASHES) ?>,
  departments: <?= json_encode(array_map(static fn ($d) => ['id' => (int) $d['id'], 'name' => $d['name']], $naimsDepts), JSON_UNESCAPED_SLASHES) ?>
};
</script>
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-mark">N</div>
      <div>
        <div class="brand-name"><?= e($app_name) ?></div>
        <div class="brand-sub">v<?= e($app_version) ?></div>
      </div>
    </div>
    <nav class="nav">
      <a href="<?= e(url('/')) ?>" class="<?= $nav_active === 'dashboard/index' ? 'active' : '' ?>">
        <?= $icon('<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>') ?> Dashboard
      </a>
      <a href="<?= e(url('/assets')) ?>" class="<?= str_starts_with($nav_active, 'assets') ? 'active' : '' ?>">
        <?= $icon('<path d="M12 2 3 7v10l9 5 9-5V7l-9-5z"/><path d="M3 7l9 5 9-5M12 22V12"/>') ?> Assets
      </a>
      <a href="<?= e(url('/work-orders')) ?>" class="<?= str_starts_with($nav_active, 'work_orders') ? 'active' : '' ?>">
        <?= $icon('<path d="M14.7 6.3a4.5 4.5 0 0 0-6 5.6L3 17.6V21h3.4l5.7-5.7a4.5 4.5 0 0 0 5.6-6L14.6 12l-2.6-2.6 2.7-3.1z"/>') ?> Work Orders
      </a>
      <a href="<?= e(url('/photos')) ?>" class="<?= str_starts_with($nav_active, 'photos') ? 'active' : '' ?>">
        <?= $icon('<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.5"/><path d="M21 16l-5-5-9 9"/>') ?> Photos
      </a>
      <a href="<?= e(url('/reports')) ?>" class="<?= str_starts_with($nav_active, 'reports') ? 'active' : '' ?>">
        <?= $icon('<path d="M5 20V12M11 20V5M17 20v-6M3 20h18"/>') ?> Reports
      </a>
      <a href="<?= e(url('/assistant')) ?>" class="<?= str_starts_with($nav_active, 'assistant') ? 'active' : '' ?>">
        <?= $icon('<path d="M12 3a7 7 0 0 0-7 7c0 2.4 1.2 4.4 3 5.7V18a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2v-2.3c1.8-1.3 3-3.3 3-5.7a7 7 0 0 0-7-7z"/><path d="M9.5 10.5h.01M14.5 10.5h.01M9 13.5c.9.8 5.1.8 6 0"/>') ?> Assistant
      </a>
      <?php if (($user['role_name'] ?? '') === 'admin'): ?>
        <div class="nav-section">Admin</div>
        <a href="<?= e(url('/admin/users')) ?>" class="<?= $nav_active === 'admin/users' ? 'active' : '' ?>">
          <?= $icon('<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M21.5 20a6.5 6.5 0 0 0-4.5-6.2"/>') ?> Users
        </a>
        <a href="<?= e(url('/admin/persons')) ?>" class="<?= str_starts_with($nav_active, 'admin/persons') ? 'active' : '' ?>">
          <?= $icon('<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>') ?> Persons
        </a>
        <a href="<?= e(url('/admin/departments')) ?>" class="<?= $nav_active === 'admin/departments' ? 'active' : '' ?>">
          <?= $icon('<rect x="5" y="3" width="14" height="18" rx="1.5"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2"/>') ?> Departments
        </a>
        <a href="<?= e(url('/admin/sites')) ?>" class="<?= $nav_active === 'admin/sites' ? 'active' : '' ?>">
          <?= $icon('<path d="M12 21s-7-5.5-7-11a7 7 0 0 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>') ?> Sites
        </a>
        <a href="<?= e(url('/admin/locations')) ?>" class="<?= $nav_active === 'admin/locations' ? 'active' : '' ?>">
          <?= $icon('<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>') ?> Locations
        </a>
        <a href="<?= e(url('/admin/categories')) ?>" class="<?= $nav_active === 'admin/categories' ? 'active' : '' ?>">
          <?= $icon('<path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9-9-9z"/><circle cx="7.5" cy="7.5" r="1.5"/>') ?> Categories
        </a>
        <a href="<?= e(url('/admin/audit')) ?>" class="<?= $nav_active === 'admin/audit' ? 'active' : '' ?>">
          <?= $icon('<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>') ?> Audit Log
        </a>
        <a href="<?= e(url('/admin/backups')) ?>" class="<?= $nav_active === 'admin/backups' ? 'active' : '' ?>">
          <?= $icon('<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>') ?> Backups
        </a>
        <a href="<?= e(url('/admin/settings')) ?>" class="<?= $nav_active === 'admin/settings' ? 'active' : '' ?>">
          <?= $icon('<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1"/>') ?> Settings
        </a>
        <a href="<?= e(url('/admin/system')) ?>" class="<?= $nav_active === 'admin/system' ? 'active' : '' ?>">
          <?= $icon('<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>') ?> System
        </a>
      <?php endif; ?>
    </nav>
    <div class="sidebar-foot">
      <form method="post" action="<?= e(url('/logout')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-sm">Sign out</button>
      </form>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="hamburger" id="hamburger" aria-label="Menu">
        <?= $icon('<path d="M4 6h16M4 12h16M4 18h16"/>') ?>
      </button>
      <form class="topbar-search" action="<?= e(url('/assets')) ?>" method="get">
        <input type="search" name="q" placeholder="Search assets — tag, serial, brand… (scanner ready)"
               value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off">
      </form>
      <?php if (!empty($user)): ?>
        <div class="dropdown bell-dd">
          <button class="bell dropdown-toggle" type="button" aria-label="Alerts" title="Alerts">
            <?= $icon('<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6z"/><path d="M10 20a2 2 0 0 0 4 0"/>') ?>
            <?php if (!empty($important_count)): ?><span class="bell-dot"><?= (int) $important_count ?></span><?php endif; ?>
          </button>
          <div class="dropdown-menu alerts-menu">
            <div class="alerts-menu-head">
              <strong>Alerts</strong>
              <span class="badge badge-red" data-if-count><?= (int) $important_count ?> important</span>
            </div>
            <div class="alerts-list">
              <?php if ($alerts === []): ?>
                <div class="empty">No active alerts. All clear.</div>
              <?php else: ?>
                <?php foreach (array_slice($alerts, 0, 12) as $alert): ?>
                  <a class="alert-item <?= $alert['severity'] === 'important' ? 'alert-important' : 'alert-info' ?>"
                     href="<?= e(url($alert['link'])) ?>">
                    <span class="alert-dot"></span>
                    <span class="alert-text">
                      <strong><?= e($alert['title']) ?></strong>
                      <small><?= e($alert['message']) ?></small>
                    </span>
                    <span class="alert-tag"><?= e(ucfirst(str_replace('_', ' ', $alert['type']))) ?></span>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
      <div class="user-chip" title="<?= e($user['full_name'] ?? '') ?> · <?= e($user['role_name'] ?? '') ?>">
        <span class="avatar"><?= e(strtoupper(substr((string) ($user['full_name'] ?? 'U'), 0, 1))) ?></span>
        <span class="user-meta">
          <span class="user-name"><?= e($user['full_name'] ?? '') ?></span>
          <span class="user-role"><?= e(ucfirst(str_replace('_', ' ', (string) ($user['role_name'] ?? '')))) ?><?php if (!empty($user['department_name'])): ?> · <?= e($user['department_name']) ?><?php endif; ?></span>
        </span>
      </div>
    </header>

    <main class="content">
      <?php if (!empty($flash)): ?>
        <div class="flash-stack" id="flash-stack">
          <?php foreach ($flash as $type => $message): ?>
            <div class="toast toast-<?= e($type) ?>"><?= e($message) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?= $content ?>
    </main>
    <footer class="foot"><?= e($app_name) ?> v<?= e($app_version) ?> · local network deployment</footer>
  </div>
</div>

<div class="modal-backdrop" id="modal-backdrop" hidden>
  <div class="modal" id="modal">
    <div class="modal-head">
      <h3 id="modal-title">Action</h3>
      <button class="btn btn-ghost btn-sm" id="modal-close" type="button">✕</button>
    </div>
    <form id="modal-form" method="post">
      <?= csrf_field() ?>
      <div class="modal-body" id="modal-body"></div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" id="modal-cancel">Cancel</button>
        <button type="submit" class="btn btn-primary" id="modal-submit">Confirm</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= e(asset_url('js/app.js')) ?>"></script>
</body>
</html>
