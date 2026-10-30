// The Markdown parser of media/js/mertools-mdimport.js: the document as the headlines and texts of a
// post, the Markdown as formatting, and nothing of the document that could run in the page.
// Run: node tests/mdimport.test.js
'use strict';
const path = require('path');
const { parse } = require(path.join(__dirname, '../plg_system_mertools/media/js/mertools-mdimport.js'));

let failed = 0;
const check = (label, got, expected) => {
  const ok = JSON.stringify(got) === JSON.stringify(expected);
  if (!ok) failed++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${label.padEnd(70)}${ok ? '' : `\n   got=${JSON.stringify(got)}\n   exp=${JSON.stringify(expected)}`}`);
};
const html = (md) => parse(md, { firstTitle: false }).blocks.map((b) => (b.type === 'text' ? b.html : `[h${b.level}:${b.text}]`)).join('|');
const inline = (md) => html(md).replace(/^<p>|<\/p>$/g, '');

// ---- the split into modules
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
  '<table style="border-collapse: collapse; width: 100%;" border="1" cellpadding="6"><thead><tr><th>A</th><th style="text-align: center;">B</th><th style="text-align: right;">C</th></tr></thead>'
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

console.log(failed ? `${failed} FAILED` : 'ALL PASS');
process.exit(failed ? 1 : 0);
