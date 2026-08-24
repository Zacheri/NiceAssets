<?php
/** @var array $rows */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var array $filters */
/** @var array $actions */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Audit Log</h1>
    <div class="page-sub">Every user action and asset change, with timestamps and context.</div>
  </div>
</div>

<form class="panel filters-bar animate-fadeup" method="get" action="<?= e(url('/admin/audit')) ?>" style="animation-delay:.05s">
  <div class="filter-grid">
    <label class="field"><span>User</span><input class="input" type="text" name="user" value="<?= e($filters['user']) ?>"></label>
    <label class="field"><span>Action</span>
      <select class="input" name="action">
        <option value="">All</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= e($a['action']) ?>" <?= $filters['action'] === $a['action'] ? 'selected' : '' ?>><?= e($a['action']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>From</span><input class="input" type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label class="field"><span>To</span><input class="input" type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <div class="filter-actions">
      <button class="btn btn-primary">Filter</button>
      <a class="btn btn-ghost" href="<?= e(url('/admin/audit')) ?>">Reset</a>
    </div>
  </div>
</form>

<section class="panel animate-fadeup" style="animation-delay:.1s">
  <div class="panel-body">
    <table class="data table-compact">
      <thead><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Entity</th><th>ID</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <?php $d = json_decode((string) $row['details'], true) ?: []; ?>
          <tr>
            <td class="nowrap"><?= e(date('M j, Y g:i A', strtotime($row['created_at']))) ?></td>
            <td><?= e($row['username']) ?></td>
            <td><span class="action-chip"><?= e(action_label($row['action'])) ?></span></td>
            <td><?= e($row['entity']) ?></td>
            <td>
              <?php if ($row['entity'] === 'asset' && $row['entity_id'] !== ''): ?>
                <a href="<?= e(url('/assets/' . (int) $row['entity_id'])) ?>"><?= e($d['asset_tag'] ?? $row['entity_id']) ?></a>
              <?php else: ?><?= e($row['entity_id']) ?><?php endif; ?>
            </td>
            <td class="details-cell" title='<?= e(json_encode($d)) ?>'><?= e(implode(', ', array_map(static fn ($k, $v) => $k . '=' . (is_scalar($v) ? $v : json_encode($v)), array_keys($d), $d))) ?></td>
            <td><?= e($row['ip']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
          <tr><td colspan="7" class="empty">No audit entries match.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <div class="table-note"><?= number_format($total) ?> total entries</div>
  </div>
</section>

<div class="pagination-wrap">
  <?= \App\Core\View::partial('pagination', ['page' => $page, 'pages' => $pages]) ?>
</div>
