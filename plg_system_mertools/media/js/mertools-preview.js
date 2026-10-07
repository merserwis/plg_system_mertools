/**
 * MerTools for Gridbox — live preview of the dark theme and sepia in the plugin settings. Reads the
 * form (palette, intensity, custom colours, accent, sepia on/off) and recolours the mock page at once;
 * the intensity uses the same formula as DarkMode::shade() in PHP.
 */
(function () {
  'use strict';

  function field(name) {
    return document.querySelector('[name="jform[params][' + name + ']"]:checked')
      || document.querySelector('[name="jform[params][' + name + ']"]:not([type="radio"])');
  }

  function val(name, def) {
    var f = field(name);
    return f && f.value !== '' ? f.value : def;
  }

  function hex(c) {
    var m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec((c || '').trim());
    if (!m) return null;
    var h = m[1].length === 3 ? m[1].replace(/(.)/g, '$1$1') : m[1];
    return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)];
  }

  /** Same as DarkMode::shade(): above 50 towards a near-black, below 50 towards a soft slate grey. */
  function shade(colour, intensity) {
    var rgb = hex(colour);
    if (!rgb || intensity === 50) return colour;
    var t = (intensity - 50) / 50, target = t > 0 ? [6, 8, 11] : [74, 82, 96], amount = t > 0 ? t * 0.55 : -t * 0.32;
    return '#' + rgb.map(function (v, i) {
      var x = Math.round(v + (target[i] - v) * amount);
      return (x < 16 ? '0' : '') + x.toString(16);
    }).join('');
  }

  var LIGHT = { bg: '#ffffff', surface: '#f4f6f8', bg_dark: '#1f2328', title: '#1f2328', text: '#3d4450',
    muted: 'rgba(31,35,40,.55)', border: '#dfe3e8', hover: '#eef1f4', shadow: 'rgba(0,0,0,.08)' };

  function init(box) {
    var data = {};
    try { data = JSON.parse(box.getAttribute('data-mt-preview')) || {}; } catch (e) { data = {}; }
    var page = box.querySelector('.mt-pv-page'), info = box.querySelector('.mt-pv-int');
    var tabs = [].slice.call(box.querySelectorAll('[data-t]')), theme = 'dark';
    var KEYS = ['bg', 'surface', 'bg_dark', 'title', 'text', 'muted', 'border', 'hover', 'shadow'];

    function palette() {
      if (theme === 'sepia') return data.sepia || LIGHT;
      if (theme === 'light') return LIGHT;
      var key = val('dark_palette', 'slate'), p = {};
      var base = (data.presets || {})[key] || (data.presets || {}).slate || LIGHT;
      KEYS.forEach(function (k) { p[k] = key === 'custom' ? val('dark_c_' + k, base[k]) : base[k]; });
      var i = parseInt(val('dark_intensity', '50'), 10);
      i = isNaN(i) ? 50 : Math.max(0, Math.min(100, i));
      (data.shades || []).forEach(function (k) { p[k] = shade(p[k], i); });
      return p;
    }

    function render() {
      var p = palette();
      KEYS.forEach(function (k) { page.style.setProperty('--' + k.replace('_', '-'), p[k]); });
      var accent = val('dark_accent_site', '1') === '0' && theme === 'dark' ? val('dark_accent', '#f2a705') : '#f2a705';
      page.style.setProperty('--accent', accent);
      var sepiaOn = val('dark_sepia', '1') !== '0';
      tabs.forEach(function (b) {
        if (b.getAttribute('data-t') === 'sepia') b.hidden = !sepiaOn;
        b.setAttribute('aria-pressed', b.getAttribute('data-t') === theme ? 'true' : 'false');
      });
      if (theme === 'sepia' && !sepiaOn) { theme = 'dark'; render(); return; }
      info.textContent = theme === 'dark' ? (data.intensity || 'Intensity: %s').replace('%s', val('dark_intensity', '50')) : '';
    }

    tabs.forEach(function (b) {
      b.addEventListener('click', function () { theme = b.getAttribute('data-t'); render(); });
    });
    var form = box.closest('form') || document;
    form.addEventListener('input', render);
    form.addEventListener('change', render);
    // the Joomla colour fields set their value without an input event: check now and then while visible
    setInterval(function () { if (box.offsetParent) render(); }, 700);
    render();
  }

  function start() {
    [].forEach.call(document.querySelectorAll('[data-mt-preview]'), init);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
