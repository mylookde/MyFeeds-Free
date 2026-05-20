=== MyFeeds — Affiliate Product Feed Manager ===
Contributors: myfeeds
Tags: affiliate, affiliate marketing, affiliate links, product feed, awin
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 1.0.8
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any affiliate product feed into searchable product cards you insert from the WordPress block editor.

== Description ==

**Stop copy-pasting affiliate products. Stop fixing dead links by hand. Start writing.**

You write about products you recommend. You drop them into your posts. A few weeks pass, and half the prices are off, a couple of products went out of stock without telling you, and one merchant quietly disappeared from your network. Every roundup, every gift guide, every product page tells the same story. Quietly going stale while you write the next one.

MyFeeds takes your affiliate program's product feed and quietly keeps it in sync with your site. You pick a product inside the block editor the way you'd pick an image, hit publish, and move on. The next morning the prices are still right. The dead products are flagged. Your readers see what the merchant is actually selling today.

You get to stay where the value is. Writing. Instead of pasting URLs at midnight.

= Who is this for =

Anyone earning a cut when readers click and buy. Whatever you cover, from clothing and gear to books, beauty, supplements, tools, baby, garden, hobbies, niche electronics, or deals: if there's an affiliate program for it, there's a product feed somewhere, and MyFeeds can read it.

The block editor stays your block editor. The plugin works in the background.

= What changes for you =

* **Your prices stop lying.** The price your reader sees today is the price on the merchant's checkout right now.
* **Your posts stop rotting.** Dead products surface so you can replace them. Stock that comes back lights up again. Nothing decays silently.
* **You publish faster.** Two letters in the editor, the product appears, you click, the card is in. No new tab, no copy, no paste.
* **Your site stays yours.** Products live in your own WordPress database. Visitors don't wait on a third-party server, and nothing about them is sent off-site when a page loads.
* **No translation tax.** Whatever your program sends you, MyFeeds reads it and figures out which column is the price, which is the image, which is the link. You don't have to learn the format.

= How it works =

1. Drop your **affiliate product feed URL** into MyFeeds.
2. Every product is imported and stored locally in your WordPress database. The plugin figures out the column structure on its own.
3. In any post or page, add the **MyFeeds Product Picker** block. Search by name, brand, or category. Click to insert.
4. The published page renders a responsive product card with the current price, image, brand, shipping, and your affiliate link. All served direct from your database, with no external call on render.

The next day the nightly sync refreshes what changed. The week after, a full import catches everything else. You don't think about it.

= What's in the box =

* Universal feed import. Almost any format your program hands you, detected automatically.
* Smart Mapping. Automatic recognition of common feed structures, with a manual editor for anything custom.
* Smart Search inside the block editor with synonym handling and multi-language support.
* Native Gutenberg **Product Picker** block with live in-editor search.
* Responsive product grid with prices, brands, shipping, and your affiliate links.
* Background imports. Large feeds process without locking your admin.
* Nightly auto-sync and weekly full re-import, scheduled and quiet.
* Honest pricing. What the feed publishes is what visitors see. No silent currency assumptions.
* Works with any WordPress theme that supports the block editor.

= A few things worth saying out loud =

* No CSV downloads, no FTP, no spreadsheet uploads, no manual price updates.
* Self-hosted. The frontend never contacts an external service to render a product.
* If your program publishes a feed file you can download, MyFeeds will almost certainly import it.

= Related paid plugins =

