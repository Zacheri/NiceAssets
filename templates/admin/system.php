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

<section class="panel animate-fadeup" style="margin-top:16px;animation-delay:.2s">
  <div class="panel-head"><h2>AI Assistant <span class="pill" id="llm-pill">…</span></div>
  <div class="panel-body">
    <p class="table-note">Local natural-language access to the inventory, powered by a GGUF model run on <code>llama-server</code> (127.0.0.1:<?= (int) $llm['port'] ?>). Nothing leaves this machine.</p>
    <div class="detail-grid">
      <div><span class="dk">llama.cpp binary</span><span class="dv" id="llm-binary"><?= e($llm['state']['binary'] ?? '') !== '' ? e($llm['state']['binary']) : 'not installed' ?></span></div>
      <div><span class="dk">Models folder</span><span class="dv"><code><?= e($llm['models_dir']) ?></code></span></div>
      <div><span class="dk">Context length</span><span class="dv" id="llm-context"><?= (int) $llm['context'] ?></span></div>
      <div><span class="dk">Selected model</span><span class="dv" id="llm-selected"><?= e($llm['state']['selected'] ?? '') !== '' ? e($llm['state']['selected']) : '—' ?></span></div>
    </div>

    <div class="form-grid" style="margin-top:12px">
      <label class="field"><span>Model</span>
        <select id="llm-model">
          <?php foreach ($llm['state']['models'] as $m): ?>
            <option value="<?= e($m['name']) ?>" <?= ($m['name'] === ($llm['state']['selected'] ?? '')) ? 'selected' : '' ?>><?= e($m['name']) ?> (<?= e(round($m['size'] / 1048576)) ?> MB)</option>
          <?php endforeach; ?>
          <?php if (($llm['state']['models'] ?? []) === []): ?><option value="">No .gguf files found</option><?php endif; ?>
        </select>
      </label>
      <label class="field"><span>Port</span><input type="number" id="llm-port" value="<?= (int) $llm['port'] ?>" min="1024" max="65535"></label>
      <label class="field"><span>Context</span><input type="number" id="llm-context-input" value="<?= (int) $llm['context'] ?>" min="2048" max="32768" step="1024"></label>
    </div>

    <div class="page-actions" style="margin-top:12px">
      <?php if (($llm['state']['binary'] ?? null) === null): ?>
        <button type="button" class="btn btn-primary" id="llm-install">Install llama.cpp (brew)</button>
      <?php endif; ?>
      <button type="button" class="btn" id="llm-select">Select model</button>
      <button type="button" class="btn" id="llm-config">Save settings</button>
      <button type="button" class="btn btn-primary" id="llm-start">Start</button>
      <button type="button" class="btn btn-ghost" id="llm-stop">Stop</button>
    </div>
    <div class="table-note" id="llm-msg" style="margin-top:10px"></div>
  </div>
</section>

<script>
(function () {
  'use strict';
  var ATR = window.ATR || {};
  var base = ATR.base || '';
  var token = ATR.token || '';
  var $ = function (id) { return document.getElementById(id); };
  var pill = $('llm-pill');
  var msg = $('llm-msg');

  function post(path, data, done) {
    var fd = new FormData();
    fd.append('_token', token);
    Object.keys(data || {}).forEach(function (k) { if (data[k] !== null && data[k] !== undefined) fd.append(k, data[k]); });
    fetch(base + path, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, json: j }; }); })
      .then(done)
      .catch(function () { done({ ok: false, json: { error: 'Network error.' } }); });
  }
  function say(t, bad) {
    msg.textContent = t;
    msg.style.color = bad ? 'var(--red)' : 'var(--muted)';
  }
  function refresh() {
    fetch(base + '/admin/llm/state', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (s) {
        var sel = $('llm-selected');
        if (sel) sel.textContent = s.selected || '—';
        var bin = $('llm-binary');
        if (bin) bin.textContent = s.binary || 'not installed';
        if (pill) {
          pill.textContent = s.status;
          pill.className = 'pill ' + (s.status === 'ready' && s.matches_selected ? 'pill-green' : s.status === 'loading' ? 'pill-amber' : 'pill-gray');
        }
      })
      .catch(function () {});
  }
  Array.prototype.forEach.call(document.querySelectorAll('#llm-select,#llm-config,#llm-start,#llm-stop,#llm-install'), function (btn) {
    btn.addEventListener('click', function () {
      btn.disabled = true;
      say('Working…');
      if (btn.id === 'llm-select') {
        post('/admin/llm/select', { model: $('llm-model').value }, function (r) { btn.disabled = false; say(r.json && r.json.error ? r.json.error : 'Model selected.', !r.ok); refresh(); });
      } else if (btn.id === 'llm-config') {
        post('/admin/llm/config', { port: $('llm-port').value, context: $('llm-context-input').value }, function (r) { btn.disabled = false; say(r.json && (r.json.error || r.json.note), !r.ok); });
      } else if (btn.id === 'llm-start') {
        post('/admin/llm/start', {}, function (r) { btn.disabled = false; say(r.json && (r.json.error || r.json.note), !r.ok); refresh(); });
      } else if (btn.id === 'llm-stop') {
        post('/admin/llm/stop', {}, function (r) { btn.disabled = false; say(r.json && r.json.error ? r.json.error : 'Stopped.', !r.ok); refresh(); });
      } else if (btn.id === 'llm-install') {
        say('Installing llama.cpp via brew — this can take a few minutes…');
        post('/admin/llm/install', {}, function (r) { btn.disabled = false; say(r.json && (r.json.message || r.json.error), !r.ok); refresh(); });
      }
    });
  });
  refresh();
  setInterval(refresh, 5000);
})();
</script>
