<?php
/** @var array $counts */
$links = [
    'assets' => ['url' => '/assets', 'label' => 'Assets'],
    'users' => ['url' => '/admin/users', 'label' => 'Users'],
    'persons' => ['url' => '/admin/persons', 'label' => 'Persons'],
    'departments' => ['url' => '/admin/departments', 'label' => 'Departments'],
    'sites' => ['url' => '/admin/sites', 'label' => 'Sites'],
    'locations' => ['url' => '/admin/locations', 'label' => 'Locations'],
    'categories' => ['url' => '/admin/categories', 'label' => 'Categories'],
    'work_orders' => ['url' => '/work-orders', 'label' => 'Open work orders'],
    'audit_entries' => ['url' => '/admin/audit', 'label' => 'Audit entries'],
];
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Administration</h1>
    <div class="page-sub">System configuration, organization, and data management.</div>
  </div>
</div>
<div class="report-cards animate-fadeup" style="animation-delay:.05s">
  <?php foreach ($links as $key => $link): ?>
    <a class="report-card" href="<?= e(url($link['url'])) ?>">
      <h3><?= number_format($counts[$key] ?? 0) ?></h3>
      <p><?= e($link['label']) ?></p>
    </a>
  <?php endforeach; ?>
</div>
