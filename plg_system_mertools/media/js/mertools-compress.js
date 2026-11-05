/**
 * MerTools for Gridbox — the lighter originals (MtcompressField, Images → Compression): what the
 * server can use, what was compressed, the copies of the originals; a try on one picture with the
 * settings of the form as they are now (before and after side by side, a slider over the picture);
 * "Compress all pictures" in a window with a log, run a few seconds at a time (stop, go on later);
 * the copies of the originals in a window, each with its return; "Restore all the originals" in the
 * window of the run, with its bar and a line per picture. Requests go to the plugin's
 * mertools_action handler with the form token.
 */
(function () {
  'use strict';

  var KEYS = ['cmp_jpeg', 'cmp_png', 'cmp_min', 'cmp_engine'];

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { e.textContent = attrs[k]; } else if (k === 'class') { e.className = attrs[k]; } else { e.setAttribute(k, attrs[k]); }
    });
    (kids || []).forEach(function (c) { if (c) { e.append(c); } });
    return e;
  }

  function init(box) {
    var cfg = JSON.parse(box.getAttribute('data-mt-cmp'));
    var T = cfg.texts;
    var lang = document.documentElement.lang || undefined;
    var figures = box.querySelector('.mt-cmp-figures');
    var result = box.querySelector('.mt-cmp-result');
    var runBtn = box.querySelector('[data-mt-cmp-run]');
    var cancelBtn = box.querySelector('[data-mt-cmp-cancel]');
    var listBtn = box.querySelector('[data-mt-cmp-list]');
    var restoreAllBtn = box.querySelector('[data-mt-cmp-restore-all]');
    var fileInput = box.querySelector('[data-mt-cmp-file]');
    var tryBtn = box.querySelector('[data-mt-cmp-try]');
    var tryOut = box.querySelector('.mt-cmp-try-out');
    var tryLine = box.querySelector('.mt-cmp-try-settings');
    var last = null;

    function t(key, a, b) { return String(T[key] || key).replace('%1$s', a).replace('%2$s', b).replace('%s', a); }
    function num(n) { return Number(n || 0).toLocaleString(lang); }
    function bytes(n) {
      n = Number(n || 0);
      if (n >= 1048576) { return (n / 1048576).toLocaleString(lang, { maximumFractionDigits: 1, minimumFractionDigits: 1 }) + ' MB'; }
      return Math.max(1, Math.round(n / 1024)).toLocaleString(lang) + ' KB';
    }
    function saving(before, after) { return before > 0 ? Math.round((1 - after / before) * 100) : 0; }
    function date(ts) { return ts ? new Date(ts * 1000).toLocaleString(lang, { dateStyle: 'short', timeStyle: 'short' }) : ''; }

    // ---------------------------------------------------------------- the form

    function formValue(name) {
      var f = document.querySelector('[name="jform[params][' + name + ']"]:checked')
        || document.querySelector('[name="jform[params][' + name + ']"]:not([type="radio"])');
      return f ? String(f.value) : String(cfg.saved[name]);
    }
    function formSettings() {
      var o = {};
      KEYS.forEach(function (k) { o[k] = formValue(k); });
      return o;
    }
    function unsaved() {
      return KEYS.some(function (k) { return formValue(k) !== String(cfg.saved[k]); });
    }
    function settingsText(o) {
      return t('SETTINGS_LINE', o.cmp_jpeg, o.cmp_png).replace('%3$s', o.cmp_min)
        + (o.cmp_engine === 'php' ? ' · ' + T.ENGINE_PHP_ONLY : '');
    }
    function showTryLine() { tryLine.textContent = settingsText(formSettings()) + (unsaved() ? ' · ' + T.UNSAVED : ''); }
    KEYS.forEach(function (k) {
      document.querySelectorAll('[name="jform[params][' + k + ']"]').forEach(function (f) {
        f.addEventListener('input', showTryLine);
        f.addEventListener('change', showTryLine);
      });
    });

    // ---------------------------------------------------------------- requests

    function post(action, data) {
      var body = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) {
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
      }
      body.append(cfg.token, '1');
      return fetch('index.php?option=com_plugins&mertools_action=' + action, {
        method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) {
        return r.json().catch(function () { throw new Error(T.ERROR + ' (HTTP ' + r.status + ')'); });
      }).then(function (d) {
        if (!d || !d.success) { throw new Error((d && d.message) || T.ERROR); }
        if (d.stats) { show(d); }
        return d;
      });
    }
    function message(kind, text) {
      result.replaceChildren(el('div', { class: 'alert alert-' + kind + ' mb-0', text: text }));
    }

    // ---------------------------------------------------------------- the figures

    function row(label, value, extra) {
      figures.append(el('dt', { class: 'col-sm-5 fw-normal', text: label }), el('dd', { class: 'col-sm-7 mb-1' }, [document.createTextNode(value), extra || null]));
    }
    function show(d) {
      last = d;
      var s = d.stats, e = d.engines;
      figures.replaceChildren();
      var jpeg = e.jpegoptim && cfg.saved.cmp_engine !== 'php' ? 'jpegoptim' : (e.gd ? T.ENGINE_GD : '—');
      var png = e.pngquant && cfg.saved.cmp_engine !== 'php' ? 'pngquant' : (e.gd ? T.ENGINE_GD_PNG : '—');
      row(T.ENGINES, 'JPEG: ' + jpeg + ' · PNG: ' + png);
      var note = !e.gd ? T.NO_GD : (!e.exec ? T.NO_EXEC : (!e.jpegoptim || !e.pngquant ? T.NO_PROGRAMS : ''));
      if (note) { row('', note); }
      row(T.UPLOADS, d.uploads ? T.UPLOADS_ON : T.UPLOADS_OFF);
      var frames = Object.keys(d.resize || {}).map(function (p) {
        var b = d.resize[p];
        return T['PLACE_' + p.toUpperCase()] + ' ' + (b[0] || T.NO_LIMIT) + ' × ' + (b[1] || T.NO_LIMIT);
      });
      row(T.RESIZE, frames.length ? frames.join(' · ') : T.RESIZE_OFF);
      row(T.COMPRESSED, s.compressed ? num(s.compressed) + ' · ' + bytes(s.before) + ' → ' + bytes(s.after) + ' (−' + saving(s.before, s.after) + ' %)' : '0');
      row(T.BACKUPS, num(s.backups) + (s.backups ? ' · ' + bytes(s.backupBytes) : '') + ' · ' + t('BACKUPS_DAYS', d.days)
        + (s.oldest ? ' · ' + t('BACKUPS_OLDEST', date(s.oldest + d.days * 86400)) : ''));
      if (s.pending) { row(T.PENDING, num(s.pending)); }
      if (!d.finish && (d.uploads || frames.length)) { row('', T.NO_FINISH); }
      runBtn.disabled = !e.gd;
      tryBtn.disabled = !e.gd;
      runBtn.lastChild.textContent = ' ' + (s.job ? t('RUN_RESUME', num(s.job.pos), num(s.job.total)) : T.RUN);
      cancelBtn.hidden = !s.job;
      restoreAllBtn.disabled = !s.backups;
      listBtn.disabled = !s.backups;
    }

    // ---------------------------------------------------------------- a try on one picture

    tryBtn.addEventListener('click', function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file) { tryOut.replaceChildren(el('div', { class: 'alert alert-warning mb-0', text: T.TEST_NO_FILE })); return; }
      if (file.size > cfg.maxUpload) { tryOut.replaceChildren(el('div', { class: 'alert alert-warning mb-0', text: t('TEST_TOO_BIG_SIZE', bytes(cfg.maxUpload)) })); return; }
      var body = new FormData();
      body.append('file', file);
      var o = formSettings();
      KEYS.forEach(function (k) { body.append(k, o[k]); });
      tryBtn.disabled = true;
      tryOut.replaceChildren(el('div', { text: T.TEST_WORKING }));
      post('cmp_test', body).then(function (d) { showTry(d, file, o); }, function (err) {
        tryOut.replaceChildren(el('div', { class: 'alert alert-danger mb-0', text: err.message }));
      }).then(function () { tryBtn.disabled = false; });
    });

    function showTry(d, file, o) {
      var r = d.result;
      var kids = [];
      var line = el('p', { class: 'mb-2' });
      if (d.image) {
        var pct = saving(r.before, r.after);
        line.append(el('strong', { text: bytes(r.before) + ' → ' + bytes(r.after) + ' (' + (pct >= 0 ? '−' : '+') + Math.abs(pct) + ' %)' }),
          document.createTextNode(' · ' + method(r) + (d.size ? ' · ' + d.size[0] + '×' + d.size[1] + ' px' : '')));
      }
      kids.push(line);
      var verdict = r.status === 'ok' ? ['success', t('TEST_OK', o.cmp_min)] : ['warning', reason(r.reason, o)];
      kids.push(el('div', { class: 'alert alert-' + verdict[0] + ' py-2', text: verdict[1] }));
      if (d.image) {
        var before = URL.createObjectURL(file);
        var wrap = el('div', { class: 'mt-cmp-compare' });
        var imgA = el('img', { src: before, alt: T.TEST_BEFORE });
        var clip = el('div', { class: 'mt-cmp-clip' }, [el('img', { src: d.image, alt: T.TEST_AFTER })]);
        var range = el('input', { type: 'range', min: '0', max: '100', value: '50', class: 'form-range', 'aria-label': T.TEST_SLIDER });
        var labels = el('div', { class: 'mt-cmp-labels' }, [el('span', { text: T.TEST_AFTER }), el('span', { text: T.TEST_BEFORE })]);
        function move() { clip.style.width = range.value + '%'; }
        range.addEventListener('input', move);
        move();
        wrap.append(imgA, clip);
        kids.push(el('div', { class: 'small text-muted mb-1', text: T.TEST_HOW }), wrap, labels, range);
        var name = file.name.replace(/(\.[a-z0-9]+)$/i, '-mertools$1');
        kids.push(el('p', { class: 'mt-2 mb-0' }, [el('a', { href: d.image, download: name, class: 'btn btn-sm btn-outline-secondary', text: T.TEST_DOWNLOAD })]));
      }
      tryOut.replaceChildren.apply(tryOut, kids);
    }

    function method(r) {
      var m = r.method === 'gd' ? (r.type === 'png' ? (r.lossy ? T.ENGINE_GD_PNG : T.ENGINE_GD_LOSSLESS) : T.ENGINE_GD) : r.method;
      return t('METHOD', m);
    }
    function reason(code, o) {
      if (code === 'gain') { return t('REASON_gain_test', (o || cfg.saved).cmp_min); }
      return T['REASON_' + code] || code;
    }

    // ---------------------------------------------------------------- compress all (the window with the log)

    // one window for both: mode 'run' (compress all) or 'restore' (restore all)
    var dlg = null, titleEl, skipsRow, logBox, bar, counts, showSkips, stopBtn, goBtn, closeBtn, mode = 'run', running = false, stopAsked = false, sum = null, lines = 0;

    function makeDialog() {
      if (dlg) { return; }
      closeBtn = el('button', { type: 'button', class: 'btn-close', 'aria-label': T.CLOSE });
      bar = el('div', { class: 'progress-bar', role: 'progressbar', style: 'width:0%' });
      counts = el('p', { class: 'mb-2 small' });
      showSkips = el('input', { type: 'checkbox', class: 'form-check-input', id: 'mt-cmp-skips' });
      logBox = el('ol', { class: 'mt-cmp-log list-unstyled mb-0', 'aria-live': 'polite' });
      stopBtn = el('button', { type: 'button', class: 'btn btn-secondary', text: T.STOP });
      goBtn = el('button', { type: 'button', class: 'btn btn-primary', text: T.GO_ON });
      titleEl = el('h3', { text: T.RUN_TITLE });
      skipsRow = el('div', { class: 'form-check mb-2' }, [showSkips, el('label', { class: 'form-check-label', for: 'mt-cmp-skips', text: T.SHOW_SKIPS })]);
      dlg = el('dialog', { class: 'mt-cmp-dlg' }, [
        el('div', { class: 'mt-cmp-head' }, [titleEl, closeBtn]),
        el('div', { class: 'mt-cmp-body' }, [
          el('div', { class: 'progress mb-2', style: 'height:1.25rem' }, [bar]), counts, skipsRow,
          logBox
        ]),
        el('div', { class: 'mt-cmp-foot' }, [stopBtn, goBtn])
      ]);
      document.body.append(dlg);
      closeBtn.addEventListener('click', function () { stopAsked = true; dlg.close(); });
      dlg.addEventListener('cancel', function () { stopAsked = true; });
      stopBtn.addEventListener('click', function () { stopAsked = true; stopBtn.disabled = true; });
      goBtn.addEventListener('click', function () { loop(); });
      showSkips.addEventListener('change', function () { dlg.classList.toggle('mt-cmp-skips-on', showSkips.checked); });
      logBox.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-rel]');
        if (b) { restoreOne(b.getAttribute('data-rel'), b); }
      });
    }

    function openDialog(kind) {
      makeDialog();
      mode = kind;
      titleEl.textContent = kind === 'restore' ? T.RESTORE_ALL_TITLE : T.RUN_TITLE;
      skipsRow.hidden = kind === 'restore';
      logBox.replaceChildren();
      lines = 0;
    }
    function setBar(pos, total) {
      var pct = total ? Math.min(100, Math.round(pos / total * 100)) : 100;
      bar.style.width = pct + '%';
      bar.textContent = pct + ' %';
    }

    function logLine(e) {
      var li = el('li', { class: 'mt-cmp-' + e.status });
      var mark = e.status === 'ok' ? '✓' : (e.status === 'fail' ? '✗' : '–');
      li.append(el('span', { class: 'mt-cmp-mark', 'aria-hidden': 'true', text: mark }), el('code', { text: e.rel }));
      if (e.status === 'ok') {
        li.append(document.createTextNode(' ' + bytes(e.before) + ' → ' + bytes(e.after) + ' (−' + saving(e.before, e.after) + ' %) · ' + method(e) + ' '));
        li.append(el('button', { type: 'button', class: 'btn btn-link btn-sm p-0 align-baseline', 'data-rel': e.rel, text: T.RESTORE }));
      } else {
        li.append(document.createTextNode(' ' + reason(e.reason)));
      }
      logBox.append(li);
      if (++lines > 3000) { logBox.firstChild.remove(); lines--; }
    }
    function note(text, kind) {
      logBox.append(el('li', { class: 'mt-cmp-note' + (kind ? ' text-' + kind : ''), text: text }));
    }
    function showProgress(run) {
      setBar(run.pos, run.total);
      var s = run.sum || {};
      counts.textContent = t('COUNTS', num(run.pos), num(run.total)) + ' · ' + T.C_OK + ': ' + num(s.ok) + ' · ' + T.C_SKIP + ': ' + num(s.skip)
        + ' · ' + T.C_FAIL + ': ' + num(s.fail) + (s.ok ? ' · ' + t('SAVED', bytes(s.before - s.after), saving(s.before, s.after)) : '');
      sum = s;
    }
    function buttons() {
      stopBtn.hidden = !running;
      stopBtn.disabled = false;
      goBtn.hidden = running || mode !== 'run' || !(last && last.stats.job);
    }

    function loop() {
      if (running) { return; }
      running = true;
      stopAsked = false;
      buttons();
      var autoscroll = function () { logBox.scrollTop = logBox.scrollHeight; };
      (function step() {
        if (stopAsked) { finish(T.STOPPED, 'warning'); return; }
        post('cmp_run').then(function (d) {
          var run = d.run;
          if (run.busy) { note(T.BUSY); setTimeout(step, 3000); return; }
          run.entries.forEach(logLine);
          autoscroll();
          showProgress(run);
          if (run.done) { finish(t('DONE', num(sum && sum.ok)), 'success'); return; }
          step();
        }, function (err) { finish(err.message, 'danger'); });
      })();
      function finish(text, kind) {
        running = false;
        note(text, kind);
        autoscroll();
        post('cmp_stats').then(buttons, buttons);
      }
    }

    runBtn.addEventListener('click', function () {
      // a closed window may still wait for the answer of a part (of either kind): nothing else starts until it ends
      if (running) { return; }
      var s = last && last.stats;
      if (s && s.job) {
        openDialog('run');
        showProgress(s.job);
        dlg.showModal();
        loop();
        return;
      }
      var question = t('RUN_CONFIRM', cfg.folder, last ? last.days : cfg.saved.cmp_keep) + '\n\n' + settingsText(cfg.saved) + (unsaved() ? '\n\n' + T.RUN_UNSAVED : '');
      if (!window.confirm(question)) { return; }
      runBtn.disabled = true;
      post('cmp_start').then(function (d) {
        openDialog('run');
        showProgress({ pos: 0, total: d.count, sum: {} });
        note(t('STARTED', num(d.count)));
        dlg.showModal();
        loop();
      }, function (err) { message('danger', err.message); }).then(function () { runBtn.disabled = false; });
    });
    cancelBtn.addEventListener('click', function () {
      if (!window.confirm(T.CANCEL_CONFIRM)) { return; }
      post('cmp_cancel').then(function () { message('success', T.CANCELLED); }, function (err) { message('danger', err.message); });
    });

    // ---------------------------------------------------------------- the copies of the originals

    var listDlg = null, listBody, listFilter, listInfo;

    function restoreOne(rel, btn) {
      if (!window.confirm(t('RESTORE_CONFIRM', rel))) { return; }
      btn.disabled = true;
      post('cmp_restore', { rel: rel }).then(function (d) {
        var text = T['RESTORE_' + d.result] || d.result;
        btn.replaceWith(el('span', { class: d.result === 'ok' ? 'text-success' : 'text-warning', text: text }));
      }, function (err) { btn.disabled = false; window.alert(err.message); });
    }

    function makeListDialog() {
      if (listDlg) { return; }
      var close = el('button', { type: 'button', class: 'btn-close', 'aria-label': T.CLOSE });
      listFilter = el('input', { type: 'search', class: 'form-control mb-2', placeholder: T.LIST_FILTER, 'aria-label': T.LIST_FILTER });
      listInfo = el('p', { class: 'small text-muted mb-2' });
      listBody = el('ol', { class: 'mt-cmp-log list-unstyled mb-0' });
      listDlg = el('dialog', { class: 'mt-cmp-dlg' }, [
        el('div', { class: 'mt-cmp-head' }, [el('h3', { text: T.LIST_TITLE }), close]),
        el('div', { class: 'mt-cmp-body' }, [listFilter, listInfo, listBody])
      ]);
      document.body.append(listDlg);
      close.addEventListener('click', function () { listDlg.close(); post('cmp_stats').catch(function () {}); });
      var timer = 0;
      listFilter.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(loadList, 300); });
      listBody.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-rel]');
        if (b) { restoreOne(b.getAttribute('data-rel'), b); }
      });
    }
    function loadList() {
      post('cmp_list', { filter: listFilter.value }).then(function (d) {
        listBody.replaceChildren();
        listInfo.textContent = t('LIST_INFO', num(d.list.length), d.days);
        d.list.forEach(function (b) {
          listBody.append(el('li', { class: 'mt-cmp-ok' }, [
            el('code', { text: b.rel }),
            document.createTextNode(' ' + bytes(b.b) + ' → ' + bytes(b.a) + ' (' + (saving(b.b, b.a) >= 0 ? '−' : '+') + Math.abs(saving(b.b, b.a)) + ' %)'
              + (b.r && b.r.length === 4 ? ' · ' + b.r[0] + '×' + b.r[1] + ' → ' + b.r[2] + '×' + b.r[3] : '') + ' · ' + date(b.t) + ' · ' + t('LIST_UNTIL', date(b.t + d.days * 86400)) + ' '),
            el('button', { type: 'button', class: 'btn btn-link btn-sm p-0 align-baseline', 'data-rel': b.rel, text: T.RESTORE })
          ]));
        });
      }, function (err) { listInfo.textContent = err.message; });
    }
    listBtn.addEventListener('click', function () {
      makeListDialog();
      listDlg.showModal();
      loadList();
    });
    // ---------------------------------------------------------------- restore all (in the window of the run)

    restoreAllBtn.addEventListener('click', function () {
      if (running || !window.confirm(T.RESTORE_ALL_CONFIRM)) { return; }
      openDialog('restore');
      restoreAllBtn.disabled = true;
      running = true;
      stopAsked = false;
      buttons();
      dlg.showModal();
      // a picture left as it is (changed, fail) keeps its copy and comes back in the next part: listed once,
      // its line again only when its result changes
      var total = (last && last.stats.backups) || 0, seen = {}, n = { ok: 0, changed: 0, fail: 0, gone: 0 }, done = 0, left = 0, skip = 0;
      function progress() {
        total = Math.max(total, done + left);
        setBar(done, total);
        counts.textContent = t('RESTORE_ALL_COUNTS', num(done), num(total)) + ' · ' + T.C_RESTORED + ': ' + num(n.ok)
          + ' · ' + T.C_CHANGED + ': ' + num(n.changed) + ' · ' + T.C_FAIL + ': ' + num(n.fail);
      }
      function line(e) {
        var li = el('li', { class: e.result === 'ok' ? 'mt-cmp-ok' : (e.result === 'fail' ? 'mt-cmp-fail' : 'mt-cmp-left') });
        li.append(el('span', { class: 'mt-cmp-mark', 'aria-hidden': 'true', text: e.result === 'ok' ? '✓' : (e.result === 'fail' ? '✗' : '–') }),
          el('code', { text: e.rel }), document.createTextNode(' ' + (T['RESTORE_' + e.result] || e.result)));
        logBox.append(li);
        if (++lines > 3000) { logBox.firstChild.remove(); lines--; }
      }
      function end(text, kind) {
        running = false;
        buttons();
        note(text, kind);
        logBox.scrollTop = logBox.scrollHeight;
        message(kind, text);
        post('cmp_stats').then(buttons, function () { restoreAllBtn.disabled = false; buttons(); });
      }
      progress();
      (function step() {
        if (stopAsked) { end(t('RESTORE_ALL_STOPPED', num(n.ok)), 'warning'); return; }
        post('cmp_restore_all', { skip: skip }).then(function (d) {
          var r = d.restore;
          // the pictures left as they are stay at the front of the server's list: the next part passes over them
          skip += r.changed + r.failed;
          r.entries.forEach(function (e) {
            var was = seen[e.rel];
            if (was === e.result) { return; }
            if (was) { n[was]--; } else { done++; }
            seen[e.rel] = e.result;
            n[e.result] = (n[e.result] || 0) + 1;
            line(e);
          });
          left = r.left;
          logBox.scrollTop = logBox.scrollHeight;
          progress();
          if (r.left > 0) { step(); return; }
          setBar(1, 1);
          // a picture that could not be written is not a success: said in a warning, its copy stays
          end(t('RESTORE_ALL_DONE', num(n.ok)) + (n.changed ? ' ' + t('RESTORE_ALL_CHANGED', num(n.changed)) : '')
            + (n.fail ? ' ' + t('RESTORE_ALL_FAILED', num(n.fail)) : ''), n.fail ? 'warning' : 'success');
        }, function (err) { end(err.message, 'danger'); });
      })();
    });

    showTryLine();
    result.textContent = T.LOADING;
    post('cmp_stats').then(function () { if (result.textContent === T.LOADING) { result.textContent = ''; } }, function (err) { message('danger', err.message); });
  }

  function start() { document.querySelectorAll('[data-mt-cmp]').forEach(init); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
