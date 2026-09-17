/* Nice Assets — frontend behavior */
(function () {
  'use strict';

  var NAIMS = window.NAIMS || (window.NAIMS = {});
  var base = NAIMS.base || '';

  /* ---------- sidebar (mobile) ---------- */
  var sidebar = document.getElementById('sidebar');
  var hamburger = document.getElementById('hamburger');
  if (sidebar && hamburger) {
    hamburger.addEventListener('click', function (e) {
      e.stopPropagation();
      sidebar.classList.toggle('open');
    });
    document.addEventListener('click', function (e) {
      if (window.innerWidth > 900) return;
      if (sidebar.classList.contains('open') && !sidebar.contains(e.target) && !hamburger.contains(e.target)) {
        sidebar.classList.remove('open');
      }
    });
  }

  /* ---------- toasts ---------- */
  var stack = document.getElementById('flash-stack');
  if (stack) {
    Array.prototype.forEach.call(stack.querySelectorAll('.toast'), function (t) {
      setTimeout(function () {
        t.style.transition = 'opacity .4s, transform .4s';
        t.style.opacity = '0';
        t.style.transform = 'translateX(20px)';
        setTimeout(function () { t.remove(); }, 420);
      }, 4500);
      t.addEventListener('click', function () { t.remove(); });
    });
  }

  /* ---------- dropdowns ---------- */
  Array.prototype.forEach.call(document.querySelectorAll('.dropdown-toggle'), function (btn) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var dd = btn.closest('.dropdown');
      var wasOpen = dd.classList.contains('open');
      Array.prototype.forEach.call(document.querySelectorAll('.dropdown.open'), function (o) { o.classList.remove('open'); });
      if (!wasOpen) dd.classList.add('open');
    });
  });
  document.addEventListener('click', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.dropdown.open'), function (o) { o.classList.remove('open'); });
  });

  /* ---------- action modal ---------- */
  var backdrop = document.getElementById('modal-backdrop');
  var modalForm = document.getElementById('modal-form');
  var modalBody = document.getElementById('modal-body');
  var modalTitle = document.getElementById('modal-title');
  var modalSubmit = document.getElementById('modal-submit');
  if (!backdrop || !modalForm) return;

  function personOptions(selectedId) {
    var persons = NAIMS.persons || [];
    return '<option value="">— Select person —</option>' + persons.map(function (p) {
      return '<option value="' + p.id + '"' + (p.id === selectedId ? ' selected' : '') + '>' + esc(p.name) + '</option>';
    }).join('');
  }

  function deptOptions(selectedId) {
    var depts = NAIMS.departments || [];
    return '<option value="">— Select department —</option>' + depts.map(function (d) {
      return '<option value="' + d.id + '"' + (d.id === selectedId ? ' selected' : '') + '>' + esc(d.name) + '</option>';
    }).join('');
  }

  var ACTIONS = {
    'check-out': {
      title: 'Check out asset',
      fields: [
        { name: 'assigned_to_person_id', label: 'Assign to person', type: 'select', options: personOptions, required: true },
        { name: 'assigned_to_department_id', label: '…or to department', type: 'select', options: deptOptions, required: true, eitherWith: 'assigned_to_person_id' },
        { name: 'due_date', label: 'Due date (optional)', type: 'date' }
      ]
    },
    'check-in': { title: 'Check in asset', confirm: 'Return this asset to stock (available) and close any open work order?' },
    'transfer': {
      title: 'Transfer to person',
      fields: [{ name: 'to_person_id', label: 'Transfer to', type: 'select', options: personOptions, required: true }]
    },
    'repair': {
      title: 'Send to repair',
      submitLabel: 'Create work order',
      fields: [
        { name: 'summary', label: 'Issue summary *', type: 'text', required: true, placeholder: 'What is wrong?' },
        { name: 'details', label: 'Details for IT', type: 'textarea' }
      ]
    },
    'broken': {
      title: 'Mark as broken',
      submitLabel: 'Mark broken',
      fields: [{ name: 'status_reason', label: 'Reason *', type: 'textarea', required: true }]
    },
    'lost': {
      title: 'Mark as lost',
      submitLabel: 'Mark lost',
      fields: [{ name: 'status_reason', label: 'Reason *', type: 'textarea', required: true }]
    },
    'dispose': {
      title: 'Dispose asset',
      submitLabel: 'Record disposal',
      fields: [
        { name: 'disposal_location', label: 'Disposal location *', type: 'text', required: true },
        { name: 'disposal_date', label: 'Disposal date', type: 'date' },
        { name: 'disposal_remaining_cost', label: 'Remaining book cost (optional)', type: 'number', step: '0.01' }
      ]
    },
    'sell': {
      title: 'Sell asset',
      submitLabel: 'Record sale',
      fields: [
        { name: 'sold_to', label: 'Sold to *', type: 'text', required: true },
        { name: 'sold_price', label: 'Sale price', type: 'number', step: '0.01' },
        { name: 'sold_date', label: 'Sale date', type: 'date' }
      ]
    },
    'donate': {
      title: 'Donate asset',
      submitLabel: 'Record donation',
      fields: [
        { name: 'donated_to', label: 'Donated to *', type: 'text', required: true },
        { name: 'donated_value', label: 'Donation value', type: 'number', step: '0.01' },
        { name: 'donated_date', label: 'Donation date', type: 'date' }
      ]
    },
    'replicate': { title: 'Replicate asset', confirm: 'Copy all details and photos, incrementing the asset tag and serial number?', submitLabel: 'Replicate' },
    'email': {
      title: 'Email asset details',
      submitLabel: 'Send email',
      fields: [
        { name: 'to', label: 'Recipient *', type: 'email', required: true, value: (NAIMS.asset && NAIMS.asset.assignedEmail) || '' },
        { name: 'subject', label: 'Subject', type: 'text', value: 'Asset ' + ((NAIMS.asset && NAIMS.asset.tag) || '') },
        { name: 'message', label: 'Message (blank = asset summary)', type: 'textarea' }
      ]
    },
    'delete': { title: 'Delete asset', confirm: 'Permanently delete this asset? This cannot be undone. Photos stay in the gallery.', submitLabel: 'Delete' }
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function openModal(cfg, assetId) {
    modalTitle.textContent = cfg.title;
    modalSubmit.textContent = cfg.submitLabel || 'Confirm';
    if (cfg.confirm) {
      modalBody.innerHTML = '<p class="modal-confirm-text">' + esc(cfg.confirm) + '</p>';
    } else {
      modalBody.innerHTML = cfg.fields.map(function (f) {
        var input;
        if (f.type === 'select') {
          input = '<select class="input" name="' + f.name + '"' + (f.required ? ' data-required="1"' : '') + '>' + f.options() + '</select>';
        } else if (f.type === 'textarea') {
          input = '<textarea class="input" name="' + f.name + '" rows="3"' + (f.required ? ' data-required="1"' : '') + '>' + esc(f.value || '') + '</textarea>';
        } else {
          input = '<input class="input" type="' + f.type + '" name="' + f.name + '"' +
            (f.step ? ' step="' + f.step + '"' : '') +
            (f.required ? ' data-required="1"' : '') +
            (f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '') +
            ' value="' + esc(f.value || '') + '">';
        }
        return '<label class="field"><span>' + esc(f.label) + '</span>' + input + '</label>';
      }).join('');
    }
    modalForm.action = base + '/assets/' + assetId + '/' + currentAction;
    backdrop.hidden = false;
    document.body.style.overflow = 'hidden';
    var first = modalBody.querySelector('input, select, textarea');
    if (first) first.focus();
  }

  function closeModal() {
    backdrop.hidden = true;
    document.body.style.overflow = '';
  }

  var currentAction = '';
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;
    var action = btn.getAttribute('data-action');
    var assetId = btn.getAttribute('data-asset');
    var cfg = ACTIONS[action];
    if (!cfg || !assetId) return;
    e.preventDefault();
    currentAction = action;

    var needsForm = cfg.fields && cfg.fields.length;
    if (!needsForm && cfg.confirm) {
      if (confirm(cfg.confirm)) {
        var form = document.createElement('form');
        form.method = 'post';
        form.action = base + '/assets/' + assetId + '/' + action;
        var token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = NAIMS.token || '';
        form.appendChild(token);
        document.body.appendChild(form);
        form.submit();
      }
      return;
    }
    openModal(cfg, assetId);
  });

  modalForm.addEventListener('submit', function (e) {
    var cfg = ACTIONS[currentAction];
    if (!cfg || !cfg.fields) return;
    var missing = null;
    var eitherInvalid = false;
    cfg.fields.forEach(function (f) {
      if (!f.required) return;
      var el = modalForm.elements[f.name];
      if (!el || el.value) return;
      if (f.eitherWith) {
        var other = modalForm.elements[f.eitherWith];
        if (!other || !other.value) eitherInvalid = true;
      } else if (!missing) {
        missing = el;
      }
    });
    if (eitherInvalid || missing) {
      e.preventDefault();
      if (eitherInvalid) {
        alert('Choose a person or a department.');
      } else {
        missing.focus();
      }
    }
  });

  document.getElementById('modal-close').addEventListener('click', closeModal);
  document.getElementById('modal-cancel').addEventListener('click', closeModal);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !backdrop.hidden) closeModal(); });

  /* ---------- grid column count ---------- */
  var picker = document.getElementById('col-picker');
  var grid = document.getElementById('asset-grid');
  if (picker && grid) {
    var saved = localStorage.getItem('naims_grid_cols');
    if (saved) {
      grid.style.setProperty('--cols', saved);
      Array.prototype.forEach.call(picker.querySelectorAll('.col-btn'), function (x) {
        x.classList.toggle('active', x.getAttribute('data-cols') === saved);
      });
    }
    Array.prototype.forEach.call(picker.querySelectorAll('.col-btn'), function (b) {
      var n = b.getAttribute('data-cols');
      var squares = b.querySelector('.col-squares');
      if (squares) {
        for (var i = 0; i < Math.min(6, +n); i++) {
          var d = document.createElement('span');
          d.style.cssText = 'width:4px;height:4px;border-radius:1px;background:currentColor;display:block';
          squares.appendChild(d);
        }
      }
      b.addEventListener('click', function () {
        grid.style.setProperty('--cols', n);
        localStorage.setItem('naims_grid_cols', n);
        Array.prototype.forEach.call(picker.querySelectorAll('.col-btn'), function (x) { x.classList.remove('active'); });
        b.classList.add('active');
        var body = new FormData();
        body.append('key', 'grid_cols');
        body.append('value', n);
        body.append('_token', NAIMS.token || '');
        fetch(base + '/prefs', { method: 'POST', body: body, headers: { 'X-Requested-With': 'fetch' } }).catch(function () {});
      });
    });
  }

  /* ---------- dependent location selects ---------- */
  function bindLocationPair(siteSel, locSel, selectedId) {
    if (!siteSel || !locSel) return;
    function rebuild(preserve) {
      var data = (NAIMS.locationsBySite || {})[siteSel.value] || [];
      var prev = preserve !== undefined ? preserve : locSel.value;
      locSel.innerHTML = '<option value="">All</option>' + data.map(function (l) {
        return '<option value="' + l.id + '"' + (String(l.id) === String(prev) ? ' selected' : '') + '>' + esc(l.name) + '</option>';
      }).join('');
    }
    if (NAIMS.selectedLocation) {
      var all = NAIMS.locationsBySite || {};
      for (var k in all) {
        if (all[k].some(function (l) { return String(l.id) === String(NAIMS.selectedLocation); })) {
          siteSel.value = k;
          break;
        }
      }
    }
    siteSel.addEventListener('change', function () { rebuild(''); });
    rebuild(NAIMS.selectedLocation ? String(NAIMS.selectedLocation) : '');
  }
  bindLocationPair(document.getElementById('filter-site'), document.getElementById('filter-location'));
  bindLocationPair(document.getElementById('form-site'), document.getElementById('form-location'), NAIMS.selectedLocation);
  bindLocationPair(document.getElementById('custom-site'), document.getElementById('custom-location'));

  /* ---------- admin user edit form ---------- */
  var userEdit = document.querySelector('.user-edit');
  if (userEdit) {
    var users = JSON.parse(userEdit.getAttribute('data-users') || '[]');
    var select = userEdit.querySelector('[name=edit_id]');
    Array.prototype.forEach.call(users, function (u) {
      var o = document.createElement('option');
      o.value = u.id;
      o.textContent = u.username + ' (' + u.full_name + ')';
      select.appendChild(o);
    });
    userEdit.addEventListener('submit', function (e) {
      var id = select.value;
      if (!id) {
        e.preventDefault();
        alert('Choose a user to edit.');
        return;
      }
      var u = users.filter(function (x) { return x.id === +id; })[0];
      if (!u) return;
      if (!userEdit.querySelector('[name=user[username]]')) {
        var h = document.createElement('input');
        h.type = 'hidden';
        h.name = 'user[username]';
        userEdit.appendChild(h);
      }
      userEdit.querySelector('[name=user[username]]').value = u.username;
      if (!userEdit.querySelector('[name=user[is_active]]')) {
        var h2 = document.createElement('input');
        h2.type = 'hidden';
        h2.name = 'user[is_active]';
        userEdit.appendChild(h2);
      }
      userEdit.querySelector('[name=user[is_active]]').value = u.is_active;
      userEdit.action = base + '/admin/users/' + id;
    });
  }

  /* ---------- dashboard charts ---------- */
  var charts = NAIMS.charts || null;
  var PALETTE = ['#2563eb', '#38bdf8', '#16a34a', '#d97706', '#7c3aed', '#0d9488', '#db2777', '#64748b'];

  function drawBar() {
    var canvas = document.getElementById('chart-categories');
    if (!canvas || !charts || !charts.categories || !charts.categories.length) return;
    var ctx = canvas.getContext('2d');
    var dpr = window.devicePixelRatio || 1;
    var w = canvas.clientWidth || 480;
    var h = 220;
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    ctx.scale(dpr, dpr);
    var data = charts.categories;
    var max = Math.max.apply(null, data.map(function (d) { return d.value; }).concat([1]));
    var pad = 8;
    var bw = (w - pad * 2) / data.length;
    ctx.font = '11px -apple-system, sans-serif';
    data.forEach(function (d, i) {
      var bh = Math.max(3, (d.value / max) * (h - 46));
      var x = pad + i * bw + bw * 0.18;
      var y = h - 30 - bh;
      var grad = ctx.createLinearGradient(0, y, 0, y + bh);
      grad.addColorStop(0, PALETTE[i % PALETTE.length]);
      grad.addColorStop(1, PALETTE[i % PALETTE.length] + '99');
      ctx.fillStyle = grad;
      roundRect(ctx, x, y, bw * 0.64, bh, 5);
      ctx.fill();
      ctx.fillStyle = '#334155';
      ctx.textAlign = 'center';
      ctx.fillText(d.value, x + bw * 0.32, y - 6);
      ctx.fillStyle = '#64748b';
      var label = d.label.length > 11 ? d.label.slice(0, 10) + '…' : d.label;
      ctx.fillText(label, x + bw * 0.32, h - 12);
    });
  }

  function drawDonut() {
    var canvas = document.getElementById('chart-status');
    var legend = document.getElementById('status-legend');
    if (!canvas || !charts || !charts.status || !charts.status.length) return;
    var ctx = canvas.getContext('2d');
    var size = 180;
    var dpr = window.devicePixelRatio || 1;
    canvas.width = size * dpr;
    canvas.height = size * dpr;
    canvas.style.width = size + 'px';
    canvas.style.height = size + 'px';
    ctx.scale(dpr, dpr);
    var data = charts.status;
    var total = data.reduce(function (s, d) { return s + d.count; }, 0) || 1;
    var cx = size / 2, cy = size / 2, r = 74, inner = 46;
    var a0 = -Math.PI / 2;
    data.forEach(function (d, i) {
      var a1 = a0 + (d.count / total) * Math.PI * 2;
      ctx.beginPath();
      ctx.arc(cx, cy, r, a0, a1);
      ctx.arc(cx, cy, inner, a1, a0, true);
      ctx.closePath();
      ctx.fillStyle = PALETTE[i % PALETTE.length];
      ctx.fill();
      a0 = a1;
    });
    ctx.fillStyle = '#0f172a';
    ctx.font = '800 22px -apple-system, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(total, cx, cy + 2);
    ctx.font = '10px -apple-system, sans-serif';
    ctx.fillStyle = '#64748b';
    ctx.fillText('ASSETS', cx, cy + 18);
    if (legend) {
      legend.innerHTML = data.map(function (d, i) {
        return '<li><span class="swatch" style="background:' + PALETTE[i % PALETTE.length] + '"></span>' +
          esc(d.name) + ' — ' + d.count + '</li>';
      }).join('');
    }
  }

  function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, 0);
    ctx.arcTo(x, y + h, x, y, 0);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  if (charts) {
    drawBar();
    drawDonut();
  }

  /* ---------- USB scanner capture ---------- */
  var scanBuffer = '';
  var scanTimes = [];
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    var typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
    if (typing || e.metaKey || e.ctrlKey || e.altKey) return;
    if (!backdrop.hidden) return;

    var now = performance.now();
    if (scanBuffer && now - scanTimes[scanTimes.length - 1] > 120) {
      scanBuffer = '';
      scanTimes = [];
    }
    if (e.key === 'Enter') {
      if (scanBuffer.length >= 2) {
        e.preventDefault();
        window.location.href = base + '/assets?q=' + encodeURIComponent(scanBuffer);
      }
      scanBuffer = '';
      scanTimes = [];
      return;
    }
    if (e.key.length === 1) {
      scanBuffer += e.key;
      scanTimes.push(now);
      if (scanTimes.length > 120) {
        scanBuffer = '';
        scanTimes = [];
      }
    }
  });
})();
