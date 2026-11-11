=== MyFeeds — Affiliate Product Feed Importer & Shoppable Product Cards ===
Contributors: myfeeds
Tags: affiliate, affiliate marketing, product feed, datafeed, product import
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.0.52
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your blog shoppable. Import your merchants' affiliate product feed and place searchable product cards in any post. Prices and links stay current.

== Description ==

**Tired of hunting for products, pasting affiliate links, fixing the ones that quietly stopped working, and earning from ads alone? Make your blog shoppable!**

Embedding affiliate links by hand eats the time you should spend writing: links that lead nowhere to replace, new products to find, prices to check. Most affiliates lose sales because a link went out of date without telling them, or because a plain text link just doesn't invite a click.

MyFeeds does that for you. Instead of text links that quietly go out of date inside your posts, your readers see product cards that show the merchant, the brand, the title and the price. The cards refresh every day, so the price your reader sees is the price your merchant is charging.

All you do is choose where your products come from: paste the product feed link from your affiliate network (or upload its file), connect Rakuten Advertising, or connect Amazon's API. Once the products are in, the **Product Picker** block lets you search them right inside the block editor, by keyword, with filters when you need them.

You get to stay where the value is. Writing. Instead of pasting URLs at midnight.

= Who is this for =

Anyone who earns a commission by promoting other companies' products, from solo bloggers and niche sites to large publishers, magazines and deal sites. If there's an affiliate program for what you cover, there's a product feed somewhere, and MyFeeds can read it. The block editor stays your block editor; the plugin works in the background.

= What changes for you =

* **Your posts become shoppable.** A grid your readers can scan: image, brand, price, discount, shipping, and your link. You compose it in seconds inside the block editor.
* **Your prices stop lying.** The price your reader sees today is the price on the merchant's checkout right now.
* **Your posts stay current.** Discontinued products surface so you can replace them. Stock that comes back lights up again. Nothing changes behind your back.
* **You publish faster.** Two letters in the editor, the product appears, you click, the card is in. You never leave the post you're writing.
* **Your site stays yours.** Products from your feed live in your own WordPress database, and so do Rakuten's. With a product feed or Rakuten, visitors don't wait on a third-party server, and nothing about them is sent off-site when a page loads.
* **You never learn a file format.** Whatever your network sends, MyFeeds reads it and works out which column is the price, which is the image, which is the link.

= How it works =

1. Choose your product source: paste the **affiliate product feed URL** from your network (or upload its file), connect Rakuten Advertising, or connect Amazon's API.
2. A feed or Rakuten is imported into your WordPress database; the plugin works out the columns on its own.
3. In any post or page, add the **MyFeeds Product Picker** block. Search by name, brand, or category. Click to insert.
4. The published page renders a responsive product card with the current price, image, brand, shipping, and your affiliate link. With a feed or Rakuten, the card is built from your own database and your server contacts no external service to render it. With Amazon's API, your server asks Amazon for data it may no longer keep (prices after an hour, everything else after a day). The pictures load from the merchant, or from Amazon.

The next day the nightly sync refreshes what changed. The week after, a full import catches everything else. You don't think about it.

= What's in the box =

* Universal feed import with Smart Mapping: almost any format your program hands you, detected automatically, and a manual editor for anything custom.
* Smart Search in the block editor, with synonyms and several languages.
* The **Product Picker** block with live in-editor search: a grid, a carousel, a recommendation card or a comparison table, and the **Look** block.
* Prices and currencies straight from the feed, never converted.

= Good to know =

* Everything happens inside WordPress, from your product source to the published card.
* Self-hosted. With a feed or Rakuten, your server contacts no external service to render a product; the pictures come from the merchant's image server. Amazon's API as your product source is the one exception (see External Services).

= Get started =

Install MyFeeds, choose your product source, and make your next post shoppable.

= Related paid plugin =

