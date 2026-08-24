<?php
/** @var array $categories */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Categories</h1>
    <div class="page-sub">Low stock thresholds and full-depreciation alert toggles are configured here.</div>
  </div>
</div>

<form method="post" action="<?= e(url('/admin/categories')) ?>" class="panel upload-panel animate-fadeup" style="animation-delay:.05s">
  <?= csrf_field() ?>
  <div class="upload-row">
    <label class="field field-grow"><span>Category name *</span><input class="input" type="text" name="category[name]" required></label>
    <label class="field"><span>Low stock threshold</span><input class="input" type="number" min="0" name="category[low_stock_threshold]" value="5"></label>
    <div class="field field-check"><label><input type="checkbox" name="category[depreciation_alert_enabled]" value="1" checked> Depreciation alerts</label><span></span></div>
    <div class="upload-actions"><button class="btn btn-primary">Add</button></div>
  </div>
</form>

<section class="panel animate-fadeup" style="animation-delay:.1s">
  <div class="panel-body">
    <table class="data">
      <thead><tr><th>Category</th><th>Assets</th><th>In stock</th><th>Low stock threshold</th><th>Depreciation alerts</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($categories as $c): ?>
          <tr>
            <form method="post" action="<?= e(url('/admin/categories/' . (int) $c['id'])) ?>">
              <?= csrf_field() ?>
              <td><strong><?= e($c['name']) ?></strong></td>
              <td><?= (int) $c['asset_count'] ?></td>
              <td><?= (int) $c['available_qty'] ?></td>
              <td><input class="input input-sm" type="number" min="0" name="category[low_stock_threshold]" value="<?= (int) $c['low_stock_threshold'] ?>"></td>
              <td><input type="checkbox" name="category[depreciation_alert_enabled]" value="1" <?= $c['depreciation_alert_enabled'] ? 'checked' : '' ?>></td>
              <td><input type="checkbox" name="category[is_active]" value="1" <?= $c['is_active'] ? 'checked' : '' ?>></td>
              <td><button class="btn btn-xs">Save</button></td>
            </form>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="table-note">Rows with stock below the threshold trigger a Low Stock alert on the dashboard (and email where enabled).</div>
  </div>
</section>

<div class="panel animate-fadeup" style="margin-top:16px;animation-delay:.15s">
  <div class="panel-body">
    <h3 class="panel-inline-title">Delete category</h3>
    <?php foreach ($categories as $c): ?>
      <form method="post" action="<?= e(url('/admin/categories/' . (int) $c['id'] . '/delete')) ?>" class="inline-row"
            onsubmit="return confirm('Delete category <?= e($c['name']) ?>? Assets using it must be reassigned first.')">
        <?= csrf_field() ?>
        <span><?= e($c['name']) ?></span>
        <button class="btn btn-xs btn-danger">Delete</button>
      </form>
    <?php endforeach; ?>
  </div>
</div>
