/* Nice Assets Assistant — chat behavior */
(function () {
  'use strict';

  var NAIMS = window.NAIMS || {};
  var base = NAIMS.base || '';
  var token = NAIMS.token || '';

  var stateEl = document.getElementById('assistant-state');
  var messagesEl = document.getElementById('assistant-messages');
  var inputEl = document.getElementById('assistant-input');
  var sendEl = document.getElementById('assistant-send');
  var clearEl = document.getElementById('assistant-clear');
  var modelFile = document.getElementById('model-file');
  if (!stateEl || !messagesEl || !inputEl || !sendEl) return;

  var LS_KEY = 'naims_assistant_history';
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
    if (st && st.external) {
      if (st.status === 'ready') {
        stateEl.className = 'assistant-state is-on';
        stateEl.textContent = 'External model ready — ' + (st.model || (st.host + ':' + st.port));
      } else {
        stateEl.className = 'assistant-state is-off';
        stateEl.textContent = 'External model server unreachable at ' + (st.host || '127.0.0.1') + ':' + (st.port || 8082);
      }
      return;
    }
    if (!st || !st.binary) {
      stateEl.className = 'assistant-state is-off';
      stateEl.textContent = 'Model server unavailable — ask an admin to check the container';
      return;
    }
    if (!st.selected) {
      stateEl.className = 'assistant-state is-warn';
      stateEl.textContent = 'No model uploaded yet — an admin can upload one from the Model panel';
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
      .then(function (st) { renderState(st); renderModels(st); })
      .catch(function () { renderState(null); renderModels(null); });
  }

  /* ---------- model panel (admin only) ---------- */
  function say(t, bad) {
    var el = document.getElementById('model-msg');
    el.textContent = t;
    el.style.color = bad ? 'var(--red)' : 'var(--muted)';
  }
  function postForm(path, data, done) {
    var fd = new FormData();
    fd.append('_token', token);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    fetch(base + path, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, json: j }; });
      })
      .then(done)
      .catch(function () { done({ ok: false, json: { error: 'Network error.' } }); });
  }

  function renderModels(st) {
    if (!modelFile) return;
    var models = (st && st.models) || [];
    var tbody = document.getElementById('model-tbody');
    tbody.innerHTML = '';
    models.forEach(function (m) {
      var tr = document.createElement('tr');
      var tdName = document.createElement('td');
      tdName.textContent = m.name;
      var tdSize = document.createElement('td');
      tdSize.textContent = Math.round(m.size / 1048576) + ' MB';
      var tdDate = document.createElement('td');
      tdDate.textContent = new Date(m.mtime * 1000).toLocaleDateString();
      var tdAct = document.createElement('td');
      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn btn-ghost btn-sm';
      del.textContent = 'Delete';
      del.disabled = !!(st && st.external);
      del.addEventListener('click', function () {
        if (!confirm('Delete ' + m.name + '?')) return;
        postForm('/admin/llm/delete', { model: m.name }, function (res) {
          say(res.ok ? 'Deleted.' : ((res.json && res.json.error) || 'Delete failed.'), !res.ok);
          pollState();
        });
      });
      tdAct.appendChild(del);
      tr.appendChild(tdName); tr.appendChild(tdSize); tr.appendChild(tdDate); tr.appendChild(tdAct);
      tbody.appendChild(tr);
    });
    if (models.length === 0) {
      var tr = document.createElement('tr');
      var td = document.createElement('td');
      td.colSpan = 4;
      td.className = 'table-note';
      td.textContent = 'No models yet — upload a .gguf file above.';
      tr.appendChild(td);
      tbody.appendChild(tr);
    }
    var select = document.getElementById('model-select');
    select.innerHTML = models.length
      ? models.map(function (m) {
          return '<option value="' + esc(m.name) + '"' + (st && st.selected === m.name ? ' selected' : '') + '>' + esc(m.name) + ' (' + Math.round(m.size / 1048576) + ' MB)</option>';
        }).join('')
      : '<option value="">No models</option>';
    if (st) {
      document.getElementById('model-port').value = st.port || 8082;
      document.getElementById('model-context').value = st.context || 8192;
      document.getElementById('model-host').value = st.host || '127.0.0.1';
      document.getElementById('model-external').checked = !!st.external;
      var ext = !!st.external;
      ['model-upload', 'model-file', 'model-select', 'model-select-btn', 'model-start', 'model-stop'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.disabled = ext;
      });
      var banner = document.getElementById('model-external-banner');
      if (ext) {
        banner.hidden = false;
        banner.textContent = 'Externally managed at ' + (st.host || '127.0.0.1') + ':' + (st.port || 8082)
          + (st.status === 'ready' ? ' — Running: ' + (st.model || '') : ' — unreachable');
      } else {
        banner.hidden = true;
        banner.textContent = '';
      }
      var pill = document.getElementById('model-pill');
      pill.textContent = st.status;
      pill.className = 'pill ' + (st.status === 'ready' && st.matches_selected ? 'pill-green' : st.status === 'loading' ? 'pill-amber' : 'pill-gray');
    }
  }

  if (modelFile) {
    document.getElementById('model-upload').addEventListener('click', function () {
      var file = modelFile.files && modelFile.files[0];
      if (!file) { say('Choose a .gguf file first.', true); return; }
      var wrap = document.getElementById('model-progress-wrap');
      var bar = document.getElementById('model-progress');
      var label = document.getElementById('model-progress-label');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', base + '/admin/llm/upload');
      xhr.upload.onprogress = function (e) {
        if (!e.lengthComputable) return;
        var pct = Math.round((e.loaded / e.total) * 100);
        bar.style.width = pct + '%';
        label.textContent = pct + '%';
      };
      function done(ok, msg) {
        wrap.hidden = true;
        bar.style.width = '0';
        label.textContent = '0%';
        say(msg, !ok);
        if (ok) { modelFile.value = ''; pollState(); }
      }
      xhr.onload = function () {
        var j = {};
        try { j = JSON.parse(xhr.responseText); } catch (e2) {}
        if (xhr.status >= 200 && xhr.status < 300 && j.ok) done(true, 'Uploaded ' + j.model + '.');
        else done(false, (j && j.error) || 'Upload failed.');
      };
      xhr.onerror = function () { done(false, 'Upload failed — network error.'); };
      wrap.hidden = false;
      var fd = new FormData();
      fd.append('_token', token);
      fd.append('model', file);
      xhr.send(fd);
    });

    document.getElementById('model-select-btn').addEventListener('click', function () {
      postForm('/admin/llm/select', { model: document.getElementById('model-select').value }, function (res) {
        say(res.ok ? 'Model selected.' : ((res.json && res.json.error) || 'Failed.'), !res.ok);
        pollState();
      });
    });
    document.getElementById('model-config').addEventListener('click', function () {
      postForm('/admin/llm/config', {
        port: document.getElementById('model-port').value,
        context: document.getElementById('model-context').value,
        host: document.getElementById('model-host').value,
        external: document.getElementById('model-external').checked ? '1' : '0'
      }, function (res) {
        say(res.ok ? ((res.json && res.json.note) || 'Saved.') : ((res.json && res.json.error) || 'Failed.'), !res.ok);
      });
    });
    document.getElementById('model-start').addEventListener('click', function () {
      postForm('/admin/llm/start', {}, function (res) {
        say(res.ok ? ((res.json && res.json.note) || 'Starting…') : ((res.json && res.json.error) || 'Failed.'), !res.ok);
        pollState();
      });
    });
    document.getElementById('model-stop').addEventListener('click', function () {
      postForm('/admin/llm/stop', {}, function (res) {
        say(res.ok ? 'Stopped.' : ((res.json && res.json.error) || 'Failed.'), !res.ok);
        pollState();
      });
    });
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
