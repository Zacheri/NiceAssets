<?php
/** @var array $columns */
/** @var array $rows */
/** @var int $totalRows */
/** @var array $run */
/** @var array $meta */
/** @var string $type */
$photoCol = null;
foreach ($columns as $i => $col) {
    if ($col['key'] === 'thumb') {
        $photoCol = $i;
    }
}
?>
<div class="page-head animate-fadeup">
  <div>
    <a class="back-link" href="<?= e(url('/reports')) ?>">‹ All reports</a>
    <h1 class="page-title"><?= e($meta['name']) ?></h1>
    <div class="page-sub"><?= e($meta['description']) ?></div>
  </div>
  <div class="page-actions">
    <a class="btn btn-ghost" href="<?= e(url('/reports/export/' . $type . request_query())) ?>">Export PDF</a>
    <a class="btn btn-ghost" href="<?= e(url('/reports/export/' . $type . request_query() . (str_contains(request_query(), '?') ? '&' : '?') . 'format=excel')) ?>">Export Excel (static)</a>
    <a class="btn btn-ghost" href="<?= e(url('/reports/export/' . $type . request_query() . (str_contains(request_query(), '?') ? '&' : '?') . 'format=excel&formulas=1')) ?>">Excel (formulas)</a>
  </div>
</div>

<div class="run-banner animate-fadeup" style="animation-delay:.05s">
  <strong><?= e($run['name']) ?></strong> saved · Version <strong>v<?= (int) $run['version'] ?></strong> ·
  <?= number_format($totalRows) ?> rows · generated <?= e(date('M j, Y g:i A', strtotime($run['generated_at']))) ?>
  <span class="run-files">
    <a class="btn btn-xs" href="<?= e(url('/reports/download/' . (int) $run['id'])) ?>">PDF file</a>
    <a class="btn btn-xs" href="<?= e(url('/reports/download/' . (int) $run['id'] . '?format=excel')) ?>">Excel file</a>
  </span>
</div>

<section class="panel animate-fadeup" style="animation-delay:.1s">
  <div class="panel-body">
    <table class="data table-compact">
      <thead>
        <tr>
          <?php if ($photoCol !== null): ?><th>Photo</th><?php endif; ?>
          <?php foreach ($columns as $col): ?><th><?= e($col['label']) ?></th><?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <?php if ($photoCol !== null): ?>
              <td>
                <?php if (!empty($row['thumb'])): ?>
                  <img class="cell-thumb" src="<?= e(url('/uploads/' . rawurlencode((string) $row['thumb']))) ?>" alt=""
                       onerror="this.src='<?= e(asset_url('img/placeholder.svg')) ?>'">
                <?php endif; ?>
              </td>
            <?php endif; ?>
            <?php foreach ($columns as $col): ?>
              <td>
                <?php
                $key = $col['key'];
                $val = $row[$key] ?? '';
                if (($col['type'] ?? '') === 'date' && $val !== '') {
                    echo e(date_fmt((string) $val));
                } elseif (($col['type'] ?? '') === 'money' && $val !== '') {
                    echo e(money($val));
                } else {
                    echo e($val);
                }
                ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
          <tr><td colspan="<?= count($columns) + ($photoCol !== null ? 1 : 0) ?>" class="empty">No rows for these filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php if ($totalRows > count($rows)): ?>
      <div class="table-note">Preview shows first <?= count($rows) ?> of <?= number_format($totalRows) ?> rows — use export for the full listing.</div>
    <?php endif; ?>
  </div>
</section>

<?php if ($type === 'custom'): ?>
  <section class="panel animate-fadeup" style="animation-delay:.15s">
    <div class="panel-head"><h2>Custom filters</h2></div>
    <div class="panel-body">
      <form class="filter-grid" method="get" action="<?= e(url('/reports/custom')) ?>">
        <label class="field"><span>Category</span>
          <select class="input" name="category_id">
            <option value="">All</option>
            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (string) $filters['category_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>Department</span>
          <select class="input" name="department_id">
            <option value="">All</option>
            <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (string) $filters['department_id'] === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>Site</span>
          <select class="input" name="site_id" id="custom-site">
            <option value="">All</option>
            <?php foreach ($sites as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (string) $filters['site_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>Location</span>
          <select class="input" name="location_id" id="custom-location"><option value="">All</option></select>
        </label>
        <label class="field"><span>Model</span><input class="input" type="text" name="model" value="<?= e($filters['model']) ?>"></label>
        <label class="field"><span>Brand</span><input class="input" type="text" name="brand" value="<?= e($filters['brand']) ?>"></label>
        <label class="field"><span>Status</span>
          <select class="input" name="status">
            <option value="">All</option>
            <?php foreach (\App\Models\Asset::STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>Search</span><input class="input" type="text" name="q" value="<?= e($filters['q']) ?>"></label>
        <div class="filter-actions">
          <button class="btn btn-primary">Run report</button>
        </div>
      </form>
    </div>
  </section>
  <script>
  window.NAIMS.locationsBySite = <?= json_encode(array_map(static fn ($locs) => array_map(static fn ($l) => ['id' => (int) $l['id'], 'name' => $l['name']], $locs), $locationsBySite), JSON_UNESCAPED_SLASHES) ?>;
  </script>
<?php endif; ?>
