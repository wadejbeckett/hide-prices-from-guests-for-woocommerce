<?php
/**
 * Module 5: legacy REST. The `/wc/v3/products*` routes carry prices as plain
 * fields; for guests those fields are emptied.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Blanks price fields in legacy REST product responses for logged-out visitors.
 *
 * On a stock WooCommerce this module never fires. `WC_REST_Controller` gates
 * every `/wc/v3/` route on the `woocommerce_rest_check_permissions` filter,
 * whose default requires a `read_product`-capable user and which therefore
 * answers an anonymous GET with HTTP 401 and no body at all. The module
 * matters only on a site that has opened these routes to guests -- a
 * headless front end, a feed exporter or an integration that returns true
 * from that permission filter -- where the response is a full product payload
 * with `price`, `regular_price`, `sale_price` and `price_html` in it, and
 * nothing else in this plugin covers it.
 *
 * Because of that default, `tests/probe/probe.py` records SKIP rather than
 * PASS on its three `rest-v3-*` surfaces on a stock install: a 401 leaks no
 * price, so there is nothing for the probe to judge. Verifying this module
 * end to end means temporarily opening the routes and re-running the probe,
 * which then reports PASS on those rows.
 *
 * `/wc/v2/` and `/wc/v1/` are claimed as well. WooCommerce still registers
 * both for backwards compatibility and both expose the same price fields, so
 * a site that opens its REST API opens all three unless it says otherwise.
 */
final class LegacyRest extends RestResponseFilter {
	public const ID = 'legacy_rest';

	/**
	 * Price fields on a legacy product or variation response.
	 *
	 * Emptied rather than removed, as elsewhere in this plugin: REST consumers
	 * are far likelier to read a field than to check it exists, and the
	 * controllers' own schemas type all four as strings, so `''` stays valid.
	 *
	 * @var string[]
	 */
	public const PRICE_KEYS = [
		'price',
		'regular_price',
		'sale_price',
		'price_html',
	];

	/**
	 * Stable identifier.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Products and, under the same prefix, their variations, in all three
	 * legacy versions.
	 */
	protected function route_pattern(): string {
		return '#^/wc/v[123]/products(?:/|$)#i';
	}

	/**
	 * Every price field, at every depth, becomes an empty string.
	 *
	 * @return array<string, callable>
	 */
	protected function rules(): array {
		// The current value is irrelevant: every one of these fields is emptied.
		$blank = static fn( mixed $value ): string => ''; // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		return array_fill_keys( self::PRICE_KEYS, $blank );
	}
}
