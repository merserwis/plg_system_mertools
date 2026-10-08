# Changelog

All changes of **MerTools for Gridbox**, newest first. **MerTools is in beta:** every version so far is a beta version — test each tool on your site before relying on it. Each version is also published as a [GitHub release](https://github.com/merserwis/plg_system_mertools/releases) with its installation package.

## 0.0.24 (Beta) — 2026-10-08

### 🛠 Fixed: page cache with a relative cache folder

- When Joomla's cache folder is set as a relative path (Global Configuration → *Path to Cache Folder*, e.g. `cache/` on merserwis.pl), the site kept its pages in `<site>/cache`, but the administrator looked in `administrator/cache`, and at the end of a request the working folder can be another one again. So the panel showed no pages, **Empty the page cache** and the emptying after saves in the administrator did not reach the kept pages, and Gridbox data (kept at the end of the request) was not kept at all. MerTools now always uses an absolute path: a relative one is taken from the site root, as the site sees it. Also the emptying of Joomla's own page cache after saving the MerTools settings now finds the right folder.
- Checked with `cache/` and with the default setting: the panel shows the site's pages and Gridbox data, an administrator save and the button empty them, Gridbox data is kept.

## 0.0.23 (Beta) — 2026-10-08

### ⚡ New: page cache for guests (tab *Page speed*, off by default)

- Gridbox builds every page anew on every visit: on merserwis.pl the browser waits 2–4 s for the first byte (real visitors, Chrome UX Report: 3.9 s on the home page), and only then can anything load. A guest gets the same page as any other guest, so the finished page is now kept in a file and the next guests get it at once (on the test site 15–20 ms instead of the full build).
- The visitor's own form token (`csrf.token` and token fields) and the CSP nonce are put into every page served from the cache, so forms and AJAX keep working.
- Never from the cache: logged-in users, anything but GET/HEAD, addresses with parameters (search, filters — campaign tags such as `utm_*`, `gclid`, `fbclid` do not count), pages with a message, the Markdown version of a page (AI Markdown), visitors with products in the cart, a wishlist, another currency, an order in progress or a comment author cookie, pages with a checkout, login, wishlist or submission form, answers other than 200 and answers that set a cookie MerTools does not know. More addresses and cookies can be excluded.
- Product pages with *Recently viewed products* are served from the cache only to visitors who have not viewed another product (the list would not be theirs); Gridbox's cookie is set as Gridbox sets it, and Gridbox's hit counters keep counting.
- Emptied on every save, delete, publish… by a logged-in user (Joomla or the Gridbox editor), on a new order, payment, comment or review, with the **Empty the page cache** button, and after the set time (default 240 minutes). The panel shows the pages kept and their size. A cached answer has the header `X-MerTools-Cache: HIT`.

### ⚡ New: Gridbox data from the cache (on by default)

- Before it shows any page, Gridbox's script loads the settings of the page's elements (`task=editor.getItems`) and its texts (`module=gridboxLanguage`) as blocking scripts — two more Joomla requests of 0.4–0.5 s each on merserwis.pl. Their addresses carry the time of the last change of the page, so they are now kept and sent at once. Works also without the page cache; only complete answers are kept.

### 📱 YouTube background on phones

- New setting *On phones* for the video background: **no video** (default), **a picture instead** or **the video as on computers**. Without the video a phone on merserwis.pl downloads about 1.1 MB less (the YouTube player and the video) and TBT drops; LCP does not change. The section gets a background colour (default dark) under its overlay, because the light colour behind a video would leave white text unreadable; it is set in the same frame in which Gridbox shows the page, so nothing flashes.
- A picture instead of the video (a chosen one, or the video's YouTube thumbnail) becomes the largest element on the screen, so Google counts LCP from it: in the test on merserwis.pl LCP got 1–2 s worse — hence not the default.
- "Phone" = a window up to 768 px (setting) or the browser's data saver on.

### ⏱ Speed panel: real server time

- The *Server* column showed Lighthouse's *server response time*, which behind Cloudflare can be far too low (merserwis.pl: 60–80 ms, while the HTML took 3.6 s). It now shows the time until Google's test had the whole HTML of the page. Measurements made before 0.0.23 show "–" there and are not compared on it.

## 0.0.22 (Beta) — 2026-10-08

### 🛠 Fixed: stretched images with *Images without waiting or jumping*

- With the option on, some images were stretched or too big: the logo and the Forbes / Gazele Biznesu badges in the header, pictures in the menu, blog lists and category pages. Gridbox sizes images by its 100×100 placeholder (its style `img[width="100"][height="100"]` lets them take their natural size within the layout), and 0.0.21 replaced it with the real size of the file, so that style no longer applied. Now the 100×100 stays, and the real proportions are added as `aspect-ratio`: the images look exactly as without the option, and the place for each image is still kept before it loads.
- Only the first two images of the header (the logo) load at once. The pictures in the menu (the mega menu and the phone menu) are left to Gridbox as before — the browser's own lazy loading never loaded them while the menu was closed.
- Checked on merserwis.pl (home, category, product, blog list, article; computer and phone, with the menu open): every image has the same size as without the option.

## 0.0.21 (Beta) — 2026-10-08

### 🚀 New tools: faster pages (tab *Page speed*)

Done on the finished page after Gridbox has made it; the Gridbox files are not changed. Each tool can be switched off, and the speed panel shows what it brought.

- **Images without waiting or jumping** (on by default): Gridbox’s lazy loading gives every image a 100×100 placeholder and loads the real one by script — also the logo and the images at the top, so the page jumps as they arrive and the top waits for the script. Now the site’s images get their real address and their real size (read from the file, cached) at once; images further down use the browser’s own lazy loading, the first images of the header load at once, and the header backgrounds show at once. Tracking pixels and images of other sites are left alone.
- **Main product photo at once** (on by default): the first picture of the product slideshow is shown at once (Gridbox hides it until its script runs) and fetched first — it is the element Google measures LCP by on product pages.
- **YouTube background after the page has loaded** (on by default): a section background video still starts by itself, at the first mouse move, scroll or touch, or a set number of seconds (default 3) after loading — the page no longer waits for the YouTube player (about 1 MB of scripts and several MB of video on the merserwis.pl home page).
- **Marketing scripts at the first interaction** (off by default): Tag Manager, Analytics, Google Ads, the Facebook pixel, Clarity, Elfsight, Cloudflare Insights and any other scripts listed start at the first mouse move, scroll, touch or key press. The cookie consent script is never delayed. Optionally also after a number of seconds.

## 0.0.20 (Beta) — 2026-10-08

### ⏱ New tool: Page speed panel (tab *Page speed*)

- Measures chosen pages with Google PageSpeed Insights, on phone and desktop, straight from the plugin settings (**Measure all pages**, or one page with **Measure**). Each page can be tested 1, 3 or 5 times; the median is kept, because the score varies by a few points between tests.
- The first measurement of a page is its **baseline** (the state before the changes). Every later one is shown next to it with the change in score and in each metric (LCP, TBT, CLS, FCP, Speed Index, server time), in green or red. Small changes within the usual noise are not shown. Any measurement can be made the baseline.
- The **history** of each page keeps the MerTools version and the tools that were on at each measurement, so it shows what a change brought, plus the biggest opportunities of the latest test.
- **Real users:** the figures of the Chrome UX Report for the whole site (TTFB, FCP, LCP, INP, CLS of the last 28 days), which tell how fast the site is for its visitors — Google's lab test runs from its own servers, usually in the USA.
- Needs a free PageSpeed Insights API key from Google Cloud Console (without one Google often refuses: the shared quota is used up). The test runs in the administrator's browser, so the PHP time limit of the host does not matter. Up to 10 pages; full addresses of other sites can be added to compare.
- Uninstalling MerTools removes the table of measurements.

## 0.0.19 (Beta) — 2026-10-08

### 🧹 New tool: Tidy up old carts (tab *Shop*)

- Gridbox keeps every cart ever started in the database and never removes one, also the carts of orders placed long ago, so the cart tables only grow (on merserwis.pl over 192,000 carts).
- The **Clean and optimise** button removes the carts nobody can open any more and then rebuilds the cart tables, so the database really gets smaller (deleted rows alone do not shrink InnoDB tables). **Check** first shows what would be removed. The panel shows the number of carts, cart items and the size of the cart tables.
- Which carts go: **empty carts** right away, and **carts with products** that nobody has used for the set number of days (default 30, at least 8). A visitor gets back to a cart only through Gridbox's cookie, valid for 7 days after the last change, so a cart unused for longer can never be opened again. The carts table has no dates, so MerTools notes when each cart is used, and carts with products can only be removed after MerTools has watched them for that many days (the panel shows the date).
- Never touched: orders and all their data (they keep their own copies of the products), carts with files attached to their products, carts used in the last hour, the newest carts. If a visitor whose cart was removed comes back, Gridbox simply starts a new cart.
- Optionally automatic: once a day, or when there are more carts than a limit (default: by hand only). The automatic run goes after the page has been sent to the visitor, in small batches, at most once an hour.
- Uninstalling MerTools removes its own tables; the Gridbox tables stay.

## 0.0.18 (Beta) — 2026-10-08

### 🧭 New tool: Redirect missing pages (tab *404 pages*, on by default)

- Instead of the 404 error page, the visitor is sent to a chosen page: the home page (default), a menu item, or any address (a path such as `/kontakt` or a full `https://` address).
- It replaces the redirect added by hand to the template's `error.php`, which every Gridbox update overwrote. The setting stays through Gridbox and Joomla updates.
- Permanent (301, default) or temporary (302) redirect.
- Redirects set for single addresses in Joomla's *Redirects* component still take precedence; MerTools only takes the missing pages that have none.
- Only pages are redirected. Form posts, AJAX and JSON requests get the normal 404, and so do addresses whose path starts with an excluded beginning (e.g. `/api/`).
- If the chosen page is itself missing, the normal error page is shown, so there is never a redirect loop. A deleted or unpublished menu item falls back to the home page.

## 0.0.17 (Beta) — 2026-10-08

### 🛠 Fixed: links to a product option shown as radio buttons (tab *Shop*)

- Links to an option were checked with every kind of Gridbox option: drop-down list, tags, colours, images and radio buttons, also two kinds in one product. Each link opens the product with the chosen option, its price and SKU, and “Add to cart” adds that option.
- Radio buttons needed a fix in Gridbox's script. The server selected the right option, but when the page opened, the script read the chosen options from an attribute that radio buttons do not have. It took the option as not chosen: on a product with a default option the default replaced the one from the link, and on a product without one “Add to cart” did nothing. MerTools now gives the selected radio button that attribute; nothing else on the page changes.

## 0.0.16 (Beta) — 2026-10-08

### 🛠 Fixed: links to a product option whose name has a symbol (tab *Shop*)

- A link to an option whose name contains a symbol written as HTML code opened the product with its default option. Example: GW Instek GPT-12000, models saved as “GPT-12002 &#128308;” (a red dot 🔴). The link `?Modele+GW+Instek+GPT-12000=GPT-12002+%26%23128308%3B` showed GPT-12001.
- The cause: Gridbox reads the address through Joomla's input filter, which turns `&#128308;` into 🔴. “GPT-12002 🔴” then never matched the saved “GPT-12002 &#128308;”.
- MerTools now passes such a value written so that the filter gives back exactly the value of the link. This works for any `&` in an option value (`&amp;`, `&quot;`, emoji codes), also when the option group name has no space.

## 0.0.15 (Beta) — 2026-10-08

### 🛒 New tool: Links to a product option (tab *Shop*, on by default)

- When an option or set of a product is chosen, Gridbox adds it to the page address, e.g. `?Zestawy+Metrel+MI+3155=MI+3155+EurotestXD+ST%2B+…`. Opening such a link showed the main product with nothing selected, so a customer sent a link to a specific set saw something else.
- The cause: Gridbox looks the option up under the name of the option group (“Zestawy Metrel MI 3155”), but PHP stores parameters whose names have a space, a dot or a square bracket under a changed name (“Zestawy_Metrel_MI_3155”; “Length [m]” even becomes an array). So the lookup failed for nearly every product.
- MerTools now gives Gridbox these parameters under their real names. The link opens the product with the option selected: its price, SKU and images, “Add to cart” ready. This also works for products with several option groups, and with `%20` instead of `+` or other parameters in the address (e.g. `utm_source`). Choosing another option, reloading and copying the address keep working as before.
- Only Gridbox pages are touched, nothing already in the address is overwritten, and the Gridbox files are not changed. A link to an option that no longer exists opens the product as before.

## 0.0.14 (Beta) — 2026-10-07

### 📞 New tool: Click to call (tab *Phone numbers*, on by default)

- Phone numbers written as plain text on the pages become links that dial them (`tel:`), also where nobody set a link — e.g. the contact rows under the products (“Kamil Turowski 22 531 00 94 / 533 394 222”), footers, the contact page.
- Polish numbers in the usual forms (`22 531 00 94`, `(22) 531-00-94`, `533 394 222`, `+48 533-394-222`, `0048…`) and international numbers starting with `+`. The link dials the number with the country code (default +48, can be changed or left empty).
- Left alone: existing links, buttons, form fields, code, numbers that are not phones (NIP, REGON, KRS, PESEL, bank accounts, serial and catalogue numbers, EAN, prices, standards like 60364-4-41, dates, postal codes) and fax numbers (but “tel./fax” is a phone). Areas can be excluded with a CSS selector.
- Looks like the text around it (underlined on hover), or like the site's links. A number never breaks across lines.
- On all devices, or only on phones and tablets.
- Light: a small script runs once the browser is idle after loading (about 15 ms on a product page with the CPU slowed 4×), and only on text with enough digits; content added later is checked the same way. The page content in Gridbox is not changed.

### 🛠 Fixed

- Nothing of MerTools is added inside the Gridbox page builder, so links and the colour marks of dark mode can never be saved into the page content.

## 0.0.13 (Beta) — 2026-10-07

### 📱 New tool: No sideways shift on phones (tab *Page layout*, on by default)

- A Gridbox element that sticks out to the right — e.g. a row with a slide-in animation or a motion effect that stays shifted on phones, or an off-canvas menu — made phones lay the whole page out wider than the screen. Gridbox hides that part, but everything fixed to the screen edges moved with it: the **hamburger** in a fixed header and the **accessibility button** ended up off-screen, and the dark-mode toggle could not be placed next to them (its phone position fell back to the floating corner, whatever was chosen).
- The page now stays exactly as wide as the screen (`overflow-x: clip` on `html` and `body`). Nothing visible is cut off; scrolling and sticky elements are not affected. It can be switched off.
- Example: on merdroid.pl the page was 599 px wide on a 390 px phone; now 390 px, the hamburger is visible again and the toggle stands beside it (or by the accessibility button) as set.

## 0.0.12 (Beta) — 2026-10-07

### 📱 Toggle position on phones

- New option **Toggle position on phones**: *As on desktop* (default, as before), *Beside the menu button (hamburger)*, *Floating* bottom right or left, *above* or *next to the accessibility button*, **In an element of the page** (any CSS selector, inside at the start or end, before or after it) or *Hidden on phones*.
- **Phone width up to (px)** sets where phones begin (default 768); the floating button on phones has its own distances from the edges.
- When the screen crosses that width (rotating a tablet, resizing the window), the button moves at once. If the element given for phones is not on the page, the button floats in the corner.

### ⚡ Speed audit — less work for the browser

Measured on merserwis.pl and merdroid.pl with the CPU slowed down 4× (as on a mid-range phone):

| | 0.0.11 | 0.0.12 |
|---|---|---|
| Adapting colours when the page opens, desktop | 691 ms | ~110 ms |
| Adapting colours when the page opens, phone | 709 ms | ~130 ms |
| Adapting colours, merdroid.pl phone | 169 ms | ~60 ms |
| Click on the toggle until the theme changes | 566 ms | 5 ms |

- Colours are read in batches and written afterwards (no forced style recalculation per element), and every element's computed style and backdrop colour is read once per pass.
- What is on screen is adapted first; the rest of the page follows in small slices while the browser is idle, so scrolling and clicks never wait.
- The click switches the theme at once; the colour fixes follow in the next frame.
- Sliders that rewrite the same class many times a second no longer trigger re-adapting.
- On phones the button is not moved again whenever the address bar hides or shows; following a pinch zoom happens at most once per frame.
- The package ships minified scripts (`mertools-dark.min.js`, 8 KB gzipped instead of 15 KB); Joomla loads the readable source when its debug mode is on.

### 🛠 Fixed

- Texts were sometimes left unreadable after the switch when the site animates its colours itself (e.g. Gridbox hotspot pop-ups, buttons with a colour transition): colours are no longer read in the middle of such an animation.
- The toggle in the menu could take the menu link colour from before the switch (dark icon on a dark header).

## 0.0.11 (Beta) — 2026-10-07

### 🌙 Dark mode

- **Bright buttons** (e.g. orange “Get the offer”) are adapted now too. New option *Bright buttons in dark mode*: **Outline** (default) — the dark background of the palette with a ring in the brand colour, like the other adapted buttons; **Softer** — the brand colour toned down; **Unchanged**. Only buttons with a label: small badges, round icons and large coloured blocks keep their colour.
- **Toggle button**: its outline and its shadow can be switched off.
- The floating button keeps **20 px** from the accessibility button (was 10 px).

## 0.0.10 (Beta) — 2026-10-07

### 🎨 Palette dots — visitors choose the colours of dark mode

- **Sepia is a dark palette now** (dark brown paper with cream text), next to Slate, Charcoal, Midnight, Warm and Dim — not a separate light theme any more. The toggle button switches light ↔ dark again. A visitor who chose the sepia theme in 0.0.8 gets dark mode with the sepia palette.
- **Dots by the toggle button**: when the pointer is over the button (keyboard focus, or a long press on phones), dots in the colours of the palettes slide out — into the page, away from the edge the button is at. A click on a dot switches dark mode to that palette; the choice is remembered. The settings choose which palettes are offered (*Palettes to choose from*); the intensity applies to all of them. The dots can be switched off.

## 0.0.9 (Beta) — 2026-10-07

### 🌙 Toggle button positions

- **Floating on the left** too: *bottom left corner*, *above the accessibility button* or *next to the accessibility button* (e.g. the accessibility panel on the left of the page) — besides the bottom right corner and the menu.
- The distance of a floating button from the side and from the bottom is set in pixels.
- The accessibility button is found by a CSS selector (default `._access-icon`); when it is missing or not visible, the toggle floats in the bottom left corner — and in that corner it never covers the accessibility button, it moves above it.

## 0.0.8 (Beta) — 2026-10-07

### 📖 Sepia theme (new)

- A third, warm paper-like theme for comfortable reading (option *Sepia theme*, on by default). The toggle button switches **light → dark → sepia**, its icon showing the next theme (moon, open book, sun). Sepia can also be the default for new visitors.
- The site colours are adapted to it the same way as in dark mode: white backgrounds take the paper tone, neutral grey or black text the warm brown one; brand colours stay.

### 🎚️ Intensity with a live preview (new)

- A slider *Intensity* (0–100) makes the dark theme softer (lighter greys) or deeper (never pure black); 50 is the palette as designed.
- A **live preview** in the settings shows a small page in the chosen palette, intensity, custom colours and accent as they are changed, before saving — with tabs for dark, sepia and light.

### 🌙 Dark mode

- **Better Search** live results and results page are adapted now: their colours are written with `color-mix()`, which the browser reports as `color(srgb …)` — every CSS colour notation is read now.
- **Breadcrumbs**: the triangles between the items (borders of `::before` / `::after` in the colour of the item) take the same dark colour as the items; thin light borders take the theme's border colour.
- **Text on photos and videos** (hero sections with a parallax image or a video background) keeps its colour — the colours behind it cannot be measured. Text that dark mode itself turned dark there (a Gridbox background variable used as text colour) gets the light text colour.
- Fix: the colour fixes are switched on before they are measured, so text is checked against the already darkened backgrounds (some text could stay dark on a dark background).

- **Gradient backgrounds** are adapted too: the light colours of a gradient (e.g. a footer fading from white to grey) are darkened like plain backgrounds, vivid brand colours in it stay.
- **Backgrounds that appear later**: Gridbox shows some section backgrounds only when the section scrolls into view (lazy loading) and gives the header a background when it becomes sticky. An element whose classes change is now adapted again together with its content — and when it returns to its previous state, so does its colour.

## 0.0.7 (Beta) — 2026-10-07

### 🌙 Dark mode

- **Logo for dark mode** (new): choose a version of the logo for the dark background (e.g. with light lettering) in the media manager; it replaces the site logo in dark mode from the first paint, also with Gridbox's lazy loading. By default the Gridbox Logo element in the header; more logos can be added with a CSS selector.
- **Page cache really emptied**: since Joomla 4 the site keeps its page cache in `administrator/cache` (or the configured cache path); 0.0.6 emptied the wrong folder, so a site with *System - Page Cache* still served the previous pages after an update. Installing, updating and saving the settings now empty the right one.
- **Colours checked again when late styles arrive**: a stylesheet loaded after the page (or a lazy section) no longer leaves a light section unadapted — everything is checked again at once, without flicker.
- **Text written by CSS** (`content: "…"` on `::before` / `::after`, e.g. the message of a cookie banner) is adapted too.

## 0.0.6 (Beta) — 2026-10-07

### 🌙 Dark mode

- **Button size in pixels**: the size of the toggle button is now typed in px (24–96, default 44) instead of chosen from a list; the icon is about half of it. Sites that had *small*, *medium* or *large* get 36, 44 or 52 px.
- **Changes visible at once**: installing or updating MerTools and saving its settings now empty Joomla's page cache (*System - Page Cache*). Until now a site with the page cache on kept serving pages with the previous script and settings, so the dark mode looked unchanged after an update.

### 🐞 Fixes

- Installing or updating no longer shows the warning *JInstaller::Install: File does not exist […]/media/css* — the package declared an empty `media/css` folder (the styles are added inline), and Joomla does not unpack empty folders. The installation itself was not affected.

## 0.0.5 (Beta) — 2026-10-07

### 🌙 Dark mode — readable on every Gridbox site

- **Colours adapted to the site** (new option *Adapt the site colours*, on by default). Gridbox writes many colours straight into the styles of sections and elements — text, links, headings, the header and accordion backgrounds — so the dark palette alone did not reach them: dark text stayed on the dark background and a white header kept its light menu text. In dark mode light backgrounds are now darkened and text that is too dark for its background is lightened just enough to be easy to read (4.5:1), keeping its colour tone. Readable brand colours stay as they are, texts on vivid buttons keep the site's design, and content loaded later (tabs, search results) is adapted too. The light theme is not changed at all.
- **Bigger, clearer toggle button**: three sizes (small 36 px, medium 44 px — default, large 52 px), a stronger icon, and in the menu it takes the colour of the menu links.
- **Always visible**: on phones, where the menu is folded behind the hamburger, the button moves next to the hamburger; when that is not possible it floats in the corner. The floating button now stays in the visible part of the screen also on pages that are wider than the phone screen (it used to end up outside it).
- The position options are now named after what they do: *At the end of the menu*, *At the start of the menu*, *Floating button*.

## 0.0.4 (Beta) — 2026-10-07

### 🌍 Languages

- The settings are in **English (default) and Polish** again. German, French, Czech and Dutch, added in 0.0.3, are removed; their files are removed on update, and those administrators see English.

## 0.0.3 (Beta) — 2026-10-07

### 🧪 Beta

- MerTools is marked as a **beta version** in the extension list, the plugin settings, the package description and on every release.

### 🌍 Languages

- The settings are now in **English (default), German, Polish, French, Czech and Dutch** and follow the language of the Joomla administrator; any other language shows English, also text by text where a translation lacks one.

## 0.0.2 (Beta) — 2026-10-06

### 🌙 Dark mode

- **New tool *Dark mode*** (tab *Dark mode*): an elegant dark theme for the whole front-end, with a **toggle button in the header**. It is built from Gridbox's own colour variables, so the site adapts cleanly — nothing is inverted, brand colours and images stay intact. The palettes are soft dark greys and blues, **never a harsh pure black**, so they are easy on the eyes.
- **Palettes:** *Slate* (elegant blue-grey, default), *Charcoal* (neutral grey), *Midnight* (deep blue), *Warm* (soft brown), *Dim* (low contrast), and *Custom* — set every colour yourself (background, cards, headings, text, muted text, borders, hover, shadow).
- **Accent as in Gridbox:** the site's brand accent is kept in dark mode by default, or you can set a different accent for dark mode.
- **Default for new visitors:** *Automatic* (follows the visitor's system light/dark setting), *Light* or *Dark*. The visitor's own choice is remembered and always wins. The theme is set before the first paint, so there is no flash.
- **Toggle button:** shown in the header next to the menu (or at the start/end of the header, or as a floating button that is always visible — best for mobile). The header element can be changed for other templates.
- **Options:** a smooth cross-fade when switching, and optionally calming the brightness of photos in dark mode.
- No Gridbox core file is changed.

## 0.0.1 (Beta) — 2026-10-06

First version.

### 🔗 Canonical URL (collapse duplicate slashes)

- Gridbox serves a page under any number of slashes in the address — `/oferta`, `//oferta` and `//////oferta` all open the same page with status 200. Search engines then see several addresses for one page (duplicate content) and the ranking is split.
- MerTools redirects every such address to the one clean address with single slashes (`//////oferta/mierniki` → `/oferta/mierniki`). Only the path is changed; the query string after `?` is left as it is, and the address always stays on this site.
- Settings (tab *Canonical URL*): on/off and the redirect type (301 permanent, recommended, or 302 temporary).
- Runs before routing, so it does not interfere with Gridbox's own routing. The Gridbox core files are not changed.
- If the site is behind Cloudflare (or another CDN) that merges the slashes *before* they reach the server, PHP never sees them and the redirect cannot be done in the plugin; the settings panel and the README then give a step-by-step Cloudflare Redirect Rule for that case.
