=== MyFeeds — Affiliate Product Feed Manager ===
Contributors: mylookde
Tags: affiliate, product feeds, gutenberg, product picker, awin
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import an affiliate product feed, search it locally, and showcase products in your posts with a native Gutenberg block.

== Description ==

**MyFeeds — Affiliate Product Feed Manager** turns an affiliate product feed into a locally searchable product index inside your WordPress site. All product data is imported into your own database, so the frontend makes no external calls when a visitor loads a page.

Pick products from your feed using the **MyFeeds – Product Picker** block in the Gutenberg editor. Search by name, brand, or category with a smart search that understands synonyms and German umlauts. Products are displayed in a responsive grid with live prices, images, and your affiliate links.

= How it works =

1. Paste a product feed URL from your affiliate network (AWIN, Webgains, Rakuten, Tradedoubler, Admitad, and other networks that provide CSV, TSV, XML, or JSON feeds).
2. MyFeeds imports and indexes the products locally in your database.
3. Use the Product Picker block in any post or page to search the index and select products.
4. Published pages render a responsive grid with prices, images, and affiliate links.

= Free plugin features =

* **One product feed** — Add and manage one affiliate product feed. The Free plugin is designed around a single-feed workflow.
* **Universal feed import** — CSV, TSV, XML, and JSON feeds are detected and parsed automatically.
* **Smart Mapping** — Automatic field detection (title, price, brand, image, affiliate link, …) with manual override if needed.
* **Smart Search** — FULLTEXT search with synonym expansion, German stemming, umlaut normalisation, and gender-aware filtering.
* **Product Picker block** — Native Gutenberg block to search, select, and display products in posts and pages.
* **Grid layout** — Responsive product grid with prices, brands, shipping info, and affiliate links.
* **Manual and automatic sync** — Update products on demand with a single click, plus a nightly quick refresh and a weekly full re-import via WP-Cron.
* **Local storage** — All product data lives in your WordPress database. No external calls on the frontend.
* **Works with any block theme** — Compatible with any WordPress theme that supports the block editor.

= Free / Pro / Premium =

MyFeeds is also available as paid Pro and Premium plugins from [myfeeds.site](https://myfeeds.site), which add multi-feed management, a carousel block, and a visual card design editor. The upgrade is entirely optional — the Free plugin is fully functional on its own.

* **Free** (this plugin) — One feed, Gutenberg grid block, smart search, manual and automatic sync.
* **Pro** — Up to five feeds and a carousel block.
* **Premium** — Unlimited feeds, a visual card design editor with Google Fonts and custom font upload, and priority support.

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

= How do I add products to a blog post? =

In the block editor, insert a **MyFeeds – Product Picker** block. Use the search bar to find products by name, brand, or category, then click to select the ones you want to display.

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

This plugin connects to one external service, the official AWIN Publisher API, and only when the site administrator chooses to use it.

= AWIN Publisher API =

When you add an AWIN feed in the WordPress admin, the plugin calls the AWIN Publisher API on your behalf to verify your publisher credentials, look up your approved advertisers, and resolve their feed download URLs so the import job knows where to pull the product feed from.

* **What data is sent:** your AWIN publisher ID, the advertiser ID, and your AWIN API key (passed as an HTTP header). No WordPress user data, no visitor data, and nothing from the frontend is transmitted.
* **When it is sent:** only in the WordPress admin, when you open the AWIN feed setup dialog, verify credentials, or trigger a feed refresh. No frontend page view ever calls this API.
* **Where it is sent:** `https://api.awin.com/` — AWIN's official publisher API endpoint.
* **Why:** AWIN requires publishers to fetch feed download URLs via their API rather than hard-coding them, because the URLs are rotated and tied to your publisher account.

AWIN's terms of service and privacy policy apply to this data exchange:

* Terms of Service: <https://www.awin.com/gb/legal/publisher-terms>
* Privacy Policy: <https://www.awin.com/gb/legal/privacy-policy>

No data is sent to any other external service. The plugin stores imported products in your own WordPress database and serves them from there; the frontend never contacts an external host to render a product.

== Source Code ==

The full source for this plugin is hosted on GitHub:

* Repository: <https://github.com/mylookde/MyFeeds-Free>
* Block editor source: `src/index.js`
* Build tool: terser via `npm run build` (configuration in `package.json`)

To rebuild the editor bundle from source, run `npm install && npm run build` inside the plugin folder.

== Screenshots ==

1. Feed Manager — configure one affiliate product feed with status, product count, and mapping quality.
2. Background Import — imports run in the background with a real-time progress bar. Continue working while products are imported.
3. Smart Mapping — automatic field detection with manual override. Maps any CSV/TSV/XML/JSON feed to product fields.
4. MyFeeds – Product Picker block — search the local product index and select products inline in the editor.
5. Grid layout — products rendered in a responsive grid with prices, brands, shipping info, and affiliate links.

== Changelog ==

= 1.0.0 =
* Initial release on WordPress.org.
* Single-feed architecture with universal CSV, TSV, XML, and JSON feed parser.
* Smart Mapping with automatic field detection and manual override.
* Smart Search with FULLTEXT indexing, synonym expansion, and German-language handling.
* MyFeeds – Product Picker Gutenberg block with a responsive grid layout.
* Background imports via the bundled Action Scheduler library.
* Nightly quick sync (active products) and weekly full import via WP-Cron.
* AWIN Publisher API integration for credential and feed-URL resolution.

== Upgrade Notice ==

= 1.0.0 =
Welcome to MyFeeds. Import your first affiliate product feed and start showcasing products in your posts.
