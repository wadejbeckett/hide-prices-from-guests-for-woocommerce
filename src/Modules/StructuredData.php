<?php
/**
 * Module 3: structured data. Product JSON-LD carries the price in `offers`;
 * for guests the node is removed entirely.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Modules;

use PriceCloak\Context;
use PriceCloak\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the `offers` node from product JSON-LD for logged-out visitors.
 *
 * Two hooks, and both are needed for different reasons. WC_Structured_Data
 * ::generate_product_data() assigns `$markup['offers'] = array( apply_filters(
 * 'woocommerce_structured_data_product_offer', $markup_offer, $product ) )`, so
 * emptying the offer alone still leaves `offers` present as an array holding an
 * empty array -- which is emitted, since the "check we have required data"
 * guard below it only tests `empty( $markup['offers'] )` and an array holding
 * an empty array is not empty. The `woocommerce_structured_data_product`
 * filter runs last, on the whole markup, and unsetting the key there is what
 * guarantees no `offers` key in the final JSON-LD. The offer filter is belt
 * and braces for anything that reads the offer before that point.
 */
final class StructuredData implements Module {
	public const ID = 'structured_data';

	/**
	 * Stable identifier.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Hook both filters last.
	 */
	public function register(): void {
		add_filter( 'woocommerce_structured_data_product', [ $this, 'filter_product' ], PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_structured_data_product_offer', [ $this, 'filter_offer' ], PHP_INT_MAX, 2 );
	}

	/**
	 * Remove exactly what register() added.
	 */
	public function unregister(): void {
		remove_filter( 'woocommerce_structured_data_product', [ $this, 'filter_product' ], PHP_INT_MAX );
		remove_filter( 'woocommerce_structured_data_product_offer', [ $this, 'filter_offer' ], PHP_INT_MAX );
	}

	/**
	 * Filter callback: drop `offers` from the product markup for guests.
	 *
	 * @param mixed $markup  Product structured data.
	 * @param mixed $product The product. Unused.
	 * @return mixed
	 */
	public function filter_product( mixed $markup, mixed $product = null ): mixed {
		if ( ! Context::is_guest() || ! is_array( $markup ) ) {
			return $markup;
		}

		unset( $markup['offers'] );

		return $markup;
	}

	/**
	 * Filter callback: an empty offer for guests.
	 *
	 * @param mixed $offer   One offer's structured data.
	 * @param mixed $product The product. Unused.
	 * @return mixed
	 */
	public function filter_offer( mixed $offer, mixed $product = null ): mixed {
		return Context::is_guest() ? [] : $offer;
	}
}
