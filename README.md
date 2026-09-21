# PriceCloak for WooCommerce

A logged-in-only pricing switch for WooCommerce.

When the master switch is **on**, a logged-out visitor cannot obtain a price from any front-end surface WooCommerce renders or serves: the catalogue, product pages, the cart and checkout (classic templates and the Cart and Checkout blocks alike), the Mini Cart block, their own order on the order-received and order-pay pages, the variation payload behind a variable product, JSON-LD, the Store API -- products, cart, checkout and order routes, and the copies of those responses WooCommerce embeds into its block pages -- and the legacy REST API; nor infer one through price filtering or price sorting, on the shop, on the Store API or on WordPress core's `/wp/v2/product` route. Authenticated requests are untouched. An optional second switch refuses guest purchasing outright -- add-to-cart and checkout processing on every path -- and replaces add-to-cart buttons with "Log in to buy".

When the master switch is **off**, the plugin registers no hooks, scripts or styles beyond its settings section. Off means the shop behaves as plain retail.

No dependency on any B2B, wholesale or role-pricing plugin. WooCommerce only.

Requires WordPress 6.4+, WooCommerce 9.0+, PHP 8.1+. Licence GPL-3.0-or-later.

## Settings

WooCommerce → Settings → Products → **Guest price visibility**. Native WooCommerce settings API, no custom admin pages. All options use the `pricecloak_` prefix and are deleted on uninstall. The plugin was previously released as "Hide Prices from Guests for WooCommerce", whose options used an `hpfg_` prefix; those are carried over once on activation (and on `plugins_loaded` until that has happened), so an in-place upgrade keeps its settings, and uninstall removes both prefixes.

| Setting | Option | Default | Notes |
| --- | --- | --- | --- |
| Hide prices from logged-out visitors | `pricecloak_enabled` | Off | Master switch. Off means zero hooks registered. |
| Replacement text | `pricecloak_replacement_text` | `Sign in to see prices` | Shown where a price would be. Blank falls back to the default. |
| Link replacement text to the login page | `pricecloak_link_to_login` | On | Links to My Account (wp-login.php on a site without one) with the current URL as `redirect_to`. Off renders plain text. See the note on the login link below. |
| Block purchasing for logged-out visitors | `pricecloak_block_purchasing` | Off | The Purchasing module's switch. |
| Module: *name* | `pricecloak_module_<id>` | On | One checkbox per module below, only meaningful when the master switch is on. |

## Modules

Each surface is one module with `register()`/`unregister()`, loaded only when the master switch is on and the module is on. No state is shared between modules.

