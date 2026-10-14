# MerTools for Gridbox

> Version 1.0.0 was the first stable release, after a full code audit (security, correctness, speed). Report problems in the [issues](https://github.com/merserwis/plg_system_mertools/issues).

A Joomla system plugin with fixes and optimisations for **Balbooa Gridbox** that cannot be done from Gridbox's own settings. Each fix is a separate tool that can be turned on or off. **The Gridbox core files are never changed**, so Gridbox updates install cleanly.

Built for the Merserwis shop (Joomla 6, Balbooa Gridbox, PHP 8.5), in the same style as [Better Search for Gridbox](https://github.com/merserwis/plg_system_bettersearch).

## Tools

Every option in the settings has a **?** beside its name with an explanation of what it does (hover, keyboard focus or click).

### 🔗 Canonical URL — collapse duplicate slashes

Gridbox serves a page under any number of slashes in the address:

```
/oferta
//oferta
//////oferta
/oferta/mierniki////mierniki-instalacji-elektrycznych
```

All of these return the same page with **status 200**, so search engines see several addresses for one page (**duplicate content**) and the link equity is split across them.

MerTools redirects every such address to the one clean address with single slashes, e.g. `//////oferta/mierniki` → `/oferta/mierniki`, with a **301** (permanent, recommended) or **302** (temporary) redirect.

- Only the **path** is normalized; the query string after `?` is left exactly as it is (a `//` there can be a real value, e.g. `?return=https://…`).
- Percent-encoded slashes (`%2F`) are part of a segment value and are not touched.
- The address always stays on the same host, so this can never redirect off-site.
- Runs in `onAfterInitialise`, before routing, so Gridbox's own routing is untouched.

**Settings:** tab *Canonical URL* — on/off and the redirect type.

#### If it still opens with duplicate slashes and does not redirect (Cloudflare / CDN)

If the addresses keep opening with duplicate slashes (status 200, no redirect) even with the tool on, the site is behind **Cloudflare** (or another CDN/proxy) that **cleans the address before it reaches the server** — it merges the extra slashes itself, so this plugin never sees them and cannot redirect them. The redirect then has to be set at Cloudflare, the only place that still knows the original address. It is a one-time setup and needs no paid plan (no regular expressions are used):

1. Log in to `dash.cloudflare.com` and choose the domain.
2. Open **Rules → Redirect Rules** and click **Create rule**.
3. Rule name, e.g. *Collapse duplicate slashes*.
4. Under **When incoming requests match**, switch to **Edit expression** and paste:
   ```
   (raw.http.request.uri ne http.request.uri and (http.request.method eq "GET" or http.request.method eq "HEAD"))
   ```
5. Under **Then**, set **Type = Dynamic** and the **URL expression**:
   ```
   concat("https://", http.host, http.request.uri)
   ```
6. **Status code 301**, **Preserve query string = off**.
7. Click **Deploy**.
8. If it still does not redirect, open **Rules → Settings → URL Normalization** and turn on *Normalize incoming URLs*.

Test by opening `https://your-site/////oferta` — it should return a **301** to the single-slash address. Leave this plugin's tool on as well: it still handles any request that reaches the server directly (not through the CDN).

How it works: `raw.http.request.uri` is the original address the visitor sent; `http.request.uri` is the address after Cloudflare's normalization (single slashes). When they differ, the rule sends a 301 to the clean one. A clean address has nothing to normalize, so the two are equal and no redirect loop happens.

### 🛒 Shop — links to a product option

A link to a chosen product option or set (Gridbox adds it to the address, e.g. `?Zestawy+Metrel+MI+3155=…`) opens the product with that option selected, with its price, SKU and images, so a customer can be sent a link to a specific set. Without it, Gridbox opened such links with nothing selected whenever the option group name had a space, a dot or a square bracket, because PHP renames those parameters, or when the option name had a symbol written as HTML code (e.g. a red dot `&#128308;`), because Joomla's input filter decodes it. Server side, Gridbox pages only, nothing in the Gridbox files is changed. Works with every kind of Gridbox option (drop-down list, tags, colours, images, radio buttons) and with several option groups in one product. On by default, tab *Shop*.

### 🏷 Shop — discount % in the product editor

Gridbox works out the discount badge from the regular and the sale price, but the sale price itself has to be calculated by hand. MerTools adds **Discount %** under *Sale price* in the Gridbox editor of a product (a small line inside the field, Gridbox's layout unchanged; a long currency such as “zł netto” is also shown on one line): typing e.g. `10` sets the sale price to the regular price − 10 % (rounded to the store's decimals) and the sale price of **every version/set from its own price**; `0` or an empty field removes the sale price. A sale price typed by hand shows its discount in the field; once a percentage was typed, a changed regular price recomputes the sale price. Gridbox saves only the prices, as always. In the Gridbox lists, `10%` typed as the sale price does the same for the product. On by default, tab *Shop*.

### 🧹 Shop — tidy up old carts

Gridbox never removes a cart, so its cart tables only grow. **Clean and optimise** (tab *Shop*) removes the carts nobody can open any more — empty carts at once, carts with products unused for N days (default 30, at least 8, since Gridbox's cart cookie lives 7 days) — and rebuilds the tables so the database shrinks; **Check** shows what would go. Orders and their data, carts with attached files and carts in use are never touched. Can also run automatically once a day or above a cart limit (default: by hand only).

### 📋 Gridbox lists — your own columns and prices edited in the list

**Columns:** the button **Columns** above the lists of products, posts and pages in the Gridbox panel shows, hides and orders Gridbox's columns (the tick box and the title always stay) and adds others: sale price, minimum quantity, **product badges** and **versions/sets** (yes/no) for products, publishing date and end, and the fields of the app — e.g. *Producent* with the names of its options (fields without a name and those listed in *Hidden app fields* are not offered). Each user arranges each list for themselves; the choice is kept on the server (Joomla user profile), **As in Gridbox** brings Gridbox's columns back. The page is rearranged on the server, so the list does not flicker, and Gridbox's own functions (selection, context menu, featured, sorting, drag-ordering) work as before.

**Editing in the list:** a click on a product's **price, sale price, stock or SKU** turns the cell into a field (a click on its **badges** opens the list of the store's badges to tick) — Enter (or leaving it) saves, Esc cancels. Amounts may be typed as `1234,50`, `1 234,50` or `1234.5`; an empty sale price removes the sale. The change is checked on the server, noted in the edit history and the kept pages are emptied. A product open in the Gridbox editor cannot be changed in the list (the editor's next save would put the old value back), and a product with options shows ⓘ — the list changes its base price, the prices of the options stay in the editor. For users who may edit in Gridbox; both can be switched off on the tab *Gridbox lists*.

**Items from other folders:** Gridbox lists a product (or post) only in its main folder, so a folder whose products were only added to it from other folders looked empty. Each folder now also lists them, after its own items and greyed out, under *Also in this folder, edited in their main folder: N*: no tick box, no editing in the list, no row menu, and the title says **Main folder: …** with a link there. The list's search and filters apply to them; on a list of more pages they are on the last one. Setting *Items from other folders* (on by default).

**“<” in introductions:** with libxml older than 2.9.11 (PHP on some hosts) Gridbox loses the text of an introduction from a “<” that starts no tag (“<30 mK”, “< 0,5 Ω”) to the next “>”, the end of its card with it — the grid or list breaks. MerTools keeps such a “<” as `&lt;` (after saves in Gridbox, at installation, daily) and fixes the description meta tags; it is shown as “<” everywhere.

### 🔒 Edit lock — one person at a time in the editor of a page

Gridbox has no check-out: two people could open the same article, product or page in the Gridbox editor and the later save overwrote the other one's work. With MerTools whoever opens the editor first holds the page; anybody else who opens it sees a page saying who edits it, since when and their last activity, with **Try again**, **Back** and — by the setting — **Take over editing** (after a confirmation). In the Gridbox lists the page shows “🔒 Edited by … (since …)”.

* The open editor confirms the lock every 30 s and gives it back when it is closed; a lock its editor stopped confirming (a closed laptop, a crashed browser) is free after 2 minutes, and one whose holder did nothing in the editor after the idle time (default 30 minutes; the holder just continues if nobody took it).
* A save from an editor that no longer holds the lock is **refused on the server** (Gridbox shows “Not saved: this page is now being edited by …”), and that editor gets a red bar saying who took it over — unsaved changes stay in it to be copied. Nothing is overwritten.
* Taking over: everybody who may edit (default), administrators only, or nobody. On by default, tab *Edit lock*. Layouts, headers and other templates of the editor are locked the same way.

### 🕘 Edit history — who changed which page or product, and when

Gridbox keeps no record of who edited a page: its page versions have no author or date and it writes nothing to Joomla's User Actions Log. MerTools notes every save in the **Gridbox editor** and every action of the **Gridbox lists** by a logged-in user — publish, unpublish, featured, duplicate, move to another category, trash, restore, delete for good — with what really changed: title, status, category, address, publishing dates, access, language, image, content, intro text, meta texts, the app's fields (description, specifications, producer…), other categories, tags, and for products price, sale price, SKU, stock and options (long texts as “changed”, the others with the old and the new value). The page is read before the request and again after it, so a request that changed nothing leaves no entry (a save in the editor is noted as “saved without changes”).

* **In the Gridbox lists** (products, articles, pages, trash): under each title the date and the user of the last change; a click opens a window with the whole history of the page.
* **Tab *Edit history***: all changes, newest first, with filters by title or ID, user, kind of change and dates.
* **User Actions Log** (optional, on by default): every change also in Joomla's *Users → User Actions Log*, with its filters and CSV export.

Kept for 365 days by default (0 = for ever) in `#__mertools_history`. Changes made before MerTools 1.0.3 and imports from CSV files are not in the history. On by default, tab *Edit history*.

### 🗄 Database — indexes of Gridbox tables

Gridbox creates its tables with a primary key only, so each lookup of product data, categories, tags or cart products reads the whole table (on merserwis.pl a query of the search index took 6 s). MerTools creates 8 plain indexes (`idx_mt_…`) and keeps them: checked after Gridbox or MerTools is installed or updated and once a day, missing ones are created again; an index Gridbox adds on the same column counts as present. The tab shows the state of each index and has a **Check and create missing** button. No data is changed; uninstalling MerTools leaves the indexes in place.

### 📞 Phone numbers — click to call

Phone numbers written as plain text (contact rows, footers, product pages) become `tel:` links, so a tap on a phone dials them — no need to add links by hand in Gridbox. Polish numbers in the usual forms (`22 531 00 94`, `(22) 531-00-94`, `533 394 222`, with or without +48) and international numbers starting with `+` are found; links, buttons, forms, code and numbers that are not phones (NIP, REGON, KRS, bank accounts, serial numbers, prices, standards, fax numbers) are left alone. The links look like the text around them (or like the site's links), the country code is added for dialling from abroad (default +48), and areas can be excluded with a CSS selector. A small script does it in the browser when it is idle; the page content is not changed. On by default, tab *Phone numbers*.

### ⏱ Page speed — PageSpeed Insights panel with before/after

At the top of the tab, **Recommended settings** shows how Gridbox (Deferred Loading, page cache, lazy loading, image and CSS compression), Joomla (page cache plugin, platform specific caching), the server (PHP, GD with WebP, OPcache) and Cloudflare (Rocket Loader) are set on the site now, what MerTools works best with, why, and where to change it.

Tab *Page speed*: measure chosen pages with PageSpeed Insights (phone and desktop, median of 1–5 tests) and compare every result with a baseline taken before the changes — score and LCP, TBT, CLS, FCP, Speed Index and server time, with the change in green or red; the server column is the time until Google’s test had the whole HTML (Lighthouse’s own server time can be far too low behind Cloudflare). The history keeps the MerTools version and the tools that were on, and the real-user figures of Chrome (CrUX) show how fast the site is for its visitors. Needs a free PageSpeed Insights API key.

The same tab has the **faster pages** tools, done on the finished page (Gridbox files untouched): the page shown at once with Gridbox’s Deferred Loading (its style sheets back in the head instead of a page hidden until all scripts have run), images with their proportions at once, the first section loaded at once and the rest lazily (no layout jumps, Gridbox’s sizing kept), **lighter WebP copies in the size of the screen** made by PHP after the page has been sent (`srcset`, also for slideshow backgrounds), the **first frame of a heavy animated GIF** at once and its animation later, **icon style sheets that do not hold up the page**, **timed Gridbox popups opened not before the first interaction**, the main product photo shown and fetched first, a YouTube section background started after the first interaction or a few seconds after loading (on phones left out, with a dark section colour, or replaced by a picture), Gridbox’s own page data requests kept, and — optionally — marketing scripts (Tag Manager, Facebook, Clarity…) started at the first interaction and a **page cache for guests**: the finished page is kept and the next guests get it at once (their own form token and nonce put in; never for logged-in users, carts, checkout or pages with parameters; emptied on every save, order or comment).

**Clear cache button in the top bar:** the administrator's top bar (next to the Joomla version) gets a **Clear cache** button. One click empties the pages kept by MerTools and the whole Joomla cache — as *System → Clear Cache* — from any screen of the administrator, and a message says what was removed. Shown to users who may clear the cache in Joomla; can be turned off in tab *Page speed*.

### 🧭 404 pages — redirect missing pages

A missing page (404) sends the visitor to a chosen page instead of the error page: the home page (default), a menu item or any address, with a permanent (301) or temporary (302) redirect. No more editing the template's `error.php` after every Gridbox update. Redirects set for single addresses in Joomla's Redirects component take precedence; form posts, AJAX/JSON requests and excluded paths keep the normal 404, and a missing target never loops. On by default, tab *404 pages*.

### 📱 Page layout — no sideways shift on phones

A Gridbox element that sticks out to the right (a row with a slide-in animation or a motion effect, an off-canvas menu) makes phones lay the page out wider than the screen. Gridbox hides that part on `<body>`, but the browser still widens the page, so everything fixed to the screen edges — the hamburger in a fixed header, the accessibility button, floating buttons — ends up off-screen. MerTools keeps the page as wide as the screen (`overflow-x: clip` on `html` and `body`; unlike `hidden` it creates no scroll container, so sticky elements and scrolling keep working). On by default, tab *Page layout*.


**Post sliders in several rows:** a Gridbox posts or products slider gets 2–4 rows when its *Class suffix* contains `mt-rows-2` … `mt-rows-4` (`mt-rows-tablet-N` / `mt-rows-phone-N` for smaller screens); the slider still moves column by column.
### 📱 Page layout — tabs as expandable sections on phones

On narrow screens a Gridbox tab bar has to be scrolled sideways and nobody sees the other tabs. Up to a chosen width (768 px by default) every tab becomes a row with its icon, name and an arrow, with its content opening below it; all closed at first (or the first one open). Sliders and carousels inside are measured again when a section opens. Gridbox's own tab is still selected, computers keep the tabs, and a turned tablet switches on the fly.

**Sliders inside the sections:** a Gridbox posts or products slider in a tab (e.g. *Promotions*, *Bestsellers*) shows one post at a time on a phone; a bar under it — previous, the position (*2 / 12*), next, and a hint that it can be swiped — shows that there are more. Or its posts one under another, or as Gridbox shows it.

### 🔗 Page layout — a link to a tab

Choosing a tab of a description writes its name in the address, without reloading the page: *Dane techniczne* → `…/product?dane_techniczne` (lower case, no accents, spaces as `_`). Whoever opens the copied address gets the page on that tab, scrolled to it below a fixed header — on phones with that section open. Closing the section on a phone takes the name out again; other parameters (e.g. a chosen product option) stay. The page cache of MerTools serves such an address as the plain page. On by default, tab *Page layout*, option *Link to a tab in the address*.

### 🌙 Dark mode

An elegant dark theme for the whole front-end, with a toggle button in the header. It is built from Gridbox's own CSS colour variables (which the theme sets on `html body`), so overriding them for a dark palette flips the whole site cleanly — nothing is inverted, so brand colours and images stay intact. The palettes are soft dark greys and blues, **never a harsh pure black**, so they are easy on the eyes.

- **Palettes:** Slate (default), Charcoal, Midnight, Warm, Sepia (dark brown paper, cream text), Dim, or Custom (every colour set by hand).
- **Intensity:** a slider 0–100 — softer (lighter greys) or deeper (never pure black); 50 is the palette as designed. A **live preview** in the settings shows the result while you change the palette, intensity, colours and accent.
- **Palette dots:** when the pointer is over the toggle button (or on a long press on phones), dots in the colours of the palettes slide out; a visitor picks e.g. Sepia instead of Slate and dark mode switches to it, remembered. Which palettes are offered is set in the settings.
- **Accent as in Gridbox:** keep the site's brand accent in dark mode, or choose another.
- **Buttons in the accent colour:** Gridbox's light and grey buttons no longer blend into the dark page — a button with a background takes the accent colour (with text that reads best on it) and an outlined one an accent outline; or every button an accent outline on a dark background; or as before. The search field gets an accent outline as well.
- **Default for new visitors:** Automatic (follows the visitor's system setting), Light or Dark. A remembered choice always wins, and the theme is applied before the first paint (no flash).
- **Adapted colours:** Gridbox writes many colours straight into section and element styles (text, links, headings, header and accordion backgrounds), so the variables alone do not reach them. Bright brand-coloured buttons are drawn as outlined dark buttons (or softened, or kept — an option). In dark mode the script darkens light backgrounds (also the light colours of gradients, and backgrounds that Gridbox adds later — lazy sections, the sticky header) and lightens text that is too dark for its background just enough for a 4.5:1 contrast, keeping its hue; readable brand colours and texts on vivid buttons stay as designed, and content loaded later is adapted too. The fixes are data attributes used only by the dark theme, so the light theme is unchanged. Option *Adapt the site colours* (on by default).
- **Logo for dark mode:** a second logo (light lettering) chosen in the media manager replaces the header logo in dark mode, also with Gridbox lazy loading; other logos via a CSS selector.
- **Toggle button:** at the end (or start) of the menu, in the colour of the menu links, in the size you type in pixels (24–96, default 44). On phones, where the menu is folded behind the hamburger, it moves next to the hamburger, or floats in the corner of the visible screen. A floating button can also be chosen: bottom right, bottom left, above or next to the site’s accessibility button, with the distances from the edges in pixels. The header selector can be changed for other templates. Phones can have their own position: next to the hamburger, floating, by the accessibility button, inside any element given by a CSS selector, or hidden — from a width you set (default 768 px).
- **Options:** smooth cross-fade on switch; optionally dim bright photos in dark mode.
- **Light on the page:** colours are read in batches, what is on screen first and the rest while the browser is idle, so scrolling never waits; a click switches the theme at once. The package ships minified scripts (8 KB gzipped).

How it works: the toggle sets `data-mertools-theme="dark"` on `<html>`; the plugin's CSS then redefines the Gridbox variables (`--bg-primary`, `--text`, `--title`, `--border`, …) for that state at a higher specificity (`html[data-mertools-theme="dark"] body`). The choice is kept in `localStorage`. The Gridbox core files are not touched.

**Settings:** tab *Dark mode*. Saving the settings, installing and updating empty Joomla's page cache (`administrator/cache/page`, or the configured cache path), so the change is visible at once. If the site is behind Cloudflare, its cache needs no purge — the script address changes with every version.

## Languages

English (default) and Polish. The settings follow the language of the Joomla administrator; for any other language — or a text a translation lacks — English is shown.

## Installation

1. Download `pkg_mertools-<version>.zip` from [Releases](https://github.com/merserwis/plg_system_mertools/releases).
2. Install it in Joomla (Extensions → Install). The package installs the system plugin and an administrator menu entry.
3. The plugin is enabled on a fresh install. Open its settings from **Components → MerTools for Gridbox** (the menu entry opens the plugin's settings page directly).

## Package structure

| Extension | What it is |
|---|---|
| `plg_system_mertools` | The system plugin — the tools and their settings. |
| `com_mertools` | A thin administrator component: the sidebar menu entry that opens the plugin settings. |

## Requirements

- Joomla 6 (tested with Joomla 6.1.4)
- Balbooa Gridbox
- PHP 8.2+ (tested with PHP 8.5.11)

## License

GNU General Public License version 3. Copyright © 2026 Merserwis.
