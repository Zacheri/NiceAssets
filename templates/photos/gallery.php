<?php
/** @var array $photos */
/** @var bool $canManage */
/** @var string $kind */
$isPortrait = $kind === 'portrait';
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title"><?= $isPortrait ? 'Portraits' : 'Photo Gallery' ?></h1>
    <div class="page-sub"><?= $isPortrait
      ? 'Profile photos for persons. Upload, view, and delete — portraits are assigned to people from their person page.'
      : 'Central gallery — photos are reusable across multiple assets of the same variety.' ?></div>
  </div>
</div>

<?php if ($canManage): ?>
  <form method="post" action="<?= e(url('/photos/upload')) ?>" enctype="multipart/form-data" class="panel upload-panel animate-fadeup" style="animation-delay:.05s">
    <?= csrf_field() ?>
    <?php if ($isPortrait): ?><input type="hidden" name="kind" value="portrait"><?php endif; ?>
    <div class="upload-row">
      <?php if (!$isPortrait): ?>
        <label class="field"><span>Variety / model group (optional)</span>
          <input class="input" type="text" name="variety" placeholder="e.g. Dell Latitude 7490" value="<?= e($variety) ?>">
        </label>
      <?php endif; ?>
      <label class="field"><span>Photos</span>
        <input class="input" type="file" name="photos[]" accept="image/*" multiple required>
      </label>
      <div class="upload-actions">
        <button type="submit" class="btn btn-primary">Upload</button>
        <span class="page-sub">JPG, PNG, WEBP, GIF · max <?= (int) \App\Core\Config::get('limits.photo_max_mb') ?> MB each</span>
      </div>
    </div>
  </form>
<?php endif; ?>

<div class="gallery-grid animate-fadeup" style="animation-delay:.1s">
  <?php foreach ($photos as $photo): ?>
    <div class="photo-card">
      <div class="photo-card-img">
        <img src="<?= e(url('/uploads/' . rawurlencode((string) $photo['filename']))) ?>" alt="" loading="lazy"
             onerror="this.src='<?= e(asset_url('img/placeholder.svg')) ?>'">
        <?php if (!$isPortrait): ?>
          <span class="photo-usage"><?= (int) $photo['usage_count'] ?> asset(s)</span>
        <?php endif; ?>
      </div>
      <div class="photo-card-body">
        <div class="photo-card-name" title="<?= e($photo['original_name']) ?>"><?= e($photo['original_name']) ?></div>
        <div class="photo-card-meta">
          <?= $isPortrait ? '' : e($photo['variety'] !== '' ? $photo['variety'] : 'No variety') . ' · ' ?><?= round((int) $photo['size'] / 1024) ?> KB
        </div>
        <?php if (!$isPortrait && !empty($photo['first_asset'])): ?>
          <a class="photo-card-link" href="<?= e(url('/assets?q=' . rawurlencode((string) $photo['first_asset']))) ?>">Linked: <?= e($photo['first_asset']) ?></a>
        <?php endif; ?>
        <?php if ($canManage): ?>
          <form method="post" action="<?= e(url('/photos/' . (int) $photo['id'] . '/delete')) ?>"
                onsubmit="return confirm('<?= $isPortrait ? 'Delete this portrait? It will be unassigned from any person.' : 'Delete this photo? It will be unlinked from all assets.' ?>')">
            <?= csrf_field() ?>
            <button class="btn btn-xs btn-danger">Delete</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if ($photos === []): ?>
    <div class="empty-grid"><div class="empty empty-lg"><?= $isPortrait ? 'No portraits yet. Upload some to get started.' : 'No photos yet. Upload some to get started.' ?></div></div>
  <?php endif; ?>
</div>
