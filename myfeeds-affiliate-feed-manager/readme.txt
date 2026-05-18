=== MyFeeds — Affiliate Product Feed Manager ===
Contributors: myfeeds
Tags: affiliate, affiliate marketing, affiliate links, product feed, awin
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import an affiliate product feed, search it locally, and showcase products in your posts with a native Gutenberg block.

== Description ==

**Stop copy-pasting affiliate products. Stop fixing dead links by hand. Start writing.**

MyFeeds — Affiliate Product Feed Manager turns your affiliate network's product feed into a locally searchable product catalog inside WordPress. Drop product cards into any blog post or page from the Gutenberg block editor — prices, images, brands and affiliate links stay current on every sync, and your visitors never wait on an external API.

Built for **fashion bloggers**, **tech reviewers**, **deal sites** and anyone running affiliate marketing on WordPress. Pick products in the editor, publish, move on. No CSV downloads, no FTP juggling, no admin overhead.

= Why MyFeeds? =

Existing WordPress affiliate plugins force a choice between **Amazon-only convenience** and **manual link-rotation tools**. MyFeeds covers the middle ground: any affiliate network with a product feed, fully imported into your own database, surfaced through a native block editor block.

* **Any affiliate network with a feed** — AWIN, CJ Affiliate, Tradedoubler, Webgains, Rakuten, Admitad, Impact, ShareASale, and any other network that exports a CSV, TSV, XML or JSON product feed.
* **Self-hosted product data** — every imported product lives in your WordPress database. The frontend never calls an external service when a visitor loads a page. Faster pages, GDPR-friendly, no third-party tracking on render.
* **Native Gutenberg block** — search by name, brand or category inside the editor and insert product cards inline. Not a shortcode wrapper.
* **Smart auto-mapping** — the importer recognises the column structure of major networks out of the box (AWIN's `aw_product_id`, CJ's `advertiser_sku`, Tradedoubler's `TDProductId`, and many more). Manual override for the rest.
* **Smart search with German support** — FULLTEXT index with synonyms, German stemming, umlaut normalisation, gender-aware filtering. Searches like "schuhe", "Schuh" and "shoes" all return the same products.
* **Truthful pricing** — no silent EUR defaults, no fake discount markers. What the feed publishes is what visitors see.
* **Built for large feeds** — background imports via Action Scheduler, 32 KB delimiter sniffing for messy CSV headers, RFC4180-aware quote handling. 80-column AWIN feeds with quoted descriptions parse correctly out of the box.

= How it works =

1. Paste your **affiliate product feed URL** from your network (AWIN, CJ, Tradedoubler, Rakuten, Webgains, Admitad, Impact, ShareASale, or any other CSV/TSV/XML/JSON exporter).
2. MyFeeds imports and indexes every product locally in your **WordPress database**.
3. In any post or page, add the **MyFeeds – Product Picker** block, search by name, brand or category, and click to insert.
4. The published page renders a **responsive product grid** with live prices, images, brands, shipping info, and your affiliate links — straight from your database. No frontend external calls.

= Use cases =

* **Affiliate roundup posts** — "20 best running shoes 2026", "Top 10 sustainable fashion brands" — replace dozens of hand-coded product blocks with a single Product Picker.
* **Fashion and lifestyle blogs** — pick today's outfit recommendations in the editor, let the nightly sync keep prices in line with the merchant store.
* **Tech review sites** — link to current product variants without rewriting old posts when SKUs change.
* **Deal aggregators** — search a multi-network catalog for matching deals and surface them inside long-form content.
* **Niche review sites** — multi-network catalog instead of being locked into one affiliate program.

= Features =

* Universal feed import — CSV, TSV, XML, JSON and gzipped variants, all detected automatically from the URL
* Smart Mapping — network-aware automatic field detection for AWIN, CJ, Tradedoubler, Webgains, Rakuten, Impact, ShareASale, Belboon, Adcell and more
* Smart Search — FULLTEXT index with synonyms, German stemming, umlaut normalisation, gender-aware filtering
* Native Gutenberg **Product Picker** block with live in-editor search and multi-select
* Responsive product grid with prices, brands, shipping info and configurable affiliate links
* Background imports via Action Scheduler — no admin lock-up on large feeds
* Nightly quick sync (active products) plus weekly full re-import via WP-Cron
* AWIN Publisher API integration — verify credentials and resolve feed URLs without leaving WordPress
* Truthful currency handling — no hardcoded EUR fallback
* Works with any block-editor compatible WordPress theme (5.8+)
* Self-hosted, no frontend external calls — products render from your own database

= Related paid plugins =

This plugin is fully functional on its own. Separate, independent paid plugins called **MyFeeds Pro** and **MyFeeds Business** are available at [myfeeds.site](https://myfeeds.site) and ship with additional features such as a carousel block, a visual card design editor with Google Fonts, click and conversion analytics, and a full multi-feed storefront system. They are not required to use this plugin.

== Installation ==

1. Upload the `myfeeds-affiliate-feed-manager` folder to the `/wp-content/plugins/` directory, or install the plugin directly from the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **MyFeeds** in your admin sidebar and click **Add your first feed**.
4. Paste a product feed URL from your affiliate network and click **Import**.
5. In the block editor, add the **MyFeeds – Product Picker** block to any post or page.
6. Search for products, select them, and publish.

= Where do I get a product feed URL? =

Sign up with an affiliate network (such as AWIN, CJ, or Tradedoubler), navigate to the product feed section — usually labelled "Create a feed" or "Product feeds" — and copy the feed URL.

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

MyFeeds works with any affiliate network that provides a product feed URL in CSV, TSV, XML, or JSON format. The parser has been tested with AWIN, Webgains, Rakuten, Tradedoubler, and Admitad.

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

= 1.0.1 =
Bug-fix release. Importer reliability for large AWIN datafeeds, faithful currency handling (no more silent EUR default), better category mapping for merchants that use breadcrumb paths, and card-display fixes against sticky theme headers and mobile typography overrides.

= 1.0.0 =
Welcome to MyFeeds. Import your first affiliate product feed and start showcasing products in your posts.
