<?php
/** @var ?array $asset */
/** @var string $suggested_tag */
$editing = $asset !== null;
$tag = $asset ? $asset['asset_tag'] : $suggested_tag;
$v = static fn (string $key, $fallback = ''): string => $editing ? (string) ($asset[$key] ?? $fallback) : old($key, $fallback);
?>
<div class="page-head animate-fadeup">
  <div>
    <a class="back-link" href="<?= $editing ? e(url('/assets/' . (int) $asset['id'])) : e(url('/assets')) ?>">‹ Back</a>
    <h1 class="page-title"><?= e($title) ?></h1>
  </div>
</div>

<form method="post" name="asset-form" action="<?= $editing ? e(url('/assets/' . (int) $asset['id'])) : e(url('/assets')) ?>" class="panel animate-fadeup form-panel" style="animation-delay:.05s">
  <?= csrf_field() ?>
  <input type="hidden" name="asset[tag]" value="">
  <div class="form-grid">
    <label class="field"><span>Asset Tag Number *</span>
      <input class="input" type="text" name="asset[asset_tag]" value="<?= e($tag) ?>" required>
    </label>
    <label class="field"><span>Serial Number</span>
      <input class="input" type="text" name="asset[serial_number]" value="<?= e($v('serial_number')) ?>">
    </label>
    <label class="field"><span>Model Number</span>
      <input class="input" type="text" name="asset[model_number]" value="<?= e($v('model_number')) ?>">
    </label>
    <label class="field"><span>Brand / Manufacturer</span>
      <input class="input" type="text" name="asset[brand]" value="<?= e($v('brand')) ?>">
    </label>
    <label class="field"><span>Category</span>
      <select class="input" name="asset[category_id]">
        <option value="">— None —</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) $v('category_id') === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Department</span>
      <select class="input" name="asset[department_id]" <?= $manager ? 'disabled' : '' ?>>
        <option value="">— None —</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int) $d['id'] ?>" <?= (int) $v('department_id') === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($manager): ?><input type="hidden" name="asset[department_id]" value="<?= (int) ($user['department_id'] ?? 0) ?>"><?php endif; ?>
    </label>
    <label class="field"><span>Site</span>
      <select class="input" name="asset[site_id]" id="form-site">
        <option value="">— None —</option>
        <?php foreach ($sites as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= (int) $v('site_id') === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Sub-location</span>
      <select class="input" name="asset[location_id]" id="form-location">
        <option value="">— None —</option>
      </select>
    </label>
    <label class="field"><span>Purchase Date</span>
      <input class="input" type="date" name="asset[purchase_date]" value="<?= e($v('purchase_date')) ?>">
    </label>
    <label class="field"><span>Purchase Cost (USD)</span>
      <input class="input" type="number" step="0.01" min="0" name="asset[purchase_cost]" value="<?= e($v('purchase_cost', '0')) ?>">
    </label>
    <label class="field"><span>Warranty Expiration</span>
      <input class="input" type="date" name="asset[warranty_expiration]" value="<?= e($v('warranty_expiration')) ?>">
    </label>
    <label class="field"><span>Sub-Quantity</span>
      <input class="input" type="number" min="1" step="1" name="asset[sub_quantity]" value="<?= e($v('sub_quantity', '1')) ?>">
    </label>
  </div>

  <div class="photo-picker-block">
    <div class="photo-picker-head">
      <h3>Photos</h3>
      <span class="page-sub">Select from the shared gallery. The first selected photo becomes the thumbnail. Photos can be reused across assets of the same variety.</span>
    </div>
    <div class="pp-current-row">
      <button class="btn btn-sm" type="button" data-pp-open="pp-asset-photos">Choose photos</button>
    </div>
    <div class="pp-strip">
      <?php foreach ($selectedPhotos as $p): ?>
        <div class="pp-strip-item">
          <img src="<?= e(url('/uploads/' . rawurlencode((string) $p['filename']))) ?>" alt=""
               onerror="this.src='<?= e(asset_url('img/placeholder.svg')) ?>'">
          <span class="pp-strip-name"><?= e($p['original_name']) ?></span>
          <button class="pp-strip-remove" type="button" data-pp-strip-remove="<?= (int) $p['id'] ?>" data-pp-name="photo_ids[]" title="Remove from selection">✕</button>
        </div>
      <?php endforeach; ?>
      <?php if ($selectedPhotos === []): ?>
        <div class="pp-strip-empty">
          No photos selected yet.
          <?php if ($photos === []): ?><a href="<?= e(url('/photos')) ?>">Upload photos</a> first — you can link them later from the asset page.<?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php foreach ($selectedPhotos as $p): ?>
      <input type="hidden" name="photo_ids[]" value="<?= (int) $p['id'] ?>" data-pp-hidden>
    <?php endforeach; ?>
  </div>

  <div class="form-foot">
    <a class="btn btn-ghost" href="<?= $editing ? e(url('/assets/' . (int) $asset['id'])) : e(url('/assets')) ?>">Cancel</a>
    <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create asset' ?></button>
  </div>
</form>

<?= \App\Core\View::partial('photo_picker_modal', [
    'slug' => 'asset-photos',
    'title' => 'Choose photos',
    'photos' => $photos,
    'mode' => 'multi',
    'name' => 'photo_ids[]',
    'selectedIds' => array_map(static fn ($p) => (int) $p['id'], $selectedPhotos),
    'confirm' => 'submit-form',
    'targetForm' => 'asset-form',
    'buttonLabel' => 'Confirm selection',
    'emptyLink' => '/photos',
]) ?>

<script>
window.NAIMS.locationsBySite = <?= json_encode(array_map(static fn ($locs) => array_map(static fn ($l) => ['id' => (int) $l['id'], 'name' => $l['name']], $locs), $locationsBySite), JSON_UNESCAPED_SLASHES) ?>;
window.NAIMS.selectedLocation = <?= (int) $v('location_id') ?>;
</script>
