<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Assistant</h1>
    <div class="page-sub">Ask in plain language. Read answers are instant — any action needs your confirmation first.</div>
  </div>
  <div class="page-actions">
    <button type="button" class="btn btn-ghost btn-sm" id="assistant-clear">Clear conversation</button>
  </div>
</div>

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
