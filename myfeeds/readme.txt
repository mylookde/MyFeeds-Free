=== MyFeeds ===
Contributors: myfeeds
Tags: affiliate, product feeds, gutenberg, product picker, awin
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import affiliate product feeds, search thousands of products, and showcase them in your blog posts with a powerful Gutenberg block.

== Description ==

**MyFeeds** turns any affiliate product feed into a searchable local product index inside your WordPress site. No external services, no API calls on the frontend, no slowdowns — everything runs on your own database.

Pick products from your feeds using the **MyFeeds – Product Picker** block in the Gutenberg editor. Search by name, brand, or category with smart search that understands synonyms and German umlauts. Display products in a clean grid or carousel layout with automatic price and availability updates.

= How it works =

1. Paste a product feed URL from your affiliate network (AWIN, Webgains, Rakuten, Tradedoubler, and others)
2. MyFeeds imports and indexes all products locally in your database
3. Use the Product Picker block in any post or page to search and select products
4. Products are displayed with live prices, images, and your affiliate links

= Key Features =

* **Feed Import** — Import CSV, XML, and other product feed formats from any affiliate network
* **Smart Search** — FULLTEXT search with synonym expansion, German stemming, umlaut normalization, and gender-as-filter
* **Product Picker Block** — Native Gutenberg block to search, select, and display products in posts and pages
* **Grid & Carousel Layouts** — Display products in responsive grid or horizontal carousel
* **Auto-Sync** — Daily quick sync keeps prices and availability up to date automatically
* **Card Design Editor** — Customize product card appearance with visual controls, Google Fonts, custom font upload, and drag & drop element ordering
* **Local Storage** — All product data stored in your WordPress database. No external dependencies, no frontend API calls
* **Works With Any Theme** — Compatible with any WordPress theme that supports the block editor

= Free vs Pro vs Premium =

**Free** — 1 feed, 3 products per post, grid layout, smart search, manual sync.

**Pro** ($19/mo) — Up to 5 feeds, unlimited products, carousel layout, daily auto-sync, quick sync, email support.

**Premium** ($39/mo) — Unlimited feeds, card design editor, Google Fonts, custom font upload, drag & drop element order, priority support.

All paid plans include a 3-day free trial. Cancel anytime.

== Installation ==

1. Upload the `myfeeds` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **MyFeeds** in your admin sidebar to add your first product feed.
4. Paste a product feed URL from your affiliate network and click **Import**.
5. In the block editor, add the **MyFeeds – Product Picker** block to any post or page.
6. Search for products, select them, and publish.

= Where do I get a product feed URL? =

Sign up with an affiliate network (such as AWIN, CJ, or Tradedoubler), navigate to their product feed section — usually called "Create a feed" or "Product feeds" — and copy the feed URL.

== Frequently Asked Questions ==

= How do I add products to a blog post? =

In the block editor, add a "MyFeeds – Product Picker" block. Use the search bar to find products by name, brand, or category, then click to select the ones you want to display.

= How long does an import take? =

It depends on the feed size. A feed with 10,000 products typically takes 2–5 minutes. Imports run in the background, so you can continue working while they process.

= Why are some products missing after import? =

Only products with valid data (title, price, image, and affiliate link) are imported. Check your feed source for incomplete entries.

= Does MyFeeds slow down my site? =

No. All product data is stored locally in your WordPress database. There are no external API calls on the frontend — your site stays fast.

= Does MyFeeds work with any theme? =

Yes, it works with any WordPress theme that supports the Gutenberg block editor (WordPress 5.8+).

= Can I use MyFeeds with the classic editor? =

No, MyFeeds requires the Gutenberg block editor.

= Is there a free trial? =

Yes, all paid plans include a 3-day free trial with no commitment.

= Can I switch plans anytime? =

Yes, you can upgrade or downgrade at any time from the Manage Plan page inside the plugin.

= What happens when I downgrade? =

Your data stays intact. If you exceed the new plan's feed limit, you'll be asked to choose which feeds to keep active.

= Which affiliate networks are supported? =

MyFeeds works with any affiliate network that provides a product feed URL (CSV, XML, or similar formats). Currently tested with AWIN and Webgains. Also compatible with Rakuten, Tradedoubler, Admitad, and any other network that offers CSV or XML feeds. More native integrations coming soon.

== Screenshots ==

1. Feed Manager — manage your affiliate product feeds with live status, product counts, and mapping quality indicators.
2. Background Import — imports run in the background with real-time progress bar. Continue working while products are imported.
3. Feed Management — Update All Feeds or Quick Sync active products. Auto-sync schedule with next sync times.
4. Smart Mapping — automatic field detection with manual override. Maps any CSV/XML feed to product fields.
5. Card Design Editor — customize product card appearance with visual controls, live preview, and hover effects (Premium).
6. Grid Layout — products displayed in a responsive grid with prices, brands, and affiliate links.
7. Carousel Layout — horizontal scrollable product carousel with discount badges and sale prices.

== Changelog ==

= 1.0.0 =
* Initial release.
* Feed import system with background processing via Action Scheduler.
* Smart search with FULLTEXT indexing, synonym expansion, and German stemming.
* MyFeeds – Product Picker Gutenberg block with grid and carousel layouts.
* Card Design Editor with Google Fonts, custom font upload, and drag & drop ordering.
* Free, Pro, and Premium plans with Freemius integration.
* Daily auto-sync and weekly full import for paid plans.
* Built-in Help & FAQ page with contact form.

== Upgrade Notice ==

= 1.0.0 =
Welcome to MyFeeds! Import your first affiliate product feed and start showcasing products in your blog posts.
