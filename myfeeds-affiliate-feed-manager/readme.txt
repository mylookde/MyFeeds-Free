=== MyFeeds — Affiliate Product Feed Importer & Shoppable Product Cards ===
Contributors: myfeeds
Tags: affiliate, affiliate marketing, product feed, datafeed, product import
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.0.28
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your blog shoppable. Import your merchants' affiliate product feed and place searchable product cards in any post. Prices and links stay current.

== Description ==

**Tired of hunting for products, pasting affiliate links, fixing the ones that quietly stopped working, and earning from ads alone? Make your blog shoppable!**

Embedding affiliate links by hand costs you valuable time. Time you should spend writing new posts, researching, and running your business. Instead, looking after your links eats it up: links that lead nowhere to replace, new products to find, prices to check. Most affiliates lose sales because a link went out of date without telling them, or because a plain text link just doesn't invite a click. So how do you show your products in a way readers actually want to explore?

MyFeeds does that for you. Instead of text links that quietly go out of date inside your posts, your readers see product cards that show the merchant, the brand, the title and the price. The cards refresh every day, so the price your reader sees is the price your merchant is charging.

All you do is copy the product feed link from your affiliate network and paste it into MyFeeds. Once the feed is imported, the **Product Picker** block lets you search it right inside the block editor, by keyword, with filters when you need them.

You get to stay where the value is. Writing. Instead of pasting URLs at midnight.

= Who is this for =

Anyone earning a cut when readers click and buy. Whatever you cover, from clothing and gear to books, beauty, supplements, tools, baby, garden, hobbies, niche electronics, or deals: if there's an affiliate program for it, there's a product feed somewhere, and MyFeeds can read it.

The block editor stays your block editor. The plugin works in the background.

= What changes for you =

* **Your posts become shoppable.** A grid your readers can scan: image, brand, price, discount, shipping, and your link. You compose it in seconds inside the block editor.
* **Your prices stop lying.** The price your reader sees today is the price on the merchant's checkout right now.
* **Your posts stay current.** Discontinued products surface so you can replace them. Stock that comes back lights up again. Nothing changes behind your back.
* **You publish faster.** Two letters in the editor, the product appears, you click, the card is in. You never leave the post you're writing.
* **Your site stays yours.** Products live in your own WordPress database. Visitors don't wait on a third-party server, and nothing about them is sent off-site when a page loads.
* **You never learn a file format.** Whatever your network sends, MyFeeds reads it and works out which column is the price, which is the image, which is the link.

= How it works =

1. Paste in the **affiliate product feed URL** from your network.
2. Every product is imported and stored locally in your WordPress database. The plugin figures out the column structure on its own.
3. In any post or page, add the **MyFeeds Product Picker** block. Search by name, brand, or category. Click to insert.
4. The published page renders a responsive product card with the current price, image, brand, shipping, and your affiliate link. All served direct from your database, with no external call on render.

The next day the nightly sync refreshes what changed. The week after, a full import catches everything else. You don't think about it.

= What's in the box =

* Universal feed import. Almost any format your program hands you, detected automatically.
* Smart Mapping. Automatic recognition of common feed structures, with a manual editor for anything custom.
* Smart Search inside the block editor with synonym handling and multi-language support.
* Native Gutenberg **Product Picker** block with live in-editor search.
* Responsive grid of product tiles with prices, brands, shipping, and your affiliate links.
* Background imports. Large feeds process without locking your admin.
* Nightly auto-sync and weekly full re-import, scheduled and quiet.
* The price and the currency come straight from the feed. Nothing is converted behind your back.
* Works with any WordPress theme that supports the block editor.

= Good to know =

* Everything happens inside WordPress, from the feed URL to the published card. There is nothing to download, upload or update by hand.
* Self-hosted. The frontend never contacts an external service to render a product.
* If your program publishes a feed file you can download, MyFeeds will almost certainly import it.

= Get started =

Install MyFeeds, paste one feed URL, and make your next post shoppable.

= Related paid plugins =

