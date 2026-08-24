<?php
/** @var array $locations */
/** @var array $sites */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Locations</h1>
    <div class="page-sub">Sub-locations within each site (rooms, aisles, docks…).</div>
  </div>
</div>

<form method="post" action="<?= e(url('/admin/locations')) ?>" class="panel upload-panel animate-fadeup" style="animation-delay:.05s">
  <?= csrf_field() ?>
  <div class="upload-row">
    <label class="field"><span>Site *</span>
      <select class="input" name="location[site_id]" required>
        <?php foreach ($sites as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="field field-grow"><span>Location name *</span><input class="input" type="text" name="location[name]" required></label>
    <label class="field"><span>Code</span><input class="input" type="text" name="location[code]" placeholder="e.g. HQ-ITLAB"></label>
    <label class="field field-grow"><span>Description</span><input class="input" type="text" name="location[description]"></label>
    <div class="upload-actions"><button class="btn btn-primary">Add</button></div>
  </div>
</form>

<section class="panel animate-fadeup" style="animation-delay:.1s">
  <div class="panel-body">
    <table class="data">
      <thead><tr><th>Site</th><th>Location</th><th>Code</th><th>Assets</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($locations as $l): ?>
          <tr>
            <td><?= e($l['site_name']) ?></td>
            <td><strong><?= e($l['name']) ?></strong> <span class="page-sub"><?= e($l['description'] ?? '') ?></span></td>
            <td><?= e($l['code'] ?? '—') ?></td>
            <td><?= (int) $l['asset_count'] ?></td>
            <td>
              <form method="post" action="<?= e(url('/admin/locations/' . (int) $l['id'] . '/delete')) ?>"
                    onsubmit="return confirm('Delete location <?= e($l['name']) ?>?')">
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
