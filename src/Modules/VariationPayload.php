<?php
/**
 * Module 2: variation payload. The array WooCommerce builds for one variation
 * carries the price as machine-readable numbers as well as rendered HTML; for
 * guests the numbers are blanked and the HTML replaced.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Modules;

use PriceCloak\Context;
use PriceCloak\Module;
use PriceCloak\Replacement;

defined( 'ABSPATH' ) || exit;

/**
 * Blanks prices in the per-variation payload for logged-out visitors.
 *
 * `woocommerce_available_variation` is the single choke point for both places
 * a variation payload reaches the browser: the inline `data-product_variations`
 * JSON printed by the variable add-to-cart template, and the `?wc-ajax=get_variation`
 * response used once a product has more variations than the inline threshold.
 */
final class VariationPayload implements Module {
	public const ID = 'variation_payload';

	/**
	 * Numeric price keys blanked for guests.
	 *
	 * They are set to an empty string rather than removed. WooCommerce's own
	 * front-end script, assets/js/frontend/add-to-cart-variation.js, never
	 * reads either key -- it drives the form from variation_id, attributes,
	 * is_in_stock, is_purchasable, availability_html, sku, image, min/max_qty,
	 * weight/dimensions and price_html -- so either choice leaves core working.
	 * Keeping the keys present, and empty, preserves the payload's shape for
	 * third-party code listening on `found_variation`, which is more likely to
	 * test a falsy value than to guard against a missing key.
	 *
	 * @var string[]
	 */
	public const PRICE_KEYS = [
		'display_price',
		'display_regular_price',
	];

	/**
	 * Stable identifier.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Hook last, so any currency switcher or B2B plugin has already priced the
	 * variation before it is blanked.
	 */
	public function register(): void {
		add_filter( 'woocommerce_available_variation', [ $this, 'filter_variation' ], PHP_INT_MAX, 3 );
	}

	/**
	 * Remove exactly what register() added.
	 */
	public function unregister(): void {
		remove_filter( 'woocommerce_available_variation', [ $this, 'filter_variation' ], PHP_INT_MAX );
	}

	/**
	 * Filter callback: guests get blanked prices, everyone else the original.
	 *
	 * Every other key -- variation_id, attributes, sku, image, is_in_stock,
	 * is_purchasable, availability_html and the rest -- is left untouched so
	 * the variation selector still resolves and still reports stock.
	 *
	 * @param mixed $data      The variation payload.
	 * @param mixed $product   Parent variable product. Unused.
	 * @param mixed $variation The variation product. Unused.
	 * @return mixed
	 */
	public function filter_variation( mixed $data, mixed $product = null, mixed $variation = null ): mixed {
		if ( ! Context::is_guest() || ! is_array( $data ) ) {
			return $data;
		}

		foreach ( self::PRICE_KEYS as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$data[ $key ] = '';
			}
		}

		if ( array_key_exists( 'price_html', $data ) ) {
			$data['price_html'] = Replacement::markup();
		}

		return $data;
	}
}
