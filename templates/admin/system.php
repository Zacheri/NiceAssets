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
      <li>Web (nginx + PHP-FPM) — container entrypoint, starts with the app</li>
      <li>PostgreSQL — separate container, starts with the app</li>
      <li>Daily backup — 02:00 (cron)</li>
      <li>Weekly activity report — Saturday 18:00 (cron)</li>
      <li>Alert sweep (email notifications) — every 15 minutes (cron)</li>
    </ul>
    <div class="table-note">Health endpoint for monitoring: <code>/healthz</code> (returns JSON, no login required).</div>
  </div>
</section>

<section class="panel animate-fadeup" style="margin-top:16px;animation-delay:.2s">
  <div class="panel-head"><h2>AI Assistant <span class="pill pill-gray" id="llm-pill">…</span></div>
  <div class="panel-body">
    <div class="detail-grid">
      <div><span class="dk">llama.cpp binary</span><span class="dv" id="llm-binary">…</span></div>
      <div><span class="dk">Loaded model</span><span class="dv" id="llm-model">—</span></div>
      <div><span class="dk">Models folder</span><span class="dv"><code><?= e($llm['models_dir']) ?></code></span></div>
    </div>
    <p class="table-note" style="margin-top:12px">Upload models and manage the model server from the <a href="<?= e(url('/assistant')) ?>">Assistant tab</a>.</p>
  </div>
</section>

<script>
(function () {
  'use strict';
  var ATR = window.ATR || {};
  var base = ATR.base || '';
  function $(id) { return document.getElementById(id); }
  function refresh() {
    fetch(base + '/admin/llm/state', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (s) {
        var bin = $('llm-binary');
        if (bin) bin.textContent = s.binary || 'not found';
        var m = $('llm-model');
        if (m) m.textContent = s.model || '—';
        var pill = $('llm-pill');
        if (pill) {
          pill.textContent = s.status;
          pill.className = 'pill ' + (s.status === 'ready' && s.matches_selected ? 'pill-green' : s.status === 'loading' ? 'pill-amber' : 'pill-gray');
        }
      })
      .catch(function () {});
  }
  refresh();
  setInterval(refresh, 5000);
})();
</script>
