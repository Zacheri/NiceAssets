<?php
/** @var array $asset */
/** @var bool $canModify */
/** @var bool $isAdmin */
$dep = $asset['depreciation'];
$terminal = in_array($asset['status'], ['disposed', 'sold', 'donated'], true);
$available = $asset['status'] === 'available';
?>
<div class="page-head animate-fadeup">
  <div>
    <a class="back-link" href="<?= e(url('/assets')) ?>">‹ All assets</a>
    <h1 class="page-title"><?= e($asset['asset_tag']) ?>
      <span class="status-badge <?= status_class($asset['status']) ?>"><?= e(status_label($asset['status'])) ?></span>
    </h1>
    <div class="page-sub"><?= e(trim(($asset['brand'] ?? '') . ' ' . ($asset['model_number'] ?? ''))) ?> · <?= e($asset['category_name'] ?? 'Uncategorized') ?></div>
  </div>
  <div class="page-actions">
    <a class="btn btn-ghost" href="<?= e(url('/assets/' . (int) $asset['id'] . '/qr')) ?>" target="_blank">QR label</a>
    <a class="btn btn-ghost" href="<?= e(url('/assets/' . (int) $asset['id'] . '/sheet')) ?>" target="_blank">Print sheet</a>
    <?php if ($canModify): ?>
      <a class="btn btn-primary" href="<?= e(url('/assets/' . (int) $asset['id'] . '/edit')) ?>">Edit</a>
    <?php endif; ?>
  </div>
</div>

