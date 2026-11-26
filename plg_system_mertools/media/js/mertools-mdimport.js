/**
 * MerTools — importing a Markdown document into the Gridbox editor: of a blog post (a button left of the
 * "Advanced Editor" switch), of a page and of an item of an app with fields, such as a product (an icon in the
 * editor's left bar). It asks for a .md file and puts it in as the modules Gridbox has for it — a Headline for
 * every heading (its level is the tag of the module) and a Text for everything between two headings, with the
 * Markdown made into formatting: bold, italics, strike, code, links, images, lists, quotes, code blocks,
 * tables, rules.
 *
 * A section of "Tytuł: / Adres (slug): / Opis meta: / Słowa kluczowe:" lines at the top (or a front matter)
 * fills the fields Title, Alias, Intro text and Meta keywords, else the first # heading is the title. In a post
 * no heading is of the first level (Gridbox shows its title as the h1); in a page the title's # heading stays,
 * an h1 (an h2 when the page has an h1). A new post or page (nothing in it but Gridbox's "click here and start
 * typing") takes the fields over what is in them, one with content only into empty fields; the modules go to
 * the end of the column the post's texts are in, in a page with content into a new section at its end.
 *
 * An item with fields (a product): a "Label: value" line at the top fills the field of that label in the item's
 * form (SKU, Cena, Producent as [Sonel](/sonel), any field of the app), a heading named as a field fills it with
 * what is under it; the labels are read from the form, so any app's fields work. What is in no field goes into
 * the content of the item's page. Fields with content are replaced only when the editor's question is answered
 * yes. Raw HTML of the document is shown as text.
 *
 * The parser (MerToolsMarkdown.parse) has no part of the editor in it: tests/mdimport.test.js runs it.
 */
