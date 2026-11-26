/**
 * MerTools for Gridbox — the tables of the site in the plugin settings (MtdbhealthField): tables not in
 * utf8mb4_unicode_ci (converted one by one), asset rows of items that no longer exist (deleted in batches), the
 * largest tables and their free space (OPTIMIZE one by one). Requests go to the plugin's mertools_action handler
 * with the form token; a change of the database is offered to a Super User only, after a question.
 */
(function () {
  'use strict';

  function init(box) {
    var cfg = JSON.parse(box.getAttribute('data-mt-dbhealth'));
    var T = cfg.texts;
    var root = box.querySelector('.mt-dbh-body');
    var busy = false;
    var state = null;

    function el(tag, cls, text) {
      var e = document.createElement(tag);
      if (cls) { e.className = cls; }
      if (text !== undefined) { e.textContent = text; }
      return e;
    }
    function mb(bytes) {
      return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }
    function say(text) {
      var args = Array.prototype.slice.call(arguments, 1);
      return args.reduce(function (s, v) { return s.replace(/%[ds]/, v); }, text);
    }
    function post(action, extra) {
      var data = new FormData();
      data.append(cfg.token, '1');
      Object.keys(extra || {}).forEach(function (k) { data.append(k, extra[k]); });
      return fetch('index.php?option=com_plugins&mertools_action=' + action, {
        method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.success) { throw new Error((d && d.message) || T.ERROR); }
        state = d;
        return d;
      });
    }
    function table(head, rows) {
      var t = el('table', 'table table-sm mb-2');
      var tr = el('tr');
      head.forEach(function (h) { var th = el('th', '', h); th.scope = 'col'; tr.append(th); });
      var thead = el('thead');
      thead.append(tr);
      var body = el('tbody');
      rows.forEach(function (r) {
        var row = el('tr');
        r.forEach(function (c) { var td = el('td'); if (c instanceof Node) { td.append(c); } else { td.textContent = c; } row.append(td); });
        body.append(row);
      });
      t.append(thead, body);
      return t;
    }
    function button(text, cls, run, kind) {
      var b = el('button', 'btn btn-sm ' + (cls || 'btn-secondary'), text);
      b.type = 'button';
      b.setAttribute('data-mt-dbh', kind);
      b.disabled = busy || !state.admin;
      b.addEventListener('click', run);
      return b;
    }
    function note(kind, text) {
      var n = el('div', 'alert alert-' + kind + ' py-2 mb-2', text);
      return n;
    }
    // one step after another (the server works on one table at a time), the panel shown again after each
    function steps(list, one, done) {
      busy = true;
      var i = 0;
      var failed = [];
      function next() {
        if (i >= list.length) { busy = false; render(); done(failed); return; }
        var item = list[i++];
        render(say(T.WORKING, i, list.length));
        one(item).then(function (msg) { if (msg) { failed.push(msg); } }, function (e) { failed.push(e.message); }).then(next);
      }
      next();
    }
    var message = null;
    function render(working) {
      var d = state;
      root.replaceChildren();
      root.append(el('p', 'mb-2', say(T.SUMMARY, d.count, mb(d.total))));
      if (working) { root.append(note('info', working)); }
      if (message) { root.append(message); }
      if (!d.admin) { root.append(note('secondary', T.ADMIN_ONLY)); }

      // ---- character sets
      root.append(el('h4', 'h6 mt-3', T.CHARSET));
      if (!d.collations.length) {
        root.append(el('p', 'text-success', T.CHARSET_OK));
      } else {
        root.append(el('p', 'small text-muted', T.CHARSET_INFO));
        var todo = d.collations.filter(function (c) { return c.verdict === 'convert'; });
        root.append(table([T.TABLE, T.COLLATION, T.SIZE, T.STATE], d.collations.map(function (c) {
          return [c.table, c.collation, mb(c.size), c.verdict === 'convert'
            ? button(T.CONVERT, 'btn-outline-primary', function () { convert([c.table]); }, 'convert')
            : el('span', 'text-warning', c.columns && c.columns.length ? say(T.COLUMNS, c.columns.join(', ')) : T.MANUAL)];
        })));
        if (todo.length > 1) {
          root.append(button(say(T.CONVERT_ALL, todo.length), 'btn-primary', function () { convert(todo.map(function (c) { return c.table; })); }, 'convert-all'));
        }
      }

      // ---- leftover asset rows
      root.append(el('h4', 'h6 mt-4', T.ASSETS));
      var kinds = Object.keys(d.orphans);
      if (!kinds.length) {
        root.append(el('p', 'text-success', T.ASSETS_OK));
      } else {
        root.append(el('p', 'small text-muted', T.ASSETS_INFO));
        root.append(table([T.KIND, T.COUNT, T.EXAMPLES], kinds.map(function (k) {
          return [T['K_' + k.toUpperCase()] || k, String(d.orphans[k].count), d.orphans[k].titles.join(', ') + (d.orphans[k].count > d.orphans[k].titles.length ? ', …' : '')];
        })));
        root.append(button(T.ASSETS_DELETE, 'btn-primary', deleteAssets, 'assets'));
      }

      // ---- sizes and free space
      root.append(el('h4', 'h6 mt-4', T.TABLES));
      root.append(el('p', 'small text-muted', T.TABLES_INFO));
      var free = d.tables.filter(function (t) { return t.free >= d.worth; });
      root.append(table([T.TABLE, T.ROWS, T.SIZE, T.FREE, ''], d.tables.map(function (t) {
        return [t.table, String(t.rows), mb(t.size), t.free ? mb(t.free) : '–',
          t.free >= d.worth ? button(T.OPTIMIZE, 'btn-outline-primary', function () { optimize([t.table]); }, 'optimize') : ''];
      })));
      if (free.length > 1) {
        root.append(button(say(T.OPTIMIZE_ALL, free.length), 'btn-primary', function () { optimize(free.map(function (t) { return t.table; })); }, 'optimize-all'));
      } else if (!free.length) {
        root.append(el('p', 'small', T.NOTHING_FREE));
      }
    }
    function finish(okText, failed) {
      message = failed.length ? note('danger', T.FAILED + ' ' + failed.join('; ')) : note('success', okText);
      render();
    }
    function convert(list) {
      if (busy || !window.confirm(say(T.CONVERT_ASK, list.length))) { return; }
      steps(list, function (t) {
        return post('dbh_convert', { table: t }).then(function (d) { return d.result.error ? t + ': ' + d.result.error : null; });
      }, function (failed) { finish(say(T.CONVERTED, list.length - failed.length), failed); });
    }
    function optimize(list) {
      if (busy || !window.confirm(say(T.OPTIMIZE_ASK, list.length))) { return; }
      var given = 0;
      steps(list, function (t) {
        return post('dbh_optimize', { table: t }).then(function (d) {
          given += Math.max(0, d.result.before - d.result.after);
          return d.result.ok ? null : t + ': ' + d.result.message;
        });
      }, function (failed) { finish(say(T.OPTIMIZED, list.length - failed.length, mb(given)), failed); });
    }
    function deleteAssets() {
      var total = Object.keys(state.orphans).reduce(function (s, k) { return s + state.orphans[k].count; }, 0);
      if (busy || !window.confirm(say(T.ASSETS_ASK, total))) { return; }
      busy = true;
      var deleted = 0;
      var failed = [];
      function round() {
        render(say(T.WORKING, deleted, total));
        post('dbh_assets').then(function (d) {
          deleted += d.result.deleted;
          failed = failed.concat(d.result.failed || []);
          // on while each batch deletes something and something is left
          if (d.result.left > 0 && d.result.deleted > 0) { round(); return; }
          busy = false;
          finish(say(T.ASSETS_DONE, deleted), failed);
        }, function (e) { busy = false; finish('', [e.message]); });
      }
      round();
    }

    post('dbh_status').then(function () { render(); }, function (e) { root.replaceChildren(note('danger', e.message)); });
  }

  function start() { document.querySelectorAll('[data-mt-dbhealth]').forEach(init); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
