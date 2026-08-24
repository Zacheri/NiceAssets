<?php
/** @var array $backups */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Backups</h1>
    <div class="page-sub">
      PostgreSQL custom-format dumps (pg_dump -Fc) plus a photo archive. An automated job runs daily at 02:00;
      the newest <?= (int) \App\Core\Config::get('limits.backup_keep') ?> are kept.
    </div>
  </div>
  <div class="page-actions">
    <form method="post" action="<?= e(url('/admin/backups/run')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-primary">Run backup now</button>
    </form>
  </div>
</div>

<section class="panel animate-fadeup" style="animation-delay:.05s">
  <div class="panel-body">
    <table class="data">
      <thead><tr><th>Backup</th><th>Size</th><th>Created</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($backups as $b): ?>
          <tr>
            <td><strong><?= e($b['name']) ?></strong></td>
            <td><?= round($b['size'] / 1048576, 2) ?> MB</td>
            <td class="nowrap"><?= e($b['mtime']) ?></td>
            <td class="nowrap">
              <a class="btn btn-xs" href="<?= e(url('/admin/backups/' . rawurlencode($b['id']) . '/download')) ?>">Download</a>
              <form method="post" action="<?= e(url('/admin/backups/' . rawurlencode($b['id']) . '/restore')) ?>"
                    onsubmit="return confirm('RESTORE this backup? Current data will be overwritten. Make sure no one is working.')">
                <?= csrf_field() ?>
                <button class="btn btn-xs btn-danger">Restore</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($backups === []): ?>
          <tr><td colspan="4" class="empty">No backups yet. Run one now or wait for the daily 02:00 job.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="panel animate-fadeup" style="margin-top:16px;animation-delay:.1s">
  <div class="panel-body">
    <h3 class="panel-inline-title">Off-server copy (recommended)</h3>
    <p class="page-sub">
      From a terminal, copy the latest dump off this Mac:
      <code>scp -r ~/Documents/ATR/storage/backups user@another-machine:~/atr-backups/</code>
      See docs/OPERATIONS.md for the full backup/restore procedure and secondary-server setup.
    </p>
  </div>
</div>
