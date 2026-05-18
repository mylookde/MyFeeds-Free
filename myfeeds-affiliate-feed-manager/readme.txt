=== MyFeeds — Affiliate Product Feed Manager ===
Contributors: myfeeds
Tags: affiliate, affiliate marketing, affiliate links, product feed, awin
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stop pasting affiliate links by hand. Your network's product feed becomes a searchable catalog inside WordPress — insert cards from the block editor.

== Description ==

**Stop copy-pasting affiliate products. Stop fixing dead links by hand. Start writing.**

You write about products you recommend. You drop them into your posts. A few weeks pass, and half the prices are off, a couple of products went out of stock without telling you, and one merchant quietly disappeared from your network. Every roundup, every gift guide, every product page tells the same story — slowly going stale while you write the next one.

MyFeeds takes your affiliate program's product feed and quietly keeps it in sync with your site. You pick a product inside the block editor the way you'd pick an image, hit publish, and move on. The next morning the prices are still right. The dead products are flagged. Your readers see what the merchant is actually selling today.

You get to stay where the value is — writing — instead of pasting URLs at midnight.

= Who is this for =

Anyone earning a cut when readers click and buy. Whatever you cover — clothing, gear, books, beauty, supplements, tools, baby, garden, hobbies, niche electronics, deals — if there's an affiliate program for it, there's a product feed somewhere, and MyFeeds can read it.

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
4. The published page renders a responsive product card with the current price, image, brand, shipping, and your affiliate link — direct from your database, no external call on render.

The next day the nightly sync refreshes what changed. The week after, a full import catches everything else. You don't think about it.

= What's in the box =

* Universal feed import — almost any format your program hands you, detected automatically.
* Smart Mapping — automatic recognition of common feed structures, with a manual editor for anything custom.
* Smart Search inside the block editor with synonym handling and multi-language support.
* Native Gutenberg **Product Picker** block with live in-editor search.
* Responsive product grid with prices, brands, shipping, and your affiliate links.
* Background imports — large feeds process without locking your admin.
* Nightly auto-sync and weekly full re-import, scheduled and quiet.
* Honest pricing — what the feed publishes is what visitors see. No silent currency assumptions.
* Works with any WordPress theme that supports the block editor.

= A few things worth saying out loud =

* No CSV downloads, no FTP, no spreadsheet uploads, no manual price updates.
* Self-hosted. The frontend never contacts an external service to render a product.
* If your program publishes a feed file you can download, MyFeeds will almost certainly import it.

= Related paid plugins =