| Module | Id / option | Hooks | Surfaces covered |
| --- | --- | --- | --- |
| Price HTML | `price_html` / `pricecloak_module_price_html` | 23 whole-value price filters at `PHP_INT_MAX`, led by `woocommerce_get_price_html` (plus the variable/grouped/variation variants, the cart line filters and the cart/checkout totals: `woocommerce_cart_total`, `woocommerce_cart_totals_order_total_html`, `_fee_html`, `_taxes_total_html`, `woocommerce_coupon_discount_amount_html`); four mixed-content filters scrubbed in place (`woocommerce_cart_shipping_method_full_label`, `woocommerce_cart_totals_coupon_html`, `woocommerce_widget_cart_item_quantity`, `woocommerce_coupon_error`); `woocommerce_cart_tax_totals`; `woocommerce_grouped_product_list_column_quantity`; `render_block` for the Mini Cart block's state and for the Add to Cart with Options grouped item selector; `woocommerce_blocks_product_grid_add_to_cart_attributes`; and, on `template_redirect` only when the request is the order-received or order-pay page, or on `woocommerce_track_order`, `woocommerce_order_formatted_line_subtotal`, `woocommerce_get_formatted_order_total` and `woocommerce_get_order_item_totals` | Shop and catalogue cards, single product, related/upsell/cross-sell, widgets and product blocks; simple, variable, grouped and external products, sale and empty prices; the classic cart and checkout totals tables (subtotal, coupon, shipping method label, fees, tax rows, order total) and the `wc-ajax` responses that re-render them (`get_cart_totals`, `get_refreshed_fragments`, `update_order_review`), and the spend thresholds in a coupon's validation notice; the Mini Cart block's `formattedSubtotal` state and aria-label; the grouped product's sold-individually "Buy one of X" label, in the classic template and in the block's checkbox `aria-label`; the `data-price` attribute the product grid blocks (Product New, On Sale, Best Sellers, Top Rated, By Category/Tag/Attribute, Hand-picked -- the default Cart page's empty-cart grid included) print on their add-to-cart buttons; a guest's own order on the order-received and order-pay pages and in the `[woocommerce_order_tracking]` result. `wc_price()` is never filtered globally, so order emails built in the same request keep their amounts |
| Variation payload | `variation_payload` / `pricecloak_module_variation_payload` | `woocommerce_available_variation` at `PHP_INT_MAX` | The inline `data-product_variations` JSON and `?wc-ajax=get_variation`: `display_price`, `display_regular_price` and `price_html` are emptied while the variation id, attributes, SKU, image and stock fields stay, so the variation form still resolves |
| Structured data | `structured_data` / `pricecloak_module_structured_data` | `woocommerce_structured_data_product` and `woocommerce_structured_data_product_offer` at `PHP_INT_MAX` | The `offers` node is removed from product JSON-LD entirely |
| Store API | `store_api` / `pricecloak_module_store_api` | `rest_request_after_callbacks` and `woocommerce_hydration_request_after_callbacks` at `PHP_INT_MAX`, claiming the `products`, `cart`, `checkout`, `order` and `batch` routes under `wc/store/v1` and the unversioned `wc/store` alias; `render_block` for the eight product grid blocks; a guest-only inline stylesheet on `wp_enqueue_scripts` | Every amount in a Store API response for an unauthenticated request: a product's `prices` and `price_range`, a cart's items (`prices`, `totals`), `totals` (with its `tax_lines`), shipping rates (`price`, `taxes`), coupons, fees and cross-sells, the checkout response's cart, an order's items and totals, and batch sub-responses -- emptied, not nulled, so the currency metadata the blocks need survives; the few fields the Cart and Checkout blocks parse as integers (`raw_prices`, a line item's `line_*` totals) become `0` so the blocks keep rendering, and the stylesheet hides the zero they paint. The same filter runs when WooCommerce hydrates those responses into a page: the Cart, Checkout and All Products blocks' preloaded cart, the Mini Cart's `woocommerce.cart` state, and the `woocommerce/products` Interactivity state on block-theme shop and product pages; the product grid blocks' inline product list is rewritten on render |
| Legacy REST | `legacy_rest` / `pricecloak_module_legacy_rest` | The same two filters, claiming `/wc/v3/products`, `/wc/v2/products` and `/wc/v1/products` | `price`, `regular_price`, `sale_price` and `price_html` emptied for unauthenticated requests, including the variations routes. On stock WooCommerce these routes 401 anonymously; the module matters where a site has opened them (headless front end, feed exporter, integration) |
| Purchasing | `purchasing` / `pricecloak_block_purchasing` (default **off**) | `woocommerce_add_to_cart_validation`, `woocommerce_store_api_validate_add_to_cart`, `rest_request_before_callbacks`, `woocommerce_before_checkout_process`, `woocommerce_store_api_checkout_update_customer_from_request`, `template_redirect`, the loop/single add-to-cart link and text filters, and `render_block` | Server-side refusal of add-to-cart on the product form, `wc-ajax=add_to_cart` and the Store API cart routes; refusal of every writing method on the Store API cart, checkout and batch routes, versioned and unversioned; refusal of checkout processing itself -- the classic form POST and `?wc-ajax=checkout` at the process stage, the Store API checkout at the first action it fires -- so a cart filled before the switch cannot become an order; cart and checkout redirect to login, except the order-received and order-pay endpoints, which authorise themselves with the order key and are left to WooCommerce (paying an existing order is not catalogue purchasing); "Log in to buy" buttons. Read methods on cart routes are left alone, so a cart built before logout survives and stays visible |
| Price filters | `price_filters` / `pricecloak_module_price_filters` | `request` and `posts_clauses` (priority 11, after WooCommerce's price clause), `woocommerce_get_catalog_ordering_args`, `woocommerce_catalog_orderby`, `widget_display_callback`, `render_block`, `rest_request_before_callbacks` and `woocommerce_hydration_dispatch_request` for the Store API products routes, `rest_product_query` for `/wp/v2/product` | The channels a guest could infer a price through without being shown one: `?min_price=&max_price=` no longer narrows the catalogue (classic loop and Product Collection/Products blocks alike) nor the Store API products routes (the parameters are removed from a guest's request, on the REST path and the hydration path), `?orderby=price` falls back to the catalogue's default ordering on the shop and on the Store API (which runs the same ordering filter) and leaves the sort dropdown, WordPress core's `/wp/v2/product` route -- which the Product Collection block's `rest_product_query` editor hook opens to `priceRange` and `orderby=price` for any request carrying `isProductCollectionBlock=true`, with no capability check -- drops the range and falls back to the route's default ordering, the legacy Price Filter widget is not displayed, and the `woocommerce/price-filter`, `product-filter-price` and `product-filter-price-slider` blocks render nothing, with the range state they register emptied. Attribute filtering is untouched. This module owns the price-shaped *question* wherever it is asked; the Store API module owns what the *answer* carries |

**The login link.** The replacement text links to the My Account page (wp-login.php on a site without one) with the current URL as `redirect_to`, rebuilt from the request's scheme, host and request URI -- so it is right on a subdirectory install -- and validated with `wp_validate_redirect()`, so a forged `Host` header cannot make it point off-site. Both destinations return the shopper to that URL after logging in: wp-login.php reads `redirect_to` itself, and on the My Account form WooCommerce's blocks package prints it as the hidden `redirect` field `WC_Form_Handler::process_login()` reads (`BlockTypesController::redirect_to_field()` on `woocommerce_login_form_end`, WooCommerce 11.1); a theme that overrides `myaccount/form-login.php` without the `woocommerce_login_form_end` action loses that field and lands the customer on the My Account dashboard instead. The `woocommerce_login_redirect` filter still has the last word.

**Off means no hooks.** `Plugin::boot()` reads the master option once. If it is off, only the settings section is registered — nothing else is hooked, and a unit test asserts it. The same is true per module: a module switched off registers nothing.

**Price sorting and price filtering are disabled for guests while prices are hidden.** A visitor who cannot see a price could otherwise recover it by asking the catalogue price-shaped questions -- "which products cost between X and Y?" is answerable to the cent in about twenty requests, and "sort by price" orders the shelf by the number being hidden. So for guests the `min_price`/`max_price` parameters are ignored, `orderby=price` sorts by the shop's default instead, the price options leave the sort dropdown and the price filter widget and blocks are not rendered. Logged-in shoppers keep all of it. Switch the `price_filters` module off to hand those channels back.

**Carts and orders.** With the master switch on, a guest sees no amount anywhere, including their own cart, the checkout, the order-received and order-pay pages, and the Store API cart, checkout and order routes: the classic cart and checkout templates print the replacement text, the Cart and Checkout blocks and the Mini Cart drawer render their line items and totals from a Store API response whose amounts are already blank, and an order read back with its key carries none. Purchasing stays possible while "Block purchasing for logged-out visitors" is off, so a guest can add to cart and check out without ever seeing a total -- coherent for a quote-first B2B shop, rarely what a retail shop wants. Enable **Block purchasing for logged-out visitors** together with the master switch unless you mean to accept blind guest orders. The order emails a guest receives are unchanged.

**What counts as a guest.** A front-end request with no logged-in user. Every module asks one class, `Context::is_guest()`, and the answer is *no* -- the real price -- for a logged-in user, for WP-Cron, for WP-CLI, while an Action Scheduler action executes (whichever runner drives it, the anonymous `admin-ajax.php` loopback included) and while a WooCommerce email renders, because in all of those the current user is 0 without a visitor being involved. That keeps a customer stock notification built by a scheduled job, and the verification email built during the guest's own sign-up request, showing the price they are about to be told about. `wc-ajax`, REST, Store API and hydration requests are front-end requests a visitor can make, so a guest on them stays a guest. The `pricecloak_is_guest` filter receives the decision and the reason (`logged_in`, `cli`, `cron`, `action_scheduler`, `email` or `''` for a guest) so a site can extend the rule.

Not in 1.0, on the roadmap: cache hints (`Cache-Control: private`, vary), RSS/product feeds, and role- or capability-based hiding.

## Caching caveat

There is no page-cache handling in 1.0. A guest page cached *while* prices are hidden is fine — it holds the replacement text. Pages cached *before* you enable the switch hold real prices and will keep being served to guests until they expire, so purge the page cache once immediately after enabling (and after disabling). Your cache must also never serve a logged-in customer's page to a logged-out visitor; a correctly configured WooCommerce page cache already bypasses logged-in sessions.

## Coexistence with B2B plugins

The plugin has no dependency on, adapter for, or awareness of any B2B or wholesale plugin, and none of them is aware of it. Both only blank what a guest is shown, so the two can run side by side safely. Decide per site which of them owns guest pricing, so there is a single place to change it.

## Development

Requires PHP 8.1+, Composer, and Node 20+ for `@wordpress/env` (wp-env refuses older Node).

```bash
composer install
vendor/bin/phpunit        # unit tests
vendor/bin/phpcs          # WordPress coding standards
php tests/run-tests.php   # same suite through a standalone runner, no PHPUnit needed
bin/build-zip.sh          # release archive: dist/<slug>-<version>.zip from git archive HEAD
```

The release archive holds the plugin and nothing else: `.gitattributes` marks the development files (`tests/`, `docs/`, `.github/`, the Composer, npm, phpcs, PHPUnit and wp-env configuration) `export-ignore`, and `.distignore` carries the same list for `wp dist-archive`. `vendor/` never ships -- the main file loads `src/Autoloader.php` when Composer's autoloader is absent -- and the archive passes the WordPress Plugin Check with no errors.

### The probe

`tests/probe/probe.py` is the definition of done: an anonymous HTTP client, Python 3 standard library only, that fetches every surface above against a running site and reports PASS/FAIL/SKIP per surface with the owning module named. Besides the catalogue surfaces it builds a guest cart through the Store API and judges the cart routes (versioned and unversioned, plus a batch sub-response), the payloads the Cart and Checkout pages embed (`createPreloadingMiddleware`, the product grids' inline lists, the Interactivity API state), the `woocommerce.cart` and `woocommerce/products` state on a block theme, the Store API and `/wp/v2/product` price-range and price-ordering parameters, and the empty-cart render of the cart page (whose product grid must carry no `data-price`; every HTML surface fails on that attribute); with purchasing blocked it requires the plugin's own refusal (HTTP 403 `pricecloak_login_required`, `{"error": true}`) rather than any failure, and with `--session-file` it replays a classic-checkout session captured while purchasing was allowed against `?wc-ajax=checkout`. GitHub Actions runs phpcs and PHPUnit on PHP 8.1 and 8.3, the standalone runner on a checkout without `vendor/` (so the TestCase shim cannot fall behind the assertions the suite uses), then the probe against wp-env (WordPress 7.1, WooCommerce 11.1.0, the tested versions) — five runs: master off (prices visible), master on (hidden, buying allowed), the same with the currency symbol printed after the amount (`woocommerce_currency_pos=right_space`, so the probe's symmetric currency test is exercised), both switches on (hidden, buying blocked), and one module switched off with `--modules-off` to prove the per-module toggles gate one module and nothing else. When buying is allowed the probe also builds a guest cart through `?wc-ajax=add_to_cart` and judges the add-to-cart fragments, the cart page, `?wc-ajax=get_cart_totals` and the Mini Cart block's Interactivity API state on the home page (a block theme's header carries the block; on a classic theme that row is a documented SKIP), and it checks that `?min_price=&max_price=` does not narrow the catalogue and `?orderby=price` does not reorder it. A SKIP is a surface the probe could not judge; in the `--expect visible` run every SKIP other than the documented ones (the `rest-v3-*` rows a stock WooCommerce answers 401 to, and `mini-cart-state` on a theme without the block) is a failure, so a probe that has gone blind -- a WAF, basic auth, a page cache -- cannot be green, and `--strict` makes every SKIP a failure for live sites.

Locally, with Docker and Node 20 available:

```bash
npm ci
npx wp-env start
npm run seed          # seeds one simple (sold individually), one variable and one grouped product by SKU
npm run probe         # expects prices visible (master switch off)
npm run probe:hidden  # expects prices hidden (master switch on)
```

Switch the site's state between runs with `npx wp-env run --quiet cli wp option update pricecloak_enabled yes|no` (and `pricecloak_block_purchasing`, `pricecloak_module_<id>`).

Without wp-env — the workstation this was developed on has Node 18, and the Docker compose plugin is not installed — the same thing runs against a throwaway plain-`docker run` stack: a `wordpress:php8.3-apache` container, a `mariadb:11` container, and `wordpress:cli-php8.3` for WP-CLI, with the repository bind-mounted into `wp-content/plugins/` and WooCommerce installed through WP-CLI. Both `seed.sh` and the probe take the endpoint from the outside, so they do not care which stack they are pointed at:

```bash
WP_CMD="docker run --rm ... wordpress:cli-php8.3 wp" bash tests/probe/seed.sh
python3 tests/probe/probe.py --base-url http://localhost:8888 \
    --seed tests/probe/seed.json --expect hidden
```

Against a live site with a page cache or CDN in front, add `--cache-bust` so every request carries a unique query argument; otherwise the probe judges copies cached before the settings changed. See `docs/SMOKE.md` for a run on a real site.

Validation for 1.0.0 was done that way against WordPress with WooCommerce 11.1.0. No compose file is committed: CI uses wp-env.

## Documentation

* `docs/SPEC.md` — the agreed specification: purpose, non-goals, settings, modules, acceptance.
* `docs/TICKETS.md` — the ticket log, with an implementation note per ticket.
* `CHANGELOG.md` — Keep a Changelog format.

## Licence

GPL-3.0-or-later. See `LICENSE`.
