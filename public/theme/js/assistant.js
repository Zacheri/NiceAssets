/* ATR Assistant — chat behavior */
(function () {
  'use strict';

  var ATR = window.ATR || {};
  var base = ATR.base || '';
  var token = ATR.token || '';

  var stateEl = document.getElementById('assistant-state');
  var messagesEl = document.getElementById('assistant-messages');
  var inputEl = document.getElementById('assistant-input');
  var sendEl = document.getElementById('assistant-send');
  var clearEl = document.getElementById('assistant-clear');
  if (!stateEl || !messagesEl || !inputEl || !sendEl) return;

  var LS_KEY = 'atr_assistant_history';
  var busy = false;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function post(path, done) {
    var fd = new FormData();
    fd.append('_token', token);
    fetch(base + path, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, json: j }; });
      })
      .then(done)
      .catch(function () { done({ ok: false, json: { error: 'Network error — is the app server up?' } }); });
  }

  /* ---------- state chip (poll) ---------- */
  function renderState(st) {
    if (!st || !st.binary) {
      stateEl.className = 'assistant-state is-off';
      stateEl.textContent = 'LLM not installed — an admin can install it from System';
      return;
    }
    if (!st.selected) {
      stateEl.className = 'assistant-state is-warn';
      stateEl.textContent = 'No model found — drop a .gguf into the models folder (Admin → System)';
      return;
    }
    var name = st.model || st.selected || '';
    if (st.status === 'ready') {
      if (st.matches_selected) {
        stateEl.className = 'assistant-state is-on';
        stateEl.textContent = 'Model ready — ' + name;
      } else {
        stateEl.className = 'assistant-state is-warn';
        stateEl.textContent = 'Loaded model differs from selection — it restarts on the next reply';
      }
    } else if (st.status === 'loading') {
      stateEl.className = 'assistant-state is-warn';
      stateEl.textContent = 'Loading model (first reply is slower)…';
    } else {
      stateEl.className = 'assistant-state is-off';
      stateEl.textContent = 'Model stopped — ' + name;
    }
  }
  function pollState() {
    fetch(base + '/assistant/state', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(renderState)
      .catch(function () { renderState(null); });
  }
  pollState();
  setInterval(pollState, 4000);

  /* ---------- messages ---------- */
  function loadHistory() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || '[]') || []; } catch (e) { return []; }
  }
  function saveHistory(list) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(list.slice(-40))); } catch (e) {}
  }
  function mdLite(text) {
    return esc(text).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\n/g, '<br>');
  }
  function pushBubble(role, html) {
    var row = document.createElement('div');
    row.className = 'msg ' + role;
    var body = document.createElement('div');
    body.className = 'msg-body';
    body.innerHTML = html;
    row.appendChild(body);
    messagesEl.appendChild(row);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return body;
  }
  function addMsg(role, text) {
    var list = loadHistory();
    list.push({ role: role, text: text });
    saveHistory(list);
    return pushBubble(role, mdLite(text));
  }

  function renderResultRows(results) {
    results.forEach(function (r) {
      var res = r.result || {};
      if (Object.prototype.toString.call(res.items) === '[object Array]') {
        res.items.forEach(function (it) {
          pushBubble('assistant', (it.ok === 1 ? '✓ ' : '✗ ') + esc(it.tag) + ' — ' + esc(it.reason));
        });
        if (res.ok !== true) {
          pushBubble('assistant', '⚠ ' + (res.checked_in || 0) + ' of ' + (res.total || 0) + ' succeeded.');
        }
        return;
      }
      var err = res.error;
      var msg = err ? String(err) : String(res.message || res.note || 'Done.');
      pushBubble('assistant', (err ? '✗ ' : '✓ ') + esc(msg));
    });
  }

  function renderPlanCard(plan) {
    var card = document.createElement('div');
    card.className = 'assistant-plan';
    var lines = plan.map(function (op) { return esc(op.preview); }).join('<br>');
    card.innerHTML =
      '<div class="assistant-plan-head">Please confirm:</div>' +
      '<div class="assistant-plan-list">' + lines + '</div>' +
      '<div class="assistant-plan-actions">' +
      '<button type="button" class="btn btn-primary btn-sm" id="plan-confirm">Confirm</button>' +
      '<button type="button" class="btn btn-ghost btn-sm" id="plan-cancel">Cancel</button>' +
      '</div>';
    messagesEl.appendChild(card);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    card.querySelector('#plan-confirm').addEventListener('click', function () {
      card.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      post('/assistant/confirm', function (res) {
        card.remove();
        if (!res.ok || !res.json || !res.json.results) {
          pushBubble('assistant', '⚠ ' + esc((res.json && res.json.error) || 'Confirm failed.'));
          return;
        }
        renderResultRows(res.json.results);
      });
    });
    card.querySelector('#plan-cancel').addEventListener('click', function () {
      post('/assistant/cancel', function () {});
      card.remove();
      pushBubble('assistant', 'Plan cancelled — nothing was changed.');
    });
  }

  /* ---------- composer ---------- */
  function setBusy(b) {
    busy = b;
    sendEl.disabled = b;
  }
  function send() {
    var text = inputEl.value.trim();
    if (!text || busy) return;
    setBusy(true);
    inputEl.value = '';
    addMsg('user', text);
    var typing = pushBubble('assistant', '<span class="msg-typing">Thinking…</span>');

    var fd = new FormData();
    fd.append('_token', token);
    fd.append('message', text);
    fetch(base + '/assistant/chat', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, json: j }; });
      })
      .then(function (res) {
        messagesEl.querySelectorAll('.assistant-plan').forEach(function (c) { c.remove(); });
        if (!res.ok || !res.json || res.json.error) {
          typing.innerHTML = '⚠ ' + esc((res.json && res.json.error) || 'Request failed.');
          setBusy(false);
          return;
        }
        typing.innerHTML = mdLite(res.json.text || '(no reply)');
        if (res.json.plan && res.json.plan.length) renderPlanCard(res.json.plan);
        setBusy(false);
        inputEl.focus();
      })
      .catch(function () {
        messagesEl.querySelectorAll('.assistant-plan').forEach(function (c) { c.remove(); });
        typing.innerHTML = '⚠ Network error — could not reach the assistant.';
        setBusy(false);
      });
  }
  sendEl.addEventListener('click', send);
  inputEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });
  if (clearEl) {
    clearEl.addEventListener('click', function () {
      if (busy) return;
      post('/assistant/clear', function (res) {
        messagesEl.innerHTML = '';
        try { localStorage.removeItem(LS_KEY); } catch (e) {}
        if (!res.ok) pushBubble('assistant', '⚠ Could not clear the server-side history.');
        pollState();
      });
    });
  }

  /* ---------- restore ---------- */
  var hist = loadHistory();
  if (hist.length === 0) {
    pushBubble('assistant', 'Hi — ask me about assets and people, e.g. "who has 00001?" or "check in everything tessa is holding". Actions always need your confirmation first.');
  } else {
    hist.forEach(function (m) { pushBubble(m.role || 'assistant', mdLite(m.text)); });
  }
  inputEl.focus();
})();
