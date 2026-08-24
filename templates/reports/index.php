<?php
/** @var array $types */
/** @var array $runs */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Reports</h1>
    <div class="page-sub">Standard reports, custom builder, and versioned report history.</div>
  </div>
  <div class="page-actions">
    <a href="<?= e(url('/reports/weekly')) ?>" class="btn btn-ghost">Weekly reports</a>
  </div>
</div>

<div class="report-cards animate-fadeup" style="animation-delay:.05s">
  <a class="report-card" href="<?= e(url('/reports/inventory')) ?>">
    <h3>Inventory Count</h3>
    <p>Complete asset listing with all details, values, and thumbnails.</p>
  </a>
  <a class="report-card" href="<?= e(url('/reports/low_stock')) ?>">
    <h3>Low Stock Alerts</h3>
    <p>Categories below their configured stock thresholds.</p>
  </a>
  <a class="report-card" href="<?= e(url('/reports/depreciation')) ?>">
    <h3>Depreciation</h3>
    <p>Current depreciated values and remaining useful life (5-year linear).</p>
  </a>
  <a class="report-card" href="<?= e(url('/reports/assignment')) ?>">
    <h3>Person Assignment</h3>
    <p>Assets assigned to each user or department.</p>
  </a>
  <a class="report-card" href="<?= e(url('/reports/duration')) ?>">
    <h3>Assignment Duration</h3>
    <p>How long each asset has been checked out.</p>
  </a>
  <a class="report-card" href="<?= e(url('/reports/multi_asset')) ?>">
    <h3>Multi-Asset Alert</h3>
    <p>Users holding multiple similar assets (e.g. 2+ Chromebooks).</p>
  </a>
  <a class="report-card accent" href="<?= e(url('/reports/custom')) ?>">
    <h3>Custom Report Builder</h3>
    <p>Filter by category, department, site, location, model, brand — choose columns.</p>
  </a>
</div>

<section class="panel animate-fadeup" style="animation-delay:.1s">
  <div class="panel-head"><h2>Recent report runs</h2></div>
  <div class="panel-body">
    <table class="data table-compact">
      <thead><tr><th>Report</th><th>Type</th><th>Version</th><th>Rows</th><th>Generated</th><th>By</th><th>Files</th></tr></thead>
      <tbody>
        <?php foreach ($runs as $run): ?>
          <tr>
            <td><strong><?= e($run['name']) ?></strong></td>
            <td><span class="action-chip"><?= e($run['report_type']) ?></span></td>
            <td>v<?= (int) $run['version'] ?></td>
            <td><?= number_format((int) $run['row_count']) ?></td>
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
          <tr><td colspan="7" class="empty">No reports generated yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