This plugin is fully functional on its own. Separate, independent paid plugins called **MyFeeds Starter**, **MyFeeds Pro** and **MyFeeds E-commerce** are available at [myfeeds.site](https://myfeeds.site/?utm_source=wporg&utm_medium=readme&utm_campaign=paid-plugins). They add things like a carousel block, a visual card designer with Google Fonts, Amazon products through Amazon's Creators API, click and conversion analytics, and a full multi-feed shop system. They are not required to use this plugin.

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

= How do I upload a merchant product feed to WordPress? =

Install MyFeeds, open **MyFeeds → Feeds**, and paste the feed URL your affiliate network gave you. MyFeeds downloads the file, works out which column is the title, the price, the image and the affiliate link, and imports every product into your own database. CSV, TSV, XML and JSON all work, compressed or not, and nothing has to be uploaded by hand.

= How do I add affiliate products to a blog post? =

Open any post in the block editor and add the **MyFeeds – Product Picker** block. Search your imported catalogue by name, brand, colour or category, tick the products you want, and they appear as product cards with image, price and a buy button. No copying links, no HTML.

= How do I keep affiliate prices up to date on my site? =

MyFeeds re-reads your feed every night and refreshes what is already in your posts, so the price a reader sees is the price the merchant is charging today. A weekly full import catches products that were added or withdrawn. You can also sync any feed by hand at any time.

= Which affiliate networks provide a product feed? =

Most of the large ones do: AWIN, Tradedoubler, CJ Affiliate, Impact, Rakuten Advertising, Pepperjam, FlexOffers and Sovrn all publish product feeds, and so do many merchants directly. Look in your network's dashboard for a section called "Product feeds", "Datafeeds" or "Create a feed". MyFeeds reads the file whatever it is called.

= Can I build an affiliate store or shop page with this? =

This plugin puts product cards inside your posts and pages. A full storefront with its own categories, filters and sorting is a paid feature and not part of this plugin.

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

= Does it work with my network's feed: Awin, CJ, Impact, Tradedoubler, Rakuten? =

Those are the ones we see most often, and their feeds import without manual work. The plugin isn't built around any single network though: it reads the file your program hands you, whatever the network is called. CSV, TSV, XML and JSON all work, compressed or not. If a column is named something the plugin has never seen, the mapping editor lets you point it at the right field yourself.

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

The full source for this plugin is open-source. See [myfeeds.site](https://myfeeds.site/?utm_source=wporg&utm_medium=readme&utm_campaign=source) for the project homepage and links to the public repository.

* Block editor source: `src/index.js`
* Build tool: terser via `npm run build` (configuration in `package.json`)

To rebuild the editor bundle from source, run `npm install && npm run build` inside the plugin folder.

== Screenshots ==

1. The product picker inside the block editor. Search your whole feed, narrow it with filters, click what you want. Here: 120 results for "shoes", filtered down to one brand, four products picked.
2. Your picked products, saved in the block. They stay together until you place them, each with its own price and discount. The shop buttons on the cards belong to MyFeeds E-commerce.
3. Those four products published as a live product grid in a real blog post. Your readers see what the merchant is actually selling today, for as long as the post exists.
4. The same four products as a swipeable carousel (MyFeeds Starter). A second way to present what you've curated, for image-heavy posts and roundups.
5. Card design editor (MyFeeds Starter). Cards that look like your blog wrote them. One save, every card across every post catches up. The live editor opens more than this screen lets on.
6. Full storefront on your own domain (MyFeeds E-commerce). Visitors see your real online shop with categories, filters and sorting. The controls they already know from any modern shop, all on your domain. Every checkout goes through your affiliate link.
7. Category manager (MyFeeds E-commerce). A shop organised the way your readers shop. Build the tree once, then curate products into each category by hand. Smart keyword search behind the scenes; you stay the editor.
8. Shop design editor (MyFeeds E-commerce). Your storefront tracks your taste. A phone, tablet and laptop preview moves with you, so what you ship is exactly what your reader meets. The live editor carries plenty more.

== Changelog ==

= 1.0.28 =
* A card no longer sends readers to a size that has sold out. Feeds ship one row per size, and the address stored with a product carries the size along with it - so a reader following a card whose size had gone landed on exactly the size that was gone. MyFeeds now recognises the sizes of one product and links to one that can be bought. Colours stay apart: the grouping is confirmed against the product photograph, so a card showing the sand-coloured pair never links to the black one.

= 1.0.27 =
* There is one way to delete a feed again. A second one existed that dropped the feed from your settings and left every one of its products in the database - rows with an image, a price and a link into a partnership that had ended, which the product picker would still offer you. It could not actually be reached from the plugin's screens, so nothing was broken by it; it is gone now rather than waiting to be found.
* The daily housekeeping also clears out products whose feed no longer exists, whatever removed it - a restored backup, an edit made straight in the database. Products a published post is showing are kept, as before, so your live pages never go blank.

= 1.0.26 =
* A product that is no longer available is left out of your pages instead of being shown as a grey "no longer available" card. That card told a reader nothing they could act on, and it made a good post look broken. Where a block has nothing left to show it now renders nothing at all, rather than an empty gap.
* Products your published posts are showing are no longer removed when you delete their feed. A product block stores only the id, so once the row was gone the post could not name what it lost and the editor could not show you what used to be there. Those rows are kept now, out of your pages but still there to work with.

= 1.0.25 =
* The plugin now lists MyFeeds as its author instead of a personal name, and links to myfeeds.site. Only the entry on your Plugins screen changes; nothing about how the plugin works is affected.

= 1.0.24 =
* Update All could not finish on some hosts. The importer runs the work in a background request to your own site; where that request is blocked - by hosting configuration, by a password-protected staging site - it falls back to running the import inline. That fallback referred to something that did not exist and stopped with an error the log recorded and nobody saw, so the progress bar sat at 1% until you gave up. On those hosts importing was simply impossible. Fixed.
* An import that could not read a feed now says so. A feed URL behind a login, a typo, an expired key: all of them used to end on "Update completed successfully" with a green tick and no products. The panel now names each feed it could not read and stays open until you dismiss it.
* Feeds that arrive as a .zip are unpacked for you. Several networks ship their catalogue that way, and until now the archive was handed to the CSV parser, which produced nothing and reported nothing. Files that are not feeds at all - an archive MyFeeds cannot open, a PDF, or the HTML login page a network returns when a link needs authentication - are now named in the error instead of being parsed as a spreadsheet.
* You can upload a feed file. Four networks hand publishers a file and no link, and the answer used to be "find your own hosting for it first". The dialog now offers a URL or an upload, recommends the URL, and says plainly that an uploaded file does not refresh by itself - the feeds list shows it as such. Accepted: .csv, .tsv, .psv, .ssv, .txt, .tab, .xml, .json, .jsonl, .ndjson, .gz and .zip.
* Deleting a feed now removes its products. They used to stay in the database, keep appearing in the Product Picker and keep inflating the product count on the Feeds page. Products that a published post still shows are the exception: those are kept so your pages do not go blank, with the values they last had, and they are no longer offered when you add new ones.
* The progress bar moves while an import runs. It updated once per batch of a thousand rows, so a small feed showed nothing at all between starting and finishing.
* MyFeeds now tidies up after itself. Once a day it removes options left behind by versions you no longer run, expired cached data, and its own finished background jobs once they are more than a week old. Other plugins' jobs are never touched.
* The feed address is checked before it is fetched. A feed URL that points at the server itself or into a private network is refused, so a mistyped or malicious address cannot be used to make your site fetch things that were never meant to face the internet.
* Advanced options: pipe-separated is now offered as a format, which is what several networks publish. Choosing a file pre-selects the matching format from its name. The gzip entry is gone because compression is unpacked before the file is read. The network list drops Amazon, which has its own connection flow, and adds FlexOffers, Sovrn and Other.

= 1.0.23 =
* Opening a post that holds several product blocks is faster. Each block asked the database to refresh its saved products on its own, so a post with seven of them made seven separate requests - and what takes the time in one of those is WordPress starting up to answer it, not the lookup. The blocks now ask together, in one request. The answer also stopped carrying the full merchant record for every product, forty-odd fields of it, when a saved tile reads seven.

= 1.0.22 =
* Try the plugin before you have a feed. With no feed configured, the Feeds page now offers to load seven sample products, so you can open the Product Picker and see what a card looks like in your own theme. The offer only appears while you have no feed, the samples are marked as sample data throughout, and one button removes them. They take the free plugin's single feed slot, so removing them frees it for your real feed.
* Product Picker: the Use and Cancel buttons are back. WordPress renders the post canvas in an iframe and caps a modal at 70% of the viewport; the picker asked for 95% of the viewport instead, which pushed its own bottom edge - and those two buttons - past the frame. Nothing in the modal names a height any more, so the results scroll and the buttons stay on screen however many come back.
* Product Picker: buttons and product names are set in the admin typeface again, not a serif. The editor side never declared a font and inherited one from the admin page. Inside the iframe there is nothing to inherit, and an element with no font falls back to the browser default.
* Product Picker: searching a large feed is roughly three times faster - measured on a real feed, 6.6 seconds down to 2.1. The candidate query stopped pulling every product's full source record through the database only to discard it, the three filter counts became a single pass when no filter is set, and those counts are held for five minutes so paging and re-sorting stop asking the same expensive question.
* Product Picker: the search bar no longer lets results slide through a gap above it, and the Add button in the detail view is the same purple as the button a screen before it.
* Feeds page: adding your first feed shows it straight away, and deleting your last one brings the empty state back. Both used to need a page reload before anything appeared to happen.
* Feeds page: the feed URL field links to a page listing where each affiliate network hides its product feed export.

= 1.0.21 =
* The importer knows six more feed formats. Tradedoubler, Commission Junction, Impact and Rakuten now come with their column names built in, and Pepperjam and FlexOffers are recognised at all. Before this, a feed from one of those networks fell through to the generic guesser and left you mapping columns by hand on the Mapping Editor screen. Nothing else changed: feeds that already import correctly keep their saved mapping.

= 1.0.20 =
* The plugin is now called "MyFeeds - Affiliate Product Feed Manager, Importer & Product Display". Same plugin, same folder, nothing to do on your side. On the plugin directory the name is the single strongest field for being found at all, and "Manager" on its own left out the two things people actually type when they go looking: importing a feed, and displaying the products.

= 1.0.19 =
* Quick Sync no longer loads the whole feed into memory. It used to hold the compressed file as one string, unpack it into a second one, and then write the result to disk to read it back line by line: measured at 145 MB of peak memory to refresh 41 products out of a 40 MB feed, against a 56 MB floor for WordPress itself. On a host with a 128 or 256 MB limit that was a fatal waiting for a large enough feed, and the nightly auto-sync takes the same path. It now streams to disk the way the full import always has, and the same measurement shows nothing above the floor at all.
* Quick Sync writes in batches. One database statement per hundred products instead of one per product. Same matching, so products that are not in the table are still left alone rather than inserted.
* Quick Sync could be run twice in a row. Its execution lock was never released, so for two minutes after a perfectly good sync the next one was skipped silently: the button appeared to do nothing at all.
* Imports now start on sites that cannot call themselves over HTTP. The background worker is spawned with a loopback request that cannot report a failure, so password-protected staging sites, some security plugins and hosts that block loopback left the import sitting at "Initializing" with an empty queue and no error anywhere. The worker now checks in when it arrives, and if it has not within ten seconds the progress poll runs the import itself.
* The progress bar tells the truth. It used to measure products found against products wanted, written only after a whole feed had been scanned, so it sat empty for the entire run and then jumped to full. It now follows the read position in the feed, which also let Quick Sync drop a full extra pass over the file: 5.6 seconds down to 4.1 on the same feed.
* When a sync finishes, the panel says how many products are simply no longer in the feed instead of counting them as failures.
* The buttons on each feed row come back to life when an import finishes, instead of staying greyed out until the page is reloaded.
* Amazon is signposted from the feeds screen, with a dialog describing what the paid Amazon source does. Nothing is installed or enabled by this plugin.
* A one-time, dismissible review request. It appears once, it can be dismissed for good, and it never comes back on its own.

= 1.0.18 =
* Block editor: product images in the picker now match what visitors see on the published post. The selected-tile refresh endpoint, the colour-sibling swatch endpoint, and the product preview that runs on block mount all pipe their image URLs through the same CDN-aware upgrader the frontend renderer already uses, so a saved block can't look soft in the editor while the published post renders crisp. Pure render-time logic, no migration.
* Smart mapper: generic hi-res image priority for custom and small-network feeds. Feeds that ship both a small `image_url` and an explicit hi-res mirror (`large_image`, `original_image`, `hires_image`, `full_image` — with or without `_url` suffix) now pick up the hi-res variant on every sync path (Full Import, Quick Sync, Action Scheduler batches, single-feed reimports). Feeds with only a single image column behave exactly as before. AWIN priority is unchanged.

= 1.0.17 =
* Product images: render-time URL upgrade for the known CDNs. AWIN's `images.productserve.com/preview/` thumbnails get rewritten to the `/large/` mirror; Shopify size suffixes (`_grande`, `_NNNxNNN`, …) get bumped to `_1024x1024`; Cloudinary upload paths without a transformation get `w_1024,q_auto,f_auto` injected; BigCommerce stencil paths bump to `1024x1024`; WordPress `-NNNxNNN` resize suffixes get stripped. Unknown URLs pass through untouched. Images go from soft thumbnail to crisp source on Retina displays without any cloud storage or extra account on your side.
* AWIN feeds: `merchant_image_url` now wins over `aw_image_url` everywhere. The AWIN variant routes through their resized `/preview/` bucket; the merchant variant is the original-resolution mirror. The smart mapper used to prefer the AWIN one, which produced soft cards on every Retina display, and the importer's force-overwrite pass would silently re-apply that choice on every Quick Sync. Both paths now converge on the merchant URL.
* AWIN affiliate links: `aw_deep_link` now wins over `merchant_deep_link` everywhere, in every sync path. The AWIN URL goes through `awin1.com` so the commission gets attributed; the merchant URL is the merchant's direct link with no AWIN involvement, so a click on it silently bypasses tracking and the publisher loses the commission. Same "last one wins" loop order in the force-overwrite map was silently overwriting the tracked URL on every Quick Sync. Replaced with explicit priority overrides in `process_critical_fields` so Full Import, Quick Sync, Action Scheduler batches and single-feed reimports all produce the same tracked URL. Existing rows where the untracked URL was already cached heal at the next nightly sync.

= 1.0.16 =
* Mapping Editor: new "Default currency" card at the bottom of the mapping grid. Pick an ISO 4217 three-letter code (USD, EUR, GBP, CHF, JPY, INR and many more, plus a custom code option) for feeds that silently omit a currency column. Without an override, silent-currency feeds used to land in the database with empty currency and the front-end rendered the price without a symbol. The override is saved together with the rest of the mapping when you click Save Mapping, so there's still just one big save button to remember. Existing imported rows pick up the override at the next sync.
* Mapping Editor: three places that hardcoded "EUR" as a fallback are gone (class-batch-importer.php, class-feed-manager.php's Single-Source-of-Truth path, and class-smart-mapper.php's apply_fallbacks). All three used to stamp "EUR" on currency-less rows before the override could run, which made the new override invisible on USD-only feeds. Now currency stays empty through the entire mapping chain and the per-feed default fills it in at the right moment.
* Mapping Editor: layout polish. Field rows inside each section (Essential, Important, Product Attributes, Additional Info, etc.) are now sorted A-Z by label. "Available Feed Columns" pills and the per-field dropdown options are also sorted A-Z. The preview pane is a collapsible details element, closed by default, so the raw-JSON sample row no longer dominates the editor.
* Mapping Editor: drag and drop from a feed-column pill onto a field-mapping select now actually works. The pills had cursor: grab and looked draggable since 1.0.2 but had no JS handler. Native HTML5 dragstart -> dragover -> drop with dataTransfer payload and a hover highlight on the target select.
* Smart Search: tokens longer than three characters now get a trailing `*` in the FULLTEXT match, so a query like "trouser" finds the same products and the same brand / colour / category facets as "trousers". Earlier behaviour: the German-leaning stemmer was producing "trous" for "trouser" — neither form existed in the FT index, so the main search found rows via a fuzzy fallback but compute_facets returned zero buckets and the filter panel disappeared. Prefix-wildcard fixes both paths.

= 1.0.15 =
* Product Picker: colour-variant swatches now catch the case where the feed leaves the colour column empty but spells the colour out in the product name. Example from a real merchant: "Denim Tears Wreath Jean Short Light Wash - S" and "Denim Tears Wreath Jean Short Black - S" both arrive with empty colour and identical attributes; we now extract "Light Wash" and "Black" from the names themselves and present them as switchable swatches. Multi-word colour phrases (Light Wash, Dark Wash, Off White, Light Blue, Hot Pink, etc.) are matched longest-first so "Light Wash" wins over "Light".

= 1.0.14 =
* Product Picker: the detail modal now shows clickable colour-variant swatches when a product has siblings in the same feed. Each swatch carries a thumbnail of that colour's actual product image, the colour name, and the colour-dot indicator. Click a swatch and the modal switches to that variant — image, price, affiliate link and title update in place. Add-to-Selection then picks up the chosen variant. Single-colour products and feeds where no family can be detected fall back to the existing display-only colour pill. The home-page counts, importer counts, feed-list stats and search result dedup are untouched: this is purely additive on the detail-modal-open path.
* Product Picker: variant family detection uses a three-step strategy chain. First, an explicit family id from the feed's raw payload (item_group_id, parent_sku, aw_group_id and friends). Second, exact product_name match within the same feed when at least two distinct colours exist (Carhartt-style merchants). Third, a conservative name-strip fallback that removes size suffixes and common colour words, then groups by the cleaned base — only triggered when the first two return nothing and the base is long enough that false-positives are unlikely. All matches are scoped to the same feed_id so colours never cross feeds.

= 1.0.13 =
* Product Picker: the product detail view (the "i" icon next to a search result) now centers inside the visible content area instead of the full viewport. The wp-admin sidebar stays uncovered, and the modal and its dark overlay only span the area to the right of it. JS measures the sidebar live (including when the block editor runs inside an iframe, by walking up to the parent admin document) and reapplies the offset on window resize.

= 1.0.12 =
* Feature preview pages: marketing copy rewritten across Shop, Card Design and Analytics. Em-dashes traded for periods so the cadence stops reading like AI. The card-design subtitle no longer leans on the "no CSS, no theme overrides, no broken mobile layouts" reassurance triplet that flagged in voice review. Benefit bullets moved from feature-listy to outcome-first ("Your blog gets a real storefront on its own domain. Visitors browse, click out, and you keep the commission.") so the reader sees what changes for them, not what the feature is.
* Feature preview pages: defensive screenshot caption "No save-and-reload loop." replaced with a positive description of what actually happens.
* Feature preview pages: the screenshot zoom lightbox now measures the wp-admin sidebar live with JavaScript and anchors its left edge to the sidebar's right edge. The previous pixel-based offsets (160 / 36 / 0) didn't survive custom admin themes, hover-expand of the auto-fold menu, or admin-theme plugins that set their body classes after first paint, so the overlay still covered the sidebar on some setups. The image now centers cleanly inside the visible content area regardless of which admin theme you run.
* Feature preview pages: CSS and JS now version themselves with file modification time, so a layout tweak between releases shows up on the next page load instead of waiting for the next plugin version bump.

= 1.0.10 =
* Listing copy: short description rewritten to name the actual mechanic up front. "Insert live affiliate product tiles into your posts. Prices and stock stay current, automatically." The old version said "searchable product cards" which buried the visual format under a feature word.
* Listing copy: new bridge paragraph at the top of the long description makes the core mechanic visible in the first 50 words ("MyFeeds drops live product tiles into your posts. The prices, stock and links update themselves..."). The pain-first narrative below stays as-is.
* Listing copy: "What changes for you" now leads with a product-tile bullet so the visual nature of the plugin is no longer a paragraph-3 discovery.
* wp.org listing banners refreshed (772×250 + 1544×500) with a clearer subhead that mirrors the new copy.

= 1.0.9 =
* Mapping Editor: new intro card at the top of the page that frames the editor as a polish tool, not a setup step. Auto-mapping handles your columns at import time. You only open the editor when a feed shows less than 100% in the Quality column on the Feeds page, and the card links straight there.
* Mapping Editor: Apply Template, Save as Template, Auto-Detect and the modal Save Template button now share a coherent brand look (outlined indigo for secondary actions, gradient indigo for primary). The bare WordPress grey button no longer sits next to the brand-gradient Save Mapping CTA.
* Mapping Editor: deleting a template no longer fires a native browser confirm dialog. A brand-styled confirm modal asks once with the template name spelled out, and the destructive action uses a red gradient so it reads as different from the indigo save actions.
* Mapping Editor: every alert() in the editor is gone. Save, apply, delete and validation feedback now use auto-dismissing toast notices in the top-right corner, including a clean error message for network or server failures.
* Mapping Editor: deleting a template removes the row inline (fade-out, no page reload) and only triggers a refresh when the list becomes empty so the friendly empty state can render.
* Feature preview pages: the screenshot zoom lightbox now centers in the visible content area instead of the full viewport. The wp-admin sidebar (160px expanded, 36px folded, 0 on mobile) is accounted for, so a screenshot you click no longer drifts behind the menu on the left.

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

= 1.0.21 =
Six more affiliate networks are recognised automatically: Tradedoubler, Commission Junction, Impact, Rakuten, Pepperjam and FlexOffers. Feeds from those no longer need their columns mapped by hand.

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
