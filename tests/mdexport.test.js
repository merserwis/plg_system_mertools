// The Markdown export of media/js/mertools-mdimport.js (toMarkdown): the HTML of a Text module or of a field made
// into Markdown that the import (parse) reads back as the same HTML. Runs in Chromium (a DOM, no Joomla).
// Run: node tests/mdexport.test.js
'use strict';
const fs = require('fs');
const path = require('path');
const { chromium } = require(process.env.PLAYWRIGHT || '/home/administrator2/.npm/_npx/705bc6b22212b352/node_modules/playwright-core');

const SCRIPT = fs.readFileSync(path.join(__dirname, '../plg_system_mertools/media/js/mertools-mdimport.js'), 'utf8');

let failed = 0;
const check = (label, got, expected) => {
  const ok = JSON.stringify(got) === JSON.stringify(expected);
  if (!ok) failed++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${label.padEnd(70)}${ok ? '' : `\n   got=${JSON.stringify(got)}\n   exp=${JSON.stringify(expected)}`}`);
};

(async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.setContent('<!DOCTYPE html><meta charset="utf-8"><body></body>');
    await page.addScriptTag({ content: SCRIPT });
    // the Markdown of some HTML, and what the import makes of that Markdown
    const md = (html, options) => page.evaluate(([h, o]) => {
      const div = document.createElement('div');
      div.innerHTML = h;
      return window.MerToolsMarkdown.toMarkdown(div, o || {});
    }, [html, options]);
    const back = (markdown) => page.evaluate((m) => window.MerToolsMarkdown.parse(m, {}).blocks
      .map((b) => (b.type === 'text' ? b.html : `[h${b.level}:${b.text}]`)).join('|'), markdown);
    const round = async (label, html, expected = html) => check('round trip: ' + label, await back(await md(html)), expected);

    await round('paragraphs', '<p>One.</p><p>Two.</p>');
    await round('bold, italics, strike, code', '<p><strong>B</strong> <em>i</em> <s>s</s> <code>c</code></p>');
    await round('b and i as strong and em', '<p><b>B</b> <i>i</i></p>', '<p><strong>B</strong> <em>i</em></p>');
    await round('a link with its title', '<p>See <a href="/sonel" title="Sonel">the maker</a>.</p>');
    await round('an address with spaces and brackets', '<p><a href="/a b(1).pdf">file</a></p>', '<p><a href="/a%20b(1).pdf">file</a></p>');
    await round('an image', '<p><img src="/images/a.jpg" alt="A photo"></p>');
    await round('a line break', '<p>a<br>b</p>');
    await round('lists, nested', '<ul><li>a</li><li>b<ol><li>c</li><li>d</li></ol></li></ul>');
    await round('a quote', '<blockquote><p>Q</p></blockquote>');
    await round('a code block, its < kept', '<pre><code>x &lt;b&gt;\ny</code></pre>');
    await round('a rule', '<p>a</p><hr><p>b</p>');
    await round('a table', '<table><thead><tr><th>Name</th><th>Value</th></tr></thead><tbody><tr><td>U</td><td>230 V</td></tr></tbody></table>');
    await round('Markdown characters in the text stay text', '<p>2 * 3 = 6, a_b_c, [x], `y`, ~z~, a | b</p>');
    await round('a line beginning as a heading, a list, a quote, a number', '<p># one</p><p>- two</p><p>&gt; three</p><p>4. four</p><p>+ five</p>');
    await round('a comment and a <br> written as text stay text', '<p>a &lt;!-- ważne --&gt; b &lt;br&gt; c</p>');
    await round('HTML in the text stays text', '<p>&lt;script&gt;x&lt;/script&gt; &amp;amp;</p>');
    await round('Gridbox\'s spans and styles: their text only', '<p><span style="color:red">red</span> and <u>under</u></p>', '<p>red and under</p>');
    await round('a heading inside a text', '<p>a</p><h3>Sub</h3><p>b</p>', '<p>a</p>|[h3:Sub]|<p>b</p>');
    await round('a div of Gridbox around paragraphs', '<div><p>a</p><div><p>b</p></div></div>', '<p>a</p><p>b</p>');
    await round('a bare address with _ and * stays the same address', '<p>see <a href="https://example.com/a_b*c">https://example.com/a_b*c</a> and a_b</p>');
    await round('an address in the text of another link stays text', '<p><a href="/x">see https://a.pl/q_r</a></p>');
    await round('a paragraph of dashes stays text, not a rule', '<p>---</p><p>- - -</p>');
    await round('code with fences of both kinds in it', '<pre><code>```\n~~~~\nważna treść\n````</code></pre>');
    await round('a link whose text is its address ending in "_"', '<p><a href="https://example.com/download?token=Ab_">https://example.com/download?token=Ab_</a></p>');
    await round('a heading ending in " #" keeps it', '<p>a</p><h2>Wynik #</h2><p>b</p>', '<p>a</p>|[h2:Wynik #]|<p>b</p>');
    await round('a heading "C#" as it is', '<p>a</p><h3>C#</h3>', '<p>a</p>|[h3:C#]');
    await round('code with two backticks in it', '<p><code>a``b</code></p>');
    await round('code beginning and ending with a backtick', '<p><code>`x`</code></p>');
    check('headings at least of minLevel', await md('<h1>A</h1><h2>B</h2><h4>C</h4>', { minLevel: 3 }), '### A\n\n### B\n\n#### C');
    check('nothing of a script, a style or a frame', await md('<p>a<script>alert(1)</script><style>p{}</style><iframe src="x"></iframe></p>'), 'a');
    check('empty paragraphs left out', await md('<p> </p><p>a</p><p><br></p>'), 'a');
  } finally {
    await browser.close();
  }
  console.log(failed ? `\n${failed} FAILED` : '\nALL PASS');
  process.exit(failed ? 1 : 0);
})();
