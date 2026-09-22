/* Nice Assets — photo picker modal (server-rendered, no data endpoints) */
(function () {
  'use strict';

  var NAIMS = window.NAIMS || (window.NAIMS = {});

  function allModals() {
    return Array.prototype.slice.call(document.querySelectorAll('.pp-modal'));
  }

  function openPhotoPicker(modalId) {
    var modal = document.getElementById(modalId);
    if (!modal) return;
    Array.prototype.forEach.call(allModals(), function (m) { m.hidden = true; });
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closePhotoPicker() {
    Array.prototype.forEach.call(allModals(), function (m) { m.hidden = true; });
    document.body.style.overflow = '';
  }

  /* ---------- open triggers ---------- */
  Array.prototype.forEach.call(document.querySelectorAll('[data-pp-open]'), function (btn) {
    btn.addEventListener('click', function () {
      openPhotoPicker(btn.getAttribute('data-pp-open'));
    });
  });

  /* ---------- close: backdrop, ×, cancel, Escape ---------- */
  Array.prototype.forEach.call(document.querySelectorAll('[data-pp-close]'), function (el) {
    el.addEventListener('click', function () { closePhotoPicker(); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var anyVisible = allModals().some(function (m) { return !m.hidden; });
    if (anyVisible) closePhotoPicker();
  });

  /* ---------- single mode: first click selects, re-click deselects ---------- */
  Array.prototype.forEach.call(allModals(), function (modal) {
    if (modal.getAttribute('data-mode') !== 'single') return;
    var radios = Array.prototype.slice.call(modal.querySelectorAll('input[type=radio]'));
    var selected = modal.querySelector('input[type=radio]:checked') || null;
    radios.forEach(function (input) {
      input.addEventListener('click', function () {
        if (selected === input) { input.checked = false; selected = null; }
        else { selected = input; } // native activation checks it
      });
      input.addEventListener('change', function () {
        if (input.checked) selected = input;
      });
    });
  });

  function selectedValues(modal) {
    return Array.prototype.map.call(modal.querySelectorAll('input:checked'), function (i) { return i.value; });
  }

  function hiddenInputs(form, name) {
    return Array.prototype.filter.call(form.querySelectorAll('input[data-pp-hidden]'), function (h) {
      return h.name === name;
    });
  }

  function syncHiddenInputs(form, name, values) {
    var existing = hiddenInputs(form, name);
    Array.prototype.forEach.call(existing, function (h) {
      if (values.indexOf(h.value) === -1) h.remove();
    });
    values.forEach(function (v) {
      var found = existing.some(function (h) { return h.value === v; });
      if (!found) {
        var h = document.createElement('input');
        h.type = 'hidden';
        h.name = name;
        h.value = v;
        h.setAttribute('data-pp-hidden', '');
        form.appendChild(h);
      }
    });
  }

  function updateStrip(form, modal, name, values) {
    var strip = form.querySelector('.pp-strip');
    if (!strip) return;
    var empty = strip.querySelector('.pp-strip-empty');
    if (empty) empty.remove();
    values.forEach(function (v) {
      if (strip.querySelector('.pp-strip-item[data-pp-id="' + v + '"]')) return;
      var input = modal.querySelector('input[value="' + v + '"]');
      var option = input ? input.closest('.pp-option') : null;
      var thumb = option ? option.querySelector('img') : null;
      var label = option ? option.querySelector('.pp-name') : null;
      var item = document.createElement('div');
      item.className = 'pp-strip-item';
      item.setAttribute('data-pp-id', v);
      var img = document.createElement('img');
      img.src = thumb ? thumb.src : '';
      img.alt = '';
      item.appendChild(img);
      var span = document.createElement('span');
      span.className = 'pp-strip-name';
      span.textContent = label ? label.textContent : '';
      item.appendChild(span);
      var btn = document.createElement('button');
      btn.className = 'pp-strip-remove';
      btn.type = 'button';
      btn.title = 'Remove from selection';
      btn.textContent = '✕';
      btn.setAttribute('data-pp-strip-remove', v);
      btn.setAttribute('data-pp-name', name);
      item.appendChild(btn);
      strip.appendChild(item);
    });
    if (values.length === 0) {
      var e = document.createElement('div');
      e.className = 'pp-strip-empty';
      e.textContent = 'No photos selected yet.';
      strip.appendChild(e);
    }
  }

  function updatePreview(modal, values) {
    var previewId = modal.getAttribute('data-pp-preview') || '';
    if (!previewId) return;
    var preview = document.getElementById(previewId);
    if (!preview) return;
    if (values[0]) {
      var checked = modal.querySelector('input:checked');
      var option = checked ? checked.closest('.pp-option') : null;
      var thumb = option ? option.querySelector('img') : null;
      var img = preview.querySelector('img');
      if (thumb && img) img.src = thumb.src;
      preview.classList.add('is-set');
    } else {
      preview.classList.remove('is-set');
    }
  }

  function confirmPick(modal) {
    var name = modal.getAttribute('data-name') || '';
    var mode = modal.getAttribute('data-mode') || 'single';
    var values = selectedValues(modal);
    if (mode === 'single' && !values[0]) {
      alert('Choose a photo first.');
      return;
    }

    if (modal.getAttribute('data-confirm') === 'post') {
      var fields = {};
      try { fields = JSON.parse(modal.getAttribute('data-fields') || '{}'); } catch (err) { fields = {}; }
      var form = document.createElement('form');
      form.method = 'post';
      form.action = modal.getAttribute('data-action-url') || '';
      var token = document.createElement('input');
      token.type = 'hidden';
      token.name = '_token';
      token.value = NAIMS.token || '';
      form.appendChild(token);
      Object.keys(fields).forEach(function (fieldName) {
        var srcName = fields[fieldName];
        var vals = [];
        Array.prototype.forEach.call(modal.querySelectorAll('input[name="' + srcName + '"]:checked'), function (i) {
          vals.push(i.value);
        });
        if (mode === 'single') vals = vals.slice(0, 1);
        vals.forEach(function (v) {
          var h = document.createElement('input');
          h.type = 'hidden';
          h.name = fieldName;
          h.value = v;
          form.appendChild(h);
        });
      });
      document.body.appendChild(form);
      form.submit();
      return;
    }

    var targetName = modal.getAttribute('data-target-form') || '';
    var target = targetName ? document.forms[targetName] : modal.closest('form');
    if (!target) return;
    if (mode === 'multi') {
      syncHiddenInputs(target, name, values);
      updateStrip(target, modal, name, values);
    } else {
      var single = hiddenInputs(target, name)[0];
      if (!single) {
        single = document.createElement('input');
        single.type = 'hidden';
        single.name = name;
        single.setAttribute('data-pp-hidden', '');
        target.appendChild(single);
      }
      single.value = values[0] || '';
    }
    updatePreview(modal, values);
    closePhotoPicker();
    target.submit();
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-pp-confirm]'), function (btn) {
    btn.addEventListener('click', function () {
      var modal = btn.closest('.pp-modal');
      if (modal) confirmPick(modal);
    });
  });

  /* ---------- remove a single selection from its preview (portrait, logo) ---------- */
  Array.prototype.forEach.call(document.querySelectorAll('[data-pp-clear]'), function (btn) {
    btn.addEventListener('click', function () {
      var name = btn.getAttribute('data-pp-clear');
      var form = btn.closest('form');
      if (form) {
        Array.prototype.forEach.call(hiddenInputs(form, name), function (h) { h.value = ''; });
      }
      var previewId = btn.getAttribute('data-pp-preview') || '';
      if (previewId) {
        var preview = document.getElementById(previewId);
        if (preview) preview.classList.remove('is-set');
      }
    });
  });

  /* ---------- remove one photo from the asset form's selected strip (delegated: buttons may be added by the picker) ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-pp-strip-remove]') : null;
    if (!btn) return;
    var value = btn.getAttribute('data-pp-strip-remove');
    var name = btn.getAttribute('data-pp-name') || '';
    var form = btn.closest('form');
    if (form) {
      Array.prototype.forEach.call(hiddenInputs(form, name), function (h) {
        if (h.value === value) h.remove();
      });
    }
    if (name) {
      var modal = document.querySelector('.pp-modal[data-name="' + name + '"]');
      var box = modal ? modal.querySelector('input[value="' + value + '"]') : null;
      if (box) box.checked = false;
    }
    var item = btn.closest('.pp-strip-item');
    if (item) item.remove();
    var strip = form ? form.querySelector('.pp-strip') : null;
    if (strip && strip.querySelectorAll('.pp-strip-item').length === 0 && !strip.querySelector('.pp-strip-empty')) {
      var empty = document.createElement('div');
      empty.className = 'pp-strip-empty';
      empty.textContent = 'No photos selected yet.';
      strip.appendChild(empty);
    }
  });
})();
