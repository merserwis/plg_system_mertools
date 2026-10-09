/**
 * MerTools for Gridbox — the indexes of Gridbox's tables in the plugin settings (MtdbindexesField):
 * the state of every index and the button that creates the missing ones. Requests go to the
 * plugin's mertools_action handler with the form token.
 */
(function () {
  'use strict';

  function init(box) {
    var cfg = JSON.parse(box.getAttribute('data-mt-dbidx'));
    var T = cfg.texts;
    var body = box.querySelector('tbody');
    var result = box.querySelector('.mt-dbidx-result');
    var button = box.querySelector('[data-mt-dbidx-fix]');

    function cell(tr, text, cls) {
      var td = document.createElement('td');
      td.textContent = text;
      if (cls) { td.className = cls; }
      tr.append(td);
    }
    function show(rows) {
      body.replaceChildren();
      (rows || []).forEach(function (r) {
        var tr = document.createElement('tr');
        cell(tr, r.table);
        cell(tr, r.column);
        if (r.state === 'ok') {
          cell(tr, r.by && r.by !== r.name ? T.OK_OTHER.replace('%s', r.by) : T.OK, 'text-success');
        } else if (r.state === 'missing') {
          cell(tr, T.MISSING, 'text-danger fw-bold');
        } else {
          cell(tr, T.NO_TABLE, 'text-muted');
        }
        body.append(tr);
      });
    }
    function message(kind, text) {
      var div = document.createElement('div');
      div.className = 'alert alert-' + kind + ' mb-0';
      div.textContent = text;
      result.replaceChildren(div);
    }
    function post(action) {
      var data = new FormData();
      data.append(cfg.token, '1');
      return fetch('index.php?option=com_plugins&mertools_action=' + action, {
        method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.success) { throw new Error((d && d.message) || T.ERROR); }
        show(d.status);
        return d;
      });
    }

    button.addEventListener('click', function () {
      button.disabled = true;
      post('dbidx_fix').then(function (d) {
        var failed = Object.keys(d.failed || {});
        if (failed.length) {
          message('danger', T.FAILED + ' ' + failed.map(function (k) { return k + ': ' + d.failed[k]; }).join('; '));
        } else {
          message('success', (d.created || []).length ? T.FIXED.replace('%d', d.created.length) : T.NOTHING);
        }
      }, function (e) { message('danger', e.message); }).then(function () { button.disabled = false; });
    });
    result.textContent = T.LOADING;
    post('dbidx_status').then(function () { result.textContent = ''; }, function (e) { message('danger', e.message); });
  }

  function start() { document.querySelectorAll('[data-mt-dbidx]').forEach(init); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
