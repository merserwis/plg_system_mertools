/**
 * MerTools for Gridbox — the copies of the Gridbox apps in the plugin settings (MtappsbackupField):
 * the apps with a button for a copy now, the copies with download, restore and delete. Requests go to
 * the plugin's mertools_action handler with the form token.
 */
(function () {
  'use strict';

  function init(box) {
    var cfg = JSON.parse(box.getAttribute('data-mt-backups'));
    var T = cfg.texts;
    var result = box.querySelector('.mt-backups-result');
    var appsBox = box.querySelector('.mt-backups-apps');
    var copiesBox = box.querySelector('.mt-backups-copies');
    var busy = false;

    function el(tag, cls, text) {
      var e = document.createElement(tag);
      if (cls) { e.className = cls; }
      if (text !== undefined && text !== null) { e.textContent = text; }
      return e;
    }
    function message(kind, text) {
      result.replaceChildren(el('div', 'alert alert-' + kind + ' mb-0', text));
    }
    function fill(text, values) {
      return String(text).replace(/\{(\w+)\}/g, function (m, k) { return values[k] !== undefined ? values[k] : m; });
    }
    function size(bytes) {
      if (bytes < 1024) { return bytes + ' B'; }
      if (bytes < 1048576) { return (bytes / 1024).toFixed(0) + ' KB'; }
      return (bytes / 1048576).toFixed(1) + ' MB';
    }
    function when(utc) {
      var d = new Date(String(utc).replace(' ', 'T') + 'Z');
      return isNaN(d) ? utc : d.toLocaleString();
    }
    function request(action, data) {
      var body = new FormData();
      body.append(cfg.token, '1');
      Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
      return fetch('index.php?option=com_plugins&mertools_action=' + action, {
        method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
    }
    function post(action, data) {
      return request(action, data).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.success) { throw new Error((d && d.message) || T.ERROR); }
        show(d);
        return d;
      });
    }
    function run(button, action, data, done) {
      if (busy) { return; }
      busy = true;
      box.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      message('info', T.WORKING);
      post(action, data).then(function (d) { done(d); }, function (e) { message('danger', e.message); })
        .then(function () { busy = false; box.querySelectorAll('button').forEach(function (b) { b.disabled = false; }); });
    }
    function btn(cls, icon, text, onClick) {
      var b = el('button', 'btn btn-sm ' + cls);
      b.type = 'button';
      b.append(el('span', 'icon-' + icon), document.createTextNode(' ' + text));
      b.querySelector('span').setAttribute('aria-hidden', 'true');
      b.addEventListener('click', onClick);
      return b;
    }
    function table(heads) {
      var t = el('table', 'table table-sm align-middle mb-3');
      var tr = el('tr');
      heads.forEach(function (h) { var th = el('th', '', h); th.scope = 'col'; tr.append(th); });
      var thead = el('thead');
      thead.append(tr);
      t.append(thead, el('tbody'));
      return t;
    }
    function row(t, cells) {
      var tr = el('tr');
      cells.forEach(function (c) {
        var td = el('td');
        if (c instanceof Node) { td.append(c); } else { td.textContent = c; }
        tr.append(td);
      });
      t.tBodies[0].append(tr);
    }

    function download(file) {
      if (busy) { return; }
      request('apps_download', { file: file }).then(function (r) {
        var type = r.headers.get('Content-Type') || '';
        if (type.indexOf('json') !== -1) {
          return r.json().then(function (d) { throw new Error((d && d.message) || T.ERROR); });
        }
        return r.blob();
      }).then(function (blob) {
        var a = el('a');
        a.href = URL.createObjectURL(blob);
        a.download = file;
        document.body.append(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
      }, function (e) { message('danger', e.message); });
    }

    function show(d) {
      var live = {};
      (d.apps || []).forEach(function (a) { live[a.id] = true; });
      var unlocked = (d.unlocked || []).map(Number);

      // the apps
      var head = el('div', 'd-flex flex-wrap align-items-center gap-2 mb-2');
      head.append(el('strong', '', T.APPS));
      head.append(btn('btn-primary', 'copy', T.COPY_ALL, function () {
        run(this, 'apps_backup', {}, function (r) { message('success', fill(T.MADE, { n: r.made })); });
      }));
      var apps = table([T.APP, T.PAGES, '', '']);
      (d.apps || []).forEach(function (a) {
        var lock = !d.protect ? '' : (unlocked.indexOf(a.id) === -1 ? '🔒 ' + T.LOCKED : '🔓 ' + T.UNLOCKED);
        row(apps, [a.title + ' (' + a.type + ', #' + a.id + ')', String(a.pages), lock,
          btn('btn-outline-secondary', 'copy', T.COPY, function () {
            run(this, 'apps_backup', { app: a.id }, function () { message('success', fill(T.MADE, { n: 1 })); });
          })]);
      });
      var folder = el('p', 'small text-muted mb-3', T.FOLDER + ' ' + d.folder);
      if (!d.writable) { folder.append(el('span', 'text-danger fw-bold', ' — ' + T.NOT_WRITABLE)); }
      appsBox.replaceChildren(head, apps, folder);
      // a server that does not obey the .htaccess / web.config of the folder (nginx…): say how to close it
      if (d.exposed) { appsBox.append(el('div', 'alert alert-warning small', T.EXPOSED)); }

      // the copies
      var title = el('strong', 'd-block mb-2', T.COPIES);
      if (!(d.copies || []).length) {
        copiesBox.replaceChildren(title, el('p', 'mb-0', T.NONE));
        return;
      }
      var copies = table([T.WHEN, T.APP, T.REASON, T.CONTENT, T.SIZE, T.WHO, '']);
      d.copies.forEach(function (c) {
        var app = el('span', '', c.app_title + ' (#' + c.app_id + ')');
        if (!live[c.app_id]) { app.append(el('span', 'badge bg-danger ms-1', T.GONE)); }
        var content = c.pages < 0 ? T.UNKNOWN : fill(T.CONTENT_FMT, { pages: c.pages, rows: c.rows, files: c.files });
        if (c.skipped > 0) { content += ' · ' + fill(T.SKIPPED, { n: c.skipped }); }
        var actions = el('div', 'd-flex flex-wrap gap-1');
        if (c.missing) {
          actions.append(el('span', 'text-danger', T.MISSING));
        } else {
          actions.append(btn('btn-outline-secondary', 'download', T.DOWNLOAD, function () { download(c.file); }));
          actions.append(btn('btn-outline-success', 'undo-2', T.RESTORE, function () {
            if (!window.confirm(fill(T.RESTORE_CONFIRM, { app: c.app_title, when: when(c.created) }))) { return; }
            run(this, 'apps_restore', { file: c.file }, function (r) { message('success', fill(T.RESTORED, r.result)); });
          }));
        }
        actions.append(btn('btn-outline-danger', 'trash', T.DELETE, function () {
          if (!window.confirm(fill(T.DELETE_CONFIRM, { app: c.app_title, when: when(c.created) }))) { return; }
          run(this, 'apps_delete', { file: c.file }, function () { message('success', T.DELETED); });
        }));
        var reason = T['R_' + String(c.reason).toUpperCase()] || c.reason;
        row(copies, [when(c.created), app, reason, content, size(c.size), c.user_name || '—', actions]);
      });
      var wrap = el('div', 'table-responsive');
      wrap.append(copies);
      copiesBox.replaceChildren(title, wrap);
    }

    result.textContent = T.LOADING;
    post('apps_list').then(function () { result.replaceChildren(); }, function (e) { message('danger', e.message); });
  }

  function start() { document.querySelectorAll('[data-mt-backups]').forEach(init); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
