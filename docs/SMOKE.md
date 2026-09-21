# Smoke test on a live site — 1.0.0

Date: 2026-09-16. Ticket T13. The plugin (the 1.0.0 candidate) was installed on a real, internet-facing WordPress site and the anonymous probe from `tests/probe/probe.py` was run against it from a separate machine.

## Site

| Item | Value |
| --- | --- |
| WordPress | 7.1, block theme Twenty Twenty-Five 1.5 |
| PHP | 8.4 (dedicated FPM pool) |
| Web server | nginx serving PHP directly, with nginx page caching in front |
| WooCommerce | 11.1.0, fresh install from wordpress.org, currency USD, HPOS default |
| Other plugins | an unrelated MCP adapter (active), backup and SEO plugins (inactive), a caching plugin's `advanced-cache.php` drop-in |
| Fixtures | one simple, one variable (two variations) and one grouped product created by `tests/probe/seed.sh` |

The site is not a shop. WooCommerce, the plugin and the fixtures were added for the test and removed afterwards.

## Results

All three probe runs were green:

| Run | Settings | Result |
| --- | --- | --- |
| 1 | master off | 18 passed, 0 failed, 3 skipped |
| 2 | master on, purchasing allowed | 18 passed, 0 failed, 3 skipped |
| 3 | master on, purchasing blocked | 18 passed, 0 failed, 3 skipped |

The three skips are the legacy `/wc/v3/` REST surfaces, which WooCommerce refuses to anonymous clients with HTTP 401 on a default install.

A guest fetch of the variable product page showed the login link (`Sign in to see prices`, pointing at My Account with `redirect_to` back to the product) in place of every price, and no `woocommerce-Price-amount` markup outside the theme's inline stylesheet. With purchasing blocked, the add-to-cart form was gone, the shop page showed "Log in to buy", a guest `wc-ajax=add_to_cart` was refused, the Store API cart returned 403, and the cart and checkout pages redirected to My Account.

## What the live site taught the probe

Two probe changes came out of this run. Neither was a plugin defect.

1. **Page caching.** The first hidden run failed on eleven surfaces because nginx answered from copies cached while the master switch was still off. The plugin registers no cache-purging code (see the caching caveat in the README), so the probe gained `--cache-bust`, which appends a unique query argument to every request. Use it on any site with a page cache or CDN.
2. **Price classes inside CSS.** Block themes print the product-price block's stylesheet on every page, and it names `.woocommerce-Price-amount` as a selector. The probe now strips `<style>` blocks before looking for price markup.

## Cleanup

Fixtures, the plugin and WooCommerce were removed and the site was compared with a database export and plugin archive taken before the test: identical plugin list, theme list, permalink structure, page list and table list; zero products; no `pricecloak_` or WooCommerce options left.

Note for anyone repeating this: WP-CLI's `plugin delete` removes files only. Use `wp plugin uninstall --deactivate` when you want a plugin's `uninstall.php` to run; otherwise its options and tables stay behind and have to be removed by hand, as they were here.
