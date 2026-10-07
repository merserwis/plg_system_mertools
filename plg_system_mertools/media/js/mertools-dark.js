/**
 * MerTools for Gridbox — dark mode. The theme itself is set very early by a small inline script in
 * the head (no flash); this script
 *  - builds the toggle button and keeps it where a visitor can see it (the menu on desktop, beside
 *    the hamburger on phones, or floating in the visible part of the screen),
 *  - switches light ↔ dark on click, offers the dark palettes as dots on hover (sepia, midnight…),
 *    remembers both choices and follows the system when "auto",
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

  /** The theme shown now: "dark" or "light". */
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

  /** The dark palette in use (the one chosen with the dots, or the default) as parsed colours. */
  function palette() {
    var theme = paletteKey();
    if (pals[theme]) return pals[theme];
    var p = (cfg.pals && cfg.pals[theme]) || cfg.pal || {};
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

  // ------------------------------------------------------------------ adaptation engine
  //
  // Speed: every pass reads first and writes afterwards (a write between two reads forces the
  // browser to recompute styles each time), keeps what it read per element for the whole pass, and
  // works out the colour behind a text once per element, from its parent (linear, not per text).
  // The visible part of the page is done at once; the rest in small slices while the browser is idle.

  var P = null; // the caches of the running pass

  function newPass() {
    P = { cs: new Map(), ps: new Map(), bd: new Map(), media: new Map() };
  }

  function css(el) {
    var c = P.cs.get(el);
    if (!c) { c = getComputedStyle(el); P.cs.set(el, c); }
    return c;
  }

  /** ::before / ::after of an element that have content: [[style, 0|1], …] (read once per pass). */
  function pseudos(el) {
    var v = P.ps.get(el);
    if (v === undefined) {
      v = [];
      for (var i = 0; i < 2; i++) {
        var s = getComputedStyle(el, i ? '::after' : '::before'), c = s.content;
        if (c && c !== 'none' && c !== 'normal') v.push([s, i]);
      }
      P.ps.set(el, v);
    }
    return v;
  }

  /** Text written by CSS (content: "…" on ::before / ::after), e.g. a cookie banner's message. */
  function pseudoText(el) {
    var v = pseudos(el);
    for (var i = 0; i < v.length; i++) {
      if (/^["'].*[A-Za-z0-9À-ɏЀ-ӿ].*["']$/.test(v[i][0].content)) return v[i][0];
    }
    return null;
  }

  /** Does a photo, video or image layer lie under the content of this element? Gridbox puts section
   *  images in a separate absolutely positioned layer (.parallax-wrapper, slideshows, video), not in
   *  the background of the text's ancestors. */
  function mediaLayer(e) {
    if (P.media.has(e)) return P.media.get(e);
    var found = false, n = 0, c, box = null, area = 0;
    for (c = e.firstElementChild; c && n < 12 && !found; c = c.nextElementSibling, n++) {
      var cs = css(c);
      if (cs.position !== 'absolute' && cs.position !== 'fixed' && c.tagName !== 'VIDEO') continue;
      if (!box) { box = e.getBoundingClientRect(); area = box.width * box.height; if (!area) break; }
      var r = c.getBoundingClientRect();
      if (r.width * r.height < area * 0.5) continue;
      found = c.tagName === 'IMG' || c.tagName === 'VIDEO' || c.tagName === 'PICTURE' || c.tagName === 'IFRAME'
        || /url\(/.test(cs.backgroundImage)
        || /video-background|parallax|slideshow/.test(typeof c.className === 'string' ? c.className : '')
        || !!c.querySelector('img, video, picture, iframe, [style*="url("]')
        || [].some.call(c.querySelectorAll('*'), function (d, i) { return i < 20 && /url\(/.test(css(d).backgroundImage); });
    }
    P.media.set(e, found);
    return found;
  }

  /** The colour behind the content of an element (after the fixes), or null over an image. */
  function bd(e) {
    if (!e || e === root) return palette().bg;
    if (P.bd.has(e)) return P.bd.get(e);
    var res, cs = css(e), bi = cs.backgroundImage;
    if (e.hasAttribute('data-mt-bgi')) bi = e.style.getPropertyValue('--mt-bgi') || bi;
    if (bi !== 'none' && (/url\(/.test(bi) || !/gradient/.test(bi))) {
      res = null;
    } else if (e !== document.body && mediaLayer(e)) {
      res = null;
    } else {
      // a gradient lies above the background colour; counted as its average colour
      var stack = [], g = bi !== 'none' ? gradientAverage(bi) : null, k = -1, i;
      var c = parse(e.hasAttribute('data-mt-bg') ? e.style.getPropertyValue('--mt-bg') : cs.backgroundColor);
      if (g) stack.push(g);
      if (c && c[3] > 0) stack.push(c);
      for (i = 0; i < stack.length; i++) if (stack[i][3] >= 1) { k = i; break; }
      if (k >= 0) {
        res = stack[k];
        for (i = k - 1; i >= 0; i--) res = over(stack[i], res);
      } else {
        res = e === document.body ? palette().bg : bd(e.parentElement);
        if (res) for (i = stack.length - 1; i >= 0; i--) res = over(stack[i], res);
      }
    }
    P.bd.set(e, res);
    return res;
  }

  /** The colour behind an element's own text. */
  function backdrop(el) {
    return bd(el);
  }

  var COLOR_RE = /(?:rgba?|color|oklch|oklab|lch|lab|hsla?|hwb)\([^()]*\)/g;

  function gradientAverage(bi) {
    var list = (bi.match(COLOR_RE) || []).map(parse).filter(Boolean);
    if (!list.length) return null;
    var s = [0, 0, 0, 0];
    list.forEach(function (c) { s[0] += c[0]; s[1] += c[1]; s[2] += c[2]; s[3] += c[3]; });
    return [s[0] / list.length, s[1] / list.length, s[2] / list.length, s[3] / list.length];
  }

  /** The palette's colour for a light background colour (darker, in the tone of the palette),
   *  keeping its tone; null when it should stay. */
  function darker(c, wide) {
    var l = lum(c);
    if (c[3] < 0.35 || l < 0.5 || (sat(c) > 0.55 && l < 0.8)) return null;
    var p = palette(), nb = mix(c, wide ? p.bg : p.surface, wide ? 0.96 : 0.9);
    nb[3] = c[3];
    return nb;
  }

  function hasOwnText(el) {
    for (var n = el.firstChild; n; n = n.nextSibling) {
      if (n.nodeType === 3 && n.nodeValue.trim() !== '') return true;
    }
    return false;
  }

  function ownText(el) {
    if (FORM[el.tagName] || hasOwnText(el)) return true;
    // font icons (an <i> or <span> with an icon class and no text)
    if ((el.tagName === 'I' || el.tagName === 'SPAN') && !el.firstElementChild && /icon|zmdi|fa-|flaticon|ba-icon/.test(el.className || '')) return true;
    return !!pseudoText(el);
  }

  /** A button or a link styled as one (not a badge, not a large coloured block). */
  function isButton(el, r) {
    var tag = el.tagName, cls = typeof el.className === 'string' ? el.className : '';
    var looks = tag === 'BUTTON' || (tag === 'INPUT' && /^(submit|button|reset)$/i.test(el.type))
      || el.getAttribute('role') === 'button' || /(^|[\s_-])(btn|button)/i.test(cls) || tag === 'A';
    // a button has a label: icon-only circles (e.g. a round orange icon) keep their colour
    return looks && r.height >= 18 && r.height <= 110 && r.width <= Math.min(620, window.innerWidth * 0.9)
      && (el.textContent || el.value || '').trim().length >= 2;
  }

  // A decision is [element, {property: value}, [attributes]]; decisions are written after the reads.
  function write(list) {
    for (var i = 0; i < list.length; i++) {
      var el = list[i][0], props = list[i][1], attrs = list[i][2], k;
      for (k in props) el.style.setProperty(k, props[k]);
      for (k = 0; k < attrs.length; k++) el.setAttribute(attrs[k], '');
    }
  }

  /** Background decisions: bright buttons, light backgrounds, light colours of gradients. */
  function decideBackground(el, cs, out) {
    var bi = cs.backgroundImage;
    if (bi !== 'none' && (/url\(/.test(bi) || !/gradient/.test(bi))) return;
    var c = parse(cs.backgroundColor), rect = null, p;
    // bright brand-coloured buttons: kept, softened, or drawn like the other adapted buttons
    if (bi === 'none' && c && c[3] >= 0.6 && (cfg.vivid || 'outline') !== 'keep') {
      var l = lum(c);
      if (sat(c) > 0.45 && l > 0.06 && l < 0.8) {
        rect = el.getBoundingClientRect();
        if (isButton(el, rect)) {
          p = palette();
          if (cfg.vivid === 'soft') {
            var soft = mix(c, p.surface, 0.3);
            soft[3] = c[3];
            out.push([el, { '--mt-bg': rgb(soft) }, ['data-mt-bg']]);
          } else {
            out.push([el, { '--mt-bg': rgb(p.surface), '--mt-ring': rgb(c) }, ['data-mt-bg', 'data-mt-ring']]);
          }
          return;
        }
      }
    }
    var light = c && darker(c, false);
    var grad = bi !== 'none';
    if (!light && !grad) return;
    rect = rect || el.getBoundingClientRect();
    var wide = rect.width >= window.innerWidth * 0.9;
    if (light) out.push([el, { '--mt-bg': rgb(darker(c, wide)) }, ['data-mt-bg']]);
    if (grad) {
      var changed = false;
      var ng = bi.replace(COLOR_RE, function (tok) {
        var d = darker(parse(tok) || [0, 0, 0, 0], wide);
        if (!d) return tok;
        changed = true;
        return rgb(d);
      });
      if (changed) out.push([el, { '--mt-bgi': ng }, ['data-mt-bgi']]);
    }
  }

  var SIDES = ['Top', 'Right', 'Bottom', 'Left'], SIDE_KEYS = ['t', 'r', 'b', 'l'];

  function borderOf(s, prefix, attr, out, el) {
    var changed = false, vals = [], k;
    for (k = 0; k < 4; k++) {
      var w = parseFloat(s['border' + SIDES[k] + 'Width']) || 0, col = s['border' + SIDES[k] + 'Color'];
      var c = w > 0 ? parse(col) : null, d = c && c[3] > 0.35 ? darker(c, false) : null;
      if (d && w <= 3) d = palette().border.slice(0, 3).concat([c[3]]);
      vals.push(d ? rgb(d) : col);
      if (d) changed = true;
    }
    if (!changed) return;
    var props = {};
    for (k = 0; k < 4; k++) props['--mt-' + prefix + SIDE_KEYS[k]] = vals[k];
    out.push([el, props, [attr]]);
  }

  /** Light border colours of an element and of its ::before / ::after (e.g. the triangles between
   *  breadcrumb items, drawn with borders in the colour of the item): thin lines take the theme's
   *  border colour, wide borders (shapes) the same colour a background would get. */
  function decideBorders(el, cs, out) {
    if (cs.borderTopStyle !== 'none' || cs.borderRightStyle !== 'none' || cs.borderBottomStyle !== 'none' || cs.borderLeftStyle !== 'none') {
      borderOf(cs, 'bd', 'data-mt-bd', out, el);
    }
    var v = pseudos(el);
    for (var i = 0; i < v.length; i++) borderOf(v[i][0], v[i][1] ? 'ba' : 'bb', v[i][1] ? 'data-mt-bda' : 'data-mt-bdb', out, el);
  }

  /** Text decision: lighten or darken just enough for a comfortable contrast, keeping the hue. */
  function decideText(el, cs, out) {
    var ps = FORM[el.tagName] || hasOwnText(el) ? null : pseudoText(el);
    if (ps) cs = ps;
    var fg = parse(cs.color);
    if (!fg) return;
    var bg = backdrop(el), attrs = ps ? ['data-mt-fg', 'data-mt-fgp'] : ['data-mt-fg'];
    if (!bg) {
      // over a photo or video: the colours behind are unknown, so the text keeps its colour — unless
      // dark mode itself turned it dark: a Gridbox background variable used as text colour (white on
      // the photo in the light theme) becomes the dark background colour; it gets the light text colour
      var pm = palette();
      if (lum(pm.bg) < 0.2 && pm.bgs.some(function (o) { return near(o, fg); })) out.push([el, { '--mt-fg': rgb(pm.title) }, attrs]);
      return;
    }
    var size = parseFloat(cs.fontSize) || 16;
    var need = (size >= 24 || (size >= 18.6 && parseInt(cs.fontWeight, 10) >= 700)) ? 3 : 4.5;
    var shown = over(fg, bg), now = contrast(shown, bg);
    if (now >= need) return;
    // text on a vivid brand colour (buttons, badges) keeps the site's design — unless its colour
    // comes from the dark palette (a theme variable), i.e. dark mode itself changed it
    var lb = lum(bg);
    if (sat(bg) > 0.5 && lb > 0.06 && lb < 0.6 && !ownColour(fg)) return;
    // a fixed text always aims at 4.5:1, also when the large-text minimum of 3:1 would pass
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
    var props = { '--mt-fg': rgb(fix) };
    if (p.accent && contrast(p.accent, bg) >= 3 && el.closest('a')) props['--mt-fg-h'] = rgb(p.accent);
    out.push([el, props, attrs]);
  }

  function skip(el) {
    return SKIP[el.tagName.toUpperCase()] || (box && box.contains(el));
  }

  /** One slice of elements: all background and border reads, their writes, then all text reads
   *  (seeing the new backgrounds) and their writes. */
  function slice(list, from, to) {
    var out = [], i, el, marked = [];
    // fresh decisions for these elements: an earlier one may have been made against a background that
    // was fixed only afterwards. Old marks are removed first, all at once, then everything is read —
    // with transitions off, or a site's own transition would report the old colour for a moment.
    for (i = from; i < to; i++) {
      el = list[i];
      if (el.hasAttribute('data-mt-bg') || el.hasAttribute('data-mt-bgi') || el.hasAttribute('data-mt-fg')
          || el.hasAttribute('data-mt-bd') || el.hasAttribute('data-mt-bdb') || el.hasAttribute('data-mt-bda')) marked.push(el);
    }
    var quiet = marked.length && !root.classList.contains('mertools-dt-calc');
    if (quiet) calc(true, true);
    if (marked.length) { marked.forEach(unmark); P.bd = new Map(); }
    for (i = from; i < to; i++) {
      el = list[i];
      if (skip(el)) continue;
      var cs = css(el);
      decideBackground(el, cs, out);
      decideBorders(el, cs, out);
    }
    write(out);
    if (out.length) P.bd = new Map(); // backgrounds changed: colours behind texts are worked out again
    out = [];
    for (i = from; i < to; i++) {
      el = list[i];
      if (skip(el) || !ownText(el)) continue;
      decideText(el, css(el), out);
    }
    write(out);
    if (quiet) calc(false);
  }

  /** Transitions off while reading colours — only needed while the visitor's switch fades the colours
   *  (toggling a class on <html> makes the browser restyle the whole page, so not otherwise). */
  function calc(on, force) {
    if (on && !force && !root.classList.contains('mt-switching')) return;
    root.classList.toggle('mertools-dt-calc', on);
  }

  var job = 0, idle = window.requestIdleCallback || function (fn) { return setTimeout(function () { fn({ timeRemaining: function () { return 8; }, didTimeout: true }); }, 16); };

  /** Adapt elements: small sets at once; a whole page by the visible part first, then the rest in
   *  idle slices (a newer pass — theme or palette switched — stops the older one). */
  function adapt(scope, done) {
    if (cfg.adaptive === false || current() === 'light' || !document.body) { calc(false); return; }
    var list = scope === document.body ? document.body.getElementsByTagName('*')
      : [scope].concat([].slice.call(scope.getElementsByTagName('*')));
    list = [].slice.call(list);
    var whole = scope === document.body, n = list.length, i = 0;
    // only whole-page passes are numbered: a newer one stops an older one; small ones never do
    var my = whole ? ++job : job;
    newPass();
    if (n <= 60) {
      calc(true);
      slice(list, 0, n);
      calc(false);
      if (done) done();
      return;
    }
    if (whole) {
      // what is on screen now first (rendered and within the first screen and a half); hidden parts
      // (closed menus, other tabs) and everything below go to the idle slices
      var bottom = (window.innerHeight || 800) * 1.5, right = window.innerWidth || 1200, now = [], later = [], seen = new Set(), k, el, r;
      for (k = 0; k < n; k++) {
        el = list[k];
        r = el.getBoundingClientRect();
        if (!((r.width || r.height) && r.top < bottom && r.bottom > -50 && r.left < right && r.right > 0)) continue;
        // with all its ancestors: a background they paint must be fixed before this text is judged
        // (an ancestor can be zero-sized or off screen while its content is visible, e.g. a popover)
        for (var a = el; a && a !== document.body && !seen.has(a); a = a.parentElement) seen.add(a);
      }
      for (k = 0; k < n; k++) (seen.has(list[k]) ? now : later).push(list[k]);
      calc(true);
      slice(now, 0, now.length);
      calc(false);
      list = later;
      n = list.length;
    }
    function more(deadline) {
      if (my !== job || current() === 'light') return;
      calc(true);
      var t0 = Date.now();
      while (i < n && Date.now() - t0 < 8 && (deadline.didTimeout || deadline.timeRemaining() > 1)) {
        var to = Math.min(n, i + 30);
        slice(list, i, to);
        i = to;
      }
      calc(false);
      if (i < n) idle(more, { timeout: 300 });
      else if (done) done();
    }
    if (i < n) idle(more, { timeout: 300 }); else if (done) done();
  }

  var done = false, pending = [], timer = 0, refreshTimer = 0, sheets = 0;

  var ATTRS = ['data-mt-bg', 'data-mt-bgi', 'data-mt-fg', 'data-mt-fgp', 'data-mt-bd', 'data-mt-bdb', 'data-mt-bda', 'data-mt-ring'];
  var PROPS = ['--mt-bg', '--mt-bgi', '--mt-fg', '--mt-fg-h', '--mt-ring'];
  ['bd', 'bb', 'ba'].forEach(function (p) { SIDE_KEYS.forEach(function (k) { PROPS.push('--mt-' + p + k); }); });
  var MARKED = '[data-mt-bg],[data-mt-bgi],[data-mt-fg],[data-mt-bd],[data-mt-bdb],[data-mt-bda]';

  function unmark(el) {
    ATTRS.forEach(function (a) { el.removeAttribute(a); });
    PROPS.forEach(function (p) { el.style.removeProperty(p); });
  }

  /** The fixes for the current theme, computed from the site's own colours. Every switch to dark
   *  mode or to another palette recomputes them. */
  function adaptAll() {
    job++;
    if (current() === 'light') {
      root.removeAttribute('data-mt-on');
      return;
    }
    [].forEach.call(document.querySelectorAll(MARKED), unmark);
    done = true;
    sheets = document.styleSheets.length;
    // on before the pass: the text check has to see the backgrounds already fixed
    if (cfg.adaptive !== false) root.setAttribute('data-mt-on', '');
    adapt(document.body);
  }

  /** One element and its content again from the site's own colours. */
  function readapt(el) {
    if (el.isConnected) adapt(el);
  }

  /** Everything again from the site's own colours: styles that arrived later (a stylesheet loaded after
   *  this script, lazy sections) may have changed them. */
  function refresh() {
    if (!done || current() === 'light') return;
    sheets = document.styleSheets.length;
    calc(true, true);
    [].forEach.call(document.querySelectorAll(MARKED), unmark);
    adapt(document.body, update);
  }

  function refreshSoon(delay) {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(refresh, delay || 300);
  }

  /** Colours fade only while the visitor switches (not on every page, not while adapting). */
  function fade() {
    if (!cfg.fade) return;
    root.classList.add('mt-switching');
    clearTimeout(fade.t);
    fade.t = setTimeout(function () { root.classList.remove('mt-switching'); }, 450);
  }

  function watch() {
    if (cfg.adaptive === false || !window.MutationObserver) return;
    new MutationObserver(function (records) {
      if (!done || current() === 'light') return;
      records.forEach(function (r) {
        r.addedNodes.forEach(function (n) { if (n.nodeType === 1 && !(box && box.contains(n))) pending.push(n); });
      });
      if (pending.length && !timer) {
        timer = setTimeout(function () {
          var nodes = pending; pending = []; timer = 0;
          nodes.forEach(function (n) { if (n.isConnected) adapt(n); });
        }, 200);
      }
    }).observe(document.body, { childList: true, subtree: true });
    // Gridbox changes classes when a lazy section background appears ("lazy-load-image"), when the
    // header becomes sticky, on sliders… — such an element is adapted again with its content, in
    // idle time and only the outermost of the elements changed meanwhile
    var changed = [];
    function flush() {
      var list = changed; changed = [];
      list.filter(function (el) {
        return !list.some(function (o) { return o !== el && o.contains(el); });
      }).forEach(readapt);
    }
    new MutationObserver(function (records) {
      if (!done || current() === 'light') return;
      var was = changed.length;
      records.forEach(function (r) {
        var t = r.target;
        // sliders (Swiper) write the same class again and again: only a real change counts
        if (r.oldValue === t.getAttribute('class')) return;
        if (t.nodeType === 1 && t !== root && t !== document.body && !(box && box.contains(t)) && changed.indexOf(t) < 0) changed.push(t);
      });
      if (!was && changed.length) setTimeout(function () { idle(flush, { timeout: 400 }); }, 120);
    }).observe(document.body, { attributes: true, attributeFilter: ['class'], attributeOldValue: true, subtree: true });
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

  var frame = 0;

  /** A visitor's switch: the new theme is painted at once, the colour fixes follow in the next frame
   *  (hidden by the fade), so the click responds without waiting for the page to be measured. */
  function apply(theme) {
    if (!root.classList.contains('mt-switching')) calc(true, true);
    root.setAttribute('data-mertools-theme', THEMES.indexOf(theme) > -1 ? theme : 'light');
    logos();
    update(true);
    job++;
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(function () {
      frame = 0;
      adaptAll();
      if (!root.classList.contains('mt-switching')) calc(false);
      update();
    });
  }

  // ------------------------------------------------------------------ toggle button and palette dots

  var box = null, btn = null, dots = null;

  /** In the menu the button takes the colour of the menu links (read again after a theme switch). */
  function matchMenu() {
    if (!btn || !li || !li.parentNode) return;
    var a = li.parentNode.querySelector('li:not(.mertools-dt-li) > a, li:not(.mertools-dt-li) a');
    if (!a) return;
    // a colour fixed by this script counts as set (the site's own transition reports the old one for a moment)
    var fixed = a.hasAttribute('data-mt-fg') && root.hasAttribute('data-mt-on') && a.style.getPropertyValue('--mt-fg');
    btn.style.setProperty('--mt-dt-color', fixed || getComputedStyle(a).color);
  }

  function update(noRead) {
    if (!btn) return;
    // reading the menu colour right after a theme switch would make the browser restyle the page at
    // once: on a switch it is read in the next frame
    if (!noRead) {
      btn.style.removeProperty('--mt-dt-color');
      matchMenu();
    }
    // the icon and the label say what a click does
    var nx = next();
    btn.innerHTML = nx === 'dark' ? MOON : SUN;
    btn.setAttribute('data-next', nx);
    btn.setAttribute('aria-pressed', current() === 'light' ? 'false' : 'true');
    var label = nx === 'dark' ? (cfg.toDark || 'Switch to dark mode') : (cfg.toLight || 'Switch to light mode');
    btn.setAttribute('aria-label', label);
    btn.setAttribute('title', label);
    if (dots) {
      var key = paletteKey(), on = current() === 'dark';
      [].forEach.call(dots.querySelectorAll('.mertools-dt-dot'), function (d) {
        d.setAttribute('aria-pressed', on && d.getAttribute('data-palette') === key ? 'true' : 'false');
      });
    }
  }

  function next() {
    return THEMES[(THEMES.indexOf(current()) + 1) % THEMES.length];
  }

  /** The palette the visitor chose with the dots (or the default one of the settings). */
  function paletteKey() {
    var k = root.getAttribute('data-mertools-palette');
    return k && cfg.pals && cfg.pals[k] ? k : (cfg.palette || 'slate');
  }

  /** A dot chosen: that palette, in dark mode, remembered. */
  function choosePalette(key) {
    fade();
    var quiet = !root.classList.contains('mt-switching');
    if (quiet) calc(true, true);
    if (key === (cfg.palette || 'slate')) root.removeAttribute('data-mertools-palette');
    else root.setAttribute('data-mertools-palette', key);
    try { localStorage.setItem(cfg.pkey || 'mertools-palette', key); } catch (e) { /* private mode */ }
    if (current() !== 'dark') {
      apply('dark');
      store('dark');
    } else {
      adaptAll();
      if (quiet) calc(false);
      update();
    }
  }

  var SUN = '<svg class="mertools-dt-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4.2"></circle><path d="M12 2.5v2.4M12 19.1v2.4M4.2 4.2l1.7 1.7M18.1 18.1l1.7 1.7M2.5 12h2.4M19.1 12h2.4M4.2 19.8l1.7-1.7M18.1 5.9l1.7-1.7"></path></svg>';
  var MOON = '<svg class="mertools-dt-moon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.5 14.3a8.5 8.5 0 0 1-10.8-10.8 0.7 0.7 0 0 0-0.9-0.9 9.8 9.8 0 1 0 12.6 12.6 0.7 0.7 0 0 0-0.9-0.9z"></path></svg>';

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

  /** Which way the dots slide out: into the page, away from the edge the button sits at. */
  function direction(dir) {
    box.setAttribute('data-dir', dir);
  }

  function detach() {
    box.classList.remove('mertools-dt-float', 'mertools-dt-burger');
    ['right', 'bottom', 'top', 'left', 'position'].forEach(function (k) { box.style.removeProperty(k); });
    if (li && li.parentNode) li.parentNode.removeChild(li);
    if (box.parentNode) box.parentNode.removeChild(box);
  }

  function placeFloat() {
    detach();
    box.classList.add('mertools-dt-float');
    document.body.appendChild(box);
    keepFloatVisible();
  }

  /** A fixed element is placed against the layout viewport; on a phone whose page is wider than the
   *  screen that is partly off-screen, so the offsets follow the visible part (visualViewport). */
  var FLOATS = { 'float': 1, 'float-left': 1, 'float-a11y-above': 1, 'float-a11y-beside': 1 };

  // phones can have their own position (cfg.mplace) up to a width (cfg.mbp); "same" keeps the desktop one
  var phoneMq = cfg.mplace && cfg.mplace !== 'same' && window.matchMedia ? matchMedia('(max-width: ' + (cfg.mbp || 768) + 'px)') : null;

  function phone() {
    return !!(phoneMq && phoneMq.matches);
  }

  /** The position in effect now: the one for phones on a narrow screen, else the desktop one. */
  function mode() {
    return phone() ? cfg.mplace : (cfg.place || 'auto');
  }

  /** The element given for phones (the first one shown), or null. */
  function customTarget() {
    var list;
    try { list = cfg.msel ? document.querySelectorAll(cfg.msel) : []; } catch (e) { return null; }
    for (var i = 0; i < list.length; i++) if (!box.contains(list[i]) && onScreen(list[i])) return list[i];
    return null;
  }

  /** The accessibility button of the site (e.g. the panel on the left), when it is fully on screen. */
  function a11yButton() {
    var el = null;
    try { el = cfg.a11y ? document.querySelector(cfg.a11y) : null; } catch (e) { el = null; }
    if (!el || !onScreen(el)) return null;
    var r = el.getBoundingClientRect(), vv = window.visualViewport;
    var top = vv ? vv.offsetTop : 0, h = vv ? vv.height : window.innerHeight;
    return r.top >= top - 1 && r.bottom <= top + h + 1 ? el : null;
  }

  function keepFloatVisible() {
    if (!box || !box.classList.contains('mertools-dt-float')) return;
    var vv = window.visualViewport || { offsetLeft: 0, offsetTop: 0, width: window.innerWidth, height: window.innerHeight };
    var ph = phone(), m = mode(), gap = 20;
    var fx = ph ? cfg.mfx : cfg.fx, fy = ph ? cfg.mfy : cfg.fy;
    fx = fx >= 0 ? fx : 18;
    fy = fy >= 0 ? fy : 18;
    var place = FLOATS[m] ? m : 'float';
    // the visible part of the screen inside the layout viewport that fixed elements are placed against
    var visRight = window.innerWidth - (vv.offsetLeft + vv.width), visBottom = window.innerHeight - (vv.offsetTop + vv.height);
    var left = null, right = null, bottom = visBottom + fy;
    if (place === 'float') {
      right = visRight + fx;
    } else {
      left = vv.offsetLeft + fx;
      var a = a11yButton(), bw = btn.offsetWidth, bh = btn.offsetHeight, r = a ? a.getBoundingClientRect() : null;
      // the bottom left corner taken by the accessibility button: go above it instead of covering it
      if (a && place === 'float-left') {
        var top = window.innerHeight - bottom - bh;
        if (!(left + bw <= r.left || left >= r.right || top + bh <= r.top || top >= r.bottom)) place = 'float-a11y-above';
      }
      if (a && place !== 'float-left') {
        if (place === 'float-a11y-above') {
          left = r.left + (r.width - bw) / 2;
          bottom = window.innerHeight - r.top + gap;
        } else {
          left = r.right + gap;
          bottom = window.innerHeight - r.bottom + (r.height - bh) / 2;
        }
      }
    }
    direction(right !== null ? 'left' : 'right');
    box.style.setProperty('right', right !== null ? Math.max(right, 8) + 'px' : 'auto');
    box.style.setProperty('left', left !== null ? Math.max(left, 4) + 'px' : 'auto');
    box.style.setProperty('bottom', Math.max(bottom, 8) + 'px');
  }

  /** In an element of the page chosen for phones (inside at the start or end, before or after it). */
  function placeCustom() {
    var t = customTarget();
    if (!t) return false;
    detach();
    var where = cfg.mins || 'append';
    if (where === 'prepend') t.insertBefore(box, t.firstChild);
    else if (where === 'before') t.parentNode.insertBefore(box, t);
    else if (where === 'after') t.parentNode.insertBefore(box, t.nextSibling);
    else t.appendChild(box);
    if (!onScreen(btn)) return false;
    // the dots slide out towards the middle of the screen
    var r = btn.getBoundingClientRect();
    direction(r.left + r.width / 2 > window.innerWidth / 2 ? 'left' : 'right');
    return true;
  }

  function place() {
    if (!box) return;
    var m = mode();
    if (m === 'hidden') { detach(); return; }
    if (m === 'selector') {
      if (!placeCustom()) placeFloat();
      return;
    }
    var host = header();
    if (FLOATS[m] || !host) {
      placeFloat();
      return;
    }
    // 1. in the menu (desktop)
    var ul = m !== 'burger' && navList(host);
    if (ul) {
      detach();
      li = li || document.createElement('li');
      li.className = 'nav-item mertools-dt-li';
      li.appendChild(box);
      if (m === 'start') ul.insertBefore(li, ul.firstChild); else ul.appendChild(li);
      if (onScreen(btn)) { direction('down'); matchMenu(); return; }
    }
    // 2. beside the hamburger (phones: the menu is closed, off-screen)
    var burger = hamburger(host);
    if (burger) {
      detach();
      box.classList.add('mertools-dt-burger');
      burger.parentNode.insertBefore(box, burger);
      // just left of the hamburger, centred on it: measured on screen, so it works whatever the
      // containing block of the header column is
      box.style.position = 'absolute';
      box.style.left = '0px';
      box.style.top = '0px';
      // the icon itself: the hamburger wrapper is often wider than its icon
      var icon = burger.querySelector('i, svg, img, span') || burger;
      var r0 = btn.getBoundingClientRect(), rb = icon.getBoundingClientRect();
      if (rb.width < 4) rb = burger.getBoundingClientRect();
      box.style.left = Math.round(rb.left - r0.width - 12 - r0.left) + 'px';
      box.style.top = Math.round(rb.top + (rb.height - r0.height) / 2 - r0.top) + 'px';
      if (onScreen(btn)) { direction('down'); return; }
    }
    // 3. floating in the visible part of the screen
    placeFloat();
  }

  /** The palette dots: one per palette offered in the settings, in the colours of that palette. */
  function buildDots() {
    var keys = Object.keys(cfg.pals || {});
    if (!cfg.picker || keys.length < 2) return;
    dots = document.createElement('span');
    dots.className = 'mertools-dt-dots';
    dots.setAttribute('role', 'group');
    dots.setAttribute('aria-label', cfg.choose || 'Colours of dark mode');
    var row = document.createElement('span');
    row.className = 'mertools-dt-dots-in';
    keys.forEach(function (k) {
      var p = cfg.pals[k], d = document.createElement('button'), name = (cfg.names && cfg.names[k]) || k;
      d.type = 'button';
      d.className = 'mertools-dt-dot';
      d.setAttribute('data-palette', k);
      d.setAttribute('aria-label', name);
      d.setAttribute('title', name);
      d.style.setProperty('--dot-bg', p.bg);
      d.style.setProperty('--dot-fg', p.title);
      d.style.setProperty('--dot-sf', p.surface);
      d.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        choosePalette(k);
      });
      row.appendChild(d);
    });
    dots.appendChild(row);
    box.appendChild(dots);
    // touch screens have no hover: a long press on the button opens the dots, a tap elsewhere closes them
    var timer = 0, longPress = false;
    btn.addEventListener('touchstart', function () {
      longPress = false;
      timer = setTimeout(function () { longPress = true; box.classList.add('is-open'); }, 450);
    }, { passive: true });
    ['touchend', 'touchmove', 'touchcancel'].forEach(function (ev) {
      btn.addEventListener(ev, function () { clearTimeout(timer); }, { passive: true });
    });
    btn.addEventListener('click', function (e) {
      if (longPress) { e.preventDefault(); e.stopImmediatePropagation(); longPress = false; }
    }, true);
    btn.addEventListener('contextmenu', function (e) { if (box.classList.contains('is-open')) e.preventDefault(); });
    document.addEventListener('click', function (e) { if (!box.contains(e.target)) box.classList.remove('is-open'); });
    box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { box.classList.remove('is-open'); btn.focus(); } });
  }

  function build() {
    if (btn || cfg.toggle === false) return;
    box = document.createElement('span');
    box.className = 'mertools-dt-box';
    btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mertools-dt';
    btn.innerHTML = MOON;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var nx = next();
      fade();
      apply(nx);
      store(nx);
    });
    box.appendChild(btn);
    buildDots();
    place();
    update();

    // placed again only when the width changes: phones fire "resize" whenever the address bar hides
    // or shows while scrolling, and moving the button then would only cost a layout
    var t = 0, width = window.innerWidth;
    function replace() {
      clearTimeout(t);
      t = setTimeout(function () {
        if (window.innerWidth === width) { keepFloatVisible(); return; }
        width = window.innerWidth;
        place();
      }, 250);
    }
    window.addEventListener('resize', replace);
    window.addEventListener('orientationchange', replace);
    if (phoneMq) {
      try { phoneMq.addEventListener('change', function () { width = window.innerWidth; place(); }); } catch (e) { /* older browsers: resize */ }
    }
    if (window.visualViewport) {
      // pinch zoom moves the visible part: at most once per frame
      var queued = 0;
      var follow = function () {
        if (queued || !box.classList.contains('mertools-dt-float')) return;
        queued = requestAnimationFrame(function () { queued = 0; keepFloatVisible(); });
      };
      window.visualViewport.addEventListener('resize', follow);
      window.visualViewport.addEventListener('scroll', follow);
    }
    // Gridbox lays the header out after its own scripts run, and an accessibility panel may appear
    // late: check the spot once more, and again a little later
    window.addEventListener('load', function () { setTimeout(place, 300); setTimeout(keepFloatVisible, 1500); });
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
    // stylesheets that arrived after the first pass (lazy-loaded CSS) — only then everything once more
    window.addEventListener('load', function () {
      setTimeout(function () { if (done && document.styleSheets.length !== sheets) refreshSoon(0); }, 400);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
