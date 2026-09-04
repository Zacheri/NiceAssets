<?php
/** @var array $runs */
$range = \App\Services\WeeklyReport::previousWeekRange();
?>
<div class="page-head animate-fadeup">
  <div>
    <a class="back-link" href="<?= e(url('/reports')) ?>">‹ All reports</a>
    <h1 class="page-title">Weekly Activity Reports</h1>
    <div class="page-sub">
      Auto-generated every Saturday via cron. Covers Mon–Fri: check-ins, check-outs, transfers, and status changes.
      Next automatic window: <strong><?= e(date('M j', strtotime((string) $range[0]))) ?> – <?= e(date('M j', strtotime((string) $range[1]))) ?></strong>
    </div>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="<?= e(url('/reports/weekly/generate')) ?>">Generate now</a>
  </div>
</div>

<section class="panel animate-fadeup" style="animation-delay:.05s">
  <div class="panel-body">
    <table class="data table-compact">
      <thead><tr><th>Week</th><th>Version</th><th>Entries</th><th>Breakdown</th><th>Generated</th><th>By</th><th>Files</th></tr></thead>
      <tbody>
        <?php foreach ($runs as $run): ?>
          <?php $s = json_decode((string) $run['summary'], true) ?: []; ?>
          <tr>
            <td><strong><?= e($run['name']) ?></strong></td>
            <td>v<?= (int) $run['version'] ?></td>
            <td><?= number_format((int) $run['row_count']) ?></td>
            <td>
              <?php foreach ((array) ($s['by_action'] ?? []) as $action => $count): ?>
                <span class="action-chip"><?= e(action_label((string) $action)) ?>: <?= (int) $count ?></span>
              <?php endforeach; ?>
            </td>
            <td class="nowrap"><?= e(date('M j, Y g:i A', strtotime($run['generated_at']))) ?></td>
            <td><?= e($run['generated_by_name'] ?? 'system') ?></td>
            <td class="nowrap">
              <?php if ($run['file_pdf'] && is_file($run['file_pdf'])): ?>
                <a class="btn btn-xs" href="<?= e(url('/reports/download/' . (int) $run['id'])) ?>">PDF</a>
              <?php endif; ?>
              <?php if ($run['file_excel'] && is_file($run['file_excel'])): ?>
                <a class="btn btn-xs" href="<?= e(url('/reports/download/' . (int) $run['id'] . '?format=excel')) ?>">Excel</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($runs === []): ?>
          <tr><td colspan="7" class="empty">No weekly reports yet. The Saturday job (or “Generate now”) will create the first one.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
