// The Markdown parser of media/js/mertools-mdimport.js: the document as the headlines and texts of a
// post, the Markdown as formatting, and nothing of the document that could run in the page.
// Run: node tests/mdimport.test.js
'use strict';
const path = require('path');
const { parse, amount, fieldUrl, filePath, safeText, safeHtml } = require(path.join(__dirname, '../plg_system_mertools/media/js/mertools-mdimport.js'));

let failed = 0;
const check = (label, got, expected) => {
  const ok = JSON.stringify(got) === JSON.stringify(expected);
  if (!ok) failed++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${label.padEnd(70)}${ok ? '' : `\n   got=${JSON.stringify(got)}\n   exp=${JSON.stringify(expected)}`}`);
};
const html = (md) => parse(md, { firstTitle: false }).blocks.map((b) => (b.type === 'text' ? b.html : `[h${b.level}:${b.text}]`)).join('|');
const inline = (md) => html(md).replace(/^<p>|<\/p>$/g, '');

// ---- the split into modules
// a headline of the page named as a field, and the field's section after it (as the export writes them): the last is the field
const twice = parse('Tytuł: P\nSKU: X\n\n## Dane techniczne\n\nTreść strony\n\n## Dane techniczne\n\npole', { field: (l) => /dane techniczne|sku/i.test(l) });
check('of two headings named as a field the last is the field, the first a headline of the page',
  [twice.sections.map((x) => x.html), twice.blocks], [['<p>pole</p>'], [{ type: 'headline', level: 2, text: 'Dane techniczne' }, { type: 'text', html: '<p>Treść strony</p>' }]]);
check('an amount as the export writes it is read back the same', [amount('1.2340'), amount('1.234')], ['1.2340', '']);
let r = parse('# Title\n\nIntro.\n\n## One\n\nText one.\n\nMore.\n\n### Two\n\n- a\n- b\n');
check('first # heading is the title, not a module', r.title, 'Title');
check('headlines and the texts between them', r.blocks, [
  { type: 'text', html: '<p>Intro.</p>' },
  { type: 'headline', level: 2, text: 'One' },
  { type: 'text', html: '<p>Text one.</p><p>More.</p>' },
  { type: 'headline', level: 3, text: 'Two' },
  { type: 'text', html: '<ul><li>a</li><li>b</li></ul>' },
]);
r = parse('Text first\n\n# Late\n\nMore');
check('a # heading after a text is no title, a headline of level 2', [r.title, r.blocks[1]], ['', { type: 'headline', level: 2, text: 'Late' }]);
r = parse('# Title\n\nA\n\n# Part two\n\nB');
check('a second # heading is a headline of level 2', r.blocks[1], { type: 'headline', level: 2, text: 'Part two' });
r = parse('---\ntitle: "From the front matter"\nauthor: x\n---\n\n## Section\n\nBody\n');
check('front matter: title taken, block removed', [r.title, r.blocks.length, r.blocks[0].text], ['From the front matter', 2, 'Section']);
r = parse('---\ntitle: Front\nslug: /blog/my-post.html\ndescription: "The lead."\nkeywords: a, b\n---\n# Heading\n\nBody');
check('front matter: title, alias, intro, keywords; a # heading other than the title is of level 2', [r.title, r.alias, r.intro, r.keywords, r.blocks],
  ['Front', 'my-post', 'The lead.', 'a, b', [{ type: 'headline', level: 2, text: 'Heading' }, { type: 'text', html: '<p>Body</p>' }]]);
check('setext headings (the first # one is the title)', [parse('Big\n===\n\nSmall\n---\n\ntext').title, html('Big\n===\n\nSmall\n---\n\ntext')], ['Big', '[h2:Small]|<p>text</p>']);
check('levels 2 to 6 stay, # at the top is the title', html('# a\n## b\n### c\n#### d\n##### e\n###### f'), '[h2:b]|[h3:c]|[h4:d]|[h5:e]|[h6:f]');
check('no h1 inside a text either (a quote)', html('x\n\n> # quoted'), '<p>x</p><blockquote><h2>quoted</h2></blockquote>');
check('#hashtag is no heading', html('#hashtag'), '<p>#hashtag</p>');
check('closing #s of a heading are dropped', html('## Title ##'), '[h2:Title]');
check('formatting in a heading is the text of the headline', html('## A **bold** and `code` [link](http://x.pl)'), '[h2:A bold and code link]');
check('an empty heading makes no module', html('##\n\ntext'), '<p>text</p>');
check('empty document', parse('').blocks, []);
check('only blank lines', parse('\n\n  \n').blocks, []);
check('CRLF and a BOM', html('﻿## A\r\n\r\nB\r\nC'), '[h2:A]|<p>B\nC</p>');
check('a heading in a code block is no heading', html('```\n# not\n```'), '<pre><code># not</code></pre>');

// ---- the fields of the post at the top of the document
const SAMPLE = '**Tytuł:** Szukasz, filtrujesz, zamawiasz. Co zmieniło się na merserwis.pl\n'
  + '**Adres (slug):** nowosci-na-stronie-wyszukiwarka-filtry-szybkosc\n'
  + '**Opis meta (do 155 znaków):** Nowa wyszukiwarka z podpowiedziami i głosem, filtry po parametrach technicznych. Sprawdź, co zmieniło się.\n'
  + '\n---\n\n# Szukasz, filtrujesz, zamawiasz. Co zmieniło się na merserwis.pl\n\nNasi klienci rzadko mają czas.\n\n## Wyszukiwarka\n\n- **Wybacza literówki.** tak\n';
r = parse(SAMPLE);
check('the section: title, alias, intro', [r.title, r.alias, r.intro], ['Szukasz, filtrujesz, zamawiasz. Co zmieniło się na merserwis.pl',
  'nowosci-na-stronie-wyszukiwarka-filtry-szybkosc', 'Nowa wyszukiwarka z podpowiedziami i głosem, filtry po parametrach technicznych. Sprawdź, co zmieniło się.']);
check('the section, its rule and the # heading that repeats the title are not in the post', r.blocks, [
  { type: 'text', html: '<p>Nasi klienci rzadko mają czas.</p>' },
  { type: 'headline', level: 2, text: 'Wyszukiwarka' },
  { type: 'text', html: '<ul><li><strong>Wybacza literówki.</strong> tak</li></ul>' },
]);
r = parse('Title: An English one\nSlug: https://x.pl/blog/an-english-one?x=1\nMeta description: *The* lead\nKeywords: a, b\n\nBody');
check('plain labels in English, an address as the alias, formatting out of the intro', [r.title, r.alias, r.intro, r.keywords, r.blocks.length],
  ['An English one', 'an-english-one', 'The lead', 'a, b', 1]);
r = parse('**Tytuł**: Jeden\n**Wstęp**: Krótko.\n\nTreść');
check('"**Label**: value" too', [r.title, r.intro, html('**Tytuł**: Jeden\n**Wstęp**: Krótko.\n\nTreść')], ['Jeden', 'Krótko.', '<p>Treść</p>']);
r = parse('Opis: tak zaczyna się zwykły akapit.\n\nDalej.');
check('one plain "Opis:" line is a paragraph, not the section', [r.intro, r.blocks[0].html], ['', '<p>Opis: tak zaczyna się zwykły akapit.</p><p>Dalej.</p>']);
r = parse('Opis: jeden wiersz\n\n---\n\nTekst');
check('…but with a rule under it, it is the section', [r.intro, html('Opis: jeden wiersz\n\n---\n\nTekst')], ['jeden wiersz', '<p>Tekst</p>']);
r = parse('**Autor:** Jan\n**Tytuł:** T\n\nTekst');
check('a label it does not know is left out with the section', [r.title, html('**Autor:** Jan\n**Tytuł:** T\n\nTekst')], ['T', '<p>Tekst</p>']);
r = parse('Uwaga: to ważne\nNastępny wiersz\n\nTekst');
check('a paragraph with a colon in its first line is text', [r.title, r.intro, r.blocks[0].html.startsWith('<p>Uwaga')], ['', '', true]);
r = parse('**Tytuł:** Inny niż nagłówek\n\n# Nagłówek\n\nTekst');
check('a # heading that is not the title named above it is a headline of level 2', r.blocks[0], { type: 'headline', level: 2, text: 'Nagłówek' });
check('only the section: no modules, the fields', [parse('**Tytuł:** T\n**Adres:** t').blocks, parse('**Tytuł:** T\n**Adres:** t').alias], [[], 't']);

check('a link line under the section stays in the post', html('**Title:** A\n\nhttps://example.com\n\nBody'), '<p><a href="https://example.com">https://example.com</a></p><p>Body</p>');
check('a reference definition under the section still works', html('**Tytuł:** A\n\n[r]: https://x.pl\n\n[link][r]'), '<p><a href="https://x.pl">link</a></p>');
check('a plain "Uwaga:" line under the section stays', [parse('**Tytuł:** A\nUwaga: ważne\n\nB').title, html('**Tytuł:** A\nUwaga: ważne\n\nB')], ['A', '<p>Uwaga: ważne</p><p>B</p>']);
check('blank lines between the lines of the section', [parse('**Tytuł:** A\n\n**Adres:** a\n\nTekst').alias, html('**Tytuł:** A\n\n**Adres:** a\n\nTekst')], ['a', '<p>Tekst</p>']);
r = parse('---\nTytuł: T\nWstęp: W\nSłowa kluczowe: k\nAdres: /blog/t\n---\nBody');
check('Polish keys of a front matter', [r.title, r.intro, r.keywords, r.alias], ['T', 'W', 'k', 't']);

// ---- inline formatting
check('bold with ** and __', inline('**a** and __b__'), '<strong>a</strong> and <strong>b</strong>');
check('italics with * and _', inline('*a* and _b_'), '<em>a</em> and <em>b</em>');
check('bold italics', inline('***a***'), '<em><strong>a</strong></em>');
check('nested', inline('**a *b* c**'), '<strong>a <em>b</em> c</strong>');
check('strike-through', inline('~~gone~~'), '<s>gone</s>');
check('snake_case_word is no italics', inline('snake_case_word'), 'snake_case_word');
check('a lone * stays', inline('2 * 3 = 6'), '2 * 3 = 6');
check('an unclosed ** stays', inline('**open'), '**open');
check('code span, no formatting inside', inline('`a **b** <i>`'), '<code>a **b** &lt;i&gt;</code>');
check('double backticks', inline('`` a ` b ``'), '<code>a ` b</code>');
check('escapes', inline('\\*not italics\\* \\# \\\\'), '*not italics* # \\');
check('link', inline('[text](https://a.pl/x?y=1&z=2 "T")'), '<a href="https://a.pl/x?y=1&amp;z=2" title="T">text</a>');
check('link with formatting in the label', inline('[**bold** link](/a)'), '<a href="/a"><strong>bold</strong> link</a>');
check('link with brackets in the address', inline('[w](https://pl.wikipedia.org/wiki/A_(b))'), '<a href="https://pl.wikipedia.org/wiki/A_(b)">w</a>');
check('image', inline('![alt text](img/a.png "Title")'), '<img src="img/a.png" alt="alt text" title="Title">');
check('image in a link', inline('[![a](i.png)](https://x.pl)'), '<a href="https://x.pl"><img src="i.png" alt="a"></a>');
check('autolink <url>', inline('<https://a.pl>'), '<a href="https://a.pl">https://a.pl</a>');
check('bare URL, the full stop is not part of it', inline('see https://a.pl/x.'), 'see <a href="https://a.pl/x">https://a.pl/x</a>.');
check('bare URL in brackets', inline('(https://a.pl/x)'), '(<a href="https://a.pl/x">https://a.pl/x</a>)');
check('reference link', html('[a][r] and [r]\n\n[r]: https://x.pl "T"'), '<p><a href="https://x.pl" title="T">a</a> and <a href="https://x.pl" title="T">r</a></p>');
check('soft and hard breaks', inline('a\nb  \nc\\\nd'), 'a\nb<br>c<br>d');
check('<br> of the document', inline('a<br>b'), 'a<br>b');
check('Polish letters', inline('**żółć** _gęślą_'), '<strong>żółć</strong> <em>gęślą</em>');
check('emphasis next to punctuation', inline('(**a**), „*b*”'), '(<strong>a</strong>), „<em>b</em>”');

// ---- blocks
check('nested lists', html('- a\n  - b\n    - c\n- d'), '<ul><li>a<ul><li>b<ul><li>c</li></ul></li></ul></li><li>d</li></ul>');
check('a sub-list indented by two under "1."', html('1. a\n  - b\n2. c'), '<ol><li>a<ul><li>b</li></ul></li><li>c</li></ol>');
check('ordered list from 3', html('3. a\n4. b'), '<ol start="3"><li>a</li><li>b</li></ol>');
check('a list after a paragraph with no blank line', html('Text:\n- a\n- b'), '<p>Text:</p><ul><li>a</li><li>b</li></ul>');
check('a list with blank lines between items is one list', html('- a\n\n- b\n\n- c'), '<ul><li>a</li><li>b</li><li>c</li></ul>');
check('an item of two paragraphs', html('- a\n\n  more\n- b'), '<ul><li><p>a</p><p>more</p></li><li>b</li></ul>');
check('a lazy line of an item', html('- a\ncontinued\n- b'), '<ul><li>a\ncontinued</li><li>b</li></ul>');
check('task list', html('- [ ] todo\n- [x] done'), '<ul><li>☐ todo</li><li>☑ done</li></ul>');
check('other bullets are another list', html('- a\n* b'), '<ul><li>a</li></ul><ul><li>b</li></ul>');
check('"2024. was" is a list, "2024 was" is not', [html('2024 was'), html('2024. was').startsWith('<ol')], ['<p>2024 was</p>', true]);
check('blockquote, lazy line, formatting', html('> a **b**\ncontinued\n>\n> c'), '<blockquote><p>a <strong>b</strong>\ncontinued</p><p>c</p></blockquote>');
check('a list in a quote', html('> - a\n> - b'), '<blockquote><ul><li>a</li><li>b</li></ul></blockquote>');
check('fenced code, language, no formatting inside', html('```js\nlet a = 1 < 2 && **b**;\n```'), '<pre><code class="language-js">let a = 1 &lt; 2 &amp;&amp; **b**;</code></pre>');
check('tilde fence, an unclosed fence runs to the end', html('~~~\na\n\nb'), '<pre><code>a\n\nb</code></pre>');
check('indented code', html('text\n\n    code <b>\n\n    more'), '<p>text</p><pre><code>code &lt;b&gt;\n\nmore</code></pre>');
check('horizontal rules', html('a\n\n---\n\n***\n\n___'), '<p>a</p><hr><hr><hr>');
check('a rule with a text under it is a heading', html('a\n---'), '[h2:a]');
check('HTML comment is dropped', html('a\n\n<!-- hidden\nmore -->\n\nb'), '<p>a</p><p>b</p>');
check('table with alignment', html('| A | B | C |\n|---|:-:|--:|\n| 1 | 2 | 3 |\n| x | y |'),
  '<table><thead><tr><th>A</th><th style="text-align: center;">B</th><th style="text-align: right;">C</th></tr></thead>'
  + '<tbody><tr><td>1</td><td style="text-align: center;">2</td><td style="text-align: right;">3</td></tr><tr><td>x</td><td style="text-align: center;">y</td><td style="text-align: right;"></td></tr></tbody></table>');
check('table with no outer pipes, formatting and an escaped pipe in a cell', html('a | b\n--|--\n**x** | 1 \\| 2').includes('<td><strong>x</strong></td><td>1 | 2</td>'), true);
check('a line with a pipe is no table without its delimiter row', html('a | b\nc'), '<p>a | b\nc</p>');
check('a text and a table in one module, a heading ends it', html('a\n\n| A |\n|---|\n| 1 |\n\n## H').startsWith('<p>a</p><table'), true);

// ---- nothing of the document runs in the page
check('<script> is shown as text', inline('<script>alert(1)</script>'), '&lt;script&gt;alert(1)&lt;/script&gt;');
check('<img onerror> is shown as text', inline('<img src=x onerror=alert(1)>'), '&lt;img src=x onerror=alert(1)&gt;');
check('javascript: link has no link', inline('[x](javascript:alert(1))'), 'x');
check('JaVaScRiPt: with a space has no link', inline('[x](JaVaScRiPt:alert(1))'), 'x');
check('data: link has no link', inline('[x](data:text/html;base64,AAAA)'), 'x');
check('javascript: image has no image', inline('![x](javascript:alert(1))'), 'x');
check('vbscript: reference link', html('[x][1]\n\n[1]: vbscript:msgbox'), '<p>x</p>');
check('a tab inside javascript: has no link', inline('[x](<java\tscript:alert(1)>)'), 'x');
check('a tab inside javascript: in an image has no image', inline('![x](<java\tscript:alert(1)>)'), 'x');
check('a control character before javascript: has no link', inline('[x](<\u0001javascript:alert(1)>)'), 'x');
check('a zero-width space inside javascript: has no link', inline('[x](<java\u200bscript:alert(1)>)'), 'x');
check('a tab in a harmless address is dropped', inline('[x](<https://a.pl/\tb>)'), '<a href="https://a.pl/b">x</a>');
check('[constructor], [toString], [hasOwnProperty] are no references', inline('[constructor] [toString] [hasOwnProperty]'), '[constructor] [toString] [hasOwnProperty]');
check('a reference named constructor still works when defined', html('[constructor]\n\n[constructor]: https://a.pl'), '<p><a href="https://a.pl">constructor</a></p>');
check('a quote in the address cannot leave the attribute', inline('[x](a"onmouseover="alert(1))'), '<a href="a&quot;onmouseover=&quot;alert(1)">x</a>');
check('a quote in the alt and the title', inline('![a"b](i.png "t\\"u")'), '<img src="i.png" alt="a&quot;b" title="t&quot;u">');
check('a tag in a heading is text', html('## <b>x</b>'), '[h2:<b>x</b>]');
check('a tag in a code block is text', html('```\n</pre><script>\n```'), '<pre><code>&lt;/pre&gt;&lt;script&gt;</code></pre>');
check('an email and a tel link are allowed', inline('[m](mailto:a@b.pl) [t](tel:+48123)'), '<a href="mailto:a@b.pl">m</a> <a href="tel:+48123">t</a>');
check('a relative link and an anchor are allowed', inline('[a](/blog/x) [b](#top) [c](../y.html)'), '<a href="/blog/x">a</a> <a href="#top">b</a> <a href="../y.html">c</a>');

// ---- a file that is made to be slow does not freeze it
const slow = (label, md) => {
  const t = Date.now();
  parse(md);
  const ms = Date.now() - t;
  check(`${label} (${ms} ms)`, ms < 3000, true);
};
slow('20000 [ in a row', '['.repeat(20000));
slow('20000 > in a row', '>'.repeat(20000));
slow('deep lists', Array.from({ length: 3000 }, (_, i) => ' '.repeat(i * 2) + '- a').join('\n'));
slow('20000 unmatched backticks of changing length', Array.from({ length: 200 }, (_, i) => '`'.repeat(i + 1) + ' a').join(' '));
slow('20000 * with spaces', '* '.repeat(20000));
slow('10000 *a', '*a '.repeat(10000));
slow('100000 closers with no opener (a* )', 'a* '.repeat(100000));
slow('100000 openers with no closer (**a )', '**a '.repeat(100000));
slow('a long document', Array.from({ length: 3000 }, (_, i) => `## Heading ${i}\n\nText **${i}** with [a link](https://x.pl/${i}) and \`code\`.\n\n- one\n- two\n`).join('\n'));

// ---- the fields of an app's item (a product): "Label: value" lines and sections under a heading named as a field
const LABELS = ['sku', 'cena', 'producent', 'cechy i funkcje', 'dane techniczne', 'wyposazenie', 'akcesoria'];
const field = (label) => LABELS.includes(label.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().trim());
const PRODUCT = '# Sonel MPI-516 Miernik\n\nSKU: SONE/MPI516\n**Cena:** 6 950,00 zł\nProducent: [Sonel](/sonel)\nOpis meta: Krótki opis.\n\n'
  + 'Wstęp produktu.\n\n## Cechy i funkcje\n\nPomiar **pętli**.\n\n### Pomiary\n\n- RCD\n\n## Dane techniczne\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\n'
  + '## Coś innego\n\nTekst.\n\n## Wyposażenie\n\n- Przewód\n';
r = parse(PRODUCT, { field });
check('product: title from the # heading, intro from Opis meta', [r.title, r.intro], ['Sonel MPI-516 Miernik', 'Krótki opis.']);
check('product: label lines of the fields, a link with its address', r.extra, [
  { label: 'SKU', value: 'SONE/MPI516', link: '' }, { label: 'Cena', value: '6 950,00 zł', link: '' }, { label: 'Producent', value: 'Sonel', link: '/sonel' }]);
check('product: sections named as fields, a sub-heading inside', r.sections.map((s) => [s.label, s.html]), [
  ['Cechy i funkcje', '<p>Pomiar <strong>pętli</strong>.</p><h3>Pomiary</h3><ul><li>RCD</li></ul>'],
  ['Dane techniczne', '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>'],
  ['Wyposażenie', '<ul><li>Przewód</li></ul>']]);
check('product: the text of a section', r.sections[0].text, 'Pomiar pętli.\nPomiary\nRCD');
check('product: what is in no field stays modules, in order', r.blocks, [
  { type: 'text', html: '<p>Wstęp produktu.</p>' }, { type: 'headline', level: 2, text: 'Coś innego' }, { type: 'text', html: '<p>Tekst.</p>' }]);
r = parse('---\ntitle: T\nsku: X-1\nproducent: "Fluke"\n---\n## Akcesoria\n\n- a\n', { field });
check('product: front matter keys of the fields', [r.title, r.extra.map((e) => e.label + '=' + e.value)], ['T', ['sku=X-1', 'producent=Fluke']]);
check('product: a section at the end of the document', r.sections, [{ label: 'Akcesoria', html: '<ul><li>a</li></ul>', text: 'a' }]);
r = parse('SKU: X\n\nText', {});
check('no fields given: "SKU: x" is text', [r.extra, r.blocks], [[], [{ type: 'text', html: '<p>SKU: X</p><p>Text</p>' }]]);
r = parse('Producent: https://sonel.pl\nSKU: <b>x</b>', { field });
check('a bare address is the link; HTML in a value is text', r.extra, [{ label: 'Producent', value: 'https://sonel.pl', link: 'https://sonel.pl' }, { label: 'SKU', value: '<b>x</b>', link: '' }]);
r = parse('Producent: [x](javascript:alert(1))\nSKU: 1', { field });
check('no javascript: link', r.extra[0].link, '');
r = parse('## Dane techniczne\n\nA\n\n## Dane techniczne\n\nB', { field });
check('a field named twice: the first section, the second a headline', [r.sections.length, r.blocks.map((b) => b.type)], [1, ['headline', 'text']]);

// ---- a field under "# Label" holds "## headings"; the next field begins its own section whatever its level
r = parse('# P\n\nSKU: 1\n\n# Cechy i funkcje\n\nA\n\n## Sekcja\n\nB\n\n### Pod\n\n| x |\n|---|\n| 1 |\n\n## Dane techniczne\n\nC\n\n## Coś innego\n\nD\n\n# Strona\n\nE', { field });
check('a field under #: its ## and ### headings and table in it, the next field\'s ## its own section',
  [r.sections.map((s) => [s.label, s.html]), r.blocks],
  [[['Cechy i funkcje', '<p>A</p><h2>Sekcja</h2><p>B</p><h3>Pod</h3><table><thead><tr><th>x</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>'],
    ['Dane techniczne', '<p>C</p>']],
  [{ type: 'headline', level: 2, text: 'Coś innego' }, { type: 'text', html: '<p>D</p>' }, { type: 'headline', level: 2, text: 'Strona' }, { type: 'text', html: '<p>E</p>' }]]);

// ---- the path a file field keeps
const ROOT = 'https://x.pl/';
check('file paths: from the site\'s root, no "/" before it', ['/images/a b.pdf', 'images/a.pdf', 'https://x.pl/images/a.pdf', 'HTTPS://X.PL/images/a.PDF'].map((u) => filePath(u, ROOT, 'pdf')),
  ['images/a%20b.pdf', 'images/a.pdf', 'images/a.pdf', 'images/a.PDF']);
check('file paths: a site in a folder', ['/sub/images/a.pdf', 'https://x.pl/sub/images/a.pdf', '/images/a.pdf'].map((u) => filePath(u, 'https://x.pl/sub/', 'pdf')), ['images/a.pdf', 'images/a.pdf', 'images/a.pdf']);
check('file paths refused: another site, a scheme, out of the folder, another type, a folder, a quote kept in', [
  'https://y.pl/a.pdf', '//y.pl/a.pdf', 'javascript:alert(1)//a.pdf', '/images/../configuration.php', '../a.pdf', '/images/a.exe', '/images/', ''].map((u) => filePath(u, ROOT, 'pdf, doc')).concat(filePath('/a".pdf', ROOT, '')),
  ['', '', '', '', '', '', '', '', 'a%22.pdf']);
check('file paths (review): ".." written as %2e, a folder of the site in another case, a full address out of its folder', [
  filePath('%2e%2e/private.pdf', 'https://x.pl/sub/', 'pdf'), filePath('/sub/images/%2E./a.pdf', 'https://x.pl/sub/', 'pdf'),
  filePath('https://x.pl/SUB/images/a.pdf', 'https://x.pl/sub/', 'pdf'), filePath('https://X.PL/sub/images/a.pdf', 'https://x.pl/sub/', 'pdf'),
  filePath('https://x.pl/images/a.pdf', 'https://x.pl/sub/', 'pdf'), filePath('https://x.pl', 'https://x.pl/', 'pdf')], ['', '', '', 'images/a.pdf', '', '']);

// ---- review: amounts, addresses a field keeps, one line of a field
check('amounts', ['6 950,00 zł', '1234.5', '0.125', '0,125', '1.234.567', '1.234,50', '1,234.50', '12,3456', '-0.5', ',5', '6950'].map(amount),
  ['6950.00', '1234.5', '0.125', '0.125', '1234567', '1234.50', '1234.50', '12.3456', '-0.5', '0.5', '6950']);
check('amounts with words around them', ['ok. 100 zł', 'Cena: 1 234,50 PLN netto', '€ 12.5', '99,99 zł/szt.', 'PLN 1.234.567,89'].map(amount), ['100', '1234.50', '12.5', '99.99', '1234567.89']);
check('no amount: other digits around it, or spaces not grouping thousands', ['100 zł + 23% VAT', '1e3', '12 34', '100 zł (2 szt.)', '1 2345'].map(amount), ['', '', '', '', '']);
check('addresses with character references refused', ['javascript&colon;alert(1)', 'java&#115;cript:x', '/a&quot/onmouseover=alert(1)', '/x?a=1&b=2', '/x?a=1&'].map(fieldUrl), ['', '', '', '/x?a=1&b=2', '/x?a=1&']);
check('a text for a field: no tag, no end of an attribute, no entity', safeText('x" onmouseover="a" <img src=x onerror=b> &lt;i&gt; R&D &amp;'), 'x” onmouseover=”a” ‹img src=x onerror=b› ＆lt;i＆gt; R&D ＆amp;');
check('HTML for a text editor field: the document\'s HTML stays text after CKEditor reads it', safeHtml('<p>a &lt;img onerror=x&gt; &amp;lt;</p>'), '<p>a ‹img onerror=x› &amp;lt;</p>');
check('no amount: ambiguous or not a number', ['6.950', '6,950', '1.2.3', '1,234,5.6', 'abc', '', '1.23.456,7'].map(amount), ['', '', '', '', '', '', '']);
check('addresses a field keeps', ['javascript:alert(1)', ' JaVa\tscript:x', 'data:text/html,x', '/a"onmouseover="alert(1)', 'index.php?option=com_gridbox&id=1', 'https://x.pl/a b', "/a'b<c>"].map(fieldUrl),
  ['', '', '', '/a%22onmouseover=%22alert(1)', 'index.php?option=com_gridbox&id=1', 'https://x.pl/a%20b', '/a%27b%3Cc%3E']);
r = parse('Producent: [Wiki](https://pl.wikipedia.org/wiki/A_(b))\nSKU: 1', { field });
check('a link with brackets in its address', r.extra[0], { label: 'Producent', value: 'Wiki', link: 'https://pl.wikipedia.org/wiki/A_(b)' });
r = parse('Producent: [x](/a"onmouseover="alert(1))\nSKU: 1', { field });
check('a quote in an address cannot leave the attribute', r.extra[0].link, '/a%22onmouseover=%22alert(1)');
r = parse('Producent: [<img src=x onerror=alert(1)>](/sonel)\nSKU: 1', { field });
check('HTML in a label is text of the value (the form escapes it for Gridbox)', [r.extra[0].value, r.extra[0].link], ['<img src=x onerror=alert(1)>', '/sonel']);
r = parse('# Produkt\n\nSKU: X-1\n\nTekst.', { field });
check('one line of a field of the form under the title', [r.extra, r.blocks], [[{ label: 'SKU', value: 'X-1', link: '' }], [{ type: 'text', html: '<p>Tekst.</p>' }]]);
r = parse('Opis: to jest zwykły akapit.\n\nTekst.', { field });
check('one line of the page\'s own fields is still text', [r.intro, r.blocks.length], ['', 1]);

// ---- a page: nothing else shows the title, so its # heading stays as the h1
r = parse('# Usługi\n\nText\n\n# Second', { keepTitle: true });
check('page: the title is also a headline h1, a second # is h2', [r.title, r.blocks.map((b) => b.type + (b.level || ''))], ['Usługi', ['headline1', 'text', 'headline2']]);
r = parse('# Usługi\n\nText', { keepTitle: 2 });
check('page with an h1 already: the title a headline h2', r.blocks.map((b) => b.type + (b.level || '')), ['headline2', 'text']);
r = parse('Tytuł: Usługi\nAdres: uslugi\n\nLead\n\n# Usługi\n\nText', { keepTitle: true });
check('page: the # heading that repeats the title after a text is the h1, after that text', r.blocks.map((b) => b.type + (b.level || '')), ['text', 'headline1', 'text']);

console.log(failed ? `${failed} FAILED` : 'ALL PASS');
process.exit(failed ? 1 : 0);