(() => {
  'use strict';

  // ------------------------------------------------------------------ inline

  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
  const esc = (s) => s.replace(/[&<>"]/g, (c) => ESC[c]);
  const unesc = (s) => s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');
  const PUNCT = /[!-/:-@[-`{-~\p{P}\p{S}]/u;
  const ASCII_PUNCT = /[!-/:-@[-`{-~]/;
  const isSpace = (c) => c === '' || /\s/.test(c);
  const isPunct = (c) => c !== '' && PUNCT.test(c);
  const refKey = (s) => s.trim().replace(/\s+/g, ' ').toLowerCase();

  // what the browser takes out of an address before it reads it (a tab, a line break, a control character)
  const INVISIBLE = /[\u0000-\u001f\u007f-\u009f\u200b-\u200d\u2028\u2029\ufeff]/g;
  // a link or an image goes only where it is safe to: the web, mail, a phone, this site
  const safeUrl = (url) => {
    const u = url.replace(INVISIBLE, '').trim();

    return !/^[a-z][a-z0-9+.-]*:/i.test(u) || /^(https?|mailto|tel|ftp):/i.test(u);
  };
  const href = (url) => esc(url.replace(INVISIBLE, '').trim().replace(/ /g, '%20'));
  const stripTags = (html) => unesc(html.replace(/<[^>]*>/g, ''));

  // [label](destination "title") after the ] at pos: {dest, title, end} or null
  const parseDestination = (src, pos) => {
    let i = pos;
    const n = src.length;
    const skip = () => { while (i < n && /[ \t\n]/.test(src[i])) i++; };
    if (src[i] !== '(') return null;
    i++;
    skip();
    let dest = '';
    if (src[i] === '<') {
      i++;
      while (i < n && src[i] !== '>' && src[i] !== '\n') {
        if (src[i] === '\\' && ASCII_PUNCT.test(src[i + 1] || '')) i++;
        dest += src[i++];
      }
      if (src[i] !== '>') return null;
      i++;
    } else {
      let depth = 0;
      while (i < n && !/[ \t\n]/.test(src[i])) {
        if (src[i] === '\\' && ASCII_PUNCT.test(src[i + 1] || '')) { dest += src[i + 1]; i += 2; continue; }
        if (src[i] === '(') depth++;
        if (src[i] === ')') { if (depth === 0) break; depth--; }
        dest += src[i++];
      }
      if (depth !== 0) return null;
    }
    skip();
    let title = '';
    if (src[i] === '"' || src[i] === "'" || src[i] === '(') {
      const close = src[i] === '(' ? ')' : src[i];
      i++;
      while (i < n && src[i] !== close) {
        if (src[i] === '\\' && ASCII_PUNCT.test(src[i + 1] || '')) i++;
        title += src[i++];
      }
      if (src[i] !== close) return null;
      i++;
      skip();
    }
    if (src[i] !== ')') return null;

    return { dest, title, end: i + 1 };
  };

  // the ] closing the [ at pos (nested brackets, escapes and code spans skipped), or -1
  const closingBracket = (src, pos) => {
    let depth = 0;
    for (let i = pos, stop = Math.min(src.length, pos + REACH); i < stop; i++) {
      const c = src[i];
      if (c === '\\') { i++; continue; }
      if (c === '`') {
        const run = /^`+/.exec(src.slice(i))[0];
        const end = src.indexOf(run, i + run.length);
        i = end === -1 ? i + run.length - 1 : end + run.length - 1;
        continue;
      }
      if (c === '[') depth++;
      if (c === ']' && --depth === 0) return i;
    }

    return -1;
  };

  const inline = (src, refs) => {
    const nodes = [];
    const n = src.length;
    let buf = '';
    let i = 0;
    const flush = () => { if (buf) { nodes.push({ html: esc(buf) }); buf = ''; } };
    const push = (html) => { flush(); nodes.push({ html }); };

    const link = (start, image) => {
      const open = image ? start + 1 : start;
      const close = closingBracket(src, open);
      if (close === -1) return null;
      const label = src.slice(open + 1, close);
      let dest = null;
      let title = '';
      let end = close + 1;
      const inl = parseDestination(src, close + 1);
      if (inl) {
        ({ dest, title, end } = inl);
      } else {
        const ref = /^\[([^\]]*)\]/.exec(src.slice(close + 1));
        const key = refKey(ref && ref[1] !== '' ? ref[1] : label);
        if (ref) end = close + 1 + ref[0].length;
        if (refs && refs[key]) ({ dest, title } = refs[key]); else return null;
      }
      const inner = inline(label, refs);
      if (image) {
        if (!safeUrl(dest) || /^(mailto|tel):/i.test(dest)) return { end, html: esc(stripTags(inner)) };

        return { end, html: '<img src="' + href(dest) + '" alt="' + esc(stripTags(inner)) + '"' + (title ? ' title="' + esc(title) + '"' : '') + '>' };
      }
      if (!safeUrl(dest)) return { end, html: inner };

      return { end, html: '<a href="' + href(dest) + '"' + (title ? ' title="' + esc(title) + '"' : '') + '>' + inner + '</a>' };
    };

    while (i < n) {
      const c = src[i];
      if (c === '\\') {
        const d = src[i + 1] || '';
        if (d === '\n') { push('<br>'); i += 2; continue; }
        if (d && ASCII_PUNCT.test(d)) { buf += d; i += 2; continue; }
        buf += c; i++;
        continue;
      }
      if (c === '`') {
        const run = /^`+/.exec(src.slice(i))[0];
        let end = -1;
        for (let j = src.indexOf(run, i + run.length); j !== -1; j = src.indexOf(run, j + 1)) {
          if (src[j + run.length] !== '`' && src[j - 1] !== '`') { end = j; break; }
          while (src[j + 1] === '`') j++;
        }
        if (end !== -1) {
          let code = src.slice(i + run.length, end).replace(/\n/g, ' ');
          if (/^ .* $/.test(code) && /[^ ]/.test(code)) code = code.slice(1, -1);
          push('<code>' + esc(code) + '</code>');
          i = end + run.length;
          continue;
        }
        buf += run; i += run.length;
        continue;
      }
      if (c === '<') {
        const rest = src.slice(i);
        let m = /^<((?:https?|mailto|ftp):[^\s<>]+)>/i.exec(rest);
        if (m) { push('<a href="' + href(m[1]) + '">' + esc(m[1]) + '</a>'); i += m[0].length; continue; }
        m = /^<br\s*\/?>/i.exec(rest);
        if (m) { push('<br>'); i += m[0].length; continue; }
        m = /^<!--[\s\S]*?-->/.exec(rest);
        if (m) { i += m[0].length; continue; }
        buf += c; i++;
        continue;
      }
      if (c === '!' && src[i + 1] === '[') {
        const r = link(i, true);
        if (r) { push(r.html); i = r.end; continue; }
      }
      if (c === '[') {
        const r = link(i, false);
        if (r) { push(r.html); i = r.end; continue; }
      }
      if (c === 'h' && /^https?:\/\//i.test(src.slice(i, i + 8)) && !/[\p{L}\p{N}]/u.test(src[i - 1] || '')) {
        let m = /^https?:\/\/[^\s<>]+/i.exec(src.slice(i))[0];
        // the punctuation after a link and a bracket it does not open are not part of it
        m = m.replace(/[?!.,:;*_~'"]+$/, '');
        while (m.endsWith(')') && (m.match(/\)/g) || []).length > (m.match(/\(/g) || []).length) m = m.slice(0, -1);
        if (m.length > 8) { push('<a href="' + href(m) + '">' + esc(m) + '</a>'); i += m.length; continue; }
      }
      if (c === '*' || c === '_' || (c === '~' && src[i + 1] === '~')) {
        let j = i;
        while (src[j] === c) j++;
        const count = j - i;
        const before = src[i - 1] || '';
        const after = src[j] || '';
        const left = !isSpace(after) && (!isPunct(after) || isSpace(before) || isPunct(before));
        const right = !isSpace(before) && (!isPunct(before) || isSpace(after) || isPunct(after));
        let canOpen = left;
        let canClose = right;
        if (c === '_') {
          canOpen = left && (!right || isPunct(before));
          canClose = right && (!left || isPunct(after));
        }
        if (c === '~' && count !== 2) {
          buf += src.slice(i, j);
        } else {
          flush();
          nodes.push({ d: true, ch: c, n: count, open: canOpen, close: canClose });
        }
        i = j;
        continue;
      }
      if (c === '\n') {
        const hard = / {2,}$/.test(buf);
        buf = buf.replace(/ +$/, '');
        if (hard) push('<br>'); else buf += '\n';
        i++;
        while (src[i] === ' ' || src[i] === '\t') i++;
        continue;
      }
      buf += c; i++;
    }
    flush();

    // emphasis: the closest opener of the same kind, the way CommonMark pairs them
    // where the search for an opener of a kind was in vain: below it there is none for the next closer either
    const bottom = {};
    for (let ci = 0; ci < nodes.length; ci++) {
      const closer = nodes[ci];
      if (!closer.d || !closer.close || closer.n === 0) continue;
      let found = -1;
      const kind = closer.ch + (closer.open ? 1 : 0) + (closer.n % 3);
      for (let oi = ci - 1; oi > (bottom[kind] ?? -1); oi--) {
        const o = nodes[oi];
        if (!o.d || !o.open || o.ch !== closer.ch || o.n === 0) continue;
        if (closer.ch === '~' && (o.n !== 2 || closer.n !== 2)) continue;
        if ((o.close || closer.open) && (o.n + closer.n) % 3 === 0 && !(o.n % 3 === 0 && closer.n % 3 === 0)) continue;
        found = oi;
        break;
      }
      if (found === -1) { bottom[kind] = ci - 1; continue; }
      const o = nodes[found];
      const use = closer.ch === '~' ? 2 : (o.n >= 2 && closer.n >= 2 ? 2 : 1);
      o.n -= use;
      closer.n -= use;
      const text = (node) => (node.d ? node.ch.repeat(node.n) : node.html);
      const tag = closer.ch === '~' ? 's' : (use === 2 ? 'strong' : 'em');
      const html = '<' + tag + '>' + nodes.slice(found + 1, ci).map(text).join('') + '</' + tag + '>';
      nodes.splice(found + 1, ci - found - 1, { html });
      ci = found + 1;
    }

    return nodes.map((node) => (node.d ? node.ch.repeat(node.n) : node.html)).join('');
  };

  // ------------------------------------------------------------------ blocks

  const BLANK = /^\s*$/;
  const FENCE = /^ {0,3}(`{3,}|~{3,})(.*)$/;
  const ATX = /^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/;
  const HR = /^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/;
  const QUOTE = /^ {0,3}> ?(.*)$/;
  const ITEM = /^( {0,3})([-*+]|\d{1,9}[.)])( +|$)(.*)$/;
  const DELIM = /^ {0,3}\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/;
  const REF = /^ {0,3}\[([^\]\n]+)\]:[ \t]*<?([^\s>]+)>?(?:[ \t]+(?:"([^"]*)"|'([^']*)'|\(([^)]*)\)))?[ \t]*$/;

  // a line that starts a block of its own, so a paragraph does not go on over it
  const startsBlock = (line) => {
    if (FENCE.test(line) && !(FENCE.exec(line)[1][0] === '`' && FENCE.exec(line)[2].includes('`'))) return true;
    if (ATX.test(line) || HR.test(line) || QUOTE.test(line)) return true;
    const m = ITEM.exec(line);

    return !!m && m[4].trim() !== '' && (/^[-*+]/.test(m[2]) || /^1[.)]$/.test(m[2]));
  };

  const splitRow = (line) => {
    let s = line.trim();
    if (s.startsWith('|')) s = s.slice(1);
    if (s.endsWith('|') && !s.endsWith('\\|')) s = s.slice(0, -1);
    const cells = [];
    let cell = '';
    for (let i = 0; i < s.length; i++) {
      if (s[i] === '\\' && s[i + 1] === '|') { cell += '|'; i++; } else if (s[i] === '|') { cells.push(cell.trim()); cell = ''; } else cell += s[i];
    }
    cells.push(cell.trim());

    return cells;
  };

  // quotes and lists inside each other go no deeper than this; deeper ones are text
  const DEPTH = 20;
  // a [ is a link only when its ] is near
  const REACH = 4000;

  const blocks = (lines, refs, depth = 0) => {
    const nodes = [];
    let para = [];
    let i = 0;
    const flush = () => {
      if (para.length) nodes.push({ type: 'p', text: para.join('\n').replace(/[ \t]+$/, '') });
      para = [];
    };
    while (i < lines.length) {
      const line = lines[i];
      if (BLANK.test(line)) { flush(); i++; continue; }
      const indent = /^ */.exec(line)[0].length;

      if (indent >= 4 && !para.length) {
        const code = [];
        while (i < lines.length && (BLANK.test(lines[i]) || /^ {4}/.test(lines[i]))) code.push(lines[i++].slice(4));
        while (code.length && BLANK.test(code[code.length - 1])) code.pop();
        nodes.push({ type: 'code', text: code.join('\n'), lang: '' });
        continue;
      }
      const fence = FENCE.exec(line);
      if (fence && !(fence[1][0] === '`' && fence[2].includes('`'))) {
        flush();
        const close = new RegExp('^ {0,3}' + fence[1][0] + '{' + fence[1].length + ',}[ \\t]*$');
        const open = indent;
        const code = [];
        i++;
        while (i < lines.length && !close.test(lines[i])) {
          code.push(lines[i].replace(new RegExp('^ {0,' + open + '}'), ''));
          i++;
        }
        i++;
        nodes.push({ type: 'code', text: code.join('\n'), lang: fence[2].trim().split(/\s+/)[0] || '' });
        continue;
      }
      if (/^ {0,3}<!--/.test(line)) {
        flush();
        while (i < lines.length && !lines[i].includes('-->')) i++;
        i++;
        continue;
      }
      if (para.length) {
        const setext = /^ {0,3}(=+|-+)[ \t]*$/.exec(line);
        if (setext) {
          nodes.push({ type: 'h', level: setext[1][0] === '=' ? 1 : 2, text: para.join('\n').replace(/[ \t]+$/, '') });
          para = [];
          i++;
          continue;
        }
      }
      const atx = ATX.exec(line);
      if (atx) {
        flush();
        nodes.push({ type: 'h', level: atx[1].length, text: (atx[2] || '').trim() });
        i++;
        continue;
      }
      if (HR.test(line)) { flush(); nodes.push({ type: 'hr' }); i++; continue; }
      if (QUOTE.test(line) && depth < DEPTH) {
        flush();
        const quoted = [];
        while (i < lines.length) {
          const q = QUOTE.exec(lines[i]);
          if (q) { quoted.push(q[1]); i++; continue; }
          if (!BLANK.test(lines[i]) && quoted.length && !BLANK.test(quoted[quoted.length - 1]) && !startsBlock(lines[i])) {
            quoted.push(lines[i++]);
            continue;
          }
          break;
        }
        nodes.push({ type: 'quote', children: blocks(quoted, refs, depth + 1) });
        continue;
      }
      const item = ITEM.exec(line);
      if (item && depth < DEPTH && (!para.length || startsBlock(line))) {
        flush();
        const list = readList(lines, i, refs, depth);
        nodes.push(list.node);
        i = list.next;
        continue;
      }
      if (!para.length && line.includes('|') && i + 1 < lines.length && DELIM.test(lines[i + 1]) && lines[i + 1].includes('-')) {
        const head = splitRow(line);
        const delim = splitRow(lines[i + 1]);
        if (head.length === delim.length && delim.every((c) => /^:?-+:?$/.test(c))) {
          const align = delim.map((c) => (c.startsWith(':') && c.endsWith(':') ? 'center' : c.endsWith(':') ? 'right' : c.startsWith(':') ? 'left' : ''));
          const rows = [];
          i += 2;
          while (i < lines.length && !BLANK.test(lines[i]) && !startsBlock(lines[i])) {
            const cells = splitRow(lines[i++]);
            while (cells.length < head.length) cells.push('');
            rows.push(cells.slice(0, head.length));
          }
          nodes.push({ type: 'table', head, align, rows });
          continue;
        }
      }
      const def = !para.length && REF.exec(line);
      if (def) {
        const key = refKey(def[1]);
        if (!refs[key]) refs[key] = { dest: def[2], title: def[3] || def[4] || def[5] || '' };
        i++;
        continue;
      }
      para.push(line.replace(/^[ \t]+/, ''));
      i++;
    }
    flush();

    return nodes;
  };

  // the list that starts at lines[start]: the items (each parsed as blocks of its own) and the line after it
  const readList = (lines, start, refs, depth) => {
    const first = ITEM.exec(lines[start]);
    const ordered = /\d/.test(first[2][0]);
    const mark = ordered ? first[2].slice(-1) : first[2];
    const items = [];
    let i = start;
    while (i < lines.length) {
      const m = ITEM.exec(lines[i]);
      if (!m || (/\d/.test(m[2][0]) ? m[2].slice(-1) : m[2]) !== mark || ordered !== /\d/.test(m[2][0])) break;
      const markerEnd = m[1].length + m[2].length;
      const gap = m[3].length;
      const width = m[4] === '' || gap > 4 ? markerEnd + 1 : markerEnd + gap;
      const body = [m[4] === '' || gap > 4 ? (m[3].slice(1) + m[4]) : m[4]];
      let j = i + 1;
      let blanks = 0;
      while (j < lines.length) {
        const l = lines[j];
        if (BLANK.test(l)) { blanks++; j++; continue; }
        const ind = /^ */.exec(l)[0].length;
        const last = body.length ? body[body.length - 1] : '';
        if (ind >= width) {
          for (let k = 0; k < blanks; k++) body.push('');
          body.push(l.slice(width));
        } else if (ind > m[1].length && ITEM.test(l)) {
          // a sub-list indented less than the text of the item (two spaces under "1. ")
          for (let k = 0; k < blanks; k++) body.push('');
          body.push(l.slice(ind));
        } else if (!blanks && !BLANK.test(last) && !startsBlock(l) && !ITEM.test(l)) {
          body.push(l.replace(/^[ \t]+/, ''));
        } else break;
        blanks = 0;
        j++;
      }
      // the blank lines after the last line of the item are not its own
      items.push(blocks(body, refs, depth + 1));
      i = j;
      // another item of the list, or the end of it
      let k = i;
      while (k < lines.length && BLANK.test(lines[k])) k++;
      const nextItem = k < lines.length ? ITEM.exec(lines[k]) : null;
      if (nextItem && (/\d/.test(nextItem[2][0]) ? nextItem[2].slice(-1) : nextItem[2]) === mark && ordered === /\d/.test(nextItem[2][0])) i = k; else break;
    }

    return { node: { type: 'list', ordered, start: ordered ? parseInt(first[2], 10) : 1, items }, next: i };
  };

  // ------------------------------------------------------------------ output

  const render = (node, refs) => {
    switch (node.type) {
      case 'p': return '<p>' + inline(node.text, refs) + '</p>';
      case 'h': {
        const level = Math.max(2, node.level); // the title of the post is the one h1

        return '<h' + level + '>' + inline(node.text, refs) + '</h' + level + '>';
      }
      case 'hr': return '<hr>';
      case 'quote': return '<blockquote>' + node.children.map((c) => render(c, refs)).join('') + '</blockquote>';
      case 'code': return '<pre><code' + (node.lang ? ' class="language-' + esc(node.lang) + '"' : '') + '>' + esc(node.text) + '</code></pre>';
      case 'list': {
        const tag = node.ordered ? 'ol' : 'ul';

        return '<' + tag + (node.ordered && node.start !== 1 ? ' start="' + node.start + '"' : '') + '>' + node.items.map((children) => {
          // an item that is one paragraph (and sub-lists) is its text, not a paragraph in it
          const plain = children.length > 0 && children.filter((c) => c.type === 'p').length === 1 && children[0].type === 'p';
          const body = children.map((c, k) => (plain && k === 0 ? inline(c.text.replace(/^\[([ xX])\][ \t]+/, (all, x) => (x === ' ' ? '☐ ' : '☑ ')), refs) : render(c, refs))).join('');

          return '<li>' + body + '</li>';
        }).join('') + '</' + tag + '>';
      }
      case 'table': {
        const cell = (tag, text, k) => '<' + tag + (node.align[k] ? ' style="text-align: ' + node.align[k] + ';"' : '') + '>' + inline(text, refs) + '</' + tag + '>';

        return '<table style="border-collapse: collapse; width: 100%;" border="1" cellpadding="6"><thead><tr>' + node.head.map((t, k) => cell('th', t, k)).join('')
          + '</tr></thead><tbody>' + node.rows.map((row) => '<tr>' + row.map((t, k) => cell('td', t, k)).join('') + '</tr>').join('') + '</tbody></table>';
      }
      default: return '';
    }
  };

  // the fields of the post a document can name at its top ("**Tytuł:** …", "Adres (slug): …") or in its front matter
  const FIELDS = [
    ['title', /^(?:meta |seo )?(?:tytuł|tytul|title)(?: (?:wpisu|artykułu|artykulu|strony|posta?))?$/],
    ['alias', /^(?:adres(?: url| strony| wpisu)?|url|slug|alias|permalink)$/],
    ['intro', /^(?:opis(?: meta)?|meta ?(?:opis|description)|description|wstęp|wstep|intro(?: ?text)?|lead|zajawka|excerpt|summary)$/],
    ['keywords', /^(?:słowa kluczowe|slowa kluczowe|meta ?keywords|keywords)$/],
  ];
  const fieldOf = (label) => {
    const key = label.replace(/\([^)]*\)/g, ' ').replace(/[_*`]/g, '').replace(/\s+/g, ' ').trim().toLowerCase();
    const found = FIELDS.find(([, re]) => re.test(key));

    return found ? found[0] : null;
  };
  // "**Label:** value", "**Label**: value", "Label: value"
  const META_LINE = /^ {0,3}(\*\*|__)?([^:*_\n][^:\n]{0,59}?)(?:(?::[ \t]*\1)|(?:\1[ \t]*:)):?[ \t]*(.*)$/;
  const plainText = (md) => stripTags(inline(md, Object.create(null))).replace(/\s+/g, ' ').trim();
  const slug = (value) => {
    let v = value.replace(/[?#].*$/, '').replace(/\/+$/, '');
    if (v.includes('/')) v = v.slice(v.lastIndexOf('/') + 1);

    return v.replace(/\.html?$/i, '').trim().replace(/\s+/g, '-');
  };

  // the lines of label: value at the top of the document, when they are such a section, and the line after it;
  // known(label): the label names a field of the page besides the four (a field of a product: SKU, Producent)
  const metaSection = (lines, known) => {
    const found = {};
    const extra = [];
    let i = 0;
    let count = 0;
    let bold = false;
    while (i < lines.length && BLANK.test(lines[i])) i++;
    const start = i;
    for (; i < lines.length; i++) {
      if (BLANK.test(lines[i])) continue;
      const m = META_LINE.exec(lines[i]);
      if (!m || ATX.test(lines[i]) || ITEM.test(lines[i]) || QUOTE.test(lines[i]) || REF.test(lines[i])) break;
      const field = fieldOf(m[2]);
      const other = !field && known(m[2]);
      // a label it does not know is of the section only in bold ("**Autor:** …"); "https://…" or "Uwaga: …" is text
      if (!field && !other && !m[1]) break;
      if (field && !(field in found)) { found[field] = m[3]; count++; }
      if (other) { extra.push([m[2], m[3]]); count++; }
      if (m[1]) bold = true;
    }
    while (i > start && BLANK.test(lines[i - 1])) i--;
    let end = i;
    while (end < lines.length && BLANK.test(lines[end])) end++;
    const rule = end < lines.length && HR.test(lines[end]);
    // a first paragraph that only begins with "Opis: …" is text, not a section of fields; a line of a field of the
    // item's form ("SKU: X-1") is one
    if (!count || i === start || !(count >= 2 || bold || rule || extra.length)) return null;

    return { fields: found, extra, next: rule ? end + 1 : i };
  };

  // an address as a field of Gridbox keeps it: Gridbox writes it into href="…" as it is, so it goes only where a link
  // of the document may go, and nothing in it ends the attribute; '' when it may not
  const fieldUrl = (url) => {
    const u = String(url || '').replace(INVISIBLE, '').trim();
    // Gridbox writes it into the page as HTML, where "javascript&colon;…" or "&#58;" is a colon: an & only before a
    // parameter of the query ("&id=256"), which no browser reads as a character
    if (!u || !safeUrl(u) || /&(?![\w.\-[\]%]*(?:=|$))/.test(u)) return '';

    return u.replace(/["'<>`\\ ]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase().padStart(2, '0'));
  };
  // the address of a value that is one link and nothing else: "[Sonel](/sonel)", "<https://…>", "https://…"
  const linkOf = (md) => {
    const s = String(md).trim();
    if (s[0] === '[') {
      const close = closingBracket(s, 0);
      const d = close === -1 ? null : parseDestination(s, close + 1);

      return d && d.end === s.length ? fieldUrl(d.dest) : '';
    }
    const m = /^<?((?:https?:\/\/|\/)[^\s<>]+)>?$/.exec(s);

    return m ? fieldUrl(m[1]) : '';
  };
  // the value of a "Label: value" line for a field: its text, and the address when it is a link
  const valueOf = (label, md) => ({ label: plainText(label), value: plainText(md), link: linkOf(md) });

  // "6 950,00 zł" → 6950.00, "0,125" → 0.125, "1.234.567" → 1234567; '' for what is no amount, or could be two
  // ("6.950": six thousand or six and a bit?)
  const amount = (v) => {
    // one number, with words or a currency before or after it but no other digits ("100 zł + 23% VAT", "1e3": none)
    const m = /^[^\d]*?(-?(?:[.,](?=\d))?\d[\d \u00a0.,]*?)(?:[ \u00a0]*[^\d \u00a0.,-][^\d]*)?[.,]?$/.exec(String(v).trim());
    if (!m) return '';
    // spaces only between groups of thousands ("6 950,00")
    if (/[ \u00a0]/.test(m[1]) && !/^-?\d{1,3}(?:[ \u00a0]\d{3})+(?:[.,]\d+)?$/.test(m[1])) return '';
    const s = m[1].replace(/[ \u00a0]/g, '');
    const seps = s.match(/[.,]/g) || [];
    let out = s;
    if (seps.length) {
      const last = Math.max(s.lastIndexOf('.'), s.lastIndexOf(','));
      const int = s.slice(0, last);
      const frac = s.slice(last + 1);
      if (new Set(seps).size === 2) {
        // 1.234,50 or 1,234.50: the last is the decimal one, the other only groups thousands before it
        if (!/^-?\d{1,3}([.,]\d{3})*$/.test(int) || int.includes(s[last])) return '';
        out = int.replace(/[.,]/g, '') + '.' + frac;
      } else if (seps.length > 1) {
        if (!/^-?\d{1,3}([.,]\d{3})+$/.test(s)) return '';
        out = s.replace(/[.,]/g, '');
      } else {
        if (frac.length === 3 && !/^-?0?$/.test(int)) return '';
        out = (/^-?$/.test(int) ? int + '0' : int) + '.' + frac;
      }
    }

    return /^-?\d+(\.\d+)?$/.test(out) ? out : '';
  };

  /**
   * The document as the modules of a post: {title, alias, intro, keywords, blocks: [{type: 'headline', level, text} |
   * {type: 'text', html}]}. The fields come from a section of "label: value" lines at the top (Tytuł,
   * Adres/slug, Opis meta/wstęp, Słowa kluczowe), else the front matter; the title else from the first
   * heading of the first level. No heading is of the first level in the post (Gridbox shows the title as
   * one): one at the top, or a first heading that repeats the title, is the title and is left out; any
   * other is of the second level.
   *
   * Options: field(label) — the label names a field of the page (an app's item: a product's SKU, Producent,
   * Dane techniczne): a "Label: value" line of it at the top is in extra [{label, value, link}], and a heading
   * of it begins a section of it (up to the next heading of its level or a higher one, its sub-headings in it)
   * in sections [{label, html, text}], not in blocks. keepTitle — the # heading that is the title is also a
   * headline, of the first level (a page, where nothing else shows the title), or of the second (keepTitle: 2,
   * a page with an h1 of its own already).
   */
  const parse = (markdown, options = {}) => {
    const known = typeof options.field === 'function' ? (label) => !!options.field(plainText(label)) : () => false;
    let text = String(markdown).replace(/^﻿/, '').replace(/\r\n?/g, '\n');
    const fields = { title: '', alias: '', intro: '', keywords: '' };
    const extra = [];
    const front = /^---[ \t]*\n([\s\S]*?)\n(?:---|\.\.\.)[ \t]*(?:\n|$)/.exec(text);
    if (front) {
      front[1].split('\n').forEach((line) => {
        const m = /^([\p{L}\p{N}_ .()%/-]+?):[ \t]*(.+?)[ \t]*$/u.exec(line);
        if (!m) return;
        const value = m[2].replace(/^(["'])(.*)\1$/, '$2');
        const field = fieldOf(m[1].replace(/_/g, ' '));
        if (field && !fields[field]) fields[field] = value;
        else if (!field && known(m[1].replace(/_/g, ' '))) extra.push([m[1].replace(/_/g, ' '), value]);
      });
      text = text.slice(front[0].length);
    }
    let lines = text.split('\n').map((l) => l.replace(/^\t+/, (tabs) => '    '.repeat(tabs.length)));
    let meta = metaSection(lines, known);
    let top = 0;
    if (!meta) {
      // or right under the # heading the document begins with ("# Miernik X" and then "SKU: …")
      while (top < lines.length && BLANK.test(lines[top])) top++;
      const h = ATX.exec(lines[top] || '');
      meta = h && h[1].length === 1 ? metaSection(lines.slice(top + 1), known) : null;
      top = meta ? top + 1 : 0;
    }
    if (meta) {
      Object.keys(meta.fields).forEach((field) => { fields[field] = meta.fields[field]; });
      extra.push(...meta.extra);
      lines = lines.slice(0, top).concat(lines.slice(top + meta.next));
    }
    Object.keys(fields).forEach((field) => { fields[field] = plainText(fields[field]); });
    if (fields.alias) fields.alias = slug(fields.alias);
    const refs = Object.create(null); // [constructor] is no reference
    const nodes = blocks(lines, refs);
    const out = [];
    let html = [];
    let heading = false;
    let h1 = '';
    const sections = [];
    let section = null;
    const close = () => { if (html.length) out.push({ type: 'text', html: html.join('') }); html = []; };
    const same = (a, b) => a.replace(/\s+/g, ' ').trim().toLowerCase() === b.replace(/\s+/g, ' ').trim().toLowerCase();
    const end = () => {
      if (!section) return;
      const body = section.html.join('');
      sections.push({ label: section.label, html: body, text: stripTags(body.replace(/<\/(p|h\d|li|tr|blockquote|pre)>|<br>/g, '\n')).replace(/[ \t]+/g, ' ').replace(/\n\s*\n+/g, '\n').trim() });
      section = null;
    };
    nodes.forEach((node, k) => {
      if (node.type === 'h' && section && node.level > section.level) { section.html.push(render(node, refs)); return; }
      if (node.type !== 'h') { (section ? section.html : html).push(render(node, refs)); return; }
      const label = stripTags(inline(node.text, refs)).trim();
      if (!label) return;
      end();
      // the title: the first heading, of the first level, when it says the title named above it, else when the
      // document begins with it (a # heading that differs from the title named above it stays, of level 2)
      if (node.level === 1 && !heading && (fields.title ? same(label, fields.title) : k === 0)) {
        heading = true;
        if (k === 0) h1 = label;
        if (options.keepTitle) { close(); out.push({ type: 'headline', level: options.keepTitle === 2 ? 2 : 1, text: label }); }
        return;
      }
      heading = true;
      if (known(label) && !sections.some((s) => same(s.label, label))) {
        close();
        section = { label, level: node.level, html: [] };
        return;
      }
      close();
      out.push({ type: 'headline', level: Math.max(2, node.level), text: label });
    });
    end();
    close();

    return { title: fields.title || h1, alias: fields.alias, intro: fields.intro, keywords: fields.keywords, blocks: out,
      sections, extra: extra.map(([label, value]) => valueOf(label, value)) };
  };

  /**
   * A text for a field of Gridbox's form. Gridbox writes the fields into its editor (value="…", <textarea>…) and many
   * into the page (a link's label, a plain textarea, the SKU of the e-mails) as they are, without escaping: a "
   * would end the attribute, a < begin a tag, and an entity (&lt;) would be one once the editor has read it and the
   * page is saved again. So no such character goes in: < > " as ‹ › ” and the & of a character reference as ＆.
   */
  const safeText = (v) => String(v).replace(/</g, '‹').replace(/>/g, '›').replace(/"/g, '”').replace(/&(?=#?[A-Za-z0-9]+;)/g, '＆');
  // the same for the HTML of a field with a text editor: CKEditor is given its <textarea>'s text, entities read
  const safeHtml = (html) => String(html).replace(/&lt;/g, '‹').replace(/&gt;/g, '›');

  const api = { parse, amount, fieldUrl, safeText, safeHtml };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (typeof document === 'undefined') return;
  window.MerToolsMarkdown = api;

  // ------------------------------------------------------------------ the editor

  const node = document.getElementById('mertools-mdimport-config');
  if (!node) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  const app = window.app;
  if (!app || typeof app.checkModule !== 'function') return;

  const MAX = 2 * 1024 * 1024;
  // Gridbox's text of a new post (START_TYPING_HERE) in the languages it may have been made in
  const norm = (text) => text.replace(/\s+/g, ' ').trim().toLowerCase();
  const PLACEHOLDERS = new Set(['Click Here And Start Typing', 'Kliknij i zacznij pisać', 'Kliknij tutaj i zacznij pisać', ...(cfg.placeholders || [])].map(norm));
  const FIELD_NAMES = { title: 'page_title', alias: 'page_alias', intro: 'intro_text', keywords: 'page_meta_keywords' };
  const notice = (message, bad) => { if (typeof app.showNotice === 'function') app.showNotice(message, bad ? 'ba-alert' : ''); };
  const say = (text, ...values) => values.reduce((s, v) => s.replace(/%[ds]/, v), text);

  const input = document.createElement('input');
  input.type = 'file';
  input.accept = '.md,.markdown,.mdown,.txt,text/markdown,text/plain';
  input.style.display = 'none';
  document.body.append(input);

  let busy = null;
  const overlay = () => {
    const div = document.createElement('div');
    div.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(255,255,255,.6);display:flex;align-items:center;justify-content:center;'
      + 'font:500 15px Roboto,Arial,sans-serif;color:#333';
    document.body.append(div);

    return div;
  };

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const until = async (look, limit) => {
    for (let waited = 0; waited < limit; waited += 100) {
      const found = look();
      if (found) return found;
      await sleep(100);
    }
    throw new Error('timeout');
  };

  // a file saved by Notepad as "ANSI" is Windows-1250, not UTF-8
  const read = async (file) => {
    const bytes = await file.arrayBuffer();
    try {
      return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
    } catch (e) {
      return new TextDecoder('windows-1250').decode(bytes);
    }
  };

  const emptyText = (el) => {
    const content = el.querySelector('.content-text');
    if (!content) return false;
    const text = norm(content.textContent);

    return (text === '' || PLACEHOLDERS.has(text)) && !content.querySelector('img, iframe, table, hr, video');
  };

  // the column the texts of the post are in: where its modules go
  const target = (doc) => {
    const item = doc.querySelector('#ba-edit-section .ba-item-text, #ba-edit-section .ba-item-headline');
    const col = item ? item.closest('.ba-grid-column') : doc.querySelector('#ba-edit-section .ba-grid-column');

    return col || null;
  };

  // In the editor of a post Gridbox puts CKEditor on every new text (CKEDITOR.inline), which starts later
  // and then writes back what the text held when it was added — Gridbox's "click here and start typing".
  // A text is filled only once its editor is ready, or what was put into it is lost.
  const ready = (w, content) => new Promise((resolve) => {
    let ed = null;
    try { ed = w.CKEDITOR && w.CKEDITOR.dom.element.get(content).getEditor(); } catch (e) { ed = null; }
    if (!ed || ed.status === 'ready') { resolve(); return; }
    const t = setTimeout(resolve, 10000);
    ed.once('instanceReady', () => { clearTimeout(t); setTimeout(resolve, 0); });
  });

  const fill = async (w, key, block) => {
    const el = w.document.getElementById(key);
    if (!el) return;
    if (block.type === 'headline') {
      const old = el.querySelector('.headline-wrapper h1, .headline-wrapper h2, .headline-wrapper h3, .headline-wrapper h4, .headline-wrapper h5, .headline-wrapper h6');
      const tag = w.document.createElement('h' + block.level);
      tag.textContent = block.text;
      if (old) old.replaceWith(tag);
      if (w.app.items[key]) w.app.items[key].tag = 'h' + block.level;
    } else {
      const content = el.querySelector('.content-text');
      if (!content) return;
      await ready(w, content);
      content.innerHTML = block.html;
    }
  };

  // ---------------------------------------------------------------- the fields of a page and of an app's item

  // a label as it is compared: no case, accents, star of a required field, colon or extra spaces
  const key = (label) => String(label).normalize('NFD').replace(/\p{M}/gu, '').replace(/[łŁ]/g, 'l')
    .replace(/[\s*:]+$/, '').replace(/\s+/g, ' ').trim().toLowerCase();
  // other names of the store's own fields of a product, besides the labels Gridbox gives them in the form
  const ALIASES = {
    sku: ['sku', 'kod', 'kod produktu', 'symbol', 'indeks', 'index', 'numer katalogowy', 'nr katalogowy', 'part number', 'mpn', 'product code'],
    price: ['cena', 'cena regularna', 'price', 'regular price'],
    sale_price: ['cena promocyjna', 'cena po rabacie', 'sale price'],
    stock: ['stan', 'stan magazynowy', 'w magazynie', 'stock', 'in stock'],
    min: ['minimalna ilosc', 'min. ilosc', 'min. qty', 'min qty'],
  };
  const fire = (el) => {
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  };
  // snap() of a field: what it holds now, and back to that (see restoreBar)
  const snapValue = (el) => () => { const was = el.value; return () => { el.value = was; fire(el); }; };
  // what a field gets: a section of the document ({html, text}) or the value of a line ({value, link})
  const asHtml = (entry) => (entry.html !== undefined ? entry.html : (entry.value ? '<p>' + esc(entry.value) + '</p>' : ''));
  const asText = (entry) => (entry.text !== undefined ? entry.text : entry.value);
  // what the restore compares: a field's own state if it has one (a link: text and address apart), else its value
  const state = (f) => (f.state ? f.state() : f.get());
  const blank = (v) => !/<(img|iframe|table|video|hr)\b/i.test(v) && stripTags(String(v)).replace(/ |&nbsp;/g, ' ').trim() === '';
  const SIMPLE = ['text', 'number', 'price', 'date', 'event-date'];

  // one field of the form of an app's item: {label, rich, get(), set(entry) → false when the value does not fit it}
  const formField = (g, label) => {
    const type = g.dataset.fieldType;
    if (g.classList.contains('product-data') || SIMPLE.includes(type)) {
      const el = g.querySelector('input[type="text"], input[type="number"], input:not([type])');
      if (!el) return null;
      const money = type === 'price' || type === 'number';

      return { label, snap: snapValue(el), get: () => el.value.trim(), set: (e) => {
        const text = asText(e).replace(/\s*\n\s*/g, ' ');
        const v = money ? amount(text) : safeText(text);
        if (v === '') return false;
        el.value = v;
        fire(el);

        return true;
      } };
    }
    if (type === 'textarea') {
      const el = g.querySelector('textarea[name="' + g.dataset.id + '"]');
      if (!el) return null;
      if (el.dataset.texteditor && !el.dataset.jce) {
        const cke = () => app.fieldsCKE && app.fieldsCKE[el.name];

        const snap = () => {
          const was = el.value;
          const data = cke() ? cke().getData() : null;

          return () => { el.value = was; if (cke()) cke().setData(data === null ? was : data); };
        };

        // CKEditor's setData is asynchronous: what the field holds after the import is known at its callback
        let settled = Promise.resolve();

        return { label, rich: true, snap, settled: () => settled, get: () => (cke() ? cke().getData() : el.value), set: (e) => {
          const html = safeHtml(asHtml(e));
          el.value = html;
          if (cke()) {
            settled = new Promise((resolve) => {
              const t = setTimeout(resolve, 3000);
              cke().setData(html, { callback: () => { clearTimeout(t); resolve(); } });
            });
          }

          return true;
        } };
      }
      if (el.dataset.texteditor && window.WFEditor) {
        const id = 'editor' + el.dataset.jce;

        const snap = () => { const was = window.WFEditor.getContent(id); return () => window.WFEditor.setContent(id, was); };

        return { label, rich: true, snap, get: () => window.WFEditor.getContent(id), set: (e) => { window.WFEditor.setContent(id, safeHtml(asHtml(e))); return true; } };
      }

      return { label, snap: snapValue(el), get: () => el.value.trim(), set: (e) => { el.value = safeText(asText(e)); fire(el); return true; } };
    }
    if (type === 'url' || type === 'field-button') {
      const text = g.querySelector('input[type="text"][data-name="label"]');
      const link = g.querySelector('input[type="text"][data-name="link"]');
      if (!link) return null;

      const snap = () => { const back = [text, link].filter(Boolean).map((el) => snapValue(el)()); return () => back.forEach((f) => f()); };

      // state: text and address apart, so "Sonel" + "/sonel" is not the same as "Sonel /sonel" typed into the text
      return { label, snap, state: () => JSON.stringify([text ? text.value : '', link.value]), get: () => [text ? text.value : '', link.value].filter(Boolean).join(' ').trim(), set: (e) => {
        // a line gives its link; a section its first link, with the text of that link
        const a = e.html ? new DOMParser().parseFromString(e.html, 'text/html').querySelector('a[href]') : null;
        const value = (a ? a.textContent : asText(e).split('\n')[0]).replace(/\s+/g, ' ').trim();
        const address = e.link || (a ? fieldUrl(a.getAttribute('href')) : '') || (text ? '' : fieldUrl(value));
        if (!address && !text) return false;
        const label = safeText(value);
        // a new label without an address: the address of the old one would lead elsewhere, so it goes
        if (address || (text && label && label !== text.value && link.value !== '')) {
          link.value = address;
          fire(link);
        }
        if (text && label && value !== address) {
          text.value = label;
          fire(text);
        }

        return true;
      } };
    }
    if (type === 'select') {
      const el = g.querySelector('select[name]');
      if (!el) return null;

      return { label, snap: snapValue(el), get: () => el.value, set: (e) => {
        const want = key(asText(e));
        const option = [...el.options].find((o) => o.value !== '' && (key(o.textContent) === want || key(o.value) === want));
        if (!option) return false;
        el.value = option.value;
        fire(el);

        return true;
      } };
    }
    if (type === 'radio' || type === 'checkbox') {
      const boxes = [...g.querySelectorAll('input[type="' + type + '"][name]')];
      if (!boxes.length) return null;
      const title = (b) => key(((b.closest('.ba-checkbox-wrapper') || b).querySelector('span') || b).textContent || b.value);

      const snap = () => {
        const was = boxes.map((b) => b.checked);

        return () => { boxes.forEach((b, i) => { b.checked = was[i]; }); fire(boxes[0]); };
      };

      return { label, snap, get: () => boxes.filter((b) => b.checked).map((b) => b.value).join(','), set: (e) => {
        const want = asText(e).split(type === 'radio' ? /\n/ : /[,;\n]/).map(key).filter(Boolean);
        const hit = boxes.filter((b) => want.includes(title(b)) || want.includes(key(b.value)));
        if (!hit.length) return false;
        boxes.forEach((b) => { b.checked = type === 'checkbox' ? hit.includes(b) : b === hit[0]; });
        fire(hit[0]);

        return true;
      } };
    }

    return null; // files, galleries, maps and the like: not from a text
  };

  // the fields of the form of an app's item (a product) by their labels, and the store's own by other names too
  const formFields = () => {
    const found = new Map();
    const box = document.getElementById('blog-post-editor-fields-options');
    if (!box) return found;
    const aliases = [];
    box.querySelectorAll('.blog-post-editor-options-group[data-field-type]').forEach((g) => {
      const name = g.querySelector('.ba-field-editor-label');
      if (!name) return;
      const copy = name.cloneNode(true);
      copy.querySelectorAll('.required-fields-star, .ba-tooltip').forEach((el) => el.remove());
      const label = copy.textContent.replace(/\s+/g, ' ').trim();
      const field = label && formField(g, label);
      if (!field) return;
      if (!found.has(key(label))) found.set(key(label), field);
      if (g.classList.contains('product-data')) (ALIASES[g.dataset.fieldKey] || []).forEach((a) => aliases.push([a, field]));
    });
    aliases.forEach(([a, field]) => { if (!found.has(key(a))) found.set(key(a), field); });

    return found;
  };

  // a field of the page itself (the panel and the settings window have one each: Gridbox saves the window's)
  const pageField = (field) => {
    const els = [...document.querySelectorAll('.page-settings-input-trigger[name="' + FIELD_NAMES[field] + '"]')];
    if (!els.length) return null;

    const snap = () => {
      const was = els.map((el) => el.value);

      return () => { els.forEach((el, i) => { el.value = was[i]; }); els[0].dispatchEvent(new Event('input', { bubbles: true })); };
    };

    return { label: cfg.texts.names[field], snap, get: () => els[0].value.trim(), set: (e) => {
      els.forEach((el) => { el.value = e.value; });
      els[0].dispatchEvent(new Event('input', { bubbles: true }));

      return true;
    } };
  };

  // a section of one column at the end of the page, as Gridbox's own New section makes it
  const newColumn = async (w) => {
    const columns = () => [...w.document.querySelectorAll('#ba-edit-section .ba-grid-column')];
    const known = new Set(columns());
    const dialog = document.getElementById('add-section-dialog');
    if (dialog) dialog.classList.remove('add-columns');
    if (app.pageStructure && app.pageStructure.context) app.pageStructure.context.task = null;
    w.app.checkModule('addColumns', { data: '12' });
    const column = await until(() => columns().find((c) => !known.has(c)), 15000);
    await sleep(300);
    closePanels();

    return column;
  };
  const closePanels = () => {
    if (typeof window.$g === 'function') window.$g('.ba-modal-cp.draggable-modal-cp.in:not(#page-structure-dialog)').modal('hide');
  };

  // The fields of the form are not in Gridbox's history (app.addHistory keeps the page in the editor's frame and
  // its items), so its Undo takes back the modules of an import but never the fields. After an import that filled
  // fields a bar stays, until it is closed or the next import, with a button that puts back what they held before
  // it; a field changed since the import is left as it is.
  let bar = null;
  const restoreBar = (changes) => {
    if (bar) { bar.remove(); bar = null; }
    if (!changes.length) return;
    bar = document.createElement('div');
    bar.className = 'mertools-md-restore';
    bar.setAttribute('role', 'status');
    bar.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99998;display:flex;align-items:center;gap:14px;'
      + 'max-width:min(720px,calc(100vw - 32px));padding:10px 12px 10px 18px;border-radius:6px;background:#2c2c2c;color:#fff;'
      + 'box-shadow:0 10px 30px rgba(0,0,0,.25);font:400 14px/1.4 Roboto,Arial,sans-serif';
    const text = document.createElement('span');
    text.textContent = say(cfg.texts.restoreBar, changes.map(({ f }) => f.label).join(', '));
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'mertools-md-restore-button';
    button.textContent = cfg.texts.restore;
    button.style.cssText = 'flex:none;padding:7px 14px;border:0;border-radius:4px;background:#fff;color:#2c2c2c;font:500 14px Roboto,Arial,sans-serif;cursor:pointer';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'mertools-md-restore-close';
    close.setAttribute('aria-label', cfg.texts.close);
    close.title = cfg.texts.close;
    close.textContent = '×';
    close.style.cssText = 'flex:none;padding:0 4px;border:0;background:none;color:inherit;font:400 22px/1 Arial,sans-serif;cursor:pointer;opacity:.7';
    button.addEventListener('click', () => {
      const restored = [];
      const changed = [];
      // in the reverse order: a field filled twice ends with what it held first
      changes.slice().reverse().forEach(({ f, back, after }) => {
        let now;
        try { now = state(f); } catch (e) { now = null; }
        if (now !== after) { changed.push(f.label); return; }
        try { back(); restored.push(f.label); } catch (e) { changed.push(f.label); }
      });
      restoreBar([]);
      notice([
        restored.length ? say(cfg.texts.restored, restored.reverse().join(', ')) : '',
        changed.length ? say(cfg.texts.changed, changed.reverse().join(', ')) : '',
      ].filter(Boolean).join(' '), !restored.length);
    });
    close.addEventListener('click', () => restoreBar([]));
    bar.append(text, button, close);
    document.body.append(bar);
  };

  // ---------------------------------------------------------------- the import

  const run = async (file) => {
    if (busy) return;
    restoreBar([]);
    const w = app.editor;
    if (!w || !w.document || !w.app || typeof w.app.checkModule !== 'function') {
      notice(cfg.texts.fail, true);

      return;
    }
    if (file.size > MAX) { notice(say(cfg.texts.large, Math.round(MAX / 1048576)), true); return; }
    // an item of an app with fields (a product), a blog post, or a page
    const form = formFields();
    const mode = form.size ? 'app' : (document.body.classList.contains('blog-post-editor-parent') ? 'post' : 'page');
    let result;
    try {
      result = parse(await read(file), {
        field: form.size ? (label) => form.has(key(label)) : null,
        keepTitle: mode === 'page' && (w.document.querySelector('#ba-edit-section h1') ? 2 : 1),
      });
    } catch (e) {
      notice(cfg.texts.fail, true);

      return;
    }
    // what goes into the fields: the page's own (title, alias, intro text, keywords), then the item's by their labels
    const plan = [];
    const used = new Set();
    Object.keys(FIELD_NAMES).forEach((field) => {
      const f = result[field] ? pageField(field) : null;
      if (f) plan.push({ f, e: { value: result[field] } });
    });
    [...result.extra, ...result.sections].forEach((e) => {
      const f = form.get(key(e.label));
      if (f && !used.has(f)) { used.add(f); plan.push({ f, e }); }
    });
    if (!result.blocks.length && !plan.length) { notice(cfg.texts.empty, true); return; }

    const col = target(w.document);
    const empty = col ? [...col.querySelectorAll(':scope > .ba-item-text')].filter(emptyText) : [];
    // a new post or page: nothing in all of it (any column, any section) but the empty text Gridbox begins a post with
    const fresh = [...w.document.querySelectorAll('#ba-edit-section .ba-item')].every((el) => empty.includes(el));
    if (mode === 'post' && result.blocks.length && !col) { notice(cfg.texts.fail, true); return; }
    // the texts of a post go where its texts are; of a page or an item into a section of their own, but for a new one
    let column = mode === 'post' || fresh ? col : null;
    const old = column ? empty : [];

    // in a new post or page in place of what the fields have, else only into the empty ones; an item asks
    const filled = [];
    const kept = [];
    const failed = [];
    // each field filled: how to put back what it held, and what the import left in it
    const changes = [];
    const differs = plan.filter(({ f, e }) => !blank(f.get()) && f.get() !== (f.rich ? asHtml(e) : asText(e)));
    const overwrite = mode === 'app'
      ? !differs.length || window.confirm(say(cfg.texts.overwrite, differs.map(({ f }) => f.label).join(', ')).replace(/\\n/g, '\n'))
      : fresh;
    const fillFields = () => plan.forEach(({ f, e }) => {
      const now = f.get();
      if (now === (f.rich ? asHtml(e) : asText(e))) return;
      if (!blank(now) && !overwrite) { kept.push(f.label); return; }
      let back = null;
      try {
        back = f.snap();
        if (f.set(e)) {
          filled.push(f.label);
          const change = { f, back, after: state(f) };
          if (f.settled) f.settled().then(() => { change.after = state(f); });
          changes.push(change);
        } else {
          failed.push(f.label);
        }
      } catch (err) {
        failed.push(f.label);
        // not left half written
        try { if (back) back(); } catch (e2) { /* as it is */ }
      }
    });
    // an item's fields at once (they are not the modules); a post's or a page's once its modules are in
    if (mode === 'app') fillFields();

    let done = 0;
    const base = app.hIndex;
    if (result.blocks.length) {
      busy = overlay();
      w.app.copyAction = null; // a module added from a text of the post would go after that text
      try {
        if (!column) column = await newColumn(w);
        const items = () => [...column.querySelectorAll(':scope > .ba-item')].map((el) => el.id);
        const known = new Set(items());
        for (const block of result.blocks) {
          busy.textContent = say(cfg.texts.working, done + 1, result.blocks.length);
          const plugin = block.type === 'headline' ? 'headline' : 'text';
          let id;
          if (!w.app.pluginLoader) {
            // Gridbox loads the module that adds modules with the first one it adds: let it, then close the panel it opens
            w.app.edit = column.id;
            w.app.checkModule('loadPlugin', { data: plugin, selector: 0 });
            id = await until(() => items().find((i) => !known.has(i)), 15000);
            await sleep(500);
            closePanels();
          } else {
            id = await w.app.pluginLoader.load(w.$g(column), plugin, 0);
          }
          known.add(id);
          await fill(w, id, block);
          done++;
        }
      } catch (e) {
        notice(cfg.texts.fail, true);
      } finally {
        busy.remove();
        busy = null;
      }
    }
    if (mode !== 'app' && (done || !result.blocks.length)) fillFields();

    const said = [
      filled.length ? say(cfg.texts.filled, filled.join(', ')) : '',
      kept.length ? say(cfg.texts.kept, kept.join(', ')) : '',
      failed.length ? say(cfg.texts.failed, failed.join(', ')) : '',
    ].filter(Boolean).join(' ');
    restoreBar(changes);
    if (!done) {
      if (!result.blocks.length) notice(said || cfg.texts.empty, !filled.length);
      else if (said) notice(said, !filled.length);

      return;
    }
    // as Gridbox's own delete of a module does it
    old.forEach((el) => { delete w.app.items[el.id]; el.remove(); });
    try { app.setRowWithIntro(); } catch (e) { /* only the intro of a post */ }
    if (typeof w.app.setMarginBox === 'function') w.app.setMarginBox();
    if (app.pageStructure && app.pageStructure.visible) app.pageStructure.updateStructure(true);
    // the steps Gridbox made for the section and the first module are part of the one step of the import
    if (Array.isArray(app.history) && app.hIndex > base) { app.history.length = base; app.hIndex = base; }
    app.addHistory();
    const headlines = result.blocks.filter((b) => b.type === 'headline').length;
    notice(say(mode === 'app' ? cfg.texts.rest : cfg.texts.done, headlines, done - headlines) + (said ? ' ' + said : ''));
  };

  input.addEventListener('change', () => {
    const file = input.files && input.files[0];
    input.value = '';
    if (file) run(file);
  });

  // the button: in a post next to Gridbox's Advanced Editor switch, in a page or an item in the editor's left bar
  const style = '.mt-md-import{display:inline-flex;align-items:center;gap:6px;height:32px;margin:0 18px 0 0;padding:0 12px;border:1px solid #c9cad1;'
    + 'border-radius:16px;background:#fff;color:#55565e;font:500 12px Roboto,Arial,sans-serif;letter-spacing:.3px;cursor:pointer;transition:all .2s}'
    + '.mt-md-import:hover,.mt-md-import:focus-visible{border-color:#1da6f4;color:#1da6f4;outline:0}'
    + '.mt-md-import i{font-size:18px;line-height:1}';
  const open = (e) => { e.preventDefault(); e.stopPropagation(); if (!busy) input.click(); };
  const button = () => {
    try {
      const doc = document.querySelector('iframe.editor-iframe')?.contentDocument;
      const bar = doc && doc.querySelector('.blog-post-editor-header-panel .post-editor-right-icons');
      if (bar && !bar.querySelector('.mt-md-import')) {
        if (!doc.getElementById('mt-md-import-style')) {
          const css = doc.createElement('style');
          css.id = 'mt-md-import-style';
          css.textContent = style;
          doc.head.append(css);
        }
        const b = doc.createElement('button');
        b.type = 'button';
        b.className = 'mt-md-import';
        b.title = cfg.texts.button;
        b.setAttribute('aria-label', cfg.texts.button);
        b.innerHTML = '<i class="zmdi zmdi-file-text"></i><span>MD</span>';
        b.addEventListener('click', open);
        bar.prepend(b);
      }
    } catch (e) {
      // the editor is not there yet
    }
    const icons = document.querySelector('.ba-sidebar .top-icons');
    if (icons && !icons.querySelector('.mt-md-import-side')) {
      // Gridbox dims the icons of the bar and takes their clicks away in the form of an item with fields: not this one
      const css = document.createElement('style');
      css.textContent = '.com_gridbox .top-icons > span.mt-md-import-side{pointer-events:auto;opacity:1}';
      document.head.append(css);
      const span = document.createElement('span');
      span.className = 'mt-md-import-side';
      span.innerHTML = '<a href="#" role="button"><span class="zmdi zmdi-file-text"></span></a><span class="ba-tooltip ba-right"></span>';
      span.querySelector('a').setAttribute('aria-label', cfg.texts.side);
      span.querySelector('.ba-tooltip').textContent = cfg.texts.side;
      span.querySelector('a').addEventListener('click', open);
      // with the icons that add content (Library ends their group with a line)
      const library = icons.querySelector('.add-library-block');
      if (library) library.before(span); else icons.append(span);
    }
  };
  setInterval(button, 1000);
})();