This plugin is fully functional on its own. Separate, independent paid plugins called **MyFeeds Pro** and **MyFeeds Business** are available at [myfeeds.site](https://myfeeds.site) — they add things like a carousel block, a visual card designer with Google Fonts, click and conversion analytics, and a full multi-feed storefront system. They are not required to use this plugin.

== Installation ==

1. Upload the `myfeeds-affiliate-feed-manager` folder to the `/wp-content/plugins/` directory, or install the plugin directly from the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **MyFeeds** in your admin sidebar and click **Add your first feed**.
4. Paste a product feed URL from your affiliate network and click **Import**.
5. In the block editor, add the **MyFeeds – Product Picker** block to any post or page.
6. Search for products, select them, and publish.

= Where do I get a product feed URL? =

Sign up with an affiliate network (such as AWIN, CJ Affiliate, Rakuten, or Impact), navigate to the product feed section — usually labelled "Create a feed" or "Product feeds" — and copy the feed URL.

== Frequently Asked Questions ==

= How long does an import take? =

It depends on the feed size. A feed with 10,000 products typically takes 2–5 minutes. Imports run in the background via Action Scheduler, so you can keep working while they process.

= Why are some products missing after import? =

Only products with valid data (title, price, image, and affiliate link) are imported. Check your feed source for incomplete entries.

= Does MyFeeds slow down my site? =

No. All product data is stored locally in your WordPress database. The frontend makes no external API calls — your site stays fast.

= Does MyFeeds work with any theme? =

Yes, it works with any WordPress theme that supports the Gutenberg block editor (WordPress 5.8+).

= Can I use MyFeeds with the Classic Editor? =

No. MyFeeds requires the block editor.

= Which affiliate networks are supported? =

If your affiliate program hands you a product feed URL you can download, MyFeeds will almost certainly read it. The plugin handles the common feed formats automatically and recognises the field structure that most networks use. For everything custom there's a manual mapping editor inside the plugin.

= Does the plugin make any external requests? =

Yes — see **External Services** below. In short: when you add an AWIN feed, the plugin talks to the official AWIN Publisher API to confirm your credentials and look up feed URLs. No data leaves your site on the frontend.

== External Services ==

This plugin connects to external services only when the site administrator chooses to configure a feed that uses them. No external services are contacted on the frontend or for visitors.

= AWIN Publisher API =

When you add an AWIN feed in the WordPress admin, the plugin calls the AWIN Publisher API on your behalf to verify your publisher credentials, look up your approved advertisers, and resolve their feed download URLs so the import job knows where to pull the product feed from.

* **What data is sent:** your AWIN publisher ID, the advertiser ID, and your AWIN API key (passed as an HTTP header). No WordPress user data, no visitor data, and nothing from the frontend is transmitted.
* **When it is sent:** only in the WordPress admin, when you open the AWIN feed setup dialog, verify credentials, or trigger a feed refresh. No frontend page view ever calls this API.
* **Where it is sent:** `https://api.awin.com/` — AWIN's official publisher API endpoint.
* **Why:** AWIN requires publishers to fetch feed download URLs via their API rather than hard-coding them, because the URLs are rotated and tied to your publisher account.

AWIN's terms of service and privacy policy apply to this data exchange:

* Terms of Service: <https://www.awin.com/gb/publisher-terms>
* Privacy Policy: <https://www.awin.com/gb/legal/privacy-policy>

= Configured product feed URL (any affiliate network) =

To import products, the plugin downloads the feed file from the URL you save in the Feed Manager. The feed URL points to your affiliate network's product feed export — for example AWIN (`https://productdata.awin.com/...`), Tradedoubler (`https://fr.tradedoubler.com/...`), Webgains, Rakuten, Admitad, or any other network that hands you a CSV/TSV/XML/JSON feed URL.

* **What data is sent:** an HTTP GET request to the feed URL with a `User-Agent` header identifying the WordPress site and plugin version. No publisher credentials, user data, or visitor data are sent in the request body.
* **When it is sent:** in the WordPress admin only, when you click "Reimport", and on the configured cron schedule (nightly quick sync and weekly full import). The frontend never calls the feed URL.
* **Where it is sent:** to the host in the feed URL you configure. The plugin does not share that URL with any third party.

Because the feed URL itself is provided by an affiliate network, the privacy and terms of that download are governed by that network. The two networks listed in our test matrix publish their terms and privacy policies at:

* **Tradedoubler** — Terms: <https://www.tradedoubler.com/terms-conditions>, Privacy Policy: <https://www.tradedoubler.com/privacy-policy>
* **AWIN** — Terms: <https://www.awin.com/gb/publisher-terms>, Privacy Policy: <https://www.awin.com/gb/legal/privacy-policy>

If you configure a feed URL from a different network (Webgains, Rakuten, Admitad, etc.), the terms and privacy policy of that network apply to the feed download. Please refer to your network's documentation.

No data is sent to any other external service. The plugin stores imported products in your own WordPress database and serves them from there; the frontend never contacts an external host to render a product.

== Source Code ==

The full source for this plugin is open-source. See <https://myfeeds.site> for the project homepage and links to the public repository.

* Block editor source: `src/index.js`
* Build tool: terser via `npm run build` (configuration in `package.json`)

To rebuild the editor bundle from source, run `npm install && npm run build` inside the plugin folder.

== Screenshots ==

1. Feed Manager — configure your affiliate product feed with status, product count, and mapping quality.
2. Background Import — imports run in the background with a real-time progress bar. Continue working while products are imported.
3. Smart Mapping — automatic field detection with manual override. Maps any CSV/TSV/XML/JSON feed to product fields.
4. MyFeeds – Product Picker block — search the local product index and select products inline in the editor.
5. Grid layout — products rendered in a responsive grid with prices, brands, shipping info, and affiliate links.

== Changelog ==

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

= 1.0.2 =
Mapping Editor overhaul: redesigned with the plugin's brand, a new pill-style quality bar that opens a detail modal showing the actual source column for every field, plus fixes for stale dropdown entries and select-overflow with long column names.

= 1.0.1 =
Bug-fix release. Importer reliability for large AWIN datafeeds, faithful currency handling (no more silent EUR default), better category mapping for merchants that use breadcrumb paths, and card-display fixes against sticky theme headers and mobile typography overrides.

= 1.0.0 =
Welcome to MyFeeds. Import your first affiliate product feed and start showcasing products in your posts.
