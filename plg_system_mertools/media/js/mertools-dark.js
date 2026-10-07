/**
 * MerTools for Gridbox — dark mode and sepia. The theme itself is set very early by a small inline
 * script in the head (no flash); this script
 *  - builds the toggle button and keeps it where a visitor can see it (the menu on desktop, beside
 *    the hamburger on phones, or floating in the visible part of the screen),
 *  - switches light → dark → sepia on click, remembers the choice and follows the system when "auto",
 *  - adapts the colours Gridbox writes into its element styles (they do not use the theme
 *    variables): light backgrounds are darkened and text too dark for its background is lightened
 *    just enough to be readable, keeping its hue. The fixes are data attributes that only the dark
 *    theme CSS uses, so the light theme is untouched. The Gridbox core is not touched.
 */
(function () {
  'use strict';

  var opts = (window.Joomla && Joomla.getOptions) ? Joomla.getOptions('plg_system_mertools') : null;
  if (!opts) {
    var el = document.querySelector('script.joomla-script-options');
    try { opts = el ? JSON.parse(el.textContent).plg_system_mertools : null; } catch (e) { opts = null; }
  }
  var cfg = (opts && opts.dark) || {};
  var KEY = cfg.key || 'mertools-theme';
  var root = document.documentElement;

  var THEMES = cfg.themes && cfg.themes.length ? cfg.themes : ['light', 'dark'];

  /** The theme shown now: "dark", "sepia" (when offered) or "light". */
  function current() {
    var t = root.getAttribute('data-mertools-theme');
    return t !== 'light' && THEMES.indexOf(t) > -1 ? t : 'light';
  }

  function store(theme) {
    try { localStorage.setItem(KEY, theme); } catch (e) { /* private mode */ }
  }

  function stored() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }

  // ------------------------------------------------------------------ colour adaptation

  var SKIP = { SCRIPT: 1, STYLE: 1, LINK: 1, META: 1, NOSCRIPT: 1, BR: 1, IMG: 1, PICTURE: 1, SOURCE: 1, VIDEO: 1, AUDIO: 1,
    CANVAS: 1, IFRAME: 1, SVG: 1, PATH: 1, OBJECT: 1, EMBED: 1, TEMPLATE: 1 };
  var FORM = { INPUT: 1, TEXTAREA: 1, SELECT: 1 };
  var pals = {};

  var parsed = {}, ctx = null;

  /** Any CSS colour as [r, g, b, a]: rgb()/rgba() directly, color(srgb …) (what color-mix() computes
   *  to), and every other notation (oklch, lab, hsl…) through a 1×1 canvas. Cached. */
  function parse(c) {
    if (!c || c === 'transparent' || c === 'none') return c === 'transparent' ? [0, 0, 0, 0] : null;
    if (parsed.hasOwnProperty(c)) return parsed[c];
    var out = null, m = /^rgba?\(([^)]+)\)$/.exec(c);
    if (m) {
      var p = m[1].split(/[\s,\/]+/).filter(Boolean).map(parseFloat);
      out = [p[0], p[1], p[2], p.length > 3 ? p[3] : 1];
    } else if ((m = /^color\(srgb\s+([^)]+)\)$/.exec(c))) {
      var q = m[1].split(/[\s\/]+/).filter(Boolean).map(parseFloat);
      out = [q[0] * 255, q[1] * 255, q[2] * 255, q.length > 3 ? q[3] : 1];
    } else {
      try {
        ctx = ctx || document.createElement('canvas').getContext('2d', { willReadFrequently: true });
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = '#000';
        ctx.fillStyle = c;
        ctx.fillRect(0, 0, 1, 1);
        var d = ctx.getImageData(0, 0, 1, 1).data;
        out = [d[0], d[1], d[2], d[3] / 255];
      } catch (e) { out = null; }
    }
    parsed[c] = out;
    return out;
  }

  function cssColor(value) {
    if (!value) return null;
    var probe = document.createElement('i');
    probe.style.color = value;
    probe.style.display = 'none';
    document.body.appendChild(probe);
    var c = parse(getComputedStyle(probe).color);
    probe.remove();
    return c;
  }

  function lum(c) {
    var a = [c[0], c[1], c[2]].map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
  }

  function contrast(a, b) {
    var x = lum(a), y = lum(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
  }

  function mix(a, b, t) {
    return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t, a.length > 3 ? a[3] : 1];
  }

  function over(fg, bg) {
    return fg[3] >= 1 ? fg : mix(bg, fg, fg[3]).slice(0, 3).concat([1]);
  }

  function sat(c) {
    var mx = Math.max(c[0], c[1], c[2]) / 255, mn = Math.min(c[0], c[1], c[2]) / 255, l = (mx + mn) / 2;
    return mx === mn ? 0 : (mx - mn) / (1 - Math.abs(2 * l - 1));
  }

  function rgb(c) {
    var s = Math.round(c[0]) + ',' + Math.round(c[1]) + ',' + Math.round(c[2]);
    return c.length > 3 && c[3] < 1 ? 'rgba(' + s + ',' + (+c[3].toFixed(3)) + ')' : 'rgb(' + s + ')';
  }

  /** The palette of the current theme (dark or sepia) as parsed colours. */
  function palette() {
    var theme = current();
    if (pals[theme]) return pals[theme];
    var p = (theme === 'sepia' ? cfg.sepia : cfg.pal) || {};
    var accent = cfg.accent || getComputedStyle(document.body).getPropertyValue('--primary').trim();
    var pal = {
      theme: theme,
      bg: cssColor(p.bg) || [27, 34, 48, 1],
      surface: cssColor(p.surface) || [35, 44, 61, 1],
      dark: cssColor(p.bg_dark) || [16, 21, 31, 1],
      title: cssColor(p.title) || [232, 236, 243, 1],
      border: cssColor(p.border) || [51, 62, 82, 1],
      accent: cssColor(accent)
    };
    // the lightest and the darkest colour of the palette: the two directions a text can be moved
    var ends = [pal.title, pal.bg, pal.dark].sort(function (a, b) { return lum(b) - lum(a); });
    pal.hi = ends[0];
    pal.lo = ends[2];
    // the colours the theme itself gives to text (the theme variables), and its background colours
    pal.own = [p.title, p.text, p.muted, p.bg, p.surface, p.bg_dark].map(cssColor).filter(Boolean);
    pal.bgs = [p.bg, p.surface, p.bg_dark].map(cssColor).filter(Boolean);
    pals[theme] = pal;
    return pal;
  }

  function near(o, c) {
    return Math.abs(o[0] - c[0]) + Math.abs(o[1] - c[1]) + Math.abs(o[2] - c[2]) < 6 && Math.abs((o[3] || 1) - (c[3] || 1)) < 0.05;
  }

  function ownColour(c) {
    return palette().own.some(function (o) { return near(o, c); });
  }

  var mediaCache = new Map();

  /** Does a photo, video or image layer lie under the content of this element? Gridbox puts section
   *  images in a separate absolutely positioned layer (.parallax-wrapper, slideshows, video), not in
   *  the background of the text's ancestors. */
  function mediaLayer(e, from) {
    if (mediaCache.has(e)) return mediaCache.get(e);
    var found = false, box = e.getBoundingClientRect(), area = box.width * box.height;
    if (area > 0) {
      for (var c = e.firstElementChild, n = 0; c && n < 12 && !found; c = c.nextElementSibling, n++) {
        if (c === from) continue;
        var cs = getComputedStyle(c);
        if (cs.position !== 'absolute' && cs.position !== 'fixed' && c.tagName !== 'VIDEO') continue;
        var r = c.getBoundingClientRect();
        if (r.width * r.height < area * 0.5) continue;
        found = c.tagName === 'IMG' || c.tagName === 'VIDEO' || c.tagName === 'PICTURE' || c.tagName === 'IFRAME'
          || /url\(/.test(cs.backgroundImage)
          || /video-background|parallax|slideshow/.test(typeof c.className === 'string' ? c.className : '')
          || !!c.querySelector('img, video, picture, iframe, [style*="url("]')
          || [].some.call(c.querySelectorAll('*'), function (d, i) { return i < 20 && /url\(/.test(getComputedStyle(d).backgroundImage); });
      }
    }
    mediaCache.set(e, found);
    return found;
  }

  /** The colour behind an element (after the fixes), or null when it is an image. */
  function backdrop(el) {
    var layers = [];
    for (var e = el, prev = null; e && e.nodeType === 1; prev = e, e = e.parentElement) {
      if (e !== el && e !== document.body && e !== root && mediaLayer(e, prev)) return null;
      var cs = getComputedStyle(e), bi = cs.backgroundImage;
      if (bi !== 'none' && (/url\(/.test(bi) || !/gradient/.test(bi))) return null;
      // a gradient above the background colour: counted as its average colour
      var g = bi !== 'none' ? gradientAverage(bi) : null;
      if (g) {
        layers.push(g);
        if (g[3] >= 1) break;
      }
      var c = parse(cs.backgroundColor);
      if (c && c[3] > 0) {
        layers.push(c);
        if (c[3] >= 1) break;
      }
    }
    var b = palette().bg;
    for (var i = layers.length - 1; i >= 0; i--) b = over(layers[i], b);
    return b;
  }

  /** Text written by CSS (content: "…" on ::before / ::after), e.g. a cookie banner's message. */
  function pseudoText(el) {
    for (var i = 0; i < 2; i++) {
      var ps = getComputedStyle(el, i ? '::after' : '::before'), c = ps.content;
      if (c && c !== 'none' && c !== 'normal' && /^["'].*[A-Za-z0-9\u00C0-\u024F\u0400-\u04FF].*["']$/.test(c)) return ps;
    }
    return null;
  }

  var COLOR_RE = /(?:rgba?|color|oklch|oklab|lch|lab|hsla?|hwb)\([^()]*\)/g;

  function gradientAverage(bi) {
    var list = (bi.match(COLOR_RE) || []).map(parse).filter(Boolean);
    if (!list.length) return null;
    var s = [0, 0, 0, 0];
    list.forEach(function (c) { s[0] += c[0]; s[1] += c[1]; s[2] += c[2]; s[3] += c[3]; });
    return [s[0] / list.length, s[1] / list.length, s[2] / list.length, s[3] / list.length];
  }

  /** The theme's colour for a light background colour (darker in dark mode, warm paper in sepia),
   *  keeping its tone; null when it should stay. */
  function darker(c, wide) {
    var l = lum(c);
    if (c[3] < 0.35 || l < 0.5 || (sat(c) > 0.55 && l < 0.8)) return null;
    var p = palette(), nb = mix(c, wide ? p.bg : p.surface, wide ? 0.96 : 0.9);
    nb[3] = c[3];
    return nb;
  }

  function ownText(el) {
    if (FORM[el.tagName]) return true;
    if (pseudoText(el)) return true;
    for (var n = el.firstChild; n; n = n.nextSibling) {
      if (n.nodeType === 3 && n.nodeValue.trim() !== '') return true;
    }
    // font icons (an <i> or <span> with an icon class and no text)
    return (el.tagName === 'I' || el.tagName === 'SPAN') && !el.firstElementChild && /icon|zmdi|fa-|flaticon|ba-icon/.test(el.className || '');
  }

  function fixBackground(el, cs) {
    var bi = cs.backgroundImage;
    if (bi !== 'none' && (/url\(/.test(bi) || !/gradient/.test(bi))) return;
    var wide = el.getBoundingClientRect().width >= window.innerWidth * 0.9;
    // dark and mid colours stay; vivid brand colours (buttons, badges) stay
    var nb = darker(parse(cs.backgroundColor) || [0, 0, 0, 0], wide);
    if (nb) {
      el.style.setProperty('--mt-bg', rgb(nb));
      el.setAttribute('data-mt-bg', '');
    }
    // a gradient (e.g. a light footer fading to grey): its light colours darkened the same way
    if (bi !== 'none') {
      var changed = false;
      var ng = bi.replace(COLOR_RE, function (tok) {
        var d = darker(parse(tok) || [0, 0, 0, 0], wide);
        if (!d) return tok;
        changed = true;
        return rgb(d);
      });
      if (changed) {
        el.style.setProperty('--mt-bgi', ng);
        el.setAttribute('data-mt-bgi', '');
      }
    }
  }

  var SIDES = ['Top', 'Right', 'Bottom', 'Left'], SIDE_KEYS = ['t', 'r', 'b', 'l'];

  /** Light border colours of an element and of its ::before / ::after (e.g. the triangles between
   *  breadcrumb items, drawn with borders in the colour of the item): thin lines take the theme's
   *  border colour, wide borders (shapes) the same colour a background would get. */
  function fixBorders(el, cs) {
    var targets = [[cs, 'bd', 'data-mt-bd']];
    for (var i = 0; i < 2; i++) {
      var ps = getComputedStyle(el, i ? '::after' : '::before');
      if (ps.content && ps.content !== 'none' && ps.content !== 'normal') targets.push([ps, i ? 'ba' : 'bb', i ? 'data-mt-bda' : 'data-mt-bdb']);
    }
    targets.forEach(function (tg) {
      var s = tg[0], changed = false, vals = [];
      for (var k = 0; k < 4; k++) {
        var w = parseFloat(s['border' + SIDES[k] + 'Width']) || 0, col = s['border' + SIDES[k] + 'Color'], c = w > 0 ? parse(col) : null;
        var d = c && c[3] > 0.35 ? darker(c, false) : null;
        if (d && w <= 3) d = palette().border.slice(0, 3).concat([c[3]]);
        vals.push(d ? rgb(d) : col);
        if (d) changed = true;
      }
      if (!changed) return;
      for (var j = 0; j < 4; j++) el.style.setProperty('--mt-' + tg[1] + SIDE_KEYS[j], vals[j]);
      el.setAttribute(tg[2], '');
    });
  }

  function fixText(el, cs) {
    var hasOwn = false;
    for (var n = el.firstChild; n; n = n.nextSibling) {
      if (n.nodeType === 3 && n.nodeValue.trim() !== '') { hasOwn = true; break; }
    }
    var ps = hasOwn || FORM[el.tagName] ? null : pseudoText(el);
    if (ps) cs = ps;
    var fg = parse(cs.color);
    if (!fg) return;
    var bg = backdrop(el);
    if (!bg) {
      // over a photo or video: the colours behind are unknown, so the text keeps its colour — unless
      // dark mode itself turned it dark: a Gridbox background variable used as text colour (white on
      // the photo in the light theme) becomes the dark background colour; it gets the light text colour
      var pm = palette();
      if (lum(pm.bg) < 0.2 && pm.bgs.some(function (o) { return near(o, fg); })) {
        el.style.setProperty('--mt-fg', rgb(pm.title));
        el.setAttribute('data-mt-fg', '');
        if (ps) el.setAttribute('data-mt-fgp', '');
      }
      return;
    }
    var size = parseFloat(cs.fontSize) || 16;
    var need = (size >= 24 || (size >= 18.6 && parseInt(cs.fontWeight, 10) >= 700)) ? 3 : 4.5;
    var shown = over(fg, bg), now = contrast(shown, bg), p0 = palette();
    // sepia: neutral dark text (greys, black written into element styles) takes the warm brown tone
    if (now >= need && p0.theme === 'sepia' && sat(shown) < 0.12 && lum(shown) < 0.2) {
      var warm = mix(shown, p0.title, 0.75);
      if (contrast(warm, bg) >= need) {
        el.style.setProperty('--mt-fg', rgb(warm));
        el.setAttribute('data-mt-fg', '');
        if (ps) el.setAttribute('data-mt-fgp', '');
      }
      return;
    }
    if (now >= need) return;
    // text on a vivid brand colour (buttons, badges) keeps the site's design — unless its colour
    // comes from the dark palette (a theme variable), i.e. dark mode itself changed it
    var lb = lum(bg);
    if (sat(bg) > 0.5 && lb > 0.06 && lb < 0.6 && !ownColour(fg)) return;
    // lighten or darken, whichever reaches a comfortable contrast with the smallest change of colour
    // (a fixed text always aims at 4.5:1, also when the large-text minimum of 3:1 would pass)
    need = 4.5;
    var p = palette(), fix = null, step = 1, best = null, bestC = now;
    [p.hi, p.lo].forEach(function (towards) {
      for (var t = 0.15; t <= 1.0001; t += 0.15) {
        var c = mix(shown, towards, Math.min(t, 1)), cr = contrast(c, bg);
        if (cr >= need) {
          if (t < step) { step = t; fix = c; }
          return;
        }
        if (cr > bestC) { bestC = cr; best = c; }
      }
    });
    fix = fix || (bestC >= now + 0.5 ? best : null);
    if (!fix) return;
    el.style.setProperty('--mt-fg', rgb(fix));
    if (el.closest('a') && p.accent && contrast(p.accent, bg) >= 3) {
      el.style.setProperty('--mt-fg-h', rgb(p.accent));
    }
    el.setAttribute('data-mt-fg', '');
    if (ps) el.setAttribute('data-mt-fgp', '');
  }

  function adapt(scope) {
    if (cfg.adaptive === false || current() === 'light' || !document.body) return;
    root.classList.add('mertools-dt-calc');
    mediaCache = new Map();
    var list = scope === document.body ? document.body.querySelectorAll('*') : [scope].concat([].slice.call(scope.querySelectorAll('*')));
    var i, el;
    // backgrounds first: the text check reads the fixed backgrounds
    for (i = 0; i < list.length; i++) {
      el = list[i];
      if (SKIP[el.tagName.toUpperCase()] || el.hasAttribute('data-mt-bg') || el.hasAttribute('data-mt-bgi') || el.closest('.mertools-dt')) continue;
      var ecs = getComputedStyle(el);
      fixBackground(el, ecs);
      if (!el.hasAttribute('data-mt-bd') && !el.hasAttribute('data-mt-bdb') && !el.hasAttribute('data-mt-bda')) fixBorders(el, ecs);
    }
    for (i = 0; i < list.length; i++) {
      el = list[i];
      if (SKIP[el.tagName.toUpperCase()] || el.hasAttribute('data-mt-fg') || el.closest('.mertools-dt') || !ownText(el)) continue;
      fixText(el, getComputedStyle(el));
    }
    void root.offsetWidth;
    root.classList.remove('mertools-dt-calc');
  }

  var done = false, pending = [], timer = 0, refreshTimer = 0;

  var ATTRS = ['data-mt-bg', 'data-mt-bgi', 'data-mt-fg', 'data-mt-fgp', 'data-mt-bd', 'data-mt-bdb', 'data-mt-bda'];
  var PROPS = ['--mt-bg', '--mt-bgi', '--mt-fg', '--mt-fg-h'];
  ['bd', 'bb', 'ba'].forEach(function (p) { SIDE_KEYS.forEach(function (k) { PROPS.push('--mt-' + p + k); }); });
  var MARKED = '[data-mt-bg],[data-mt-bgi],[data-mt-fg],[data-mt-bd],[data-mt-bdb],[data-mt-bda]';

  function unmark(el) {
    ATTRS.forEach(function (a) { el.removeAttribute(a); });
    PROPS.forEach(function (p) { el.style.removeProperty(p); });
  }

  /** The fixes for the current theme, computed from the site's own colours (in one task: no flicker).
   *  Every switch to dark or sepia recomputes them, since the two themes need different colours. */
  function adaptAll() {
    if (current() === 'light') {
      root.removeAttribute('data-mt-on');
      return;
    }
    [].forEach.call(document.querySelectorAll(MARKED), unmark);
    done = true;
    // on before the pass: the text check has to see the backgrounds already fixed
    if (cfg.adaptive !== false) root.setAttribute('data-mt-on', '');
    adapt(document.body);
  }

  /** One element and its content again from the site's own colours (in one task: no flicker). */
  function readapt(el) {
    if (!el.isConnected) return;
    if (el.matches(MARKED)) unmark(el);
    [].forEach.call(el.querySelectorAll(MARKED), unmark);
    adapt(el);
  }

  /** Everything again from the site's own colours: styles that arrived later (a stylesheet loaded after
   *  this script, lazy sections) may have changed them. Runs in one task, so nothing flickers. */
  function refresh() {
    if (!done || current() === 'light') return;
    [].forEach.call(document.querySelectorAll(MARKED), unmark);
    adapt(document.body);
    update();
  }

  function refreshSoon(delay) {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(refresh, delay || 300);
  }

  function watch() {
    if (cfg.adaptive === false || !window.MutationObserver) return;
    new MutationObserver(function (records) {
      if (!done || current() === 'light') return;
      records.forEach(function (r) {
        r.addedNodes.forEach(function (n) { if (n.nodeType === 1 && !n.classList.contains('mertools-dt')) pending.push(n); });
      });
      if (pending.length && !timer) {
        timer = setTimeout(function () {
          var nodes = pending; pending = []; timer = 0;
          nodes.forEach(function (n) { if (n.isConnected) adapt(n); });
        }, 200);
      }
    }).observe(document.body, { childList: true, subtree: true });
    // Gridbox changes classes when a lazy section background appears ("lazy-load-image"), when the
    // header becomes sticky, on sliders… — such an element is adapted again with its content
    var changed = [], ctimer = 0;
    new MutationObserver(function (records) {
      if (!done || current() === 'light') return;
      records.forEach(function (r) {
        var t = r.target;
        if (t.nodeType === 1 && t !== root && t !== document.body && !t.classList.contains('mertools-dt')
            && !t.classList.contains('mertools-dt-li') && changed.indexOf(t) < 0) changed.push(t);
      });
      if (changed.length && !ctimer) {
        ctimer = setTimeout(function () {
          var list = changed; changed = []; ctimer = 0;
          // only the outermost changed elements: their content is redone with them
          list.filter(function (el) {
            return !list.some(function (o) { return o !== el && o.contains(el); });
          }).forEach(readapt);
        }, 120);
      }
    }).observe(document.body, { attributes: true, attributeFilter: ['class'], subtree: true });
    // stylesheets added or finishing later change the colours of what is already adapted
    new MutationObserver(function (records) {
      records.forEach(function (r) {
        r.addedNodes.forEach(function (n) {
          if (n.nodeName === 'STYLE' || (n.nodeName === 'LINK' && /stylesheet/i.test(n.rel || ''))) refreshSoon();
        });
      });
    }).observe(document.head, { childList: true });
    document.addEventListener('load', function (e) {
      var t = e.target;
      if (t && t.nodeName === 'LINK' && /stylesheet/i.test(t.rel || '')) refreshSoon();
    }, true);
  }

  // ------------------------------------------------------------------ logo for dark mode

  /** Swaps the logo image to the dark-mode logo and back. Gridbox lazy loading writes the address
   *  from data-gridbox-lazyload-src later, so that attribute is swapped too. */
  function logos() {
    if (!cfg.logo || !cfg.logoSel) return;
    var dark = current() === 'dark', list;
    try { list = document.querySelectorAll(cfg.logoSel); } catch (e) { return; }
    [].forEach.call(list, function (el) {
      var imgs = el.tagName === 'IMG' ? [el] : [].slice.call(el.querySelectorAll('img'));
      imgs.forEach(function (img) {
        if (dark) {
          if (!img.hasAttribute('data-mt-logo')) {
            img.setAttribute('data-mt-logo', JSON.stringify({ src: img.getAttribute('src'), srcset: img.getAttribute('srcset'),
              lazy: img.getAttribute('data-gridbox-lazyload-src') }));
          }
          if (img.hasAttribute('data-gridbox-lazyload-src')) img.setAttribute('data-gridbox-lazyload-src', cfg.logo);
          img.removeAttribute('srcset');
          img.setAttribute('src', cfg.logo);
        } else if (img.hasAttribute('data-mt-logo')) {
          var o = {};
          try { o = JSON.parse(img.getAttribute('data-mt-logo')) || {}; } catch (e) { o = {}; }
          if (o.lazy !== null && o.lazy !== undefined) img.setAttribute('data-gridbox-lazyload-src', o.lazy);
          if (o.srcset) img.setAttribute('srcset', o.srcset);
          // the lazy loader may have replaced the placeholder meanwhile: restore the real image
          img.setAttribute('src', o.lazy || o.src || img.getAttribute('src'));
          img.removeAttribute('data-mt-logo');
        }
      });
    });
  }

  function apply(theme) {
    root.setAttribute('data-mertools-theme', THEMES.indexOf(theme) > -1 ? theme : 'light');
    logos();
    adaptAll();
    update();
  }

  // ------------------------------------------------------------------ toggle button

  var btn = null;

  /** In the menu the button takes the colour of the menu links (read again after a theme switch). */
  function matchMenu() {
    if (!btn || !li || !li.parentNode) return;
    var a = li.parentNode.querySelector('li:not(.mertools-dt-li) > a, li:not(.mertools-dt-li) a');
    if (a) btn.style.setProperty('--mt-dt-color', getComputedStyle(a).color);
  }

  function update() {
    if (!btn) return;
    btn.style.removeProperty('--mt-dt-color');
    matchMenu();
    // the icon and the label say what a click does: the next theme of the cycle
    var nx = next();
    btn.innerHTML = ICONS[nx] || MOON;
    btn.setAttribute('data-next', nx);
    btn.setAttribute('aria-pressed', current() === 'light' ? 'false' : 'true');
    var label = { dark: cfg.toDark || 'Switch to dark mode', sepia: cfg.toSepia || 'Switch to sepia mode',
      light: cfg.toLight || 'Switch to light mode' }[nx];
    btn.setAttribute('aria-label', label);
    btn.setAttribute('title', label);
  }

  function next() {
    return THEMES[(THEMES.indexOf(current()) + 1) % THEMES.length];
  }

  var SUN = '<svg class="mertools-dt-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4.2"></circle><path d="M12 2.5v2.4M12 19.1v2.4M4.2 4.2l1.7 1.7M18.1 18.1l1.7 1.7M2.5 12h2.4M19.1 12h2.4M4.2 19.8l1.7-1.7M18.1 5.9l1.7-1.7"></path></svg>';
  var MOON = '<svg class="mertools-dt-moon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.5 14.3a8.5 8.5 0 0 1-10.8-10.8 0.7 0.7 0 0 0-0.9-0.9 9.8 9.8 0 1 0 12.6 12.6 0.7 0.7 0 0 0-0.9-0.9z"></path></svg>';
  // an open book: sepia, the reading theme
  var BOOK = '<svg class="mertools-dt-book" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 5.5c2.8-1.2 6.2-1.1 9.5 1v13c-3.3-2.1-6.7-2.2-9.5-1z"></path><path d="M21.5 5.5c-2.8-1.2-6.2-1.1-9.5 1v13c3.3-2.1 6.7-2.2 9.5-1z"></path></svg>';
  var ICONS = { dark: MOON, sepia: BOOK, light: SUN };

  /** Is the element really on screen (inside the visible part of the page, not hidden)? */
  function onScreen(el) {
    if (!el || !el.isConnected) return false;
    var r = el.getBoundingClientRect();
    if (r.width < 4 || r.height < 4) return false;
    var vv = window.visualViewport, vw = vv ? vv.width : window.innerWidth, left = vv ? vv.offsetLeft : 0;
    if (r.right > left + vw + 1 || r.left < left - 1) return false;
    for (var e = el; e && e !== document.body; e = e.parentElement) {
      var cs = getComputedStyle(e);
      if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) < 0.05) return false;
    }
    return true;
  }

  function header() {
    var host = null;
    try { host = document.querySelector(cfg.selector || 'header.header'); } catch (e) { host = null; }
    return host || document.querySelector('header');
  }

  function navList(host) {
    // the Gridbox header lays its items out on a grid, so a bare button floats; the reliable spot is
    // the menu list itself. Query each selector in turn (a selector list returns the first in DOM
    // order, which would be the menu wrapper, not the <ul>).
    var sels = ['ul.mod-menu', '.ba-item-main-menu ul', '.ba-menu-wrapper ul', 'nav ul', 'ul.nav'];
    for (var i = 0; i < sels.length; i++) {
      var ul = host.querySelector(sels[i]);
      if (ul) return ul;
    }
    return null;
  }

  function hamburger(host) {
    var sels = ['.open-menu', '.ba-hamburger-menu .open-menu', '[class*="hamburger"] > i', '.navbar-toggler'];
    for (var i = 0; i < sels.length; i++) {
      var h = host.querySelector(sels[i]);
      if (h && onScreen(h)) return h;
    }
    return null;
  }

  var li = null;

  function detach() {
    btn.classList.remove('mertools-dt-float', 'mertools-dt-burger');
    ['right', 'bottom', 'top', 'left', 'position'].forEach(function (k) { btn.style.removeProperty(k); });
    if (li && li.parentNode) li.parentNode.removeChild(li);
    if (btn.parentNode) btn.parentNode.removeChild(btn);
  }

  function placeFloat() {
    detach();
    btn.classList.add('mertools-dt-float');
    document.body.appendChild(btn);
    keepFloatVisible();
  }

  /** A fixed element is placed against the layout viewport; on a phone whose page is wider than the
   *  screen that is partly off-screen, so the offsets follow the visible part (visualViewport). */
  function keepFloatVisible() {
    if (!btn || !btn.classList.contains('mertools-dt-float')) return;
    var vv = window.visualViewport;
    if (!vv) return;
    var right = window.innerWidth - (vv.offsetLeft + vv.width) + 18;
    var bottom = window.innerHeight - (vv.offsetTop + vv.height) + 18;
    btn.style.setProperty('right', Math.max(right, 8) + 'px');
    btn.style.setProperty('bottom', Math.max(bottom, 8) + 'px');
  }

  function place() {
    if (!btn) return;
    var host = header();
    if (cfg.place === 'float' || !host) {
      placeFloat();
      return;
    }
    // 1. in the menu (desktop)
    var ul = navList(host);
    if (ul) {
      detach();
      li = li || document.createElement('li');
      li.className = 'nav-item mertools-dt-li';
      li.appendChild(btn);
      if (cfg.place === 'start') ul.insertBefore(li, ul.firstChild); else ul.appendChild(li);
      if (onScreen(btn)) { matchMenu(); return; }
    }
    // 2. beside the hamburger (phones: the menu is closed, off-screen)
    var burger = hamburger(host);
    if (burger) {
      detach();
      btn.classList.add('mertools-dt-burger');
      burger.parentNode.insertBefore(btn, burger);
      // just left of the hamburger, centred on it: measured on screen, so it works whatever the
      // containing block of the header column is
      btn.style.position = 'absolute';
      btn.style.left = '0px';
      btn.style.top = '0px';
      // the icon itself: the hamburger wrapper is often wider than its icon
      var icon = burger.querySelector('i, svg, img, span') || burger;
      var r0 = btn.getBoundingClientRect(), rb = icon.getBoundingClientRect();
      if (rb.width < 4) rb = burger.getBoundingClientRect();
      btn.style.left = Math.round(rb.left - r0.width - 12 - r0.left) + 'px';
      btn.style.top = Math.round(rb.top + (rb.height - r0.height) / 2 - r0.top) + 'px';
      if (onScreen(btn)) return;
    }
    // 3. floating in the visible part of the screen
    placeFloat();
  }

  function build() {
    if (btn || cfg.toggle === false) return;
    btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mertools-dt';
    btn.innerHTML = MOON;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var nx = next();
      apply(nx);
      store(nx);
    });
    place();
    update();

    var t = 0;
    function replace() {
      clearTimeout(t);
      t = setTimeout(place, 250);
    }
    window.addEventListener('resize', replace);
    window.addEventListener('orientationchange', replace);
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', keepFloatVisible);
      window.visualViewport.addEventListener('scroll', keepFloatVisible);
    }
    // Gridbox lays the header out after its own scripts run; check the spot once more
    window.addEventListener('load', function () { setTimeout(place, 300); });
  }

  // follow the system when the visitor made no explicit choice and the default is "auto"
  if (cfg.def === 'auto' && window.matchMedia) {
    try {
      matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        var s = stored();
        if (s !== 'dark' && s !== 'light') apply(e.matches ? 'dark' : 'light');
      });
    } catch (e) { /* older browsers */ }
  }

  function start() {
    build();
    logos();
    adaptAll();
    update();
    watch();
    // late styles (stylesheets loaded after this script, lazy sections) — everything once more
    window.addEventListener('load', function () { refreshSoon(400); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
