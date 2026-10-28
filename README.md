# MerTools for Gridbox

> Version 1.0.0 was the first stable release, after a full code audit (security, correctness, speed). Report problems in the [issues](https://github.com/merserwis/plg_system_mertools/issues).

A Joomla system plugin with fixes and optimisations for **Balbooa Gridbox** that cannot be done from Gridbox's own settings, for any Joomla 6 + Gridbox site. Each fix is a separate tool that can be turned on or off. **The Gridbox core files are never changed**, so Gridbox updates install cleanly.

First built for the Merserwis shop (Joomla 6, Balbooa Gridbox, PHP 8.5), in the same style as [Better Search for Gridbox](https://github.com/merserwis/plg_system_bettersearch); since 1.7.0 meant for any Joomla + Gridbox site. The figures quoted below (load times, sample sizes) come from that shop.

> **Check your site.** After installing, after updating and after turning on any tool, check on your own site that it works as it should: as a guest (in a private window), on a computer and on a phone, on several kinds of pages (home page, post, product, cart, form). Every Joomla + Gridbox site is built differently; if something does not work right, turn that tool off and [report it](https://github.com/merserwis/plg_system_mertools/issues). Some tools are on right after installing — the control panel shows which.

## The administrator (1.7.0)

Everything is under **Components → MerTools for Gridbox**, laid out like Akeeba Admin Tools:

- **Control Panel** — the tools as tiles in sections (*Speed*, *Search engines*, *Shop* — only with a Gridbox store —, *Gridbox in the administrator*, *Configuration*), each with a dot that says whether it is on; on the side the state of MerTools, shortcuts (quick setup, configuration, clear the cache, open Gridbox) and the reminder to check the site.
- **The sidebar menu** opens the tools used day to day: *Page Cache*, *Images*, *Sitemap*, *404 Pages*, *Edit History*; the cog beside each opens its settings. A tool's page starts with its *Overview* (what it did, its buttons), its settings follow in the next tabs.
- **Configuration** — what is set once and forgotten, one page with a vertical tab per tool: *Addresses (URL)*, *Site hygiene*, *Structured data*, *Blog*, *Shop*, *First view*, *Page layout*, *Tabs*, *Phone numbers*, *Dark mode*, *Lists in the administrator*, *Edit lock*.
- **Quick Setup** — 15 switches with the main tools, each marked *recommended* or *optional*; *Turn on the recommended* switches on the recommended ones and leaves the optional ones as they are, and only the switches you change are saved. A fresh install offers it first.

The settings are still the parameters of the plugin, so nothing changes for the site itself; opening the plugin in Joomla's plugin manager leads to the control panel (the plugin's own form, with every setting at once, opens only when the component is disabled).

| Tool | Where |
|---|---|
| Page cache, warm-up, *Clear cache* button in the top bar | *Page Cache* |
| Images without waiting, WebP copies | *Images* |
| PageSpeed Insights measurements | *Speed Measurements* |
| Recommended settings of Gridbox, Joomla and the server | *Diagnostics* |
| Faster first view (page shown at once, icons, main photo, videos, popups, marketing scripts) | *Configuration → First view* |
| Sitemap | *Sitemap* |
| 404 log, moved pages, redirects | *404 Pages* |
| Canonical URL, guard of the sorting, pages of the lists | *Configuration → Addresses (URL)* |
| Site hygiene, Content-Security-Policy | *Configuration → Site hygiene* |
| Structured data of the site | *Configuration → Structured data* |
| Blog feeds, article data | *Configuration → Blog* |
| Product option links, discount %, price on request, product data | *Configuration → Shop* |
| Omnibus | *Omnibus* |
| Tidy up old carts | *Carts* |
| Gridbox lists (columns, editing in the list, media) | *Configuration → Lists in the administrator* |
| Edit lock | *Configuration → Edit lock* |
| Edit history | *Edit History* |
| Apps protection and backups | *Gridbox Apps* |
| Indexes of Gridbox tables, “<” in introductions | *Database* |
| Post rows, no sideways shift, finished items out of the lists | *Configuration → Page layout* |
| Tabs on phones, link to a tab | *Configuration → Tabs* |
| Phone numbers | *Configuration → Phone numbers* |
| Dark mode | *Configuration → Dark mode* |

## Tools

Every option in the settings has a **?** beside its name with an explanation of what it does (hover, keyboard focus or click). Where a tool below names a tab, see the table above for where it is since 1.7.0.

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


### 🔢 Pages of the lists (?page=N)

Gridbox gives every page of a list of products or posts (a category, a blog, a tag: `?page=2`, `?page=3`…) the canonical of the first page and the same title and description, so search engines take them for copies of page 1 and the products further down are found only through the sitemap (on merserwis.pl `/oferta/akcesoria` has 144 pages). A page past the last one (`?page=999`) answered 200 with an empty list and links up to page 1000, and `?page=0` or `?page=abc` repeated page 1. With *Pages of the lists* on (tab *Canonical URL*, off by default): page 2 and further get their own canonical (`…?page=2`) and “– page 2” in the title and the description, a page whose list came out empty answers **404** with noindex, and a page number that is not one (0, 1, 01, abc) is redirected (301) to the list without it, the other parameters kept.

### 🛡 Gridbox lists — guard of the sorting (security)

Gridbox puts the sorting of a list from the address (`?sort-by=…`) into the `ORDER BY` of its database query without checking it, and its editor's task that lists posts (`editor.getBlogPosts`, which takes an order too) checks no rights — on Gridbox 2.20.4 an address with another value gave an error page and could reach the database. With *Guard the sorting of the lists* (**on by default**), a sorting other than the ones Gridbox offers (default, price low/high, newest, highest rated, most reviewed, most popular) is taken out of the address with a 301 (other requests: 400) before Gridbox runs, and that task needs the right to edit in Gridbox (403). Comments and reviews sort by a fixed list of their own and are not touched; nothing changes for visitors.
### 🗂 Page layout — finished items out of the lists

Cards of Gridbox lists (recent posts, a blog, a category, products) whose title has a word of your list in it (e.g. `[ZAKOŃCZONE]` of a promotion that is over) are left out of the home page or of all the lists, while the page itself stays open, in the sitemap and in a search.

### 🗺 Structured data of the site (off by default)

The organization (address, hours, NIP/KRS, contact points, accreditations), the WebSite with the search box, the BreadcrumbList of every page (with the page itself last) and the Service of the pages of a service, as JSON-LD. A skeleton to fill in yourself: every field is empty and an empty field is left out of the data.

### 🧼 Site hygiene — the small things audits find

Campaign tags (`?srsltid=`, `?utm_*`…) out of `og:url` (the page cache kept them for everybody), `Secure` and `SameSite=Lax` on the cookies, no obsolete `X-XSS-Protection`, the language code `pl` instead of `pl-pl`, and an optional Content-Security-Policy (report only or enforced; the default policy cannot break a page).

### 🛒 Shop — links to a product option

A link to a chosen product option or set (Gridbox adds it to the address, e.g. `?Zestawy+Metrel+MI+3155=…`) opens the product with that option selected, with its price, SKU and images, so a customer can be sent a link to a specific set. Without it, Gridbox opened such links with nothing selected whenever the option group name had a space, a dot or a square bracket, because PHP renames those parameters, or when the option name had a symbol written as HTML code (e.g. a red dot `&#128308;`), because Joomla's input filter decodes it. Server side, Gridbox pages only, nothing in the Gridbox files is changed. Works with every kind of Gridbox option (drop-down list, tags, colours, images, radio buttons) and with several option groups in one product. On by default, tab *Shop*.

### 🏷 Shop — discount % in the product editor

Gridbox works out the discount badge from the regular and the sale price, but the sale price itself has to be calculated by hand. MerTools adds **Discount %** under *Sale price* in the Gridbox editor of a product (a small line inside the field, Gridbox's layout unchanged; a long currency such as “zł netto” is also shown on one line): typing e.g. `10` sets the sale price to the regular price − 10 % (rounded to the store's decimals) and the sale price of **every version/set from its own price**; `0` or an empty field removes the sale price. A sale price typed by hand shows its discount in the field; once a percentage was typed, a changed regular price recomputes the sale price. Gridbox saves only the prices, as always. In the Gridbox lists, `10%` typed as the sale price does the same for the product. On by default, tab *Shop*.

### 👁 Folding the fields in the editor

An eye right of the name of every field in the Gridbox editor of a product (or a post) folds the field down to its name; another click shows it again. The browser remembers which fields are folded, so every other product of the app opens with the same ones folded. A required field Gridbox finds empty on saving is shown even when folded; nothing is saved with the page. On by default, tab *Shop*.

### 💬 Shop — price on request

A product without a price (0 or empty in Gridbox) shows a text such as **“On request”** where its price would be — on the product page and in the product lists and sliders (Gridbox leaves the place empty). A product without a price of its own whose **models (a Gridbox option list) have prices** shows **“Choose a model from the list”** instead, until a model is chosen; then the price of that model is shown, or “On request” when that model has no price either. On the product page the script reads the models and the chosen one from Gridbox's own data; in the lists, which carry no models, the server marks those products (one query for the products of the page). The texts use Gridbox's price classes, so they look like the prices of that element, in a size of their own (18 px by default, `0` = the price size). Only lists and sliders that show prices get them: one whose prices are switched off in its Gridbox settings has an empty price for every product and stays as it is. Lists loaded later (filters, more products, search) and a model chosen on the product page are followed too. A “lowest price of the last 30 days” line of an Omnibus plugin that says 0,00 is hidden. The texts and the size are set on tab *Shop*; off by default. It replaces a separate script for the same task, which is taken off the pages (`plugins/system/omnibus/assets/scprice.js`).

### 🔎 Shop — product data for search engines (JSON-LD)

Gridbox fills the Product data Google reads from a schema template, and on merserwis.pl that went wrong on most product pages: a product without a price said `"price": "0"` and “in stock” (42% of a sample), the default template called every product *used* and gave it an empty description (73%), a brand from a link field came out as `index.php?option=com_gridbox&…`, and a line break or a quotation mark in an introduction broke the whole block, which Google then drops. With *Product data for search engines* on (tab *Shop*, off by default), MerTools makes the block of a product page from the product itself with `json_encode`, in place of Gridbox's:

- no offer for a product without a price (price on request), a price range (`AggregateOffer`) when only its versions have prices, the sale price when there is one — as Gridbox shows it;
- a new product (`NewCondition`), the VAT status of the store's prices (`priceSpecification`, from *Gridbox → Store → Tax*);
- the introduction, or the meta description, as the description; the brand from the product's field named *Producer*/*Brand* (the name, not the link); every picture of the product's gallery; `mpn` from the SKU (setting: the whole SKU or the part after its last `/`);
- the availability from a field of the product (setting *Availability from the field*, e.g. *Delivery time* filled by an import) by rules `text = Value` (`Brak na magazynie = BackOrder`, `W magazynie = InStock`…), and a value for the products no rule matches (Gridbox's “in stock”, or e.g. *Back order* when the goods are mostly ordered after a sale);
- keys of Gridbox's block that MerTools does not make (`gtin`, `aggregateRating`…) are kept; other blocks (FAQ) are not touched.

On a copy of merserwis.pl, 61 product pages had one valid Product block each, no price 0, brand, description and mpn on all of them.

### 🏷 Shop — Omnibus: lowest price before a reduction

The lowest price of the 30 days before a price reduction, shown next to the reduced price as the law asks for it (EU Omnibus directive; in Poland art. 4 of the act on informing about the prices of goods and services) — no separate Omnibus plugin needed.

- **Price history.** Gridbox keeps none, so MerTools keeps the regular and sale prices of every product and every version with the time of each change (tables `#__mertools_omnibus` and `#__mertools_omnibus_log`, kept two years and left in place when MerTools is uninstalled). Changes are picked up after anything sent to Gridbox by a logged-in user, once an hour (imports, changes made past Gridbox) and when the settings are saved.
- **The reference price.** When a price goes down to a sale price, the lowest price that applied in the 30 days before that moment is worked out — the price in force when the 30 days began and every price after it, an earlier sale price of those days included; a product offered for less than 30 days: since it is offered. It is kept for as long as the sale lasts. A further reduction takes the 30 days before itself; a sale price raised or a regular price changed during the sale keep it; the end of the sale clears it.
- **Shown** on the product page under the price, for the version chosen, only while it is on sale (no checking on a timer), and — setting *Also in the lists*, on by default — in small letters under the sale price in the lists and sliders that show prices. The text is set on tab *Shop* (`{price}` = the lowest price in the store's format).
- **Moving from a separate Omnibus plugin.** At the start, every sale running then takes that plugin's reference price when it belongs to the same sale (same regular and sale price; never above the regular price), else the regular price; new products on sale from their first day: their own price. The panel on tab *Shop* lists every product and version on sale with the price shown and where it came from. The plugin's lines and its script (`plugins/system/omnibus/assets/scr_omnibus.js`) are taken off the pages and the line keeps the look of its stylesheet; what it did for category pages is done by Better Categories for Gridbox 1.7.0 (below). With both, the plugin can be removed.
- **Gridbox's own sales** (*Gridbox → Discounts*: a percentage or an amount off every product, the products of chosen categories or chosen products and versions, with or without dates) are reductions like any other. A product or version without a sale price of its own gets the price such a sale makes of it in the history, the way Gridbox works it out (the first sale that is for it, guests' access levels), so the line shows the lowest price of the 30 days before the sale started. The prices are compared again the moment a sale starts or ends (at the first visit after it), not only once an hour. The Omnibus panel lists every Gridbox sale with its state and whether the line follows it: a cart discount (an amount off the order above a total) lowers no product price, and a sale for some users only (an access level other than the guests') is not what guests see — both are marked there.

A visitor who views prices in another currency (currency switcher, currency of the language) sees no Omnibus line, because the history is kept in the store's default currency. Off by default, tab *Shop*.

### 🗂 Page layout — category pages: now in Better Categories

Since 1.4.0 the category descriptions (where the category layout holds the placeholder “Category Description”) and the click on a whole row of Gridbox's Categories element are done by **Better Categories for Gridbox 1.7.0**, with more settings (place of the description, only its beginning with a “Read more” button). Better Categories switches both on at its update when *Category pages* was on here.

### 🔎 Blog — article data for search engines (JSON-LD)

Gridbox writes the publishing date of a post's schema in words of the site's language (`12 czerwiec 2020`), which no search engine reads as a date — on merserwis.pl all 196 articles of the SEO audit — puts the values in unescaped, and a schema copied by hand into a post can be broken (two posts had a whole `<script>` pasted into `@id`, or a missing quotation mark). With *Article data for search engines* on (tab *Blog feeds*, off by default), MerTools makes the BlogPosting block of every post of a blog app from the post itself, in place of the one of its schema field or Gridbox's template:

- the dates in ISO 8601 with the time zone (`2020-06-12T09:30:00+02:00`), and `dateModified` when MerTools knows the last change of the content (the dates of the sitemap, else the edit history) — never a date it does not know;
- the post's Gridbox authors as people, else the organization; the publisher with the `@id` of the organization of the home page (`/#organization`), its name (setting, the site name by default) and logo (setting, else the one of the post's block);
- the language, the introduction (or meta description) as the description, the intro image; other keys of the post's block are kept, other blocks (FAQ) are not touched.

On a copy of merserwis.pl, 344 posts had one valid BlogPosting each, the two broken ones included.

### 📰 Blog feeds — RSS and Atom

Gridbox has no feeds. MerTools gives every Gridbox blog an **RSS 2.0** and an **Atom 1.0** feed of its newest posts, for feed readers, RSS-to-email newsletters and aggregators: the address of a blog page with Joomla's usual feed parameters.

```
/blog?format=feed&type=rss
/blog?format=feed&type=atom
/blog/poradniki?format=feed&type=rss      ← a category and its subcategories
```

- **What is in it:** only what a guest sees — published, public, already started and not ended posts, not in the trash; on a multilingual site those of the page's language. Newest first, each with its title, address, the introduction (or the meta description), the intro image (optionally also as the item's attachment: RSS `enclosure` / `media:content`, Atom enclosure link), the date, the authors and the category, and under the text a button-like link **“Read the full article →”** to the post. A category page gives the posts of that category and its subcategories, a tag or author page the posts of that tag or author.
- **Found by readers:** the blog pages and their posts get `<link rel="alternate" type="application/rss+xml">` (and Atom) in the head — a category, tag or author page its own feed and the blog's; or every page the feeds of all blogs; or none.
- **Light:** the feed is sent before Gridbox runs (Gridbox's own answer to `format=feed` would be an error page). An `ETag` and `Cache-Control` let readers and Cloudflare keep it; a reader asking again gets **304 Not Modified** while nothing changed. Dates are given in UTC (Gridbox keeps them in the site's time zone).
- A blog without a feed (not chosen, unpublished, not public) and a format not offered answer **404**; with the tool off, nothing changes.

**Settings:** tab *Blog feeds (RSS / Atom)* — on/off (off by default), the formats (both, RSS or Atom only), which Gridbox apps have a feed (none chosen: every blog; a portfolio or the shop can be chosen too), the number of posts (20), the text of a post (introduction, meta description, or title only), the intro image and whether it is also an attachment (off), the link to the full article and its text, the links in the head (blog pages, every page, none) and the refresh time in minutes (60). The panel *Feed addresses* lists every blog with a feed and its addresses.

### 🧹 Shop — tidy up old carts

Gridbox never removes a cart, so its cart tables only grow. **Clean and optimise** (tab *Shop*) removes the carts nobody can open any more — empty carts at once, carts with products unused for N days (default 30, at least 8, since Gridbox's cart cookie lives 7 days) — and rebuilds the tables so the database shrinks; **Check** shows what would go. Orders and their data, carts with attached files and carts in use are never touched. Can also run automatically once a day or above a cart limit (default: by hand only).

### 📋 Gridbox lists — your own columns and prices edited in the list

**Columns:** the button **Columns** above the lists of products, posts and pages in the Gridbox panel shows, hides and orders Gridbox's columns (the tick box and the title always stay) and adds others: sale price, minimum quantity, **product badges** and **versions/sets** (yes/no) for products, publishing date and end, and the fields of the app — e.g. *Producent* with the names of its options (fields without a name and those listed in *Hidden app fields* are not offered). Each user arranges each list for themselves; the choice is kept on the server (Joomla user profile), **As in Gridbox** brings Gridbox's columns back. The page is rearranged on the server, so the list does not flicker, and Gridbox's own functions (selection, context menu, featured, sorting, drag-ordering) work as before.

**Editing in the list:** a click on a product's **price, sale price, stock or SKU** turns the cell into a field (a click on its **badges** opens the list of the store's badges to tick) — Enter (or leaving it) saves, Esc cancels. Amounts may be typed as `1234,50`, `1 234,50` or `1234.5`; an empty sale price removes the sale. The change is checked on the server, noted in the edit history and the kept pages are emptied. A product open in the Gridbox editor cannot be changed in the list (the editor's next save would put the old value back), and a product with options shows ⓘ — the list changes its base price, the prices of the options stay in the editor. For users who may edit in Gridbox; both can be switched off on the tab *Gridbox lists*.

**Author:** Gridbox's column *Author* of the blog lists shows only Gridbox's own authors, as avatars; MerTools adds the Joomla user who created the page (from the edit history; for pages older than the history the first person who changed it, in grey). The lists without that column (products, pages) can add it with **Columns**.

**Drag and drop in the media manager:** files dragged from the computer and dropped on Gridbox's media manager — in the panel and in the editor — are uploaded to the folder open in it, as with its button *Upload* (the same allowed types, the progress count, the folder reloaded). Setting *Drag files into the media manager* (on by default).

**Items from other folders:** Gridbox lists a product (or post) only in its main folder, so a folder whose products were only added to it from other folders looked empty. Each folder now also lists them, after its own items and greyed out, under *Also in this folder, edited in their main folder: N*: no tick box, no editing in the list, no row menu, and the title says **Main folder: …** with a link there. The list's search and filters apply to them; on a list of more pages they are on the last one. Setting *Items from other folders* (on by default).

**“<” in introductions:** with libxml older than 2.9.11 (PHP on some hosts) Gridbox loses the text of an introduction from a “<” that starts no tag (“<30 mK”, “< 0,5 Ω”) to the next “>”, the end of its card with it — the grid or list breaks. MerTools keeps such a “<” as `&lt;` (after saves in Gridbox, at installation, daily) and fixes the description meta tags; it is shown as “<” everywhere.

### 🛡 Apps protection — no app deleted by one click, and copies to restore

Gridbox deletes an app — a shop, a blog — with one click on the **X** of its card on the Gridbox dashboard and one “Delete” in its question: the app, every product or post in it, its categories, fields, product data, tags, comments and reviews, past the trash and for good. There is no undo.

- **Lock.** Every app is locked against deletion (on by default): the X of its card shows a 🔒, and a click on it says how to unlock the app instead of opening Gridbox's question. The server refuses the deletion whatever sends it — the X of a card, the trash icon of an app in a folder of apps, *Delete app* in the toolbar of a single page app, or a request sent by hand (Gridbox asks for no form token there) — so the lock holds without the script too. To delete an app: choose it under *Apps that may be deleted*, save, delete it in Gridbox, take it off the list again.
- **Copy before a deletion.** Before Gridbox deletes an app that may be deleted, MerTools writes a copy of everything Gridbox deletes with it; with *No copy, no deletion* (on by default) an app whose copy cannot be written is not deleted, and Gridbox shows why.
- **Regular copies** of every app, daily or weekly (daily by default), made after a visit to the site — after the response has gone to the visitor where the server allows it (PHP-FPM, LiteSpeed), else one app an hour. An app that has not changed since its last copy gets no new one. The newest 7 regular copies of each app are kept (setting); copies made before a deletion or by hand stay until deleted. They also cover what the lock does not: products deleted one by one from the trash, an import gone wrong.
- **A copy** is a gzip file of JSON lines with the rows of the app in Gridbox's tables (the app, its order on the dashboard, categories, fields and their options, products or posts, their fields, files, category and tag links, product data and versions, tags, comments and reviews with their attachments and likes, badges, related products, authors) and the files Gridbox deletes with it (the styles of the app and its pages, files of the fields, attachments; a file over 20 MB is left out and noted). It is written and read a line at a time, so a large shop fits the memory. The copies are kept in `administrator/backups/mertools` (closed to the web with `.htaccess` and `web.config`, names with a random part) or in a folder of your own, best outside the web root.
- **Restore** (panel *Copies of the apps*) brings back what is missing and changes nothing that exists: the app when it is gone, the categories, fields and tags whose ids are free, and every product or post that is missing with its product data, fields, tags, categories, comments and reviews, then the missing files — with their own ids, so links, menu items and orders point at them again. A restore is checked first (the copy must be whole, no id it needs may be used by another app now) and the rows are written in one transaction. Restoring a copy again changes nothing. Smart Search: index the site again after a restore.
- The panel lists the apps with their state and a *Copy now* button, and every copy with its date, kind, content and size, to download, restore or delete. Copies put into the folder by hand (e.g. downloaded from another site) are listed too. The copies and their list (table `#__mertools_app_backups`) stay when MerTools is uninstalled.

Tested on Gridbox 2.20: a shop of 636 products and a blog deleted with Gridbox's own X and restored came back identical, row for row.

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

Phone numbers written as plain text (contact rows, footers, product pages) become `tel:` links, so a tap on a phone dials them — no need to add links by hand in Gridbox. Numbers written with the international prefix (`+48 22 531 00 94`, `0034 612 345 678`) are always found, and numbers without it (nine digits in groups: `22 531 00 94`, `(22) 531-00-94`, `533 394 222`) once the country's calling code is set (since 1.7.0 empty by default; installs updated from older versions keep 48); links, buttons, forms, code and numbers that are not phones (NIP, REGON, KRS, bank accounts, serial numbers, prices, standards, fax numbers) are left alone. The links look like the text around them (or like the site's links), the country code is added to those numbers for dialling from abroad, and areas can be excluded with a CSS selector. A small script does it in the browser when it is idle; the page content is not changed. On by default, tab *Phone numbers*.

### ⏱ Page speed — PageSpeed Insights panel with before/after

At the top of the tab, **Recommended settings** shows how Gridbox (Deferred Loading, page cache, lazy loading, image and CSS compression), Joomla (page cache plugin, platform specific caching), the server (PHP, GD with WebP, OPcache, and whether Joomla may clear OPcache after a save — where the hosting forbids it and PHP does not check the files, a saved Global Configuration seems to come back until OPcache is reset) and Cloudflare (Rocket Loader) are set on the site now, what MerTools works best with, why, and where to change it.

Tab *Page speed*: measure chosen pages with PageSpeed Insights (phone and desktop, median of 1–5 tests) and compare every result with a baseline taken before the changes — score and LCP, TBT, CLS, FCP, Speed Index and server time, with the change in green or red; the server column is the time until Google’s test had the whole HTML (Lighthouse’s own server time can be far too low behind Cloudflare). The history keeps the MerTools version and the tools that were on, and the real-user figures of Chrome (CrUX) show how fast the site is for its visitors. Needs a free PageSpeed Insights API key.

The same tab has the **faster pages** tools, done on the finished page (Gridbox files untouched): the page shown at once with Gridbox’s Deferred Loading (its style sheets back in the head instead of a page hidden until all scripts have run), images with their proportions at once, the first section loaded at once and the rest lazily (no layout jumps, Gridbox’s sizing kept), **lighter WebP copies in the size of the screen** made by PHP after the page has been sent (`srcset`, also for slideshow backgrounds), the **first frame of a heavy animated GIF** at once and its animation later, **icon style sheets that do not hold up the page**, **timed Gridbox popups opened not before the first interaction**, the main product photo shown and fetched first, **product photos as real pictures with the product's name as alt** laid on Gridbox's backgrounds (for image search; same look, same requests, Gridbox's zoom and dots untouched), a YouTube section background started after the first interaction or a few seconds after loading (on phones left out, with a dark section colour, or replaced by a picture), Gridbox’s own page data requests kept, and — optionally — marketing scripts (Tag Manager, Facebook, Clarity…) started at the first interaction and a **page cache for guests**: the finished page is kept and the next guests get it at once (their own form token and nonce put in; never for logged-in users, carts, checkout or pages with parameters; emptied on every save, order or comment; **warmed up** in the background, a few pages a minute from the sitemap, so that the first visitor after an emptying does not wait for a page to be built).

**Clear cache button in the top bar:** the administrator's top bar (next to the Joomla version) gets a **Clear cache** button. One click empties the pages kept by MerTools and the whole Joomla cache — as *System → Clear Cache* — from any screen of the administrator, and a message says what was removed. Shown to users who may clear the cache in Joomla; can be turned off in tab *Page speed*.

### 🧭 404 pages — moved pages followed, missing addresses logged and redirected

A missing page (404) gets, in this order:

1. **A redirect of its own**, typed for that address in the log (below) — a 301.
2. **The page it was** (setting *Follow renamed and moved pages*, on by default). A Gridbox address ends with the alias of its page or category (`/oferta/mierniki/…/sonel-mpi-540`). When a product or post gets another alias, or goes to another category, Gridbox answers its old address with 404 and the links to it — in Google, in e-mails, on other sites — lead nowhere. MerTools keeps the aliases of every Gridbox page and category (`#__mertools_aliases`), notes each one that changes (`#__mertools_moved`: after a save in Gridbox that may change an alias, once an hour and at installation; the alias changes the edit history holds since 1.0.3 are taken over at the start), and sends a missing address whose last part is the alias of a page or category — of today or an earlier one — to where it is now, with a **301**. Of several such pages (the same alias in two apps), the one sharing the longest beginning with the missing address wins (the same app and category). Only published pages and categories a guest may see; never to the missing address itself.
3. **410 Gone for a withdrawn page** (setting *Withdrawn pages*, 410 by default): when everything with the alias the missing address ends with is unpublished, in the trash, past its end of publishing or deleted — MerTools keeps the aliases of deleted pages and categories (`#__mertools_gone`) — the address answers **410** with the same error page, so search engines drop it sooner than after a 404. On merserwis.pl withdrawn products are unpublished, not deleted: 290 of the addresses in the log were such products, sent to the home page until 1.6.2. Or the visitor goes to the category the page was in (a 301) when a guest may see it.
4. **The general target** (setting *Redirect missing pages*, **off by default since 1.6.2**): the home page, a menu item or any address, with a permanent (301) or temporary (302) redirect. Not recommended: Google counts a missing page sent to the home page as missing anyway (a “soft 404”), and agents probing addresses (`/.well-known/…`) take the home page for what they asked. Off, a missing address gets the error page with a real 404, and the log shows which addresses deserve a redirect of their own.

**Log of missing addresses** (setting *Log missing addresses*, on by default): every page address that answered 404 (`#__mertools_notfound`) with its number of visits, the first and the last one, the page the last visitor came from and what MerTools did with it — no IP address or any other data of the visitors. The panel on the tab lists them (search; all, not redirected to a page of their own, or redirected; newest or most visited first) with a field for a redirect of its own and a **suggestion** — a page or category with a similar alias, one click to use it. The old aliases of renamed pages and categories are listed under it, with the address of each page today, and can be deleted. Addresses not visited for 90 days (setting) go; those with a redirect of their own stay; never more than 10 000.

Redirects set for single addresses in Joomla's Redirects component come before all of it; form posts, AJAX/JSON requests and excluded paths keep the normal 404 (excluded paths are not logged either). The log, the old aliases and the aliases of deleted pages stay when MerTools is uninstalled. Tab *404 pages*.

### 🗺 Sitemap — only the addresses worth indexing, with true dates, one map per app

Gridbox writes one static `sitemap.xml` with every address it knows: pages with `noindex`, tag pages that robots.txt blocks, and nearly all of them with a date from the last few days — every save in Gridbox's editor deletes the CSS of all pages, and the next visit of each page writes it again with a new `saved_time`, which Gridbox gives as the page's date. Search Console reports the blocked and `noindex` addresses as errors, and Google learns to ignore the dates.

With the tool on (setting *Sitemap of MerTools*, off by default), `/sitemap.xml` is an **index** and each Gridbox app has its own map — `sitemap-products-2.xml`, `sitemap-blog-4.xml`, `sitemap-pages.xml` for the menu items and single pages, `-p2`, `-p3`… over 10 000 addresses (setting) — so Search Console shows the indexing of the shop, the blog and the pages apart. Or all in one file.

- **The addresses**: Gridbox's own scope (menu items, single pages, and for each app its home page, categories, tags, authors and pages, an item with a menu item of its own once), its links (built by Gridbox's functions, so the addresses are the same as in its sitemap — on merserwis.pl all 4655 of them), its include flags (setting).
- **Left out**, each with a reason counted in the panel: not published, switched off in Gridbox, `noindex` (the robots of the page as Gridbox writes it: the menu item, the page, the Global Configuration), blocked by robots.txt for `*` or Googlebot (groups, Allow/Disallow, the longest match wins, `*` and `$`), types switched off (tags and authors by default), excluded apps, excluded paths (`/sandbox`, `/blog/regulamin/*`), excluded titles (`[ZAKOŃCZONE]`), addresses with parameters, repeated addresses.
- **The dates** (`lastmod`, in UTC to the second): the moment the content of a page changed, not the moment it was saved — a hash of its texts, fields (a product's parameters), title, address and picture, kept in `#__mertools_sitemap`; with *Count price and stock changes* a product's price, stock, variations and options too. A page seen for the first time takes its newest change from the edit history; without one it has no date (or, by setting, the date of publishing). A category, an app's home page, a tag and an author take the newest date of their pages, a menu item the date of what it shows. No `changefreq` and no `priority` (Google ignores them).
- **Fast**: the maps are built into files in Joomla's cache folder (about 4 s for 4500 addresses) and sent from there before Joomla's router, with `ETag`, `Last-Modified` and 304 for a repeated request; a map that does not exist is a real 404. They are built again after the refresh time (360 min, setting), after a change in Gridbox or the menus, robots.txt or the settings — the request that finds them out of date gets them at once, the new ones are built after its answer.
- **Take over from Gridbox**: while Gridbox's `sitemap.xml` exists, the server sends it before Joomla runs, so `/sitemap.xml` answers with it; the maps `/sitemap-…xml` answer from the start. The button *Take over from Gridbox* copies its file to `/tmp`, switches Gridbox's sitemap off (so it is not written again), deletes the file, builds the maps and checks `/sitemap.xml`; *Undo* puts it all back. *Compare with Gridbox's sitemap* lists the addresses of Gridbox's file that are not in the maps, each with its reason.
- **The panel**: who answers `/sitemap.xml`, Gridbox's flag and file, the Sitemap line of robots.txt, the last build, the maps (addresses, newest date, size), what is left out per reason (with a search), a field *Why is this address not in the map?* and a warning when the dates look changed in bulk.

The dates stay when MerTools is uninstalled (without them every date would start again as unknown); the built files go. Tab *Sitemap*.

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
2. Install it in Joomla (Extensions → Install). The package installs the system plugin and its administrator component.
3. The plugin is enabled on a fresh install. Open **Components → MerTools for Gridbox** and start with the **Quick Setup**.
4. Check your site (see the note at the top) after every tool you turn on.

## Package structure

| Extension | What it is |
|---|---|
| `plg_system_mertools` | The system plugin — the tools and their settings. |
| `com_mertools` | The administrator: control panel, quick setup, the pages of the tools and the configuration (the settings themselves are the plugin's parameters). |

## Requirements

- Joomla 6 (tested with Joomla 6.2)
- Balbooa Gridbox
- PHP 8.2+ (tested with PHP 8.5.12)

## License

GNU General Public License version 3. Copyright © 2026 Merserwis.
