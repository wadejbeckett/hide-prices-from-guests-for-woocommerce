<?php
/**
 * Minimal WooCommerce stubs for the price inference tests. Under WordPress
 * with WooCommerce loaded this file defines nothing.
 *
 * WC() returns an object whose `query` property is whatever the test put in
 * $GLOBALS['pricecloak_test']['wc_query'], so a test can hand the module a fake
 * WC_Query and watch which of its methods are called and which of its
 * posts_clauses callbacks are detached.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( function_exists( 'WC' ) ) {
	return;
}

// The WC() function and the widget classes belong together: they are the two
// ways the price filters module reaches WooCommerce.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
function WC(): object { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return (object) [ 'query' => $GLOBALS['pricecloak_test']['wc_query'] ?? null ];
}

/**
 * wc_get_product(): the products a test registered in
 * $GLOBALS['pricecloak_test']['products'], keyed by id, or false.
 */
function wc_get_product( mixed $id ): mixed {
	return $GLOBALS['pricecloak_test']['products'][ (int) $id ] ?? false;
}

/**
 * A product with a name, which is all the grouped-label scrub reads.
 */
class PriceCloakFakeProduct {
	public function __construct( private string $name ) {}

	public function get_name(): string {
		return $this->name;
	}
}

/**
 * The two methods of WP_Scripts the Store API module uses to rewrite the
 * product grids' inline script.
 */
class PriceCloakFakeScripts {
	/** @var array<string, array<string, mixed>> */
	public array $data = [];

	public function get_data( string $handle, string $key ): mixed {
		return $this->data[ $handle ][ $key ] ?? false;
	}

	public function add_data( string $handle, string $key, mixed $value ): bool {
		$this->data[ $handle ][ $key ] = $value;
		return true;
	}
}

class WC_Widget_Price_Filter {}

class WC_Widget_Product_Categories {}

/**
 * The two methods of WC_Query the module touches, with a log of what it asked for.
 */
class PriceCloakFakeWcQuery {
	/** @var array<int, array{0: string, 1: string}> */
	public array $ordering_calls = [];

	public function get_catalog_ordering_args( string $orderby = '', string $order = '' ): array {
		$this->ordering_calls[] = [ $orderby, $order ];
		$args                   = [
			'orderby'  => 'price' === $orderby ? 'price' : $orderby,
			'order'    => 'DESC' === strtoupper( $order ) ? 'DESC' : 'ASC',
			'meta_key' => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		];
		if ( 'menu_order' === $orderby ) {
			$args['orderby'] = 'menu_order title';
		}
		return apply_filters( 'woocommerce_get_catalog_ordering_args', $args, $orderby, $order );
	}

	public function order_by_price_asc_post_clauses( array $args ): array {
		return $args;
	}

	public function order_by_price_desc_post_clauses( array $args ): array {
		return $args;
	}
}
