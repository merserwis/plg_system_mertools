/**
 * MerTools for Gridbox — help tooltips in the plugin settings: a "?" beside every option name that
 * has an explanation; the explanation shows on hover, on keyboard focus and on a click (a second
 * click, Escape or a click elsewhere closes it). The explanation moves into the tooltip, so the
 * settings stay short.
 */
(function () {
  'use strict';

  function start() {
    var form = document.getElementById('style-form') || document.querySelector('form[name="adminForm"]');
    if (!form) { return; }
    var opts = (window.Joomla && Joomla.getOptions && Joomla.getOptions('plg_system_mertools.admin')) || {};
    var n = 0;

    function closeAll(except) {
      form.querySelectorAll('.mt-help-wrap.is-open').forEach(function (w) {
        if (w !== except) {
          w.classList.remove('is-open');
          w.querySelector('.mt-help').setAttribute('aria-expanded', 'false');
        }
      });
    }

    function add(scope) {
      scope.querySelectorAll('.control-group').forEach(function (g) {
        var head = g.querySelector('.control-label');
        var desc = g.querySelector('.controls > [id$="-desc"]') || g.querySelector('[id$="-desc"]');
        if (!head || !desc || !desc.textContent.trim() || head.querySelector('.mt-help-wrap')) { return; }
        var wrap = document.createElement('span');
        wrap.className = 'mt-help-wrap';
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'mt-help';
        b.textContent = '?';
        b.setAttribute('aria-label', opts.help || '?');
        b.setAttribute('aria-expanded', 'false');
        var tip = document.createElement('span');
        tip.className = 'mt-help-tip';
        tip.id = 'mt-help-tip-' + (++n);
        tip.setAttribute('role', 'note');
        tip.innerHTML = (desc.querySelector('.form-text') || desc).innerHTML;
        b.setAttribute('aria-describedby', tip.id);
        wrap.append(b, tip);
        (head.querySelector('label, legend') || head).append(wrap);
        g.classList.add('mt-has-help');
      });
    }

    // on the whole page: a click anywhere outside a tooltip closes it
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('.mt-help');
      if (!b) {
        if (!(e.target.closest && e.target.closest('.mt-help-tip'))) { closeAll(null); }
        return;
      }
      // a "?" inside a label must not toggle the switch of that label
      e.preventDefault();
      e.stopPropagation();
      var wrap = b.parentNode;
      var open = !wrap.classList.contains('is-open');
      closeAll(wrap);
      wrap.classList.toggle('is-open', open);
      b.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeAll(null);
        if (document.activeElement && document.activeElement.classList.contains('mt-help')) { document.activeElement.blur(); }
      }
    });
    add(form);
    document.addEventListener('subform-row-add', function (e) { add((e.detail && e.detail.row) || e.target); });
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
