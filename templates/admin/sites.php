<?php
/** @var array $sites */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Sites</h1>
    <div class="page-sub">Offices and warehouses. Sub-locations are managed under Locations.</div>
  </div>
</div>

<form method="post" action="<?= e(url('/admin/sites')) ?>" class="panel upload-panel animate-fadeup" style="animation-delay:.05s">
  <?= csrf_field() ?>
  <div class="upload-row">
    <label class="field field-grow"><span>Site name *</span><input class="input" type="text" name="site[name]" required></label>
    <label class="field field-grow"><span>Address</span><input class="input" type="text" name="site[address]"></label>
    <div class="upload-actions"><button class="btn btn-primary">Add</button></div>
  </div>
</form>

<section class="panel animate-fadeup" style="animation-delay:.1s">
  <div class="panel-body">
    <table class="data">
      <thead><tr><th>Site</th><th>Address</th><th>Locations</th><th>Assets</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($sites as $s): ?>
          <tr>
            <td><strong><?= e($s['name']) ?></strong></td>
            <td><?= e($s['address'] ?? '') ?></td>
            <td><?= (int) $s['location_count'] ?></td>
            <td><?= (int) $s['asset_count'] ?></td>
            <td>
              <form method="post" action="<?= e(url('/admin/sites/' . (int) $s['id'] . '/delete')) ?>"
                    onsubmit="return confirm('Delete site <?= e($s['name']) ?>? Only works when it has no locations or assets.')">
                <?= csrf_field() ?>
                <button class="btn btn-xs btn-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
