/**
 * MerTools for Gridbox — quick setup: the button "Turn on the recommended" turns on every switch
 * recommended for most sites (data-recommended="1"); the optional ones stay as they are (an optional
 * tool may still be needed, e.g. Omnibus in an EU shop). Nothing is saved until the page is saved.
 */
(function () {
  'use strict';

  function start() {
    var button = document.querySelector('[data-mt-recommended]');
    if (!button) { return; }
    button.addEventListener('click', function () {
      document.querySelectorAll('.mt-setup-switch[data-recommended="1"]').forEach(function (set) {
        var input = set.querySelector('input[value="1"]');
        if (input && !input.checked) {
          input.checked = true;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        set.querySelectorAll('input').forEach(function (i) { i.classList.toggle('active', i.checked); });
      });
    });
    // the switcher of Joomla marks the chosen option with the class "active"
    document.querySelectorAll('.mt-setup-switch input').forEach(function (input) {
      input.addEventListener('change', function () {
        input.closest('.switcher').querySelectorAll('input').forEach(function (i) { i.classList.toggle('active', i.checked); });
      });
    });
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
