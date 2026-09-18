<?php
/** @var array $persons */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Persons</h1>
    <div class="page-sub">Employees that assets can be checked out to.</div>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/persons/new')) ?>">+ New Person</a>
  </div>
</div>

<div class="asset-grid" style="--cols: 3">
  <?php foreach ($persons as $p): ?>
    <div class="asset-card animate-fadeup <?= $p['is_terminated'] ? 'card-terminated' : '' ?>">
      <a class="card-stretch" href="<?= e(url('/admin/persons/' . (int) $p['id'])) ?>" aria-label="View <?= e($p['full_name']) ?> and their checked-out assets"></a>
      <div class="card-body person-card-body">
        <div class="person-head">
          <span class="avatar avatar-lg"><?= e(strtoupper(substr((string) $p['full_name'], 0, 1))) ?></span>
          <div class="person-id">
            <span class="card-tag"><?= e($p['full_name']) ?></span>
            <div class="card-model"><?= e($p['job_title']) !== '' ? e($p['job_title']) : '—' ?></div>
          </div>
          <span class="status-badge <?= $p['is_terminated'] ? 'badge-red' : 'badge-green' ?>"><?= $p['is_terminated'] ? 'Terminated' : 'Active' ?></span>
        </div>
        <div class="card-meta">
          <span><?= e($p['department_name'] ?? '') !== '' ? e($p['department_name']) : 'No department' ?></span>
          <?php if ((int) $p['checked_out_count'] > 0): ?>
            <span class="dot">·</span>
            <span><?= (int) $p['checked_out_count'] ?> asset<?= (int) $p['checked_out_count'] === 1 ? '' : 's' ?> checked out</span>
          <?php endif; ?>
        </div>
        <div class="person-contacts">
          <?php if ($p['work_email'] !== ''): ?><div><span class="pc-label">Work</span><?= e($p['work_email']) ?></div><?php endif; ?>
          <?php if ($p['personal_email'] !== ''): ?><div><span class="pc-label">Personal</span><?= e($p['personal_email']) ?></div><?php endif; ?>
          <?php if ($p['phone'] !== ''): ?><div><span class="pc-label">Phone</span><?= e($p['phone']) ?></div><?php endif; ?>
          <?php if ($p['address'] !== ''): ?><div><span class="pc-label">Address</span><?= e($p['address']) ?></div><?php endif; ?>
        </div>
        <?php if ($p['notes'] !== ''): ?>
          <div class="person-notes" title="<?= e($p['notes']) ?>"><?= e(mb_strimwidth($p['notes'], 0, 130, '…', '8bit')) ?></div>
        <?php endif; ?>
      </div>
      <div class="card-actions">
        <form method="post" action="<?= e(url('/admin/persons/' . (int) $p['id'] . '/toggle')) ?>" class="inline-form">
          <?= csrf_field() ?>
          <label class="switch-label">
            <span>Terminate?:</span>
            <input class="switch" type="checkbox" value="1" <?= $p['is_terminated'] ? 'checked' : '' ?>
                   onchange="this.form.requestSubmit()">
          </label>
        </form>
        <span class="spacer"></span>
        <a class="btn btn-sm" href="<?= e(url('/admin/persons/' . (int) $p['id'] . '/edit')) ?>">Edit</a>
        <form method="post" action="<?= e(url('/admin/persons/' . (int) $p['id'] . '/delete')) ?>"
              onsubmit="return confirm('Delete <?= e($p['full_name']) ?>? Any assets checked out to them become unassigned.')">
          <?= csrf_field() ?>
          <button class="btn btn-sm btn-ghost btn-danger">Delete</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if ($persons === []): ?>
    <div class="empty-grid">
      <div class="empty empty-lg">
        <p>No persons yet.</p>
        <a class="btn btn-primary" href="<?= e(url('/admin/persons/new')) ?>">Add your first person</a>
      </div>
    </div>
  <?php endif; ?>
</div>
