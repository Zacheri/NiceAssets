<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Assistant</h1>
    <div class="page-sub">Ask in plain language. Read answers are instant — any action needs your confirmation first.</div>
  </div>
  <div class="page-actions">
    <button type="button" class="btn btn-ghost btn-sm" id="assistant-clear">Clear conversation</button>
  </div>
</div>

<?php if (\App\Core\Auth::isAdmin()): ?>
<section class="panel animate-fadeup" style="margin-bottom:16px">
  <div class="panel-head"><h2>Model</h2><span class="pill pill-gray" id="model-pill">…</span></div>
  <div class="panel-body">
    <div class="form-grid">
      <label class="field"><span>Upload a .gguf model (max 12 GB)</span>
        <input type="file" id="model-file" accept=".gguf">
      </label>
      <div class="field" style="align-self:end">
        <button type="button" class="btn btn-primary" id="model-upload">Upload</button>
      </div>
    </div>
    <div id="model-progress-wrap" hidden>
      <div class="progress"><div class="progress-bar" id="model-progress"></div></div>
      <div class="table-note" id="model-progress-label">0%</div>
    </div>

    <table class="table" style="margin-top:12px">
      <thead><tr><th>Model</th><th>Size</th><th>Updated</th><th></th></tr></thead>
      <tbody id="model-tbody"></tbody>
    </table>

    <div class="form-grid" style="margin-top:12px">
      <label class="field"><span>Selected model</span>
        <select id="model-select"></select>
      </label>
      <label class="field"><span>Port</span><input type="number" id="model-port" min="1024" max="65535"></label>
      <label class="field"><span>Context</span><input type="number" id="model-context" min="2048" max="32768" step="1024"></label>
    </div>

    <div class="page-actions" style="margin-top:12px">
      <button type="button" class="btn" id="model-select-btn">Select model</button>
      <button type="button" class="btn" id="model-config">Save settings</button>
      <button type="button" class="btn btn-primary" id="model-start">Start</button>
      <button type="button" class="btn btn-ghost" id="model-stop">Stop</button>
    </div>
    <div class="table-note" id="model-msg" style="margin-top:10px"></div>
  </div>
</section>
<?php endif; ?>

<div class="assistant-wrap animate-fadeup">
  <div class="assistant-state" id="assistant-state">Checking model…</div>
  <div class="assistant-messages" id="assistant-messages"></div>
  <div class="assistant-composer">
    <textarea id="assistant-input" rows="1"
              placeholder='Try: "who has 00001?" · "check in everything tessa is holding" · "move 00001 to the IT department"'></textarea>
    <button type="button" class="btn btn-primary" id="assistant-send">Send</button>
  </div>
</div>

<script src="<?= e(asset_url('js/assistant.js')) ?>"></script>
