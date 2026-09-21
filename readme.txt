=== PriceCloak for WooCommerce ===
Contributors: wadejbeckett
Tags: woocommerce, hide price, guests, login, b2b
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 9.0
WC tested up to: 11.1.0
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

One switch: logged-out visitors see no prices anywhere WooCommerce renders or serves them.

== Description ==

When the switch is on, a logged-out visitor cannot obtain a price from any front-end surface WooCommerce renders or serves. When the switch is off, the plugin registers no hooks at all and the shop behaves as normal retail.

Surfaces covered, one module each:

* **Price HTML** — the price shown on the shop and catalogue pages, single product pages, related, upsell and cross-sell blocks, product widgets and product blocks, for simple, variable, grouped and external products, including sale and empty prices; the cart and checkout totals (subtotal, coupon, shipping, fees, tax, total), the spend thresholds in coupon error notices, and the AJAX responses that refresh them; the Mini Cart block's subtotal; the `data-price` attribute on the product grid blocks' buttons; and a guest's own order on the order-received and order-pay pages and in the order tracking form's result.
* **Variation payload** — the per-variation data a variable product page hands the browser: both the inline `data-product_variations` JSON and the `wc-ajax=get_variation` response.
* **Structured data** — the `offers` node in the product JSON-LD search engines read.
* **Store API** — every amount in a Store API response for an unauthenticated request: the products routes, the cart, checkout and order routes, batch sub-responses, under `/wc/store/v1/` and the unversioned `/wc/store/` alias, and the copies of those responses WooCommerce embeds into the Cart, Checkout, Mini Cart and product grid blocks and into block-theme shop and product pages.
* **Legacy REST** — `/wc/v3/products` and its variations routes (and the v1/v2 equivalents) for unauthenticated requests, on sites that have opened those routes to guests.
* **Purchasing** (off by default) — server-side refusal of add-to-cart and of checkout processing on every path (the classic form, `wc-ajax` and the Store API, versioned and unversioned), cart and checkout redirected to login, and native "Log in to buy" buttons. Paying an existing order through its order-pay link stays possible.
* **Price filters** — the ways a guest could infer a price without being shown one: price-range filtering (`min_price`/`max_price`) is ignored on the shop, on the Store API and on WordPress core's `/wp/v2/product` route, price sorting falls back to the default ordering everywhere and leaves the sort dropdown, and the price filter widget and blocks are not rendered. Logged-in shoppers keep all of them.

Authenticated requests are never touched. Each module can be switched off on its own.

