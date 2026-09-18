<?php
/** @var array $person */
/** @var array $assets */
$count = count($assets);
$terminated = !empty($person['is_terminated']);
?>
<div class="page-head animate-fadeup">
  <div>
    <a class="back-link" href="<?= e(url('/admin/persons')) ?>">← Persons</a>
    <h1 class="page-title"><?= e($person['full_name']) ?>
      <span class="status-badge <?= $terminated ? 'badge-red' : 'badge-green' ?>"><?= $terminated ? 'Terminated' : 'Active' ?></span>
    </h1>
    <div class="page-sub"><?= e($person['job_title'] !== '' ? $person['job_title'] : 'No job title') ?> · <?= e($person['department_name'] ?? '') !== '' ? e($person['department_name']) : 'No department' ?></div>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/persons/' . (int) $person['id'] . '/edit')) ?>">Edit</a>
  </div>
</div>

<section class="panel animate-fadeup" style="animation-delay:.05s">
  <div class="panel-head"><h2>Details</h2></div>
  <div class="panel-body">
    <div class="person-contacts">
      <div><span class="pc-label">Job title</span><?= e($person['job_title'] !== '' ? $person['job_title'] : '—') ?></div>
      <div><span class="pc-label">Department</span><?= e($person['department_name'] ?? '') !== '' ? e($person['department_name']) : 'No department' ?></div>
      <div><span class="pc-label">Work</span><?= e($person['work_email'] !== '' ? $person['work_email'] : '—') ?></div>
      <div><span class="pc-label">Personal</span><?= e($person['personal_email'] !== '' ? $person['personal_email'] : '—') ?></div>
      <div><span class="pc-label">Phone</span><?= e($person['phone'] !== '' ? $person['phone'] : '—') ?></div>
      <div><span class="pc-label">Address</span><?= e($person['address'] !== '' ? $person['address'] : '—') ?></div>
    </div>
    <?php if ($person['notes'] !== ''): ?>
      <div class="person-notes"><?= e($person['notes']) ?></div>
    <?php endif; ?>
  </div>
</section>

<div class="section-title animate-fadeup" style="animation-delay:.1s">Checked out (<?= $count ?>)</div>
<div class="asset-grid" style="--cols: 3">
  <?php foreach ($assets as $a): ?>
    <?= \App\Core\View::partial('asset_card', ['asset' => $a, 'canModify' => true, 'isAdmin' => true]) ?>
  <?php endforeach; ?>
  <?php if ($count === 0): ?>
    <div class="empty-grid">
      <div class="empty empty-lg">
        <p>No assets are currently checked out to <?= e($person['full_name']) ?>.</p>
      </div>
    </div>
  <?php endif; ?>
</div>
