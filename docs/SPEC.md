# Specification — PriceCloak for WooCommerce

Agreed with Wade Beckett in a scoping session on 15 September 2026. This is the shared understanding; change it here before changing code.

## Purpose

A logged-in-only pricing switch for WooCommerce. When **on**, logged-out visitors cannot obtain a price from any front-end surface WooCommerce renders or serves. When **off**, the plugin registers no hooks and the shop behaves as normal retail.

Origin: on a live shop, a B2B plugin's "Hide prices" feature blanks price HTML but the Classic `wc-ajax=get_variation` response and the inline `available_variations` JSON still carry numeric `display_price` / `display_regular_price`. Rather than patch one vendor gap, this plugin covers every surface, independently of any B2B plugin, so it can be reused on other sites.

## Non-goals

- No B2B plugin dependency, adapter or setting mirroring. Both may run side by side; each only blanks.
- No role- or group-based hiding in v1. "Hidden" means the request is a guest as defined below. Nothing else.
- No theme-specific work. Avada or other theme templates that print prices outside WooCommerce filters are the site's concern.
- No page-cache handling in v1 (documented; see Roadmap).
- No wholesale, quote, or request-a-price features.

## Definitions

- **Guest**: a front-end request with no logged-in WordPress user. A process with no user that is not such a request is *not* a guest: WP-Cron, WP-CLI, an executing Action Scheduler action (whichever runner drives it, the anonymous `admin-ajax.php` loopback included) and a WooCommerce email being rendered all get the real price, whoever the current user is. `wc-ajax`, REST, Store API and hydration requests are front-end requests a visitor can make, so a guest on them stays a guest. One class, `Context::is_guest()`, makes this decision for every module; the `pricecloak_is_guest` filter (`bool $is_guest, string $reason`) lets a site extend it.
- **Hidden state**: master switch on and the request is from a guest.
- **Surface**: one distinct place WooCommerce exposes a price. Each surface is one module.

## Settings

Section **Guest price visibility** under WooCommerce → Settings → Products, via the native WooCommerce settings API. No custom admin pages.

| Option | Default | Notes |
| --- | --- | --- |
| Hide prices from logged-out visitors | Off | Master switch. Off means zero hooks registered. |
| Replacement text | `Sign in to see prices` | Shown where a price would be. |
| Link replacement text to the login page | On | Links to the My Account login page with a redirect back to the current URL. Off renders plain text. |
| Block purchasing for logged-out visitors | Off | Enables the Purchasing module. |
| Per-module toggles | On | One checkbox per module below, only meaningful when the master is on. |

Options are stored with the `pricecloak_` prefix. Uninstall removes them.

The plugin was previously named "Hide Prices from Guests for WooCommerce" and stored the same options under an `hpfg_` prefix. On activation, and on `plugins_loaded` until it has run once (guarded by the `pricecloak_migrated_from_hpfg` option, so it costs one `get_option()` afterwards), every legacy option whose `pricecloak_` counterpart has never been saved is copied across and the legacy row deleted, so a site upgrading in place keeps its settings. Uninstall removes both prefixes.

## Modules

Every module is a class with `register()` and `unregister()`, no state shared between modules, loaded only when the master switch is on and the module is on. Each ships with unit tests and an entry in the HTTP probe.