This plugin is fully functional on its own. A separate, independent paid plugin called **MyFeeds Pro** is available at [myfeeds.site](https://myfeeds.site/?utm_source=wporg&utm_medium=readme&utm_campaign=paid-plugins), in two plans: Pro and Shop. It adds things like a visual card designer with Google Fonts, auto-play and arrow colours for the carousel, click analytics per post and product, and, on the Shop plan, a full shop with its own categories and filters. It is not required to use this plugin.

== Installation ==

1. Upload the `myfeeds-affiliate-feed-manager` folder to the `/wp-content/plugins/` directory, or install the plugin directly from the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **MyFeeds** in your admin sidebar and choose your product source: **+ Add a feed**, **+ Add API source** for Rakuten Advertising, or **Connect Amazon's API**.
4. For a feed, paste a product feed URL from your affiliate network (or upload its file) and click **Create Feed**.
5. In the block editor, add the **MyFeeds – Product Picker** block to any post or page.
6. Search for products, select them, and publish.

= Where do I get a product feed URL? =

Sign up with an affiliate network (such as AWIN, CJ Affiliate, Rakuten, or Impact), navigate to the product feed section (usually labelled "Create a feed" or "Product feeds"), and copy the feed URL.

== Frequently Asked Questions ==

= Who is MyFeeds for? =

Anyone in affiliate marketing who promotes other companies' products and earns a commission on the sale, from solo bloggers and niche sites to large publishers, magazines and deal sites. They use MyFeeds to show those products as cards inside their posts, with an image, the price and their affiliate link, and the cards keep themselves current. If your network gives you a product feed or a datafeed, MyFeeds can import it.

= Where do my products come from? =

You choose one: a product feed (by link or file), Rakuten Advertising (with as many of its advertisers as you like), or Amazon's API. Amazon products by link or ASIN work beside any of them. To use a different source, choose "Change source" on the Feeds screen: the products of the old source then leave your published posts, as when a feed is deleted, and the Content health card lists those posts.

= How do I upload a merchant product feed to WordPress? =

Install MyFeeds, open **MyFeeds → Feeds**, and paste the feed URL your affiliate network gave you. MyFeeds downloads the file, works out which column is the title, the price, the image and the affiliate link, and imports every product into your own database. CSV, TSV, XML and JSON all work, compressed or not, and nothing has to be uploaded by hand.

= How do I add affiliate products to a blog post? =

Open any post in the block editor and add the **MyFeeds – Product Picker** block. Search your imported catalogue by name, brand, colour or category, tick the products you want, and they appear as product cards with image, price and a buy button. No copying links, no HTML.

= How do I keep affiliate prices up to date on my site? =

MyFeeds re-reads your feed every night and refreshes what is already in your posts, so the price a reader sees is the price the merchant is charging today. A weekly full import catches products that were added or withdrawn. You can also sync any feed by hand at any time.

= Which affiliate networks provide a product feed? =

Most of the large ones do: AWIN, Tradedoubler, CJ Affiliate, Impact, Rakuten Advertising, Pepperjam and FlexOffers all offer product feeds, and so do many merchants directly. Some hand you a link, some a file to download. Look in your network's dashboard for a section called "Product feeds", "Datafeeds" or "Create a feed". MyFeeds takes either: paste the link or upload the file.

= Can I build an affiliate store or shop page with this? =

This plugin puts product cards inside your posts and pages. A full storefront with its own categories, filters and sorting is a paid feature and not part of this plugin.

= How long does an import take? =

It depends on the feed size. A feed with 10,000 products typically takes 2–5 minutes. Imports run in the background via Action Scheduler, so you can keep working while they process.

= Why are some products missing after import? =

Only products with valid data (title, price, image, and affiliate link) are imported. Check your feed source for incomplete entries.

= Does MyFeeds slow down my site? =

Not with a feed or Rakuten: the products are stored in your WordPress database, and a page with product cards is built from there, without a request to your affiliate network. With Amazon's API as your product source, a page can wait for Amazon: your server asks Amazon for product data when a page with Amazon products is shown and the stored data is missing or the price is more than an hour old. While the page is built that is at most four requests per block, about a second apart, for the products whose data is missing; your readers' browsers then ask your own site for the newer prices. Amazon products added without the API cause no request. **External Services** below lists everything the plugin contacts.

= Does MyFeeds work with any theme? =

Yes, it works with any WordPress theme that supports the Gutenberg block editor (WordPress 5.8+).

= Can I use MyFeeds with the Classic Editor? =

No. MyFeeds requires the block editor.

= Which affiliate networks are supported? =

If your affiliate program hands you a product feed URL you can download, MyFeeds will almost certainly read it. The plugin handles the common feed formats automatically and recognises the field structure that most networks use. For everything custom there's a manual mapping editor inside the plugin.

= Does it work with my network's feed: Awin, CJ, Impact, Tradedoubler, Rakuten? =

The automatic mapping is written for the feed formats these networks document. The plugin isn't built around any single network though: it reads the file your program hands you, whatever the network is called. CSV, TSV, XML and JSON all work, compressed or not. If a column is named something the plugin has never seen, the mapping editor lets you point it at the right field yourself.

= My theme or plugin shows products without a MyFeeds block. Can the nightly sync keep them current? =

Yes. The nightly sync refreshes every product it finds on the site: MyFeeds blocks in posts, pages and any public post type, in synced patterns, in block widgets and in block-theme templates. Products a theme or plugin shows by itself (its own table, a template, a post type that keeps them in custom fields) can be announced with the `myfeeds_active_product_ids` filter: return the list you were given plus the `external_id` of each product you show, and the nightly sync keeps their price and stock current. The filter can only add products, never remove the ones your blocks hold. The `myfeeds_discovery_post_types` filter narrows or widens the post types that are read.

= I use a page cache. Do my posts show the new prices after an import? =

Yes. MyFeeds needs no cache plugin and works the same without one. When a site has one, MyFeeds clears the cached copies of the posts that show products after every import, and only those: never the front page or the whole site. It does this for BigScoots Cache, WP Rocket, LiteSpeed Cache, SiteGround Speed Optimizer, W3 Total Cache, WP Super Cache, WP Fastest Cache, Cache Enabler, Breeze, Nginx Helper, Hummingbird and WP-Optimize. Any other cache, a host's own cache or a CDN can listen to the `myfeeds_product_posts_changed` action, which hands over the addresses of those posts after every import.

= Does the plugin make any external requests? =

Yes. What your site contacts depends on your product source; **External Services** below lists every case. In short: your site downloads your product feed from the address you saved (or, with Rakuten Advertising as your product source, asks Rakuten's API for your advertisers' products; with Amazon's API as your product source, asks Amazon's API for product data), measures the file size of a few product pictures, and reads a Look photo from a web address when you paste one in the editor. The pictures themselves stay with the merchant, and your readers' browsers load them from there.

== External Services ==

MyFeeds keeps feed and Rakuten products in your own WordPress database. It contacts other servers in four cases: your site fetches the product feed you configured, or asks Rakuten Advertising or Amazon's API for product data when one of them is your product source; it measures the file size of a few product pictures; and it reads a photo you give a Look block by its web address.

= Configured product feed URL (your affiliate network) =

To import products, your site downloads the feed file (CSV, TSV, XML or JSON) from the URL you save in the Feed Manager.

* **What is sent:** an HTTP GET request to the feed URL, with WordPress's standard `User-Agent` header (your WordPress version and site address) and whatever your network put into the URL itself, such as a publisher ID or a key. No visitor data is sent.
* **When it is sent:** when you add, edit, test or reimport a feed in the WordPress admin, and on the plugin's schedule (a daily sync and a weekly full import). Showing a product card to a reader never calls the feed URL.
* **Where it is sent:** to the host in the feed URL you saved, following any redirect. The plugin passes the URL on to no one.

Your affiliate network's terms of service and privacy policy apply to the download.

= Rakuten Advertising (when it is your product source) =

Rakuten Advertising hands out no feed link. As your product source, your site asks Rakuten's API for the products of the advertisers you choose and imports them like any feed, through a file in your upload folder.

* **What is sent:** your Rakuten API details (client ID, secret and account SID) to get a sign-in token, then advertiser IDs and the categories or words of your Rakuten feeds. No visitor data is sent.
* **When it is sent:** when you connect and test, list your advertisers, set up or edit a Rakuten feed, every night at 00:30 to refresh your Rakuten feeds, when you press "Refresh now" on a Rakuten feed or bring Rakuten back with "Change source", when you search in the product picker while the "Rakuten" switch under the search is on (it is on until you turn it off), and when you paste a shop's address into a Look point and one of your Rakuten advertisers sells it.
* **Where it is sent:** `api.linksynergy.com` (`/token`, `/v1/partnerships`, `/productsearch/1.0`).

The product pictures load from the merchant, as with any feed. Rakuten Advertising's Publisher Membership Agreement (https://rakutenadvertising.com/legal-notices/publisher-membership-agreement/) and Privacy Policy (https://rakutenadvertising.com/privacy-policy/) apply; its API is documented on the Developer Portal (https://developers.rakutenadvertising.com/).

= Amazon's Creators API (when it is your product source) =

With Amazon's API as your product source, your site asks Amazon for the title, picture and price of the Amazon products in your posts, and searches Amazon's catalogue for you in the editor. Your post keeps only the ASIN.

* **What is sent:** your Amazon API details (credential ID and secret) for a sign-in token, then your partner tag, the Amazon site, and the product numbers (ASINs) or your search words. No visitor data is sent.
* **When it is sent:** when you test the connection, search Amazon, add a product or open a post with Amazon products in the block editor; and when a page with Amazon products is shown and the stored data is missing or the price is more than an hour old: while the page is built, for the missing products, up to ten products per request and at most four requests per block and Amazon site; from the reader's browser through your own site (`/wp-json/myfeeds/v1/amazon/live`), for prices over an hour old, up to ten products per request, never for a product your site does not hold. A sign-in token is asked for about once an hour. Amazon products added without the API cause no request.
* **Where it is sent:** the sign-in to `api.amazon.com`, `api.amazon.co.uk` or `api.amazon.co.jp` (by the region of your Amazon site), the product requests to `creatorsapi.amazon`.

MyFeeds keeps Amazon's data for at most a day and shows a price older than an hour with the time Amazon gave it. It never copies Amazon's pictures: your readers' browsers load them from Amazon's image servers, which receive their IP address and browser details. Changing the product source or deactivating the plugin deletes Amazon's data from your site.

* **Terms and privacy:** Amazon's Associates Program IP License (https://affiliate-program.amazon.com/help/operating/policies/#Associates%20Program%20IP%20License) and Amazon's Privacy Notice (https://www.amazon.com/gp/help/customer/display.html?nodeId=468496) apply; the API is documented at https://affiliate-program.amazon.com/creatorsapi/docs/.

= Product pictures (the merchant's image server) =

A feed lists the web address of every product picture, on the merchant's own server or image CDN. MyFeeds shows the picture from that address and does not copy it to your site.

* **In your readers' browsers:** a reader's browser requests each picture from the merchant's image server, as for any picture embedded from another website; that server receives the reader's IP address, browser details and, usually, your site's address. The same happens in your own browser in the block editor.
* **From your server:** the plugin finds out whether an image server can deliver smaller pictures: your server asks for the first byte of a few sample pictures per image host and reads the file size from the reply, with WordPress's standard `User-Agent` header and no visitor data. The check starts after you have opened the WordPress admin, runs in the background, and asks a host that answered again after a month at the earliest.
* **Where it is sent:** to the hosts named in the picture addresses of your feed. For Amazon products with Amazon's API, the pictures come from Amazon's image servers.

Their terms and privacy policies apply. An image optimisation or CDN plugin that rewrites picture addresses may load them through its own service instead.

= A Look photo from a web address (the site you name) =

The Look block shows a photo with numbered points on it. The photo comes from your media library, or from a web address you paste under "Image from a URL".

* **What is sent:** when you press "Check", your server sends an HTTP GET request to the address you pasted; if that is a web page (a Pinterest pin, a product page), it also requests the preview image the page names. The first request carries a `User-Agent` header that says "MyFeeds image check" and names your site's address; the others carry WordPress's standard one (your WordPress version and site address). No visitor data is sent.
* **When it is sent:** only in the block editor, when you check an address and, if you choose "Copy into my media library", once more to copy the image into your media library. Showing a Look to a reader never makes your server fetch its photo.
* **Where it is sent:** to the host of the address you pasted, and to the host of the preview image that page names. Addresses inside your own network are refused.
* **A linked photo:** with "Link the image", your readers' browsers load the photo from that server, which receives their IP address and browser details; MyFeeds tells the browser to send no referrer with it. Name that server in your privacy policy; the editor says so too.

The terms and privacy policy of the site you take the photo from apply, and so do its rights: copy an image only if you may. A product address you paste into a point is looked up in your own database. With Rakuten Advertising as your product source, an address from one of your Rakuten advertisers that your database does not hold yet is also looked up at Rakuten, by the words in the address (see Rakuten Advertising above); with Amazon's API, an Amazon address at Amazon.

= Links on a product card =

Every product card, and every point of a Look, links to the affiliate link from your feed; your affiliate network or the merchant is asked only when a reader clicks it. Sample products, if you use them, link to a page on myfeeds.site.

Without Amazon's API, an Amazon product you add by its link or ASIN links to its page on the Amazon site of your partner tag, with that tag; your site sends Amazon nothing, and the reader's browser goes to Amazon only on a click. The card shows the brand, name and note you typed, or the name taken from the link's own words, never anything Amazon sends, and the post keeps only the ASIN. With Amazon's API as your product source, the card shows Amazon's title, picture and price with the time it was read, and links with the address Amazon's API hands out, unchanged. The "Available at Amazon" badge, if you choose one, is an image from your own media library. Amazon's terms (https://affiliate-program.amazon.com/help/operating/policies/#Associates%20Program%20IP%20License) and privacy notice (https://www.amazon.com/gp/help/customer/display.html?nodeId=468496) apply on Amazon's site.

Apart from the above, the plugin sends nothing anywhere: no analytics, no tracking, no usage reporting. Links in its admin screens open only when you click them.

== Source Code ==

The full source for this plugin is open-source. See [myfeeds.site](https://myfeeds.site/?utm_source=wporg&utm_medium=readme&utm_campaign=source) for the project homepage and links to the public repository.

* Block editor source: `src/index.js`
* Build tool: terser via `npm run build` (configuration in `package.json`)

To rebuild the editor bundle from source, run `npm install && npm run build` inside the plugin folder. The other scripts in `assets/` are not built; they ship as they are written.

= Credits =

* Carousel: [Splide](https://github.com/Splidejs/splide) 4.1.4 by Naotoshi Fujita, MIT license. Bundled unchanged as `assets/vendor/splide.min.js` and `assets/vendor/splide-core.min.css` (only the reference to a source map that is not shipped is removed); the license text is in `assets/vendor/splide-LICENSE.txt`.

== Screenshots ==

1. The product picker inside the block editor. Search your whole feed, narrow it with filters, click what you want. Here: 120 results for "shoes", filtered down to one brand, four products picked.
2. Your picked products, saved in the block. They stay together until you place them, each with its own price and discount. The shop buttons on the cards belong to the Shop plan of MyFeeds Pro.
3. Those four products published as a live product grid in a real blog post. Your readers see what the merchant is actually selling today, for as long as the post exists.
4. The same four products as a swipeable carousel. A second way to present what you've curated, for image-heavy posts and roundups.
5. Card design editor (MyFeeds Pro). Cards that look like your blog wrote them. One save, every card across every post catches up. The live editor opens more than this screen lets on.
6. Full storefront on your own domain (MyFeeds Pro, Shop plan). Visitors see your real online shop with categories, filters and sorting. The controls they already know from any modern shop, all on your domain. Every checkout goes through your affiliate link.
7. Category manager (MyFeeds Pro, Shop plan). A shop organised the way your readers shop. Build the tree once, then curate products into each category by hand. Smart keyword search behind the scenes; you stay the editor.
8. Shop design editor (MyFeeds Pro, Shop plan). Your storefront tracks your taste. A phone, tablet and laptop preview moves with you, so what you ship is exactly what your reader meets. The live editor carries plenty more.

== Changelog ==

= 1.0.52 =
* Changed: the buttons on the Shop, Design and Analytics pages and in the Amazon dialog lead to the two plans of the paid plugin, Pro and Shop (Pro instead of Starter), and start the trial each plan comes with. The Shop plan was called E-commerce before.
* Changed: the readme describes the one paid plugin, MyFeeds Pro, and its two plans.

= 1.0.51 =
* Fixed: on sites with a page cache (BigScoots, WP Rocket, LiteSpeed, SiteGround Optimizer and others), a post could keep showing sold-out products and old prices until the cache expired on its own. After every import MyFeeds now clears the cached copies of the posts that show products - those posts only, never the whole site. Any other cache can listen to the new `myfeeds_product_posts_changed` action.
* Improved: opening the product search in the Product Picker block sends one search instead of two. The search window is now a part of the editor of its own, which changes nothing in how it looks or works.

= 1.0.50 =
* Fixed: the nightly sync refreshed only products placed in posts and pages. Products in other post types, synced patterns, block widgets and block-theme templates kept their old price and stock until the weekly full import. They are now refreshed every night too.
* New: themes and plugins that show products without a MyFeeds block can announce them with the `myfeeds_active_product_ids` filter, and the nightly sync keeps them current.

= 1.0.49 =
* Changed: the one-time review request now comes after a week of working product cards instead of two, and first asks one question: how did you find MyFeeds? Each answer is a link that opens a short thank-you page on myfeeds.site; the plugin itself sends nothing.

= 1.0.48 =
* Fixed: the mapping editor shows a feed's fields again. Loading them ended in a PHP error.
* Fixed: a feed in the format Tradedoubler documents for its Products API imported no products, and a Rakuten catalogue file opened as a feed without products.
* Fixed: an XML feed without line breaks between its products lost every second product, and a price with a thousands mark ("1,299.00") was read wrong.
* Fixed: Ascend's sale price and the column names of Impact's catalogue format are read. In an AWIN feed, the link without tracking no longer replaces the link you mapped. The feed dialog no longer chooses AWIN for every feed.
* Improved: JSON feeds of any size are imported. A refused or timed-out download says what happened. After you change a feed's address or upload a new file, the Feeds screen offers to import it right away.
* Improved: the folders for uploaded feed files and for the copy made during an import are closed to requests from the web. The check that refuses addresses inside your own server also recognises IPv6 notation.
* Improved: the background check of merchants' image servers keeps one byte instead of a whole picture and no longer waits behind servers that cannot be measured. Grouping the sizes of a product after an update finishes by itself.
* Changed: the "External Services" section of this readme is corrected, and the note about Amazon on the Feeds screen describes the paid plans as they are now.

= 1.0.47 =
* Fixed: a product card could show its grey placeholder instead of the picture on sites where an image optimisation or CDN plugin rewrites the addresses of pictures. Some shops turn such services away, and the card then had no picture, although the same picture loads in any browser. A card now goes back to the picture's own address at the shop, once, when the copy it was given does not load. Sites without such a plugin behave exactly as before, and Jetpack's image CDN already left product cards alone.

= 1.0.46 =
* Fixed: activating the plugin skipped its own setup step. The product table, the default settings and the scheduled syncs only appeared with the first visit to the WordPress admin afterwards, so a site activated from the command line had no product table until then, and the daily housekeeping wrote "feed list unreadable" to the error log every day until the first feed was added. Activation now sets everything up right away, and running it again (deactivate, activate) keeps your feeds and products as they are.

= 1.0.45 =
* Improved: the product grid is as wide as your post's text instead of a fixed 1,200 pixels, so cards line up with your writing in every theme. In the block toolbar you can now set it to Wide or Full width, like other blocks.
* Fixed: on narrow cards a sale price was cut off ("89,9...") next to the struck-through old price. It now moves to its own line. Wider cards look exactly as before.

= 1.0.44 =
* Fixed: products of a feed you deleted came back into your posts. Deleting a feed takes the products your posts show off the page and keeps them in the database, but the clean-up that runs right after the delete marked them to stay visible again, with their last price and a link that may no longer lead anywhere. They now stay hidden, and products this already happened to are hidden again once, on the first page load after the update.
* Fixed: products of a removed feed that no post uses any more are now cleared out of the database instead of staying there forever.

= 1.0.43 =
* Improved: prices are written the way their currency is normally written: "£150.00", "$150.00", "150,00 €". A site whose language uses that same currency keeps its own way of writing it. The card and the block editor always show the same price.
* Improved: the Feed Name field says what the name is for. Readers see it on your product cards, so the shop's name works best.
* Fixed: the product picker no longer says "Free Shipping" when a feed has no shipping data. Only a real shipping cost of 0 counts as free shipping, and a delivery time is no longer shown as a shipping cost.
* Fixed: a product without a price says "Price on request" in the product picker too, the same as on the card.
* Fixed: one selected product reads "Use 1 Product".
* Fixed: on the Feeds screen the feed dialogs no longer appear twice after a feed is added or removed, and a script error after an import is gone.

= 1.0.42 =
* Improved: product card images now come in the size the card is actually shown at. The browser measures the card and picks the smallest file that is still sharp on that screen, instead of one 800 px image for every device. Only for image hosts that can deliver smaller copies; cards set to fill their frame keep the one sharp image.

= 1.0.41 =
* Improved: the Analytics preview's insight cards show every card the dashboard has today, including top earning posts and risers and fallers (example data).

= 1.0.40 =
* Improved: the Analytics preview shows the dashboard as it looks now, with unique visitors, earnings per click and the day-by-day comparison with the period before (example data).

= 1.0.39 =
* Fixed: undo and redo in the block editor now update a product block's tiles. Before, the tiles kept showing the old selection, and the next change inside the block could save it back.

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

Older entries (1.0.28 and earlier) are in changelog.txt, which ships with the plugin.

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
