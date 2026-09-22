<?php
/** @var string $slug */
/** @var string $title */
/** @var array $photos */
/** @var string $mode */
/** @var string $name */
/** @var array $selectedIds */
/** @var string $confirm */
$targetForm = $targetForm ?? '';
$formAction = $formAction ?? '';
$buttonLabel = $buttonLabel ?? 'Confirm';
$preview = $preview ?? '';
$emptyLink = $emptyLink ?? '';
$selected = array_map('intval', (array) ($selectedIds ?? []));
$type = $mode === 'multi' ? 'checkbox' : 'radio';
?>
<div class="pp-modal" id="pp-<?= e($slug) ?>" hidden
     data-mode="<?= e($mode) ?>" data-confirm="<?= e($confirm) ?>" data-name="<?= e($name) ?>"
     data-fields='<?= e(json_encode([$name => $name], JSON_UNESCAPED_SLASHES)) ?>'
     <?php if ($targetForm !== ''): ?>data-target-form="<?= e($targetForm) ?>"<?php endif; ?>
     <?php if ($formAction !== ''): ?>data-action-url="<?= e($formAction) ?>"<?php endif; ?>
     <?php if ($preview !== ''): ?>data-pp-preview="<?= e($preview) ?>"<?php endif; ?>>
  <div class="pp-backdrop" data-pp-close></div>
  <div class="pp-dialog" role="dialog" aria-modal="true">
    <div class="pp-head">
      <h3><?= e($title) ?></h3>
      <button class="pp-close" type="button" data-pp-close aria-label="Close">×</button>
    </div>
    <div class="pp-grid">
      <?php foreach ($photos as $photo): ?>
        <label class="pp-option">
          <input type="<?= $type ?>" name="<?= e($name) ?>" value="<?= (int) $photo['id'] ?>" <?= in_array((int) $photo['id'], $selected, true) ? 'checked' : '' ?>>
          <img src="<?= e(url('/uploads/' . rawurlencode((string) $photo['filename']))) ?>" alt=""
               onerror="this.src='<?= e(asset_url('img/placeholder.svg')) ?>'">
          <span class="pp-name"><?= e($photo['original_name']) ?><?= ($photo['variety'] ?? '') !== '' ? ' (' . e($photo['variety']) . ')' : '' ?></span>
        </label>
      <?php endforeach; ?>
      <?php if ($photos === []): ?>
        <div class="pp-empty">
          No photos available.<?php if ($emptyLink !== ''): ?> <a href="<?= e(url($emptyLink)) ?>">Open the gallery</a> to upload some first.<?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="pp-foot">
      <button class="btn" type="button" data-pp-close>Cancel</button>
      <button class="btn btn-primary" type="button" data-pp-confirm><?= e($buttonLabel) ?></button>
    </div>
  </div>
</div>
