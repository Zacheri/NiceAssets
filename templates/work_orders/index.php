<?php
/** @var array $orders */
/** @var bool $canModify */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Work Orders</h1>
    <div class="page-sub">IT work tracking. Completing a work order returns the asset to stock.</div>
  </div>
</div>

<?php if ($canModify): ?>
  <form method="post" action="<?= e(url('/work-orders')) ?>" class="panel upload-panel animate-fadeup" style="animation-delay:.05s">
    <?= csrf_field() ?>
    <div class="upload-row">
      <label class="field"><span>Asset (optional)</span>
        <select class="input" name="wo[asset_id]">
          <option value="">— None —</option>
          <?php foreach ($assets as $a): ?>
            <option value="<?= (int) $a['id'] ?>"><?= e($a['asset_tag']) ?> · <?= e(trim(($a['brand'] ?? '') . ' ' . ($a['model_number'] ?? ''))) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field field-grow"><span>Summary *</span>
        <input class="input" type="text" name="wo[summary]" required placeholder="What needs fixing?">
      </label>
      <label class="field field-grow"><span>Details</span>
        <input class="input" type="text" name="wo[details]" placeholder="Extra notes…">
      </label>
      <div class="upload-actions"><button class="btn btn-primary">Create</button></div>
    </div>
  </form>
<?php endif; ?>

<div class="panel animate-fadeup" style="animation-delay:.1s">
  <div class="panel-body">
    <table class="data">
      <thead>
        <tr><th>Work Order</th><th>Asset</th><th>Summary</th><th>Status</th><th>Opened</th><th>Completed</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $wo): ?>
          <tr>
            <td><strong><?= e($wo['wo_number']) ?></strong></td>
            <td>
              <?php if ($wo['asset_id'] !== null): ?>
                <a href="<?= e(url('/assets/' . (int) $wo['asset_id'])) ?>"><?= e($wo['asset_tag'] ?? '—') ?></a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= e($wo['summary']) ?></td>
            <td><span class="status-badge <?= $wo['status'] === 'completed' ? 'status-available' : ($wo['status'] === 'in_progress' ? 'status-in_repair' : 'status-checked_out') ?>"><?= e(ucfirst($wo['status'])) ?></span></td>
            <td class="nowrap"><?= e(date('M j, Y', strtotime($wo['created_at']))) ?></td>
            <td class="nowrap"><?= $wo['completed_at'] ? e(date('M j, Y', strtotime($wo['completed_at']))) : '—' ?></td>
            <td class="nowrap">
              <?php if ($canModify && $wo['status'] !== 'completed' && $wo['asset_id'] !== null): ?>
                <form method="post" action="<?= e(url('/work-orders/' . (int) $wo['id'] . '/complete')) ?>">
                  <?= csrf_field() ?>
                  <button class="btn btn-xs btn-primary">Complete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($orders === []): ?>
          <tr><td colspan="7" class="empty">No work orders found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
