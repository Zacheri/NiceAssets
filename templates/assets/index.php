<?php
/** @var array $rows */
/** @var int $total */
/** @var int $cols */
$canModify = \App\Core\Auth::canModify();
$isAdmin = \App\Core\Auth::isAdmin();
$f = $filters;
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Assets</h1>
    <div class="page-sub"><?= number_format($total) ?> asset<?= $total === 1 ? '' : 's' ?>
      <?php if (!\App\Core\Auth::isAdmin() && !empty($user['department_name'])): ?>
        · scoped to <?= e($user['department_name']) ?><?php endif; ?>
    </div>
  </div>
  <div class="page-actions">
    <?php if ($canModify): ?>
      <a href="<?= e(url('/assets/new')) ?>" class="btn btn-primary">+ New Asset</a>
    <?php endif; ?>
  </div>
</div>

<form class="panel filters-bar animate-fadeup" method="get" action="<?= e(url('/assets')) ?>" style="animation-delay:.05s">
  <div class="filter-grid">
    <label class="field"><span>Search</span>
      <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Tag, serial, brand, model…">
    </label>
    <label class="field"><span>Category</span>
      <select class="input" name="category_id">
        <option value="">All</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) $f['category_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Department</span>
      <select class="input" name="department_id">
        <option value="">All</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int) $d['id'] ?>" <?= (int) $f['department_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Site</span>
      <select class="input" name="site_id" id="filter-site">
        <option value="">All</option>
        <?php foreach ($sites as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= (int) $f['site_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Location</span>
      <select class="input" name="location_id" id="filter-location">
        <option value="">All</option>
      </select>
    </label>
    <label class="field"><span>Status</span>
      <select class="input" name="status">
        <option value="">All</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= e($s) ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Brand</span>
      <input class="input" type="text" name="brand" value="<?= e($f['brand']) ?>">
    </label>
    <label class="field"><span>Model</span>
      <input class="input" type="text" name="model" value="<?= e($f['model']) ?>">
    </label>
    <label class="field"><span>Assigned to person</span>
      <select class="input" name="assigned_person_id">
        <option value="">All</option>
        <?php foreach ($persons as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= (int) $f['assigned_person_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Purchased from</span>
      <input class="input" type="date" name="purchased_from" value="<?= e($f['purchased_from']) ?>">
    </label>
    <label class="field"><span>Purchased to</span>
      <input class="input" type="date" name="purchased_to" value="<?= e($f['purchased_to']) ?>">
    </label>
    <label class="field"><span>Warranty from</span>
      <input class="input" type="date" name="warranty_from" value="<?= e($f['warranty_from']) ?>">
    </label>
    <label class="field"><span>Warranty to</span>
      <input class="input" type="date" name="warranty_to" value="<?= e($f['warranty_to']) ?>">
    </label>
    <div class="field field-check">
      <label><input type="checkbox" name="overdue_only" value="1" <?= $f['overdue_only'] ? 'checked' : '' ?>> Overdue only</label>
      <span></span>
    </div>
    <label class="field"><span>Sort</span>
      <select class="input" name="sort">
        <option value="newest" <?= $f['sort'] === 'newest' ? 'selected' : '' ?>>Newest first</option>
        <option value="oldest" <?= $f['sort'] === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
        <option value="tag" <?= $f['sort'] === 'tag' ? 'selected' : '' ?>>Asset tag</option>
        <option value="cost_desc" <?= $f['sort'] === 'cost_desc' ? 'selected' : '' ?>>Cost (high to low)</option>
        <option value="warranty" <?= $f['sort'] === 'warranty' ? 'selected' : '' ?>>Warranty expiry</option>
      </select>
    </label>
    <div class="filter-actions">
      <button type="submit" class="btn btn-primary">Apply</button>
      <a href="<?= e(url('/assets')) ?>" class="btn btn-ghost">Reset</a>
    </div>
  </div>
</form>

<div class="toolbar animate-fadeup" style="animation-delay:.1s">
  <div class="toolbar-left">
    <span class="toolbar-label">Columns</span>
    <div class="col-picker" id="col-picker" data-cols="<?= (int) $cols ?>">
      <?php for ($i = 1; $i <= 6; $i++): ?>
        <button type="button" class="col-btn <?= $i === (int) $cols ? 'active' : '' ?>" data-cols="<?= $i ?>">
          <span class="col-squares" data-n="<?= $i ?>"></span><?= $i ?>
        </button>
      <?php endfor; ?>
    </div>
  </div>
  <div class="toolbar-right">
    <span class="toolbar-label">Showing</span>
    <?= $total === 0 ? 0 : ((int) $page === 1 ? 1 : (($page - 1) * $perPage) + 1) ?>–<?= min($total, $page * $perPage) ?> of <?= number_format($total) ?>
  </div>
</div>

<div class="asset-grid" id="asset-grid" style="--cols: <?= (int) $cols ?>">
  <?php foreach ($rows as $asset): ?>
    <?= \App\Core\View::partial('asset_card', ['asset' => $asset, 'canModify' => $canModify, 'isAdmin' => $isAdmin]) ?>
  <?php endforeach; ?>
  <?php if ($rows === []): ?>
    <div class="empty-grid">
      <div class="empty empty-lg">
        <p>No assets match your filters.</p>
        <?php if ($canModify): ?><a class="btn btn-primary" href="<?= e(url('/assets/new')) ?>">Add your first asset</a><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="pagination-wrap animate-fadeup" style="animation-delay:.15s">
  <?php if ($pages > 1): ?>
    <?= \App\Core\View::partial('pagination', ['page' => $page, 'pages' => $pages]) ?>
  <?php endif; ?>
</div>

<script>
window.NAIMS.locationsBySite = <?= json_encode(array_map(static fn ($locs) => array_map(static fn ($l) => ['id' => (int) $l['id'], 'name' => $l['name']], $locs), $locationsBySite), JSON_UNESCAPED_SLASHES) ?>;
window.NAIMS.canModify = <?= $canModify ? 'true' : 'false' ?>;
window.NAIMS.isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
</script>
