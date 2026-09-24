=== MyFeeds — Affiliate Product Feed Importer & Shoppable Product Cards ===
Contributors: myfeeds
Tags: affiliate, affiliate marketing, product feed, datafeed, product import
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.0.38
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

= 1.0.38 =
* Improved: the storefront picture on the E-commerce page shows the shop as it looks now.
* Improved: the changelog on WordPress.org was being cut off part way through. The recent releases are listed there in full again, and the complete history now ships with the plugin in changelog.txt.

= 1.0.37 =
* Fixed: a digit no longer starts a word in search. Looking for "tee" could match a sunglasses model number like 214050TEESPIBOR, and "slim" three eyeglass part numbers — the same kind of false match the whole-word rule exists to prevent. It also made search contradict itself: a long word was matched one way and a short one another.
* Improved: the size a merchant's image CDN is asked for is now checked against that merchant instead of assumed. One widely documented parameter turned out to be ignored by a large retailer's image server, and a wrong assumption could never correct itself before.

= 1.0.36 =
* Improved: product images are asked for at the size they are shown. Some merchants ship the print master — one feed averaged 2 MB per product image, with individual files past 25 MB — which makes a post crawl and can leave an image stuck on its alt text while the browser waits. MyFeeds now asks the merchant's image CDN for a copy that fits the card. On a shop page with 72 product images that was 61.4 MB before and 5.8 MB after.
* Improved: for an image host MyFeeds has not seen before, it works out how to ask, once, in the background, and remembers the answer. Nothing is re-imported, no image is copied to your server, and a host that cannot make a smaller copy is left exactly as it was.
* Improved: one product, one card. Sizes of the same product are now grouped in the block picker and in the picker's detail view, whichever way the feed writes the size — "Size 11.0 W", "Size: Large", "- S" or a separate size field. The card says how many sizes a product comes in, and the detail view lists each size and colour with its own link.
* Fixed: the detail view asked for a product's sizes with an empty id and came back with nothing. The list of sizes stayed empty however the feed was written.
* Fixed: re-importing your feed now marks the products your merchant has withdrawn, instead of leaving them on sale with a link that goes nowhere. They are never deleted — a product already placed in a post turns into the "no longer available" card, so nothing disappears from your posts without warning. If more than half the feed would be marked at once, the import treats that as a truncated download and leaves everything alone.
* Fixed: "Showing whole-word matches only" no longer costs a row of empty space right above the products in the search window. It sits next to the result count.
* Improved: two redundant indexes have left the products table, making imports a little faster and the table a little smaller. Existing installations are cleaned up once, automatically.

= 1.0.35 =
* Improved: a search word of three letters or fewer now has to start a word, the way longer words always did. Searching "men" used to return every women's product because the word sits inside "women"; on a real catalogue that was 58,176 matches where 27,358 were meant.
* Added: a line under the search toolbar says whether you are seeing whole-word matches, and offers the partial ones with one click. Nothing is dropped, so a Sweatshirt is still one click away from "shirt".
* Fixed: when a search found nothing and the plugin fell back to a looser match, it did not say so. It does now.
* Fixed: a number in a search was silently ignored on MySQL 8 in that fallback, because it used a word-boundary syntax that only the older MySQL understands.
* Fixed: a search word of three letters or fewer also demanded every one of its synonyms. Searching "men" quietly meant "men and man" and found a fraction of what it should. A synonym is an alternative now, the way it always was for longer words.

= 1.0.34 =
* Fixed: product titles lost every dash, curly quote and ellipsis, and some accented letters, showing question marks instead. A pattern that strips trademark marks was matching single bytes rather than characters, so it deleted the first byte of those characters and left something that was no longer readable text. Titles now keep them, and a mis-declared encoding is repaired instead of being filled with question marks.
* Fixed: products from feeds that write a size as "Shirt - XL" with a long dash appeared once per size in the block picker. The rule that recognises a size suffix could not match that dash, so the sizes never collapsed into one product.
* Fixed: the mapping editor could sit on "Loading..." forever for a large feed. It pulled the whole file into memory to read a single row; it now streams from disk like the importer does, and unpacks gzip and zip on the way.

= 1.0.33 =
* Fixed: adding or re-importing one feed could turn into an update of every feed. The import of a single feed has no background worker to wait for, so ten seconds in it looked stuck, and the recovery step started a full update in its place. It now leaves single-feed imports alone.

= 1.0.32 =
* Fixed: on password-protected sites the import restarted itself from the status poll and marked real products unavailable. The fix applies to any site whose own server cannot reach it without a password, and to every feed on it.

= 1.0.31 =
* Filtering, sorting and paging inside a search are near-instant. The first search still asks the index once; from then on a filter chip reads from what that search found, instead of searching the whole catalogue again with the filter attached. A brand filter on a 90,000-row catalogue took 7.6 seconds and now takes a few hundredths.
* Every sort order gives one answer: two products that tie on price, discount or date come back in the same order every time.
* "Back to search" and "Add to selection" return you to where you were in the results, not to the top.
* The mapping editor reads XML and JSON feeds. It used to show an empty form for any feed that is not CSV, and no error.
* Feeds are listed alphabetically in the mapping editor.
* Card images are no longer routed through Jetpack's image CDN. Shopify and other merchant CDNs need the exact address the feed delivered, and the CDN dropped part of it, so some cards showed no image while the editor showed the product.
* A feed without a currency column is priced in the currency of the market it names (a delivery-time column per country, or the country in the shop link). Partnerize feeds priced in pounds showed euros. A default currency set in the mapping editor still wins.

= 1.0.30 =
* A search that reports 245 results now shows all 245. Where a feed ships a row per size, a search matches many more rows than it has products - sixteen rows per product in one measured case - and the plugin only ever looked at the first few hundred rows. So a search could report hundreds of results and hand you twenty, with "Load more" unable to reach past them. It now looks far enough to fill the pages it promises, and it is about twice as fast at the same time.
* Product links saved in a post could lose the ampersands out of them, leaving an address that looks fine and leads nowhere: the affiliate id and the merchant id were swallowed into one long parameter. Existing cards repair themselves as they are read, so your links point where they should again.

= 1.0.29 =
* In the product detail view, clicking one of the smaller images now shows it in the large frame. They were there to look at and did nothing.
* Choosing a size now selects that size. Picking a colour already switched the product for real - link, price and all - but a size only changed what was highlighted, so adding the product to a post linked to whichever size the search had opened. Pick 42 and the card links to 42.

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

Older entries (1.0.23 and earlier) are in changelog.txt, which ships with the plugin.

== Upgrade Notice ==

= 1.0.36 =
Product images are now requested at the size they are shown. On pages where a merchant ships very large images this is the difference between tens of megabytes and a few. Sizes of the same product are grouped into one card, and re-importing your feed marks the products your merchant has withdrawn.

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
