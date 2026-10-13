/**
 * MerTools for Gridbox — the clear-cache button in the top bar of the administrator: one click
 * empties the pages kept by MerTools and Joomla's cache, the result is shown as a Joomla message.
 * Atum copies the button into its "more" menu on narrow screens, so clicks are caught on the document.
 */
(function () {
  'use strict';

  var busy = false;

  function message(type, text) {
    if (window.Joomla && Joomla.renderMessages) {
      var m = {};
      m[type] = [text];
      Joomla.renderMessages(m);
    } else {
      window.alert(text);
    }
  }

  document.addEventListener('click', function (e) {
    var button = e.target.closest ? e.target.closest('[data-mertools-clear-cache]') : null;
    if (!button) { return; }
    e.preventDefault();
    if (busy) { return; }
    var cfg = (window.Joomla && Joomla.getOptions) ? Joomla.getOptions('plg_system_mertools.header') : null;
    if (!cfg || !cfg.url) { return; }
    busy = true;
    var icons = document.querySelectorAll('[data-mertools-clear-cache] .header-item-icon span');
    icons.forEach(function (i) { i.className = 'icon-spinner icon-spin'; });
    fetch(cfg.url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r || !r.success) { throw new Error((r && r.message) || cfg.error); }
        message(r.errors && r.errors.length ? 'warning' : 'message', r.message);
      })
      .catch(function (err) {
        message('error', (err && err.message) || cfg.error);
      })
      .then(function () {
        busy = false;
        icons.forEach(function (i) { i.className = 'icon-trash'; });
      });
  });
})();
