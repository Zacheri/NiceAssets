<?php
/** @var string $version */
/** @var string $php */
/** @var string $os */
/** @var string $postgres */
/** @var string $db_size */
/** @var array $storage */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">System</h1>
    <div class="page-sub">Deployment and health overview.</div>
  </div>
</div>

<section class="panel animate-fadeup" style="animation-delay:.05s">
  <div class="panel-body">
    <div class="detail-grid">
      <div><span class="dk">ATR Inventory version</span><span class="dv"><strong>v<?= e($version) ?></strong></span></div>
      <div><span class="dk">PHP</span><span class="dv"><?= e($php) ?></span></div>
      <div><span class="dk">Operating system</span><span class="dv"><?= e($os) ?></span></div>
      <div><span class="dk">PostgreSQL</span><span class="dv"><?= e(explode(' ', $postgres, 3)[1] ?? $postgres) ?></span></div>
      <div><span class="dk">Database size</span><span class="dv"><?= e($db_size) ?></span></div>
      <div><span class="dk">Time</span><span class="dv"><?= e(date('F j, Y g:i A T')) ?></span></div>
    </div>
  </div>
</section>

<section class="panel animate-fadeup" style="margin-top:16px;animation-delay:.1s">
  <div class="panel-head"><h2>Storage</h2></div>
  <div class="panel-body">
    <div class="detail-grid">
      <div><span class="dk">Photo uploads</span><span class="dv"><?= e($storage['uploads']) ?></span></div>
      <div><span class="dk">Backups</span><span class="dv"><?= e($storage['backups']) ?></span></div>
      <div><span class="dk">Generated reports</span><span class="dv"><?= e($storage['reports']) ?></span></div>
    </div>
  </div>
</section>

<section class="panel animate-fadeup" style="margin-top:16px;animation-delay:.15s">
  <div class="panel-head"><h2>Background services</h2></div>
  <div class="panel-body">
    <ul class="checklist">
      <li>Nginx + PHP-FPM — managed by launchd (brew services), starts at boot</li>
      <li>PostgreSQL — managed by launchd (brew services), starts at boot</li>
      <li>Daily backup — 02:00 (launchd agent)</li>
      <li>Weekly activity report — Saturday 18:00 (launchd agent)</li>
      <li>Alert sweep (email notifications) — every 15 minutes (launchd agent)</li>
    </ul>
    <div class="table-note">Health endpoint for monitoring: <code>/healthz</code> (returns JSON, no login required).</div>
  </div>
</section>
