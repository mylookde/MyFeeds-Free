=== MyFeeds — Affiliate Product Feed Importer & Shoppable Product Cards ===
Contributors: myfeeds
Tags: affiliate, affiliate marketing, amazon affiliate, product feed, product import
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.1.4
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Spend less time managing affiliate products. Import them from your affiliate networks, Rakuten or Amazon and show them right in your posts.

== Description ==

**Make your blog shoppable. Spend more time creating, less time managing products.**

Running an affiliate blog takes more than writing good content.

You search for products across different networks, add affiliate links to your posts and try to keep everything up to date. Prices change, products disappear and old links need attention. Before you know it, managing products has become a job of its own.

MyFeeds helps you bring that work together in WordPress.

Bring in products from your affiliate networks, Rakuten Advertising or Amazon, and find them right in the block editor while you write. Show them as product grids, carousels, recommendation cards, comparison tables or photos with clickable products. Your affiliate link is built into every card automatically.

Your time should go into content that helps your readers, not into fixing product links. Spend less time maintaining products, and give your readers a better way to discover what you recommend.

= How it works =

1. **Bring your products into WordPress.** Import a product feed from your affiliate network, connect Rakuten Advertising, or add Amazon products.
2. **Choose the products you recommend.** Search them in the block editor while you write.
3. **Show them in your content.** As a product grid, carousel, recommendation card, comparison table or a photo with clickable products.

= Where your products come from =

**Product feeds from your affiliate networks.** Most affiliate networks offer product feeds: a file or a link with all the products a merchant sells. Paste the link or upload the file. MyFeeds recognizes common fields like title, price, image and link on its own. If a feed names them differently, you match them yourself in the mapping editor. CSV, TSV, XML and JSON all work, compressed or not. A feed link is updated every night; an uploaded file stays as it is until you upload a new one.

**Rakuten Advertising.** Connect your account and choose the advertisers you work with. Their products come in like a feed and are updated every night.

**Amazon, with or without the API.** Add any Amazon product by its link or ASIN. MyFeeds takes the name from the link, which you can change, and adds your Amazon tracking ID. No API is needed for this. If your Amazon Associates account meets Amazon's requirements for its API, you can use Amazon's API as your product source instead: search Amazon in the editor and show Amazon's title, picture and price.

You use one product source at a time, and Amazon products by link work next to it. You can change the source later on the Feeds screen.

= How your readers see them =

* **Product grid.** Cards with picture, brand, price and merchant, easy to scan. For roundups, gift guides and best-of lists.
* **Carousel.** The same products to swipe through, when your post is already long or full of pictures.
* **Recommendation card.** One product you recommend most, shown large, with a label like "Our pick" and a short reason. MyFeeds suggests both, and you can write your own.
* **Comparison table.** Your pick large, alternatives beside or below, each with a label like "Budget pick" or "Premium pick", so readers can compare at a glance.
* **Look block.** Your own photo with numbered points, each linked to a product: an outfit, a room, a desk setup. A list under the photo shows the same products.

Recommendation cards and comparison tables also work with Amazon products you add by link.

= How MyFeeds keeps your posts current =

* With a feed link or Rakuten, the products in your posts are updated every night.
* Once a week, a full import brings in new products and notices the ones that are gone.
* When a product is no longer available, it is no longer shown in your posts. MyFeeds lists the posts it appeared in under "Content health", so you can replace it. If it comes back, it is shown again.
* Your products are stored in your own WordPress database. With a feed or Rakuten, product cards are built from there, without contacting an outside service on each page view. Only the pictures load from the merchant's image server.
* After each import, MyFeeds clears the cached copies of the posts that show products, for the common cache plugins.

= What changes for you =

* You find products where you already write, without switching between networks or copying links.
* Your readers get clear product recommendations instead of a list of bare affiliate links.
* You don't have to check every old post by hand. MyFeeds shows you which posts have a product that is gone.
* You have more time for research, writing and your readers.

= Get started =