1. **Price HTML** — `woocommerce_get_price_html` and related filters for simple, variable, grouped and external products; catalogue cards, single product, related/upsell/cross-sell, widgets, product blocks (including the `data-price` attribute on the product grid blocks' buttons); a guest's own order on the order-received, order-pay and order-tracking surfaces. Returns the replacement text.
2. **Variation payload** — `woocommerce_available_variation` at maximum priority: blank `display_price`, `display_regular_price`, `price_html`; keep variation ID, attributes, SKU, image and stock fields so selectors still work. Covers both inline `data-product_variations` JSON and `wc-ajax=get_variation`.
3. **Structured data** — `woocommerce_structured_data_product` and `woocommerce_structured_data_product_offer`: remove `offers` entirely.
4. **Store API** — every Store API surface that carries an amount, for unauthenticated requests: the products routes (`/products*`, `products/collection-data`), the cart routes (`/cart`, `/cart/items*`, every cart write's response, batch sub-responses), the checkout routes (`/checkout`, `/checkout/{id}`) and the order route (`/order/{id}`), under both the `wc/store/v1` namespace and its unversioned `wc/store` alias, and the same responses when WooCommerce hydrates them into a page (the Cart, Checkout and Mini Cart blocks' preloaded cart, the Interactivity API `woocommerce/products` state on block-theme shop and product pages). Amounts are emptied, currency metadata kept, `price_html` replaced, via `rest_request_after_callbacks` and `woocommerce_hydration_request_after_callbacks`. Response blanking only; the price-shaped request parameters on those routes are module 7's.
5. **Legacy REST** — `/wc/v3/products*` and `/wc/v3/products/{id}/variations*` for unauthenticated requests: strip `price`, `regular_price`, `sale_price`, `price_html`. Authenticated requests untouched.
6. **Purchasing** (off by default) — server-side refusal of add-to-cart (form POST, `wc-ajax=add_to_cart`, Store API cart routes) and of checkout processing (the classic form POST and `wc-ajax=checkout` at `woocommerce_before_checkout_process`; the Store API checkout routes, versioned and unversioned, at `rest_request_before_callbacks` and again at the first action the checkout route fires) for guests; cart and checkout redirect to login; native single/loop add-to-cart buttons replaced by a "Log in to buy" link. Carts created before logout are preserved, and reading them stays allowed.
7. **Price filters** — the channels a guest could infer a price through without being shown one: `min_price`/`max_price` and `orderby=price` are inert on the main query, on the Store API products routes (request parameters stripped at `rest_request_before_callbacks` and the hydration equivalent), on WordPress core's `/wp/v2/product` route (the Product Collection block's `priceRange` and `orderby=price` dropped at `rest_product_query`) and wherever `WC_Query::get_catalog_ordering_args()` runs; the Price Filter widget and blocks render nothing. Owns the *question*; module 4 owns the *answer*.
8. **Cache hints** — *roadmap, not v1*: `Cache-Control: private` / vary on affected responses.

Feeds and emails are out of v1, which means they must not be altered: no email is filtered, whether it is the order email a guest receives after checking out, a customer stock notification built inside an Action Scheduler job, or the verification email built during the guest's own front-end request -- the guest definition above excludes every one of those contexts. RSS product feeds are noted in the Roadmap.

### Carts and orders

Decision (17 September 2026): with the master switch on, a guest sees no amount anywhere, including their own cart, the checkout, the order-received and order-pay pages, and the Store API cart, checkout and order routes. The promise is unconditional; a cart's total is a price like any other. Purchasing stays possible while the Purchasing module is off, so a guest can add to cart and check out without ever seeing an amount -- a coherent B2B "quote first" flow, but rarely what a shop wants -- and the documentation recommends enabling "Block purchasing for logged-out visitors" together with the master switch. Order emails stay out of scope.

## Behaviour when off

`Plugin::boot()` reads the master option once; if off, it registers only the settings section. No other hook, script or style is registered. A test asserts this.

## Compatibility

- WordPress 6.4+, WooCommerce 9.0+, PHP 8.1+.
- Declares HPOS and Cart/Checkout Blocks compatibility through `FeaturesUtil`.
- Coexists with a B2B plugin's own hide-prices feature; neither is aware of the other.

## Acceptance

One repeatable probe, `tests/probe/probe.py`, runs as an anonymous HTTP client against a site with at least one simple, one variable and one grouped product. It fetches every surface above and fails on any numeric price or any `offers` node. It is the definition of done for every module and for every release.

Testing tiers:

1. `@wordpress/env` (Docker) on the workstation and in GitHub Actions: PHPUnit unit tests plus the probe, on every push.
2. Manual smoke on a staging site (hosting and WooCommerce presence still to be confirmed; if it has no WooCommerce, skip this tier).
3. A production site under its own scoped approval: backup, canary product, full probe, narrow reversal. Not part of this repo's release process.

## Repository

- Slug `pricecloak-for-woocommerce`, text domain the same, PHP namespace `PriceCloak`, prefix `pricecloak_`. The name does not start with "WooCommerce", which wordpress.org rejects.
- Layout mirrors the sibling plugin: Composer autoload, `src/` modules, `tests/Unit`, `tests/probe`, phpcs with WordPress standard, `readme.txt` for wordpress.org, `CHANGELOG.md`.
- Licence GPL-3.0-or-later. Local and private until the first tagged release, then public on GitHub with full documentation.
- Vendor-neutral: no client or company name in code, docs or defaults.

## Reference material (read, do not copy)

An earlier, site-specific prototype (frozen, outside this repository) implements module 2 with 28 unit cases and a WP-CLI integration test, plus an HTTP price-privacy assertion script. Useful for the exact fields to blank and for probe ideas; its dependency on a B2B plugin's option is not carried over.

## Roadmap after v1

- Cache hints module.
- RSS/product feed module.
- Role and capability based hiding.
- Decide, per site, whether this plugin or the B2B plugin owns guest pricing.
