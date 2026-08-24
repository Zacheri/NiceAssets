<?php
/** @var array $stats */
/** @var array $alerts */
/** @var array $activity */
/** @var array $category_chart */
/** @var array $status_chart */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Dashboard</h1>
    <div class="page-sub"><?= e(date('l, F j, Y')) ?> · <span data-role="user-name"><?= e($user['full_name'] ?? '') ?></span></div>
  </div>
  <div class="page-actions">
    <a href="<?= e(url('/assets/new')) ?>" class="btn btn-primary" data-perm-modify>+ New Asset</a>
  </div>
</div>

<div class="cards-row">
  <div class="stat-card accent-blue animate-fadeup" style="animation-delay:.02s">
    <div class="stat-num"><?= number_format((int) $stats['total']) ?></div>
    <div class="stat-label">Total assets</div>
  </div>
  <div class="stat-card accent-green animate-fadeup" style="animation-delay:.06s">
    <div class="stat-num"><?= number_format((int) $stats['available_qty']) ?></div>
    <div class="stat-label">In stock (available)</div>
  </div>
  <div class="stat-card accent-amber animate-fadeup" style="animation-delay:.1s">
    <div class="stat-num"><?= number_format((int) $stats['checked_out']) ?></div>
    <div class="stat-label">Checked out</div>
  </div>
  <div class="stat-card accent-purple animate-fadeup" style="animation-delay:.14s">
    <div class="stat-num"><?= number_format((int) $stats['in_repair']) ?></div>
    <div class="stat-label">In repair</div>
  </div>
  <div class="stat-card accent-red animate-fadeup" style="animation-delay:.18s">
    <div class="stat-num"><?= number_format((int) $stats['overdue']) ?></div>
    <div class="stat-label">Overdue returns</div>
  </div>
  <div class="stat-card accent-slate animate-fadeup" style="animation-delay:.22s">
    <div class="stat-num"><?= money($stats['current_value']) ?></div>
    <div class="stat-label">Depreciated book value</div>
  </div>
</div>

<div class="dash-grid">
  <section class="panel animate-fadeup" style="animation-delay:.15s">
    <div class="panel-head">
      <h2>Alerts</h2>
      <span class="badge badge-red" data-if-count><?= (int) $important_count ?> important</span>
    </div>
    <div class="panel-body alerts-list">
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
  </section>

  <section class="panel animate-fadeup" style="animation-delay:.2s">
    <div class="panel-head"><h2>Assets by category</h2></div>
    <div class="panel-body">
      <canvas id="chart-categories" height="220"></canvas>
    </div>
  </section>

  <section class="panel animate-fadeup" style="animation-delay:.25s">
    <div class="panel-head"><h2>Status breakdown</h2></div>
    <div class="panel-body chart-row">
      <canvas id="chart-status" width="180" height="180"></canvas>
      <ul class="legend" id="status-legend"></ul>
    </div>
  </section>

  <section class="panel animate-fadeup" style="animation-delay:.3s">
    <div class="panel-head"><h2>Recent activity</h2></div>
    <div class="panel-body">
      <table class="data table-compact">
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Item</th></tr></thead>
        <tbody>
          <?php foreach ($activity as $row): ?>
            <tr>
              <td class="nowrap"><?= e(date('M j, g:i A', strtotime($row['created_at']))) ?></td>
              <td><?= e($row['username']) ?></td>
              <td><span class="action-chip"><?= e(action_label($row['action'])) ?></span></td>
              <td><?= e($row['entity_id']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($activity === []): ?>
            <tr><td colspan="4" class="empty">No activity recorded yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<script>
window.ATR.charts = {
  categories: <?= json_encode(array_map(static fn ($r) => ['label' => $r['name'], 'value' => (int) $r['count']], $category_chart), JSON_UNESCAPED_SLASHES) ?>,
  status: <?= json_encode($status_chart, JSON_UNESCAPED_SLASHES) ?>
};
window.ATR.canModify = <?= \App\Core\Auth::canModify() ? 'true' : 'false' ?>;
</script>