<div class="show-grid">
  <div class="show-main">
    <section class="panel animate-fadeup" style="animation-delay:.05s">
      <div class="panel-head">
        <h2>Photos</h2>
        <?php if ($canModify): ?>
          <a class="btn btn-ghost btn-sm" href="<?= e(url('/photos')) ?>">Open gallery</a>
        <?php endif; ?>
      </div>
      <div class="panel-body">
        <?php if ($asset['photos'] !== []): ?>
          <div class="photo-row">
            <?php foreach ($asset['photos'] as $i => $photo): ?>
              <figure class="photo-thumb <?= $photo['is_thumbnail'] ? 'thumb-active' : '' ?>">
                <img src="<?= e(url('/uploads/' . rawurlencode((string) $photo['filename']))) ?>" alt=""
                     onerror="this.src='<?= e(asset_url('img/placeholder.svg')) ?>'">
                <?php if ($photo['is_thumbnail']): ?>
                  <figcaption class="thumb-flag">Thumbnail</figcaption>
                <?php endif; ?>
                <?php if ($canModify): ?>
                  <div class="photo-tools">
                    <?php if (!$photo['is_thumbnail']): ?>
                      <form method="post" action="<?= e(url('/assets/' . (int) $asset['id'] . '/photos/thumbnail')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                        <button class="btn btn-xs" title="Set as thumbnail">★</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('/assets/' . (int) $asset['id'] . '/photos/unassign')) ?>"
                          onsubmit="return confirm('Unlink this photo from the asset?')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                      <button class="btn btn-xs btn-danger" title="Unlink">✕</button>
                    </form>
                  </div>
                <?php endif; ?>
              </figure>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="empty">No photos linked yet. <a href="<?= e(url('/photos')) ?>">Add one from the gallery</a>.</div>
        <?php endif; ?>
        <?php if ($canModify): ?>
          <form method="post" action="<?= e(url('/assets/' . (int) $asset['id'] . '/photos/assign')) ?>" class="assign-photo">
            <?= csrf_field() ?>
            <span>Link a gallery photo:</span>
            <select class="input" name="photo_id" required>
              <option value="">Choose…</option>
              <?php foreach (\App\Models\Photo::all() as $p): ?>
                <option value="<?= (int) $p['id'] ?>"><?= e($p['original_name']) ?><?= $p['variety'] ? ' — ' . e($p['variety']) : '' ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm">Link</button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <section class="panel animate-fadeup" style="animation-delay:.1s">
      <div class="panel-head"><h2>Details</h2></div>
      <div class="panel-body">
        <div class="detail-grid">
          <div><span class="dk">Asset Tag</span><span class="dv"><?= e($asset['asset_tag']) ?></span></div>
          <div><span class="dk">Serial Number</span><span class="dv"><?= e($asset['serial_number'] ?? '—') ?></span></div>
          <div><span class="dk">Model Number</span><span class="dv"><?= e($asset['model_number'] ?? '—') ?></span></div>
          <div><span class="dk">Brand</span><span class="dv"><?= e($asset['brand'] ?? '—') ?></span></div>
          <div><span class="dk">Category</span><span class="dv"><?= e($asset['category_name'] ?? '—') ?></span></div>
          <div><span class="dk">Department</span><span class="dv"><?= e($asset['department_name'] ?? '—') ?></span></div>
          <div><span class="dk">Site</span><span class="dv"><?= e($asset['site_name'] ?? '—') ?></span></div>
          <div><span class="dk">Location</span><span class="dv"><?= e($asset['location_name'] ?? '—') ?><?= $asset['location_code'] ? ' (' . e($asset['location_code']) . ')' : '' ?></span></div>
          <div><span class="dk">Assigned To</span><span class="dv"><?= e($asset['assigned_name'] ?? ($asset['assigned_dept_name'] ?? '—')) ?></span></div>
          <div><span class="dk">Due Date</span><span class="dv <?= !empty($asset['due_date']) && strtotime((string) $asset['due_date']) < time() && $asset['status'] === 'checked_out' ? 'text-danger' : '' ?>"><?= date_fmt($asset['due_date']) ?></span></div>
          <div><span class="dk">Purchase Date</span><span class="dv"><?= date_fmt($asset['purchase_date']) ?></span></div>
          <div><span class="dk">Purchase Cost</span><span class="dv"><?= money($asset['purchase_cost']) ?></span></div>
          <div><span class="dk">Warranty Expires</span><span class="dv"><?= date_fmt($asset['warranty_expiration']) ?></span></div>
          <div><span class="dk">Sub-Quantity</span><span class="dv"><?= (int) $asset['sub_quantity'] ?></span></div>
          <?php if (!empty($asset['status_reason'])): ?>
            <div class="wide"><span class="dk">Status Reason</span><span class="dv"><?= e($asset['status_reason']) ?></span></div>
          <?php endif; ?>
          <?php if ($asset['status'] === 'disposed'): ?>
            <div><span class="dk">Disposed At</span><span class="dv"><?= e($asset['disposal_location'] ?? '—') ?> on <?= date_fmt($asset['disposal_date']) ?></span></div>
            <div><span class="dk">Remaining Cost</span><span class="dv"><?= $asset['disposal_remaining_cost'] !== null ? money($asset['disposal_remaining_cost']) : '—' ?></span></div>
          <?php endif; ?>
          <?php if ($asset['status'] === 'sold'): ?>
            <div><span class="dk">Sold To</span><span class="dv"><?= e($asset['sold_to'] ?? '—') ?></span></div>
            <div><span class="dk">Sale Price</span><span class="dv"><?= $asset['sold_price'] !== null ? money($asset['sold_price']) : '—' ?></span></div>
            <div><span class="dk">Sale Date</span><span class="dv"><?= date_fmt($asset['sold_date']) ?></span></div>
          <?php endif; ?>
          <?php if ($asset['status'] === 'donated'): ?>
            <div><span class="dk">Donated To</span><span class="dv"><?= e($asset['donated_to'] ?? '—') ?></span></div>
            <div><span class="dk">Donation Value</span><span class="dv"><?= $asset['donated_value'] !== null ? money($asset['donated_value']) : '—' ?></span></div>
            <div><span class="dk">Donation Date</span><span class="dv"><?= date_fmt($asset['donated_date']) ?></span></div>
          <?php endif; ?>
          <div><span class="dk">Created</span><span class="dv"><?= date_fmt($asset['created_at'], 'M j, Y g:i A') ?> by <?= e($asset['created_by_name'] ?? '—') ?></span></div>
          <div><span class="dk">Last Updated</span><span class="dv"><?= date_fmt($asset['updated_at'], 'M j, Y g:i A') ?></span></div>
        </div>
      </div>
    </section>

    <?php if ($asset['wo_number']): ?>
      <section class="panel animate-fadeup" style="animation-delay:.15s">
        <div class="panel-head"><h2>Work Order</h2>
          <?php if ($canModify && $asset['wo_status'] !== 'completed'): ?>
            <form method="post" action="<?= e(url('/work-orders/' . (int) $asset['work_order_id'] . '/complete')) ?>">
              <?= csrf_field() ?>
              <button class="btn btn-sm btn-primary">Complete WO</button>
            </form>
          <?php endif; ?>
        </div>
        <div class="panel-body">
          <div class="detail-grid">
            <div><span class="dk">Number</span><span class="dv"><?= e($asset['wo_number']) ?></span></div>
            <div><span class="dk">Status</span><span class="dv"><?= e(ucfirst((string) $asset['wo_status'])) ?></span></div>
            <div class="wide"><span class="dk">Summary</span><span class="dv"><?= e($asset['wo_summary'] ?? '—') ?></span></div>
            <?php if (!empty($asset['wo_details'])): ?>
              <div class="wide"><span class="dk">Details</span><span class="dv"><?= e($asset['wo_details']) ?></span></div>
            <?php endif; ?>
            <div><span class="dk">Opened</span><span class="dv"><?= date_fmt($asset['wo_created_at'], 'M j, Y g:i A') ?></span></div>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <section class="panel animate-fadeup" style="animation-delay:.2s">
      <div class="panel-head"><h2>History</h2></div>
      <div class="panel-body">
        <ul class="timeline">
          <?php foreach ($asset['audit'] as $row): ?>
            <li>
              <span class="timeline-dot"></span>
              <div>
                <strong><?= e(action_label($row['action'])) ?></strong>
                <span class="timeline-who">by <?= e($row['username']) ?></span>
                <div class="timeline-when"><?= e(date('M j, Y g:i A', strtotime($row['created_at']))) ?></div>
                <?php
                $d = json_decode((string) $row['details'], true) ?: [];
                $bits = array_filter($d, static fn ($v) => $v !== null && $v !== '');
                if ($bits !== []): ?>
                  <div class="timeline-details"><?= e(implode(' · ', array_map(static fn ($k, $v) => $k . ': ' . (is_scalar($v) ? $v : json_encode($v)), array_keys($bits), $bits))) ?></div>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
          <?php if ($asset['audit'] === []): ?><li class="empty">No recorded history yet.</li><?php endif; ?>
        </ul>
      </div>
    </section>
  </div>

  <div class="show-side">
    <section class="panel animate-fadeup" style="animation-delay:.05s">
      <div class="panel-head"><h2>Lifecycle</h2></div>
      <div class="panel-body action-list">
        <?php if ($canModify): ?>
          <?php if ($available): ?>
            <button class="btn btn-primary btn-block" data-action="check-out" data-asset="<?= (int) $asset['id'] ?>">Check out</button>
          <?php elseif (!$terminal): ?>
            <button class="btn btn-block" data-action="check-in" data-asset="<?= (int) $asset['id'] ?>" data-confirm="Return this asset to stock?">Check in (IT support)</button>
          <?php endif; ?>
          <?php if ($asset['status'] === 'checked_out' && !empty($asset['assigned_to_person_id'])): ?>
            <button class="btn btn-block" data-action="transfer" data-asset="<?= (int) $asset['id'] ?>">Transfer to person…</button>
          <?php endif; ?>
          <?php if (!$terminal): ?>
            <button class="btn btn-block" data-action="repair" data-asset="<?= (int) $asset['id'] ?>">Send to repair</button>
            <button class="btn btn-block" data-action="broken" data-asset="<?= (int) $asset['id'] ?>">Mark broken</button>
            <button class="btn btn-block" data-action="lost" data-asset="<?= (int) $asset['id'] ?>">Mark lost</button>
          <?php endif; ?>
          <?php if ($asset['status'] !== 'disposed'): ?>
            <button class="btn btn-block" data-action="dispose" data-asset="<?= (int) $asset['id'] ?>">Dispose</button>
          <?php endif; ?>
          <?php if ($asset['status'] !== 'sold'): ?>
            <button class="btn btn-block" data-action="sell" data-asset="<?= (int) $asset['id'] ?>">Sell</button>
          <?php endif; ?>
          <?php if ($asset['status'] !== 'donated'): ?>
            <button class="btn btn-block" data-action="donate" data-asset="<?= (int) $asset['id'] ?>">Donate</button>
          <?php endif; ?>
          <div class="btn-sep"></div>
          <button class="btn btn-ghost btn-block" data-action="replicate" data-asset="<?= (int) $asset['id'] ?>">Replicate asset</button>
          <button class="btn btn-ghost btn-block" data-action="email" data-asset="<?= (int) $asset['id'] ?>">Email details</button>
          <?php if ($isAdmin): ?>
            <button class="btn btn-danger btn-block" data-action="delete" data-asset="<?= (int) $asset['id'] ?>">Delete asset</button>
          <?php endif; ?>
        <?php else: ?>
          <div class="empty">Read-only access. Viewers cannot modify assets.</div>
        <?php endif; ?>
      </div>
    </section>

    <section class="panel animate-fadeup" style="animation-delay:.1s">
      <div class="panel-head"><h2>Depreciation</h2></div>
      <div class="panel-body">
        <?php if (empty($asset['purchase_date'])): ?>
          <div class="empty">Set a purchase date to calculate depreciation.</div>
        <?php else: ?>
          <div class="dep-line"><span>Current value</span><strong><?= money($dep['value']) ?></strong></div>
          <div class="dep-line"><span>Purchase cost</span><span><?= money($asset['purchase_cost']) ?></span></div>
          <div class="dep-line"><span>Annual (5-yr linear)</span><span><?= money($dep['annual']) ?></span></div>
          <div class="dep-line"><span>Accumulated</span><span><?= money($dep['accumulated']) ?></span></div>
          <div class="dep-bar"><div class="dep-fill" style="width: <?= (float) $dep['progress'] ?>%"></div></div>
          <?php if ($dep['fully']): ?>
            <div class="dep-note note-warn">Fully depreciated.</div>
          <?php else: ?>
            <div class="dep-note"><?= (int) $dep['remaining_months'] ?> months remaining · <?= number_format($dep['progress'], 1) ?>% depreciated</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<script>
window.ATR.canModify = <?= $canModify ? 'true' : 'false' ?>;
window.ATR.isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
window.ATR.asset = {
  id: <?= (int) $asset['id'] ?>,
  tag: '<?= e($asset['asset_tag']) ?>',
  assignedEmail: '<?= e($asset['assigned_email'] ?? '') ?>',
  assignedName: '<?= e($asset['assigned_name'] ?? '') ?>'
};
</script>