Install MyFeeds, choose where your products come from and add your first products to a post.

= Good to know =

* Works with any theme that supports the block editor.
* Prices and currencies come straight from your source and are never converted.

= Related paid plugin =

This plugin is fully functional on its own. A separate, independent paid plugin called **MyFeeds Pro** is available at [myfeeds.site](https://myfeeds.site/?utm_source=wporg&utm_medium=readme&utm_campaign=paid-plugins), in two plans: Pro and Shop. Pro adds several product sources side by side and a design editor that fits your cards to your style: colors, spacing, fonts and what each card shows. Its click analytics show which posts, products, brands and networks bring the most clicks. They also list the products that have left your feeds and lead you to the posts that show them; a relisted product is swapped in one click. The Shop plan adds a storefront on your own domain, with categories, filters and sorting. MyFeeds Pro is not required to use the free plugin.

== Installation ==

1. Upload the `myfeeds-affiliate-feed-manager` folder to the `/wp-content/plugins/` directory, or install the plugin directly from the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **MyFeeds** in your admin sidebar and choose your product source: **+ Add a feed**, **+ Add API source** for Rakuten Advertising, or **Connect Amazon's API**.
4. For a feed, paste a product feed URL from your affiliate network (or upload its file) and click **Create Feed**.
5. In the block editor, add the **MyFeeds – Product Picker** block to any post or page, or **MyFeeds Look** for a photo with clickable products.
6. Search for products, select them, and publish.

= Where do I get a product feed URL? =

Sign up with an affiliate network (such as AWIN), open its product feed section (often called "Create a feed", "Product feeds" or "Datafeeds") and copy the feed link. If your network offers a file, upload it. With Rakuten Advertising, choose **+ Add API source** instead.

== Frequently Asked Questions ==

= Who is MyFeeds for? =

Anyone in affiliate marketing who promotes other companies' products and earns a commission on the sale, from solo bloggers and niche sites to large publishers, magazines and deal sites. They use MyFeeds to show those products as cards inside their posts, with an image, the price and their affiliate link. If your network gives you a product feed or a datafeed, MyFeeds can import it, and Amazon products work by link, without Amazon's API.

= Where do my products come from? =

You choose one: a product feed (by link or file), Rakuten Advertising (with as many of its advertisers as you like), or Amazon's API. Amazon products by link or ASIN work beside any of them. To use a different source, choose "Change source" on the Feeds screen: the products of the old source then leave your published posts, as when a feed is deleted, and the Content health card lists those posts.

= How do I upload a merchant product feed to WordPress? =

Install MyFeeds, open **MyFeeds → Feeds**, choose **+ Add a feed** and paste the feed link your affiliate network gave you, or upload its file. MyFeeds works out which column is the title, the price, the image and the affiliate link, and imports the products into your own database. CSV, TSV, XML and JSON all work, compressed or not. A feed link is read again every night.

= How do I add affiliate products to a blog post? =

Open any post in the block editor and add the **MyFeeds – Product Picker** block. Search your products, narrow them by brand, color, category or shop, tick the ones you want, and choose how they show: product grid, carousel, recommendation card or comparison table. Every card carries your affiliate link. No copying links, no HTML. For a photo with clickable products, add the **MyFeeds Look** block.

= How do I keep affiliate prices up to date on my site? =

MyFeeds re-reads your feed link every night and refreshes what is already in your posts, so a reader sees the price from your merchant's latest update. An uploaded file stays as it was until you upload a new one. A weekly full import catches products that were added or withdrawn. You can also sync any feed by hand at any time.

= Which affiliate networks provide a product feed? =

Most of the large ones do: AWIN, Tradedoubler, CJ Affiliate, Impact, Rakuten Advertising, Pepperjam and FlexOffers all offer product feeds, and so do many merchants directly. Some hand you a link, some a file to download. Look in your network's dashboard for a section called "Product feeds", "Datafeeds" or "Create a feed". MyFeeds takes either: paste the link or upload the file. Rakuten Advertising can also be connected through its API (**+ Add API source**).

= Can I build an affiliate store or shop page with this? =

This plugin puts product cards inside your posts and pages. A full storefront with its own categories, filters and sorting is a paid feature and not part of this plugin.

= How long does an import take? =

It depends on the feed size. A feed with 10,000 products typically takes 2–5 minutes. Imports run in the background via Action Scheduler, so you can keep working while they process.

= Why are some products missing after import? =

MyFeeds imports every row from which it can read a product number. Rows without one are skipped, and a number that appears twice in a feed is one product. If many products are missing, open the mapping editor and check which column holds the product number.

= Does MyFeeds slow down my site? =

Not with a feed or Rakuten: the products are stored in your WordPress database, and a page with product cards is built from there, without a request to your affiliate network. With Amazon's API as your product source, a page can wait for Amazon: your server asks Amazon for product data when a page with Amazon products is shown and the stored data is missing or the price is more than an hour old. While the page is built that is at most four requests per block, about a second apart, for the products whose data is missing; your readers' browsers then ask your own site for the newer prices. Amazon products added without the API cause no request. **External Services** below lists everything the plugin contacts.

= Does MyFeeds work with any theme? =

Yes, it works with any WordPress theme that supports the Gutenberg block editor (WordPress 5.8+).

= Can I use MyFeeds with the Classic Editor? =

No. MyFeeds requires the block editor.

= Which affiliate networks are supported? =

If your affiliate program gives you a product feed, as a link or a file, MyFeeds can usually read it: it recognizes the field structure most networks use, and for anything unusual there is a mapping editor. Rakuten Advertising can also be connected through its API, and Amazon products work by link or through Amazon's API.

= Does it work with my network's feed: Awin, CJ, Impact, Tradedoubler, Rakuten? =

The automatic mapping is written for the feed formats these networks document. The plugin isn't built around any single network though: it reads the file your program hands you, whatever the network is called. CSV, TSV, XML and JSON all work, compressed or not. If a column is named something the plugin has never seen, the mapping editor lets you point it at the right field yourself.

= My theme or plugin shows products without a MyFeeds block. Can the nightly sync keep them current? =

Yes. The nightly sync refreshes every product it finds on the site: MyFeeds blocks in posts, pages and any public post type, in synced patterns, in block widgets and in block-theme templates. Products a theme or plugin shows by itself (its own table, a template, a post type that keeps them in custom fields) can be announced with the `myfeeds_active_product_ids` filter: return the list you were given plus the `external_id` of each product you show, and the nightly sync keeps their price and stock current. The filter can only add products, never remove the ones your blocks hold. The `myfeeds_discovery_post_types` filter narrows or widens the post types that are read.

= I use a page cache. Do my posts show the new prices after an import? =

Yes. MyFeeds needs no cache plugin and works the same without one. When a site has one, MyFeeds clears the cached copies of the posts that show products after every import, and only those: never the front page or the whole site. It does this for BigScoots Cache, WP Rocket, LiteSpeed Cache, SiteGround Speed Optimizer, W3 Total Cache, WP Super Cache, WP Fastest Cache, Cache Enabler, Breeze, Nginx Helper, Hummingbird and WP-Optimize. Any other cache, a host's own cache or a CDN can listen to the `myfeeds_product_posts_changed` action, which hands over the addresses of those posts after every import.

= Does the plugin make any external requests? =

Yes. What your site contacts depends on your product source; **External Services** below lists every case. In short: your site downloads your product feed from the address you saved (or, with Rakuten Advertising as your product source, asks Rakuten's API for your advertisers' products; with Amazon's API as your product source, asks Amazon's API for product data), measures the file size of a few product pictures, and reads a Look photo from a web address when you paste one in the editor. The pictures themselves stay with the merchant, and your readers' browsers load them from there.

== External Services ==

MyFeeds contacts other servers in four cases: your site fetches the product feed you configured, or asks Rakuten Advertising or Amazon's API for product data when one of them is your product source; it measures the file size of a few product pictures; and it reads a photo you give a Look block by its web address.

= Configured product feed URL (your affiliate network) =

To import products, your site downloads the feed file (CSV, TSV, XML or JSON) from the URL you save in the Feed Manager.

* **What is sent:** an HTTP GET request to the feed URL, with WordPress's standard `User-Agent` header (your WordPress version and site address) and whatever your network put into the URL itself, such as a publisher ID or a key. No visitor data is sent.
* **When it is sent:** when you add, edit, test or reimport a feed in the WordPress admin, and on the plugin's schedule (a daily sync and a weekly full import). Showing a product card to a reader never calls the feed URL.
* **Where it is sent:** to the host in the feed URL you saved, following any redirect. The plugin passes the URL on to no one.

Your affiliate network's terms and privacy policy apply.

= Rakuten Advertising (when it is your product source) =

Rakuten Advertising hands out no feed link. As your product source, your site asks Rakuten's API for the products of the advertisers you choose and imports them like any feed.

* **What is sent:** your Rakuten API details (client ID, secret and account SID) to get a sign-in token, then advertiser IDs and the categories or words of your Rakuten feeds. No visitor data is sent.
* **When it is sent:** when you connect and test, list your advertisers, set up or edit a Rakuten feed, every night at 00:30 to refresh your Rakuten feeds, when you press "Refresh now" on a Rakuten feed or bring Rakuten back with "Change source", when you search in the product picker while the "Rakuten" switch under the search is on (it is on until you turn it off), and when you paste a shop's address into a Look point and one of your Rakuten advertisers sells it.
* **Where it is sent:** `api.linksynergy.com` (`/token`, `/v1/partnerships`, `/productsearch/1.0`).

Rakuten Advertising's Publisher Membership Agreement (https://rakutenadvertising.com/legal-notices/publisher-membership-agreement/) and Privacy Policy (https://rakutenadvertising.com/privacy-policy/) apply; its API is documented on the Developer Portal (https://developers.rakutenadvertising.com/).

= Amazon's Creators API (when it is your product source) =

With Amazon's API as your product source, your site asks Amazon for the title, picture and price of the Amazon products in your posts, and searches Amazon's catalogue for you in the editor. Your post keeps only the ASIN.

* **What is sent:** your Amazon API details (credential ID and secret) for a sign-in token, then your partner tag, the Amazon site, and the product numbers (ASINs) or your search words. No visitor data is sent.
* **When it is sent:** when you test the connection, search Amazon, add a product or open a post with Amazon products in the block editor; and when a page with Amazon products is shown and the stored data is missing or the price is more than an hour old: while the page is built, for the missing products, up to ten products per request and at most four requests per block and Amazon site; from the reader's browser through your own site (`/wp-json/myfeeds/v1/amazon/live`), for prices over an hour old, up to ten products per request, never for a product your site does not hold. A sign-in token is asked for about once an hour. Amazon products added without the API cause no request.
* **Where it is sent:** the sign-in to `api.amazon.com`, `api.amazon.co.uk` or `api.amazon.co.jp` (by the region of your Amazon site), the product requests to `creatorsapi.amazon`.

MyFeeds keeps Amazon's data for at most a day and shows a price older than an hour with the time Amazon gave it. It never copies Amazon's pictures: your readers' browsers load them from Amazon's image servers, which receive their IP address and browser details. Changing the product source or deactivating the plugin deletes the Amazon data MyFeeds keeps.

* **Terms and privacy:** Amazon's Associates Program IP License (https://affiliate-program.amazon.com/help/operating/policies/#Associates%20Program%20IP%20License) and Amazon's Privacy Notice (https://www.amazon.com/gp/help/customer/display.html?nodeId=468496) apply; the API is documented at https://affiliate-program.amazon.com/creatorsapi/docs/.

= Product pictures (the merchant's image server) =

MyFeeds shows each product picture from the address in your feed, on the merchant's server or image CDN, and does not copy it to your site.

* **In your readers' browsers:** a reader's browser requests each picture from the merchant's image server, as for any picture embedded from another website; that server receives the reader's IP address, browser details and, usually, your site's address.
* **From your server:** the plugin finds out whether an image server can deliver smaller pictures: your server asks for the first byte of a few sample pictures per image host and reads the file size from the reply, with WordPress's standard `User-Agent` header and no visitor data. The check starts after you have opened the WordPress admin, runs in the background, and asks a host that answered again after a month at the earliest.
* **Where it is sent:** to the hosts named in the picture addresses of your feed.

Their terms and privacy policies apply. An image optimisation or CDN plugin that rewrites picture addresses may load them through its own service instead.

= A Look photo from a web address (the site you name) =

A Look photo comes from your media library, or from a web address you paste under "Image from a URL".

* **What is sent:** when you press "Check", your server sends an HTTP GET request to the address you pasted; if that is a web page (a Pinterest pin, a product page), it also requests the preview image the page names. The first request carries a `User-Agent` header that says "MyFeeds image check" and names your site's address; the others carry WordPress's standard one. No visitor data is sent.
* **When it is sent:** only in the block editor, when you check an address and, if you choose "Copy into my media library", once more to copy the image. Showing a Look to a reader never makes your server fetch its photo.
* **Where it is sent:** to the host of the address you pasted, and of the preview image that page names. Addresses inside your own network are refused.
* **A linked photo:** with "Link the image", your readers' browsers load the photo from that server, which receives their IP address and browser details; MyFeeds tells the browser to send no referrer with it. Name that server in your privacy policy.

The terms and privacy policy of the site you take the photo from apply, and so do its rights: copy an image only if you may. A product address you paste into a point is looked up in your own database. With Rakuten Advertising as your product source, an address from one of your Rakuten advertisers that your database does not hold yet is also looked up at Rakuten, by the words in the address (see Rakuten Advertising above); with Amazon's API, an Amazon address at Amazon.

= Links on a product card =

Every product card, and every point of a Look, links to the affiliate link from your feed; your affiliate network or the merchant is asked only when a reader clicks it. Sample products, if you use them, link to a page on myfeeds.site.

Without Amazon's API, an Amazon product you add by its link or ASIN links to its page on the Amazon site of your partner tag, with that tag; your site sends Amazon nothing, and the reader's browser goes to Amazon only on a click. The card shows the brand, name and note you typed, or the name taken from the link's own words, never anything Amazon sends, and the post keeps only the ASIN. With Amazon's API as your product source, the card links with the address Amazon's API hands out, unchanged. The "Available at Amazon" badge, if you choose one, is an image from your own media library. Amazon's terms (https://affiliate-program.amazon.com/help/operating/policies/#Associates%20Program%20IP%20License) and privacy notice (https://www.amazon.com/gp/help/customer/display.html?nodeId=468496) apply on Amazon's site.

Apart from the above, the plugin sends nothing anywhere: no analytics, no tracking, no usage reporting. Links in its admin screens open only when you click them.

== Source Code ==

The full source is public: [github.com/mylookde/MyFeeds-Free](https://github.com/mylookde/MyFeeds-Free). The project homepage is [myfeeds.site](https://myfeeds.site/?utm_source=wporg&utm_medium=readme&utm_campaign=source).

* Block editor source: `src/index.js`

To rebuild the editor bundle from source (terser, configured in `package.json`), run `npm install && npm run build` inside the plugin folder. The other scripts in `assets/` ship as written, without a build.

= Credits =

* Carousel: [Splide](https://github.com/Splidejs/splide) 4.1.4 by Naotoshi Fujita, MIT license. Bundled unchanged as `assets/vendor/splide.min.js` and `assets/vendor/splide-core.min.css` (only the reference to a source map that is not shipped is removed); the license text is in `assets/vendor/splide-LICENSE.txt`.

== Screenshots ==

1. The Product Picker in the block editor. Search your whole catalog, narrow it with filters, pick what fits your post.
2. Your picks published as a product grid: picture, brand, price, discount and merchant, from your merchant's latest update. Your affiliate link is built into every card.
3. The same picks as a swipeable carousel, for long posts and picture-heavy roundups.
4. The Look block: numbered points on your own photo, each linked to a product. A list of the same products can show underneath.
5. A recommendation card for the product you recommend most, with a label and a short reason. It also works with Amazon products added by link.
6. A comparison table: your pick large, the alternatives beside it (below it on phones), each with a label like "Budget pick" or "Premium pick". It also works with Amazon products added by link.
7. Design editor (MyFeeds Pro). Adjust colors, fonts and spacing of your product cards; one save updates every card on your site.
8. Your own storefront (MyFeeds Pro, Shop plan), with categories, filters and sorting on your domain. Every product leads to the merchant through your affiliate link.
9. Category manager (MyFeeds Pro, Shop plan). Build the category tree once, then choose the products for each category.
10. Shop design editor (MyFeeds Pro, Shop plan), with phone, tablet and laptop previews.

== Changelog ==

= 1.1.4 =
* New: a carousel can play by itself. In the block's Display Settings, turn on "Auto-play" and choose how long each slide stays, from 2 to 15 seconds (3 by default). It is off until you turn it on, and it pauses while a reader points at the carousel or moves through it with the keyboard.
* Fixed: the plugin directory listed the Product Picker block as "MyFeeds u2013 Product Picker". The block's name now carries a real dash.

= 1.1.3 =
* Fixed: with Rakuten Advertising as your product source, two advertisers can use the same product number for two different products (merchants of one group often share their article numbers). A card in your post could then show the other advertiser's product, price and link. A product you pick in the block editor now keeps the advertiser it came from, and its card shows that one. Blocks saved before this update find their product by the merchant name they saved.
* Fixed: the product search in the block editor could count two such products and show only one, because the second replaced the first. Both show now, each with its merchant.
* Fixed: the points of a Look, the sizes and colours in a product's details and the refresh of your saved products when you open a post in the block editor keep to the advertiser a product came from, too.
* Fixed: the nightly update of the products in your posts stopped at the first advertiser with a product number and could write that advertiser's price, picture and link onto the other one's product. Each advertiser's product is now updated from its own feed.
* Fixed: when a full import found a product gone from one advertiser, a product with the same number from another advertiser was marked unavailable too and left your posts. Only the advertiser's own product is marked now.

= 1.1.2 =
* New: the filters of the product search in the block editor have a Merchant section. When the products a search finds come from two merchants or more, tick one or several to see only theirs. It works together with the other filters, shows as a chip above the results and goes with "Clear all". With Rakuten Advertising as your product source, each advertiser is a merchant.
* Fixed: when a search found nothing with whole words and fell back to words inside longer words, it dropped the filters you had set and showed products of any brand, merchant or price. The fallback now keeps your filters.
* Fixed: in a narrow editor window the short filter lists of the product search (Merchant, Price) sit side by side instead of each taking a full row.
* Changed: in the filters of the product search, "On sale" and "In stock" sit under the price, so the filter panel is no taller than its longest list and the first results stay in view.
* Faster: with a filter set in the product search (brand, colour, category or merchant), the counts beside the filters take one pass over the matching products, plus one for each list you filtered on, instead of a query for every list.
* Fixed: with a filter set, the category counts of the product search could count two products with the same name and colour as one. They now count products the way the brand and colour lists do.
* Fixed: on a search that matches thousands of products, the number of results and the counts beside the filters count every match. Before, they counted part of them: a merchant could be missing from the list, and ticking a filter could show more results than the search without it. Where the search can count only part, the number says so with a "+" (for example "867+ results"), and where the list holds fewer products than the number, a line under it says so.
* Faster: a search that matches thousands of products counts its results in the pass that counts the filters, not in a second one.
* Fixed: the × on an active filter tells screen readers which filter it removes.

= 1.1.1 =
* Fixed: a product without a photo shows a plain grey picture with a picture outline in its place. Before, its card in your post and its tile in the block editor showed a broken-image icon with the product's name. That picture is never given to search engines as the product's photo.
* Fixed: when a product's photo does not load from the merchant, its card shows a grey box with a picture outline. Before, the broken-image icon and the product's name lay on top of it.
* Changed: the paid plugin's editor for the look of your cards is called the design editor, on the Design page and in this readme. The picture on the Design page shows the editor as it looks today.

= 1.1.0 =
* New: choose where your products come from. Paste a product feed link or upload its file as before, connect Rakuten Advertising, or connect Amazon's API. Without a source the Feeds screen offers all three; with one, it offers "Change source".
* New: Rakuten Advertising as your product source. Your site asks Rakuten's API for the products of the advertisers you choose, as many as you like, imports them like a feed and refreshes them every night. External Services says what is sent and when.
* New: Amazon's API as your product source. Search Amazon in the block editor; the card shows Amazon's title, picture and price, with the time the price was read. Amazon opens its API to associates with ten qualified sales in thirty days. External Services says what is sent and when.
* New: Amazon products by link or ASIN, beside your source. Save your partner tag under "+ Add Amazon" on the Feeds screen, then add a product by its Amazon link or ASIN in the product block or in a Look. The card links straight to Amazon with your tag.
* New: the product block shows your products as a grid, a carousel, a recommendation card or a comparison table.
* New: the Look block. Put numbered points on a photo of your own and link each one to a product.
* New: "Change source" on the Feeds screen. The products of the old source leave your published posts, as when a feed is deleted, and the Content health card lists those posts. A source that was set aside can be brought back there.
* Changed: a site that ran MyFeeds Pro with several sources keeps the one with the most products in your posts; the others are set aside, not deleted, and the notice on the Feeds screen swaps one back in. Before, the free plugin dropped every feed after the first.
* Changed: the Content health card speaks for your source; "Update All Feeds" is suggested for a feed only.
* Fixed: on a site that never ran the paid plugin, the products of a deleted feed stayed in your posts, and reimporting a feed by hand left the products your merchant had withdrawn as they were. The products table lacked a column both steps write; it is added on the first visit to the admin after the update.
* Improved: deleting a feed or changing the source clears the cached copies of your posts with products, as an import does.
* Improved: notices on MyFeeds screens stand above the page heading, and other plugins' notices are left off these screens.
* Fixed: sample products no longer appear in a page's structured data (JSON-LD).
* Fixed: on PHP 8.4, reading a CSV or TSV feed raised a deprecation notice for every row.

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

= 1.1.4 =
Adds auto-play for the carousel, off until you turn it on. Coming from 1.0.x: one product source, now also Rakuten or Amazon's API; a site that ran MyFeeds Pro keeps its biggest source, the others are set aside, not deleted.

= 1.1.3 =
Fixes cards that could show another advertiser's product, price and link when two advertisers share product numbers. Coming from 1.0.x: one product source, now also Rakuten or Amazon's API; a site that ran MyFeeds Pro keeps its biggest source, the others are set aside, not deleted.

= 1.1.2 =
Adds a Merchant filter to the product search. Coming from 1.0.x: one product source, now also Rakuten or Amazon's API; a site that ran MyFeeds Pro keeps its biggest source, the others are set aside, not deleted.

= 1.1.1 =
Fixes the broken-image icon on a product without a photo. Coming from 1.0.x: one product source, now also Rakuten or Amazon's API; a site that ran MyFeeds Pro keeps its biggest source, the others are set aside, not deleted.

= 1.1.0 =
One product source, now with Rakuten and Amazon's API; every display and the Look; Amazon products by link or ASIN. A site that ran MyFeeds Pro keeps its biggest source; the others are set aside, not deleted.

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
