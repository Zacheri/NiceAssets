<?php
/** @var array $asset */
/** @var bool $canModify */
/** @var bool $isAdmin */
?>
<div class="asset-card animate-fadeup">
  <a href="<?= e(url('/assets/' . (int) $asset['id'])) ?>" class="card-thumb">
    <?php if (!empty($asset['thumb'])): ?>
      <img src="<?= e(url('/uploads/' . rawurlencode((string) $asset['thumb']))) ?>" alt="" loading="lazy"
           onerror="this.src='<?= e(asset_url('img/placeholder.svg')) ?>'">
    <?php else: ?>
      <img src="<?= e(asset_url('img/placeholder.svg')) ?>" alt="" loading="lazy">
    <?php endif; ?>
    <span class="status-badge <?= status_class($asset['status']) ?>"><?= e(status_label($asset['status'])) ?></span>
  </a>
  <div class="card-body">
    <a href="<?= e(url('/assets/' . (int) $asset['id'])) ?>" class="card-tag"><?= e($asset['asset_tag']) ?></a>
    <div class="card-model"><?= e(trim(($asset['brand'] ?? '') . ' ' . ($asset['model_number'] ?? ''))) ?: '—' ?></div>
    <div class="card-meta">
      <span><?= e($asset['category_name'] ?? '—') ?></span>
      <span class="dot">·</span>
      <span><?= e($asset['department_name'] ?? 'Unassigned dept') ?></span>
      <?php if (!empty($asset['location_name'])): ?>
        <span class="dot">·</span>
        <span><?= e($asset['location_name']) ?></span>
      <?php endif; ?>
    </div>
    <div class="card-meta">
      <span><?= $asset['assigned_name'] ?? ($asset['assigned_dept_name'] ?? 'Unassigned') ?></span>
      <span class="dot">·</span>
      <span><?= money($asset['purchase_cost']) ?></span>
    </div>
  </div>
  <div class="card-actions">
    <?php if ($canModify): ?>
      <?php if ($asset['status'] === 'available'): ?>
        <button class="btn btn-sm btn-primary" data-action="check-out" data-asset="<?= (int) $asset['id'] ?>">Check out</button>
      <?php elseif (!in_array($asset['status'], ['available', 'disposed', 'sold', 'donated'], true)): ?>
        <button class="btn btn-sm" data-action="check-in" data-asset="<?= (int) $asset['id'] ?>" data-confirm="Return this asset to stock?">Check in</button>
      <?php endif; ?>
      <a class="btn btn-sm" href="<?= e(url('/assets/' . (int) $asset['id'] . '/edit')) ?>">Edit</a>
    <?php endif; ?>
    <a class="btn btn-sm btn-ghost" href="<?= e(url('/assets/' . (int) $asset['id'] . '/sheet')) ?>" target="_blank">Print</a>
    <?php if ($canModify): ?>
      <div class="dropdown">
        <button class="btn btn-sm btn-ghost dropdown-toggle" type="button" aria-label="More actions">⋯</button>
        <div class="dropdown-menu">
          <?php if (!in_array($asset['status'], ['disposed', 'sold', 'donated'], true)): ?>
            <button type="button" class="dropdown-item" data-action="repair" data-asset="<?= (int) $asset['id'] ?>">Send to repair</button>
            <button type="button" class="dropdown-item" data-action="broken" data-asset="<?= (int) $asset['id'] ?>">Mark broken</button>
            <button type="button" class="dropdown-item" data-action="lost" data-asset="<?= (int) $asset['id'] ?>">Mark lost</button>
          <?php endif; ?>
          <?php if ($asset['status'] === 'checked_out' && !empty($asset['assigned_name'])): ?>
            <button type="button" class="dropdown-item" data-action="transfer" data-asset="<?= (int) $asset['id'] ?>">Transfer to person…</button>
          <?php endif; ?>
          <?php if ($asset['status'] !== 'disposed'): ?>
            <button type="button" class="dropdown-item" data-action="dispose" data-asset="<?= (int) $asset['id'] ?>">Dispose</button>
          <?php endif; ?>
          <?php if ($asset['status'] !== 'sold'): ?>
            <button type="button" class="dropdown-item" data-action="sell" data-asset="<?= (int) $asset['id'] ?>">Sell</button>
          <?php endif; ?>
          <?php if ($asset['status'] !== 'donated'): ?>
            <button type="button" class="dropdown-item" data-action="donate" data-asset="<?= (int) $asset['id'] ?>">Donate</button>
          <?php endif; ?>
          <button type="button" class="dropdown-item" data-action="replicate" data-asset="<?= (int) $asset['id'] ?>">Replicate asset</button>
          <button type="button" class="dropdown-item" data-action="email" data-asset="<?= (int) $asset['id'] ?>">Email details</button>
          <?php if ($isAdmin): ?>
            <button type="button" class="dropdown-item danger" data-action="delete" data-asset="<?= (int) $asset['id'] ?>">Delete asset</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