This plugin is fully functional on its own. Separate, independent paid plugins called **MyFeeds Pro** and **MyFeeds Business** are available at [myfeeds.site](https://myfeeds.site). They add things like a carousel block, a visual card designer with Google Fonts, click and conversion analytics, and a full multi-feed shop system. They are not required to use this plugin.

== Installation ==

1. Upload the `myfeeds-affiliate-feed-manager` folder to the `/wp-content/plugins/` directory, or install the plugin directly from the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **MyFeeds** in your admin sidebar and click **Add your first feed**.
4. Paste a product feed URL from your affiliate network and click **Import**.
5. In the block editor, add the **MyFeeds – Product Picker** block to any post or page.
6. Search for products, select them, and publish.

= Where do I get a product feed URL? =

Sign up with an affiliate network (such as AWIN, CJ Affiliate, Rakuten, or Impact), navigate to the product feed section (usually labelled "Create a feed" or "Product feeds"), and copy the feed URL.

== Frequently Asked Questions ==

= How long does an import take? =

It depends on the feed size. A feed with 10,000 products typically takes 2–5 minutes. Imports run in the background via Action Scheduler, so you can keep working while they process.

= Why are some products missing after import? =

Only products with valid data (title, price, image, and affiliate link) are imported. Check your feed source for incomplete entries.

= Does MyFeeds slow down my site? =

No. All product data is stored locally in your WordPress database. The frontend makes no external API calls, so your site stays fast.

= Does MyFeeds work with any theme? =

Yes, it works with any WordPress theme that supports the Gutenberg block editor (WordPress 5.8+).

= Can I use MyFeeds with the Classic Editor? =

No. MyFeeds requires the block editor.

= Which affiliate networks are supported? =

If your affiliate program hands you a product feed URL you can download, MyFeeds will almost certainly read it. The plugin handles the common feed formats automatically and recognises the field structure that most networks use. For everything custom there's a manual mapping editor inside the plugin.

= Does the plugin make any external requests? =

Yes. See **External Services** below. In short: when you add an AWIN feed, the plugin talks to the official AWIN Publisher API to confirm your credentials and look up feed URLs. No data leaves your site on the frontend.

== External Services ==

This plugin connects to external services only when the site administrator chooses to configure a feed that uses them. No external services are contacted on the frontend or for visitors.

= AWIN Publisher API =

When you add an AWIN feed in the WordPress admin, the plugin calls the AWIN Publisher API on your behalf to verify your publisher credentials, look up your approved advertisers, and resolve their feed download URLs so the import job knows where to pull the product feed from.

* **What data is sent:** your AWIN publisher ID, the advertiser ID, and your AWIN API key (passed as an HTTP header). No WordPress user data, no visitor data, and nothing from the frontend is transmitted.
* **When it is sent:** only in the WordPress admin, when you open the AWIN feed setup dialog, verify credentials, or trigger a feed refresh. No frontend page view ever calls this API.
* **Where it is sent:** `https://api.awin.com/`, AWIN's official publisher API endpoint.
* **Why:** AWIN requires publishers to fetch feed download URLs via their API rather than hard-coding them, because the URLs are rotated and tied to your publisher account.

AWIN's terms of service and privacy policy apply to this data exchange:

* Terms of Service: <https://www.awin.com/gb/publisher-terms>
* Privacy Policy: <https://www.awin.com/gb/legal/privacy-policy>

= Configured product feed URL (your affiliate network) =

To import products, the plugin downloads the feed file from the URL you save in the Feed Manager. The feed URL points to your affiliate network's product feed export, in CSV, TSV, XML, or JSON format.

* **What data is sent:** an HTTP GET request to the feed URL with a `User-Agent` header identifying the WordPress site and plugin version. No publisher credentials, user data, or visitor data are sent in the request body.
* **When it is sent:** in the WordPress admin only, when you click "Reimport", and on the configured cron schedule (nightly quick sync and weekly full import). The frontend never calls the feed URL.
* **Where it is sent:** to the host in the feed URL you configure. The plugin does not share that URL with any third party.

Because the feed URL itself is provided by an affiliate network, the privacy and terms of that download are governed by that network. Please refer to your network's terms of service and privacy policy for details on what they record about feed downloads.

No data is sent to any other external service. The plugin stores imported products in your own WordPress database and serves them from there; the frontend never contacts an external host to render a product.

== Source Code ==

The full source for this plugin is open-source. See <https://myfeeds.site> for the project homepage and links to the public repository.

* Block editor source: `src/index.js`
* Build tool: terser via `npm run build` (configuration in `package.json`)

To rebuild the editor bundle from source, run `npm install && npm run build` inside the plugin folder.

== Screenshots ==

1. Feed Manager. Configure your affiliate product feed with status, product count, and mapping quality.
2. Background Import. Imports run in the background with a real-time progress bar. Continue working while products are imported.
3. Smart Mapping. Automatic field detection with manual override. Maps any CSV/TSV/XML/JSON feed to product fields.
4. MyFeeds Product Picker block. Search the local product index and select products inline in the editor.
5. Grid layout. Products rendered in a responsive grid with prices, brands, shipping info, and affiliate links.

== Changelog ==

= 1.0.8 =
* Compatibility: tested with WordPress 7.0. No code changes — the new "Modern" admin theme renders MyFeeds screens cleanly, and the iframed editor falls back to non-iframe mode for posts containing the product-picker block (block-API v3 upgrade is a future-proofing item, not a regression).

= 1.0.7 =
* Smart Search: the result counter and the facet pills now report the same number of products the grid actually renders. Previously, every size variant of a product was tallied separately in the header total and in the brand/colour/category pills, so a search for "head" could promise "17 results" or "Bape (15)" and then deliver 5 and 3 once the result deduplicator collapsed the sizes. Counts now flow through the same size-suffix pipeline as the result set.

= 1.0.6 =
* Smart Search: fixed a recall bug where any query containing a short token (e.g. "air force 1" or "nike 1") returned zero results because the FULLTEXT engine drops sub-min tokens from required clauses and the LIKE fallback was using a MySQL 5.x word-boundary regex that broke on 8.0.4+. Short tokens now AND-constrain the FULLTEXT match via a portable space-padded LIKE.
* Smart Search: quoted-phrase queries ("air force 1") now use a substring LIKE constraint instead of a FULLTEXT phrase clause, so phrases that contain short tokens work too. Quote characters are also properly stripped before tokenization.
* Smart Search: phrase + filter combinations now honour the phrase in facet aggregation and the honest-total count, so the result number and the facet pills stay in sync when you have a quoted phrase active.

= 1.0.5 =
* Smart Search: the picker can now narrow a result set without leaving the page. Brand, colour, category and price all live as one-click filters with live counts that respect every other active filter. Sort by best match, price, biggest discount or newest.
* Smart Search: results-as-you-type. The picker refetches after a short pause so you stop having to hit Enter every time you change your mind.
* Smart Search: did-you-mean. Type "addidas" and the picker offers "adidas" instead of returning nothing. Powered by edit-distance against your own product vocabulary, so it learns from the feeds you import.
* Smart Search: phrase support. Put "nike air max" in double quotes and exact matches float to the top.
* Smart Search: smart query parser. Type "schwarze sneaker unter 80 euro im sale" and the price + sale intent get pulled out of the query automatically.
* Smart Search: visual colour picker. Tick a colour swatch instead of typing the colour name.
* Smart Search: recently used products show up as quick-insert chips before you type anything, so a product you used yesterday is one click away.
* Smart Search: honest result count. The total at the top now reflects the real number of products in your feed that match, not just the dedup'd top of the fetched batch.
* Smart Mapper: self-healing repair pass at every sync (previously released in 1.0.4) — corrects a stale mapping when a merchant drops a column instead of writing default values into your DB.

= 1.0.4 =
* Smart Mapper: self-healing repair pass. After the initial auto-map the mapper now checks each chosen source column against a real sample row. If the column is empty for that row, the mapper walks the full ranked candidate list and swaps in the next column that genuinely carries data. Same pass runs at the start of every sync so a stale mapping (column dropped by the merchant after the feed was first added) gets corrected before the import writes default values into the DB.
* Smart Mapper: kept-not-dropped policy. When no better alternative shows up in the inspected sample row, the existing mapping is kept rather than removed - the inspected row is one of thousands and the column may be populated for most products even if empty in the first row.

= 1.0.3 =
* Content Health: new read-only card on the MyFeeds page that surfaces published posts referencing products no longer in your feed. Shows the count, lists the affected post titles with how many products are missing each, and refreshes itself after every sync.
* Importer: Quick Sync now self-heals from a crashed background worker. If the worker is killed mid-feed (PHP timeout, host OOM, feed-download stall), a watchdog auto-cancels the stale "running" state after 5 minutes so the UI stops looping on a phantom progress bar.
* Importer: Cancel during a large batch is now respected. Previously a Cancel click that landed mid-feed could be silently overwritten by the in-flight batch finishing, leaving the UI showing IMPORTING for a sync that was actually done.
* Importer: each Quick Sync now writes a starting-feed and finished-feed log line so a future stall points directly at the culprit feed.
* Admin assets: per-file cache-buster so a single CSS or JS tweak invalidates the browser cache instantly between releases.
* Listing copy: short description and feature list rewritten to focus on what changes for you (prices stop lying, posts stop rotting, you publish faster) instead of technical plumbing.

= 1.0.2 =
* Mapping Editor: new pill-style mapping quality bar with three buckets (>=90 green, >=70 orange, <70 red) - same palette as the feed-status badges.
* Mapping Editor: click the quality bar to open a detail modal that lists every standard field with its actual source column from the feed (`<source_column>` to `<db_column>`) plus per-field tier and missing-row counts. Makes it obvious when the mapper picked the wrong source slot.
* Mapping Editor: fixed a stale "Select a feed" dropdown bug - entries left behind from older multi-feed installs are now filtered out and the option is self-healed on first render.
* Mapping Editor: long feed column names like `merchant_product_category_path` no longer push selects out of their card. Field rows now shrink correctly inside the grid.
* Mapping Editor: bigger help icons with an instant on-hover tooltip that shows the field description (no more 1 second browser delay).
* Mapping Editor: redesigned with the plugin's brand styling - cleaner cards, brand-accent panel titles, draggable pill-style column tags, focus rings, brand-gradient primary CTA.
* Plugins screen: added an Upgrade action link on the plugin row that points at myfeeds.site.
* New marketing preview pages for the Shop, Card Design and Analytics features - opened from the MyFeeds submenu, each shows a benefits overview and screenshots from the paid plugins.
* Cleanup: removed the legacy dismissible top banner and the in-plugin Contact Us page (use the wp.org support forum or myfeeds.site/contact instead).
* Internal: stripped emoji prefixes from debug-log lines.

= 1.0.1 =
* Importer: detect feed format from the URL (AWIN `format/csv` path, query strings like `?format=csv`, file extensions) so large AWIN datafeeds with 80+ columns no longer get misclassified.
* Importer: format detection now reads 32 KB instead of 4 KB and walks the first unquoted line, so commas inside quoted product descriptions stop fooling the delimiter vote.
* Importer: network-agnostic product-id detection covers AWIN, CJ, ShareASale, Belboon, Impact, Webgains, Tradedoubler, Adcell, Daisycon, and standard EAN/GTIN/UPC/MPN keys out of the box.
* Importer: stop dropping `original_price` when the feed's rrp/list price equals the current price. The mapping is preserved; the strike-through display still only kicks in when there is a real discount.
* Importer: stop defaulting `currency` to EUR when the feed has no currency column. Empty stays empty so a USD merchant never shows "€" on cards that link to a USD checkout.
* Smart Mapper: AWIN category mapping now probes `category_name`, `merchant_product_category_path`, `merchant_category`, `product_type`, and the Fashion-feed taxonomy in order, so merchants that only fill the breadcrumb path get categorised correctly.
* Card display: cap product-card z-indexes so they no longer punch through sticky theme headers.
* Card display: drive grid gap and padding from CSS variables (no visible change with defaults).
* Card display: remove mobile typography hardcodes that overrode user font-size settings; lock card line-height against host themes so prices stop inheriting oversized body line-heights.

= 1.0.0 =
* Initial release on WordPress.org.
* Universal CSV, TSV, XML, and JSON feed parser.
* Smart Mapping with automatic field detection and manual override.
* Smart Search with FULLTEXT indexing, synonym expansion, and German-language handling.
* MyFeeds – Product Picker Gutenberg block with a responsive grid layout.
* Background imports via the bundled Action Scheduler library.
* Nightly quick sync (active products) and weekly full import via WP-Cron.
* AWIN Publisher API integration for credential and feed-URL resolution.

== Upgrade Notice ==

= 1.0.8 =
WordPress 7.0 compatibility confirmed. No code changes, just a tested-up-to bump so the plugin keeps its clean WordPress.org listing.

= 1.0.7 =
Honest counts: the result total and facet pills in the product picker now match the grid below them. Size variants are no longer double-counted in the header.

= 1.0.6 =
Recall fix: queries with a short token like "air force 1" or "nike 1" now return matches instead of an empty list. Recommended for anyone running MySQL 8 (most modern hosts).

= 1.0.5 =
The product picker grew a real search surface: filters for brand, colour, category and price, a sort dropdown, did-you-mean recovery, phrase matching and a visual colour swatch picker. Type-and-search-live; the results refetch as you change your mind.

= 1.0.4 =
The Smart Mapper now double-checks its picks against a real sample row and swaps in the next-best column when its first choice is empty - so a merchant dropping a column after the feed was first added stops silently writing default values into your DB.

= 1.0.3 =
A new Content Health card on the MyFeeds page tells you when a post still links to products that have dropped out of your feed. Quick Sync now self-heals from a crashed background worker, and a Cancel mid-batch is finally respected.

= 1.0.2 =
Mapping Editor overhaul: redesigned with the plugin's brand, a new pill-style quality bar that opens a detail modal showing the actual source column for every field, plus fixes for stale dropdown entries and select-overflow with long column names.

= 1.0.1 =
Bug-fix release. Importer reliability for large AWIN datafeeds, faithful currency handling (no more silent EUR default), better category mapping for merchants that use breadcrumb paths, and card-display fixes against sticky theme headers and mobile typography overrides.

= 1.0.0 =
Welcome to MyFeeds. Import your first affiliate product feed and start showcasing products in your posts.