**Why it exists.** Blanking the rendered price HTML is not enough. On a variable product, WooCommerce also publishes a machine-readable variation payload — the inline variations JSON and the AJAX variation lookup — carrying numeric `display_price` and `display_regular_price` fields. A plugin that only filters price HTML leaves those numbers in the page source and in an AJAX response any anonymous client can request, so the prices are still there for anyone who looks. The same is true of the JSON-LD `offers` node and of the Store and REST API product payloads. This plugin closes every one of those surfaces rather than one of them.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/`, or install the zip through Plugins → Add New → Upload Plugin.
2. Activate it. WooCommerce 9.0 or later must be active.
3. Go to WooCommerce → Settings → Products → Guest price visibility.
4. Turn on **Hide prices from logged-out visitors**. Optionally adjust the replacement text, whether it links to the login page, and whether purchasing is blocked for guests.
5. If you run a page cache, purge it once after enabling (see the FAQ).

== Frequently Asked Questions ==

= Does it work with caching plugins? =

There is no page-cache handling in 1.0. The plugin sends no cache headers and does not vary any response; adding `Cache-Control: private` and vary hints on affected responses is on the roadmap.

In practice: a guest page cached *while* prices are hidden is fine — it holds the replacement text, and that is what every guest should see anyway. The problem is pages cached *before* you turned the switch on: those hold real prices and will keep being served to guests until they expire. Purge the whole page cache once, immediately after enabling (and again after disabling). Make sure your cache never serves a page cached for a logged-in customer to a logged-out visitor; any correctly configured WooCommerce page cache already bypasses the cache for logged-in sessions.

= Does it coexist with B2B plugins? =

Yes. The plugin has no dependency on, adapter for, or awareness of any B2B, wholesale or role-pricing plugin, and none of them is aware of it. Both only blank what a guest is shown, so running both is additive and safe — the visitor simply sees whichever replacement is applied last. Decide per site which one you want to own guest pricing, so there is one place to change it.

= Can I hide prices from some roles, or from some customers only? =

Not in 1.0. "Hidden" means exactly "the request has no logged-in WordPress user". Role- and capability-based hiding is on the roadmap.

= Does it stop guests buying? =

Only if you turn on **Block purchasing for logged-out visitors**, which is off by default. With it off, prices are hidden but a guest can still add to cart and check out. With it on, add-to-cart is refused server-side on every path (the product form, `wc-ajax=add_to_cart` and the Store API cart routes), checkout processing is refused as well (the classic form and `wc-ajax=checkout`, and the Store API checkout routes, so a cart filled before you turned the switch on cannot become an order), cart and checkout redirect to the login page, and add-to-cart buttons become a "Log in to buy" link. A cart built before the customer logged out is preserved, not emptied, and can still be viewed. A guest holding the order-pay link for an order that already exists (an invoice you created in the admin, or a pending order WooCommerce emailed a "pay now" link for) can still open it and pay: paying an existing order is not catalogue purchasing, and WooCommerce checks the order key itself.

= Where does the "Sign in to see prices" link go? =

To the My Account page, or to wp-login.php on a site without one, with the page the visitor was on as `redirect_to`, and after logging in the visitor is returned there. On the My Account form that works through WooCommerce's own hidden `redirect` field, printed on the `woocommerce_login_form_end` action; a theme that overrides `myaccount/form-login.php` and drops that action lands the customer on the My Account dashboard instead. The target never points off-site: it is validated against your home URL before it goes on the link, and again by WooCommerce and WordPress before they follow it.

= What does a guest see in their cart and at the checkout? =

No amounts. With the master switch on, a guest's own cart, the checkout, the order-received and order-pay pages and the Store API cart, checkout and order routes carry the replacement text or nothing where an amount would be -- the Cart and Checkout blocks and the Mini Cart drawer render their line items and totals from a cart response whose amounts are already blank. If purchasing is not blocked, that guest can still place the order without ever seeing a total, which suits a quote-first B2B shop and hardly anything else. So enable **Block purchasing for logged-out visitors** together with the master switch unless you mean to accept blind guest orders.

= I was running "Hide Prices from Guests for WooCommerce". Do I lose my settings? =

No. This is the same plugin under a new name. The first time it runs, it copies every setting from the old `hpfg_` option names to the new `pricecloak_` ones and removes the old rows. Uninstalling removes both sets, so nothing is left behind either way.

= Does it hide prices in emails or feeds? =

Emails are not touched, including the order emails a guest receives after checking out and the customer stock notifications a guest signs up for: the plugin never filters `wc_price()` globally, the order-level filters it uses are attached only while the order-received or order-pay page, or an order tracking result, is being rendered, and its guest decision is "no" while any WooCommerce email renders, inside WP-Cron, WP-CLI and Action Scheduler jobs. The `pricecloak_is_guest` filter lets a site extend that decision. RSS and product feeds are not covered in 1.0; a feed module is on the roadmap.

== Changelog ==

= 1.0.0 =
* First release.
* Renamed from "Hide Prices from Guests for WooCommerce"; settings stored under the previous `hpfg_` option names are carried over automatically on activation.
* Master switch: when on, guests get no price from any WooCommerce surface; when off, no hooks are registered at all.
* Modules: Price HTML, Variation payload, Structured data, Store API, Legacy REST, Price filters, and Purchasing (off by default).
* Settings section under WooCommerce → Settings → Products: replacement text, optional login link, purchasing block, and one toggle per module.
* Declares WooCommerce HPOS and Cart/Checkout Blocks compatibility.
* The classic cart and checkout totals, the Mini Cart block's subtotal state, a guest's own order-received, order-pay and order-tracking pages and the grouped product's sold-individually label (classic template and block) carry the replacement text instead of an amount; the product grid blocks' buttons carry no `data-price`; price filtering and price sorting are inert for guests on the shop, on the Store API and on the `/wp/v2/product` route.
* The Store API cart, checkout and order routes, the unversioned `/wc/store/` alias, and every copy of those responses WooCommerce embeds into its block pages carry no amount for guests; the Cart and Checkout blocks and the Mini Cart drawer keep rendering.
* With purchasing blocked, checkout processing itself is refused on the classic form, `wc-ajax=checkout` and the Store API, versioned and unversioned.
* The login link carries the current page as `redirect_to`, correct on a subdirectory install and never pointing off-site; with purchasing blocked, a guest can still pay an existing order through its order-pay link.
* One guest decision for every module: WP-Cron, WP-CLI, Action Scheduler jobs and emails being rendered get the real price, so a customer stock notification (back-in-stock or verification) is never sent with the replacement text; the `pricecloak_is_guest` filter extends the rule.
* The development repository carries an anonymous HTTP probe (`tests/probe/probe.py`, not part of this package) that checks every surface on a live site.
