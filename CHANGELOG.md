# Changelog

All changes of **MerTools for Gridbox**, newest first. Versions 0.0.x were beta versions; 1.0.0 is the first stable release. Each version is also published as a [GitHub release](https://github.com/merserwis/plg_system_mertools/releases) with its installation package.

## 1.0.1 — 2026-10-13

### 🐛 Pictures shown too small

- **Pictures in descriptions, tabs and blog articles shrank to 300 px** (e.g. the *Equipment* tab of a product, the pictures of an article) when *Images in the size of the screen (WebP)* was on. The copies come with a `srcset`, and its `sizes` started with `auto`; Gridbox gives most pictures no CSS width (`width="100" height="100"` with `width: auto`), and for such a picture the browser takes 300 px as the width. Now `sizes` says the picture's own width, or the whole screen when the screen is narrower: `(max-width: 800px) 100vw, 800px` — the pictures are as large as without the copies (checked on merserwis.pl at 1400 and 390 px: 800 / 777 / 602 / 419 px instead of 300) and still load the lighter files.
- Pictures of blog and store lists ask for at most 400 px, as before; on phones they again fill the card (338 px, they had 300).
- The pages kept by MerTools and Joomla's page cache are emptied by the update, so the pictures are right at once.

## 1.0.0 — 2026-10-13

### 🎉 First stable release

After 34 beta versions and the code audit below, MerTools leaves beta: the “(Beta)” mark is gone from the extension name, the settings and the package.

### 🔍 Code audit: security, bugs and speed

A full review of the plugin's code (three independent passes: security, correctness, performance), with every finding checked in the code and fixed:

**Security**
- **Gridbox data answers kept for everyone.** The kept answers of Gridbox's page data requests (prices, formatting) were served to all guests, also when the visitor who first fetched them had their own currency, cart or wishlist cookie — Gridbox builds prices for the currency of the cookie, so a visitor could make every other guest see prices in another currency for up to 4 hours. Now the cookie checks of the page cache (cart, wishlist, order, currency, comments, and the cookies listed in the settings) apply to the data answers as well, and an answer that sets a cookie is not kept.
- **Anybody could empty the cache.** A guest's GET of a Gridbox order or payment address (`task=store.…Callback`) emptied the whole page cache, even when Gridbox did nothing with it; a loop of such requests kept the cache cold. For a guest the emptying now counts only when Gridbox answered as it does to a real order or payment (a POST, or a redirect) and at most every 20 seconds; the emptying also no longer makes that visitor wait for the deleting of the files.
- **Copies of protected pictures.** A picture address in a page could point into any folder of the site, and the WebP copy made of it was public. Copies are made only of pictures in the public asset folders (`images`, `media`, `templates`, `modules`, `components`, `plugins`, `uploads`, `files`), never of hidden files or folders.
- The **Clear cache** button sends its form token in the request body instead of the address (no token in web-server logs); the dark-mode selectors and logo address from the settings are checked more strictly before they go into a style rule (an open quote or comment could swallow the rules after it).
- A message that comes up while a page is rendered (Joomla's message queue) stops the page from being kept.
- A flood of made-up addresses of Gridbox data requests cannot fill the disk between the hourly tidy-ups: the tidy-up also runs after about every 32nd kept answer.

**Bugs**
- A picture that made PHP run out of memory while its copies were made came first again with every page and blocked the rest of the queue for ever; also animated GIFs were decoded without the memory check. The queue is now written before a picture is worked on, the memory limit is checked after it was raised (a picture that does not fit is skipped), and while a visitor still waits (servers without `fastcgi_finish_request`) only pictures up to 4 megapixels are made, for half a second.
- A page adding new pictures to the queue could put back pictures a parallel request had just finished, which made them again and emptied the page cache for nothing. The queue is merged with the file now.
- A Gridbox data answer could be kept after the cache had been emptied (the time of the emptying was read once, at the start of the request).
- An image address with `$1` or a backslash in it (`data-gridbox-lazyload-src`) was changed when put into `src`.
- The async loading of icon stylesheets also caught `materialize.css` (a whole CSS framework, unstyled first paint).
- The daily index check finished the response on its own and so left the image and cart work with the short budgets; the hourly and daily work ran on every request when the cache folder could not be written; the page speed history did not record whether icon styles, WebP copies, GIF frames, popups, slider rows, tabs and DB indexes were on.
- The image size cache dropped everything when full (every picture read again); now the older half goes.
- Uninstalling leaves no kept pages, picture lists or time stamps in Joomla's cache folder.
- The `Tabs` helper no longer depends on Joomla's `Text` (its test crashed since 0.0.34); the tab rows no longer look up their item through the DOM, and a tab whose id starts with a digit is found too.

**Speed**
- The first visitor of every page got it **uncompressed** (gzip was switched off to keep the page as sent — 400 KB instead of 80 KB). The page is now kept from its uncompressed copy and the response stays compressed.
- Everything after the response — keeping the page, the hourly tidy-up, the daily index check, pictures, carts — now runs after the response has been **closed** (`fastcgi_finish_request`), not before: 10–20 ms less for every page not yet kept, up to a few hundred ms less once an hour.
- The language file (60 KB) is loaded only where a text is needed, not on every page view.
- Dark mode: the three observers start with the first dark view (nothing to watch while the page is light); an icon-font stylesheet arriving late (MerTools loads them async) no longer makes the whole page be adapted again; the slides of Gridbox's sliders moving (class changes every few seconds) no longer trigger work, nor does anything while the tab is hidden.
- The slider bar in the tab sections updates its number once per frame; the "mt-rows" check skips pages without such a class; the cache folder path is worked out once per request.

## 0.0.34 (Beta) — 2026-10-13

### 🧹 New: *Clear cache* button in the administrator's top bar

- The top bar of the administrator, next to the Joomla version, has a **Clear cache** button. One click empties the pages kept by MerTools and every group of the Joomla cache — the same as *System → Clear Cache* (Joomla's own code, so other extensions are told about it as usual) — without leaving the screen you are on. A Joomla message says how many cache groups were cleared, their size and how many kept pages were removed.
- Shown only to users who may clear the cache in Joomla (*Access Administration Interface* of *Cache*); the request needs the form token. On narrow screens the button moves into the *more* (⋯) menu of the bar with the other items.
- Can be turned off: *Clear cache button in the top bar* (tab *Page speed*, on by default).

### 🌙 Dark mode: buttons and the search field in the accent colour

- In dark mode Gridbox's light and grey buttons (*Read more*, *Contact us*, header buttons) got the dark colours of the palette and blended into the page. Now a button with a background takes the **accent colour** — the dark-mode accent, or Gridbox's — with its text in the colour of the palette that reads best on it; a button drawn with an outline only gets an **outline in the accent colour**. Checked on the merserwis.pl home page: 11 buttons in orange with dark text, 10 outlined ones with an orange outline.
- The **search field** gets an outline in the accent colour too (its frame — on Gridbox's store search the box around the field — instead of a faint grey line).
- New option *Buttons in dark mode* (tab *Dark mode*): **Accent colour** (default), **Accent outline** (every button dark with an outline in the accent colour) or **As before** (then *Bright buttons in dark mode* applies as until now).

### 📱 Tabs as sections: sliders show that there are more

- A Gridbox posts or products slider inside a tab (on merserwis.pl *Promotions*, *Bestsellers*, *New products*) shows one post at a time on a phone, with no arrows or dots — nothing said that it can be swiped. Now a bar under it shows **previous**, the **position** (*2 / 12*) and **next** — Gridbox's own steps, the number follows swipes and autoplay too — with the hint *Swipe sideways or use the arrows to see more*.
- New option *Sliders inside the sections* (tab *Page layout*): **Arrows and number** (default), **One under another** (all posts in a column instead of sliding) or **As Gridbox shows it**. Only while the tabs are sections; computers keep the slider unchanged.

## 0.0.33 (Beta) — 2026-10-12

### 🐞 Recommended settings: Platform Specific Caching

- Joomla shows *Platform Specific Caching* (and uses it) only while *System Cache* is Conservative or Progressive. With the System Cache off the check no longer asks to change an option that cannot be seen: it is marked as recommended with “not used (System Cache off)”.
- The *where* of the check now says that the option appears only with the System Cache on: *System → Global Configuration → System → Cache → Platform Specific Caching*.

## 0.0.32 (Beta) — 2026-10-12

### ✅ New: recommended settings (top of the *Page speed* tab)

- A table of the settings of Gridbox, Joomla, the server and Cloudflare that let MerTools work best — for each: the state on this site now (✔ as recommended, ✖ to change, ⚠ worth changing, ℹ for information), the recommended value, why, and where to change it. *Check again* reads the state anew after a change.
- **Gridbox** (read from its own settings): Deferred Loading on together with MerTools' *Page shown at once*; Gridbox's page cache off (it keeps the first visitor's form token; use the MerTools page cache); Lazy load images on; Compress / Adaptive images not needed with the MerTools WebP copies; Compress CSS worth a try.
- **Joomla:** the System - Page Cache plugin off while the MerTools page cache is on; Platform Specific Caching off (phones kept old settings and scripts on merserwis.pl).
- **Server:** PHP 8.2 or newer; GD with WebP (for the image copies); OPcache on with enough room — its use, free memory, hit rate and cached files are shown when the hosting allows it (a full OPcache was the main cause of the slow server on merserwis.pl).
- **Cloudflare:** Rocket Loader off — checked in the page a guest gets.

## 0.0.31 (Beta) — 2026-10-12

### 🧱 New: post sliders in several rows (tab *Page layout*, on by default)

- Gridbox shows the posts of a slider — *Recent posts slider*, *Related posts slider*, *Recently viewed products* — in one row only, with no setting for more. Now a slider can have 2, 3 or 4 rows: in Gridbox open the slider's settings and enter in **Class suffix** e.g. `mt-rows-2`. Different rows on smaller screens: `mt-rows-tablet-N` (up to 1024 px) and `mt-rows-phone-N` (up to 768 px), e.g. `mt-rows-3 mt-rows-tablet-2 mt-rows-phone-1`.
- The posts fill the rows one after the other (first row 1 2 3, second row 4 5 6 with three visible columns) and the slider moves column by column as before; arrows, dots and autoplay work as set in Gridbox, and the posts keep their markup (add to cart and wishlist still work). Turning a tablet or resizing the window regroups the slider.
- Sliders without such a class are not changed. The settings show the steps and the classes in a box under the option (*Page layout* tab).
- Checked on the merserwis.pl home page: 2 rows of 3, sliding, 1 row on phones, regrouping on resize, images and cards as before, no script errors.

### ↩️ Closing the settings opens the Gridbox panel

- **Close**, **Save & Close** and **Save** then **Close** in the MerTools settings now go to the Gridbox panel instead of the list of plugins (when Gridbox is installed).

### 🐞 Fixes

- The history of speed measurements now also records the tools added in 0.0.29–0.0.30 (icon styles, WebP copies, GIF first frame, popups after the first interaction).

## 0.0.30 (Beta) — 2026-10-12

### ⚡ New: popups after the first interaction (tab *Page speed*, on by default)

- A Gridbox popup (lightbox) set to open after a time — trigger *Time delay* — now opens at that time, but not before the visitor has first used the page: scrolled, touched the screen, moved the mouse or pressed a key. If the time has already passed, it opens right at that moment.
- Why: a large popup that opens while the visitor is still only looking at the page becomes the largest element of the page, and Google measures the loading time of real visitors (LCP) by it — on akademia.merserwis.pl a popup with a banner opened 4 s after loading and moved LCP to about 4.6 s. On phones Google also counts a popup before any interaction as an intrusive interstitial. Interaction ends the LCP measurement, so a popup opened after it does not count.
- Checked on akademia.merserwis.pl (popup set to 4 s): no interaction → no popup; mouse moved after 1 s → popup at 4.4 s; mouse moved after 7 s → popup at 7.0 s. No script errors.
- Popups that open on scrolling or when leaving the page, and Gridbox’s own cookie notice, are not changed. Gridbox’s files are not changed either: its popup function is wrapped right after its script.

## 0.0.29 (Beta) — 2026-10-12

### ⚡ Faster pages on any Gridbox site (tab *Page speed*)

Measured on the home page of merdroid.pl (Lighthouse, phone, median of 3): **score 69 → 86, LCP 15.5 s → 3.7 s, FCP 3.0 s → 2.6 s**. The page had no Gridbox lazy loading, so MerTools did nothing to its images until now; it downloaded all six of them (3.4 MB, one of them a 1.4 MB animated GIF at the top) at once, and the picture the visitor waits for arrived last.

- **Images without waiting and without jumping — now for every image.** Until now only images of Gridbox's own lazy loading were handled. Now every image of the site gets its proportions from the file (no layout shift when it arrives), the header and the first section of the page (up to 4 images) load at once with the largest of them first (`fetchpriority="high"`), and every image further down waits until the visitor scrolls near it (`loading="lazy"`). Sites with Gridbox's lazy loading on now also load the images of the first section at once instead of lazily.
- **New: images in the size of the screen (WebP)**, on by default. MerTools makes lighter WebP copies of every JPEG and PNG of the site in a few widths (480 – 1920 px, never wider than the original) and the page offers them (`srcset`/`sizes`, from the Gridbox column the picture is in); each browser takes the one that fits its screen — on merdroid.pl the photos went from 300–400 KB to 25–60 KB on a phone. The copies are made by PHP's GD after the page has gone to the visitor, a few at a time, so nobody waits; they live in `media/mertools-img`, the originals are never changed, and a changed original gets new copies under a new name. Small pictures (under 300 px or 20 KB) are left as they are.
- **Background pictures too:** pictures given as a background in the page (the slides of Gridbox slideshows and post sliders) get the lighter copy, and phones a smaller one through a media rule. The main product photo is preloaded in the size each screen will show.
- **New: heavy GIF animations — first frame at once**, on by default. An animated GIF at the top of a page (over 150 KB) shows its first frame at once as a small WebP image (13 KB instead of 1.4 MB on merdroid.pl) and the animation is loaded when the page has loaded. A GIF converted to animated WebP and put next to it under the same name (`animation.webp` beside `animation.gif`) is used for the animation instead.
- **New: icon styles without holding up the page**, on by default. The style sheets of icon fonts (Font Awesome, Material Icons, Gridbox's icon libraries) load alongside the page: text and pictures appear sooner, the icons a moment later.
- **Image copies panel:** pictures with copies, waiting ones, the space the copies take, *Make copies now* and *Remove all copies*. Removing the copies or making new ones empties the kept pages, so no page points at a missing file. Uninstalling MerTools removes the copies.
- Updating MerTools now empties the pages kept by MerTools as well, so the new version applies at once.

### ❓ Help tooltips in the settings

- Every option has a **?** beside its name: hover it, focus it with the keyboard or click it to read what the option does. The explanation moves into the tooltip, so the settings are shorter. Options that had no explanation (the panels, the colours of a custom dark palette) got one.

### 🐞 Fixes

- No PHP 8.5 deprecation notices from the image tools (`imagedestroy()` is not used).

## 0.0.28 (Beta) — 2026-10-09

### 🛠 Tabs as sections on phones: fixes

- **Sliders and carousels in a section** (e.g. *Bestsellers* and *New products* on the home page) stayed empty after opening: Gridbox measures them while the content is hidden (height 0) and builds them again a moment after a tab is shown. Now the content is visible before Gridbox's tab is selected, an already active tab gets its "shown" too, and for a few seconds after opening every carousel that gets a height of 0 is measured again with Gridbox's own function.
- **All sections closed by default** (the first one can still be opened at start: *First section open*).
- **More space** above and below a row: taller rows, a gap between an open row and its content and before the next row.

## 0.0.27 (Beta) — 2026-10-09

### 📱 New: tabs as expandable sections on phones (tab *Page layout*, on by default)

- On a phone a Gridbox tab bar is wider than the screen (the product tabs on merserwis.pl: 751 px on a 340 px column): it has to be scrolled sideways and visitors do not see that there are more tabs.
- Now, up to a chosen screen width (768 px by default), every tab becomes a row with its icon, its name and an arrow, one under the other, and its content opens right below it. The first section is open (can be switched off). Several sections can be open at once; opening one does not move the page.
- Opening a section also selects Gridbox's own tab, so whatever Gridbox does when a tab is shown still happens. Computers keep the tabs; turning a tablet switches between the two on the fly.
- Accessible: the rows are buttons with `aria-expanded`/`aria-controls`, the content a region labelled by its row. Colours come from the theme variables (also in dark mode).

## 0.0.26 (Beta) — 2026-10-09

### ⚡ New: indexes of Gridbox tables (tab *Database*, on by default)

- Gridbox creates its tables with a primary key only: every lookup of product data by product, of categories and tags by page and of cart products by cart reads the whole table. On merserwis.pl the indexing query of Better Search took 6 s on the server; with one index the same query took 0.07 s instead of 1.6 s in a test with the same amount of data (about 0.3 s expected on the server). Gridbox joins its product data by product in more than 50 places (product and list pages, cart, orders), so the shop gains too.
- MerTools creates 8 plain indexes (named `idx_mt_…`) on Gridbox's product data, product variations, cart products, category and tag maps and page fields. No data is changed.
- They are kept: checked after Gridbox or MerTools is installed or updated, and once a day (after the page has gone to the visitor); missing ones are created again. An index Gridbox may add one day on the same column counts as present — no second one is made. Only `SHOW TABLES` / `SHOW INDEX` are used: many hosting accounts cannot read `information_schema`.
- The *Database* tab shows the state of every index and has a **Check and create missing** button.
- Uninstalling MerTools leaves the indexes in place (they only speed Gridbox up).

## 0.0.25 (Beta) — 2026-10-09

### ⚡ New: page shown at once (tab *Page speed*, on by default)

- With Gridbox's **Deferred Loading** on (Gridbox → Settings → Performance), Gridbox moves all style sheets to the end of the page as preloads and hides the whole page (`<body style="opacity: 0">`) until DOMContentLoaded — that is until every script at the end has been fetched and run, Gridbox's own *getItems* request included. Nothing is painted before that, the main product photo neither: on a phone that is seconds of white screen and a low PageSpeed score (FCP, LCP).
- MerTools now puts the style sheets back into the head as normal style sheets (same order, after the inline styles, so the result is the same as after Gridbox's script) and takes the hiding style off the page. The scripts still load at the end. Without Deferred Loading nothing changes; the tool can be switched off in *Page speed*.
- Measured on a merserwis.pl product page (Lighthouse, phone): score 71 → 78 (simulated) and 78 → 85 (real throttling), LCP −1.5 s, blocking time 141 → 12 ms, no layout shift; computer 94 → 96. The page looks the same before and after Gridbox's scripts have run.
- The dark mode script stays in the head with Deferred Loading too, so dark mode visitors see no light flash now that the page is not hidden any more.

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
