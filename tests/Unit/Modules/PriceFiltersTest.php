<?php
/**
 * Price filters module: the channels through which a guest could infer a
 * price without ever being shown one -- price-range query parameters,
 * price sorting, the Price Filter widget and blocks -- are closed for
 * guests and left exactly as they were for logged-in shoppers.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit\Modules;

use PriceCloak\Modules;
use PriceCloak\Modules\PriceFilters;
use PriceCloakFakeWcQuery;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WC_Widget_Price_Filter;
use WC_Widget_Product_Categories;

final class PriceFiltersTest extends TestCase {
	private const WHERE = " AND wp_posts.post_type = 'product' AND NOT (123.450000<wc_product_meta_lookup.min_price OR 123.450000>wc_product_meta_lookup.max_price )  AND wp_posts.post_status = 'publish'";

	protected function setUp(): void {
		pricecloak_test_reset();
	}

	private function registered( bool $logged_in = false ): PriceFilters {
		$GLOBALS['pricecloak_test']['logged_in'] = $logged_in;
		$module                                  = new PriceFilters();
		$module->register();
		return $module;
	}

	// -- identity and wiring ----------------------------------------------

	public function test_id_is_price_filters(): void {
		$this->assertSame( 'price_filters', ( new PriceFilters() )->id() );
	}

	public function test_module_is_in_the_registry(): void {
		$ids = array_map( static fn( $m ) => $m->id(), Modules::all() );
		$this->assertContains( 'price_filters', $ids );
	}

	public function test_register_hooks_every_channel_and_unregister_removes_them(): void {
		$module = $this->registered();

		foreach ( [ 'request', 'posts_clauses', 'woocommerce_get_catalog_ordering_args', 'woocommerce_catalog_orderby', 'widget_display_callback', 'render_block', 'rest_request_before_callbacks', 'woocommerce_hydration_dispatch_request', 'rest_product_query' ] as $hook ) {
			$this->assertArrayHasKey( $hook, $GLOBALS['pricecloak_test']['filters'], "Missing hook $hook." );
		}
		$this->assertSame( 11, $GLOBALS['pricecloak_test']['filters']['posts_clauses'][0][1], 'The clause strip must run after WooCommerce adds the clause at 10.' );
		$this->assertSame( [ PHP_INT_MAX, 2 ], array_slice( $GLOBALS['pricecloak_test']['filters']['rest_product_query'][0], 1 ), 'The core REST query filter runs last, after the Product Collection controller at 10, with the request.' );

		$module->unregister();

		$this->assertSame( [], $GLOBALS['pricecloak_test']['filters'] );
	}

	// -- query parameters --------------------------------------------------

	public function test_price_query_vars_are_dropped_from_a_guest_request(): void {
		$this->registered();

		$vars = apply_filters(
			'request',
			[
				'post_type'   => 'product',
				'min_price'   => '100',
				'max_price'   => '200',
				'filter_size' => 'large',
			]
		);

		$this->assertSame(
			[
				'post_type'   => 'product',
				'filter_size' => 'large',
			],
			$vars
		);
	}

	public function test_price_query_vars_survive_for_a_logged_in_shopper(): void {
		$this->registered( true );
		$vars = [
			'min_price' => '100',
			'max_price' => '200',
		];

		$this->assertSame( $vars, apply_filters( 'request', $vars ) );
	}

	public function test_woocommerce_price_clause_is_stripped_for_a_guest(): void {
		$this->registered();

		$clauses = apply_filters(
			'posts_clauses',
			[
				'where' => self::WHERE,
				'join'  => ' INNER JOIN wc_product_meta_lookup ',
			],
			null
		);

		$this->assertStringNotContainsString( 'min_price', $clauses['where'] );
		$this->assertStringNotContainsString( 'max_price', $clauses['where'] );
		$this->assertStringContainsString( "wp_posts.post_type = 'product'", $clauses['where'] );
		$this->assertStringContainsString( "wp_posts.post_status = 'publish'", $clauses['where'] );
		$this->assertSame( ' INNER JOIN wc_product_meta_lookup ', $clauses['join'], 'Other clauses are not touched.' );
	}

	public function test_a_negative_bound_is_stripped_too(): void {
		$this->registered();
		$where = ' AND NOT (-1.000000<wc_product_meta_lookup.min_price OR 0.000000>wc_product_meta_lookup.max_price ) ';

		$this->assertSame( ' ', apply_filters( 'posts_clauses', [ 'where' => $where ], null )['where'] );
	}

	public function test_price_clause_survives_for_a_logged_in_shopper_and_odd_input_passes_through(): void {
		$this->registered( true );
		$this->assertSame( self::WHERE, apply_filters( 'posts_clauses', [ 'where' => self::WHERE ], null )['where'] );

		$GLOBALS['pricecloak_test']['logged_in'] = false;
		$this->assertSame( 'not clauses', apply_filters( 'posts_clauses', 'not clauses', null ) );
		$this->assertSame( [ 'join' => 'x' ], apply_filters( 'posts_clauses', [ 'join' => 'x' ], null ) );
	}

	// -- sorting -----------------------------------------------------------

	public function test_price_ordering_falls_back_to_the_catalogue_default_for_a_guest(): void {
		$query                                  = new PriceCloakFakeWcQuery();
		$GLOBALS['pricecloak_test']['wc_query'] = $query;
		update_option( 'woocommerce_default_catalog_orderby', 'date' );
		add_filter( 'posts_clauses', [ $query, 'order_by_price_asc_post_clauses' ] );
		$this->registered();

		$args = $query->get_catalog_ordering_args( 'price', 'ASC' );

		$this->assertSame( 'date', $args['orderby'] );
		$this->assertSame( [ [ 'price', 'ASC' ], [ 'date', '' ] ], $query->ordering_calls );
		$callbacks = array_map( static fn( $entry ) => $entry[0], $GLOBALS['pricecloak_test']['filters']['posts_clauses'] );
		$this->assertNotContains( [ $query, 'order_by_price_asc_post_clauses' ], $callbacks, 'The price ORDER BY clause must be detached.' );
	}

	public function test_a_price_default_falls_back_to_menu_order_rather_than_recursing(): void {
		$query                                  = new PriceCloakFakeWcQuery();
		$GLOBALS['pricecloak_test']['wc_query'] = $query;
		update_option( 'woocommerce_default_catalog_orderby', 'price-desc' );
		$this->registered();

		$args = $query->get_catalog_ordering_args( 'price', 'DESC' );

		$this->assertSame( 'menu_order title', $args['orderby'] );
		$this->assertSame( 'ASC', $args['order'] );
	}

	public function test_ordering_without_a_wc_query_object_still_resets(): void {
		$this->registered();

		$args = apply_filters(
			'woocommerce_get_catalog_ordering_args',
			[
				'orderby'  => 'price',
				'order'    => 'ASC',
				'meta_key' => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			],
			'price',
			'ASC'
		);

		$this->assertSame(
			[
				'orderby'  => 'menu_order title',
				'order'    => 'ASC',
				'meta_key' => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			],
			$args
		);
	}

	public function test_other_orderings_and_logged_in_price_sorting_pass_through(): void {
		$query                                  = new PriceCloakFakeWcQuery();
		$GLOBALS['pricecloak_test']['wc_query'] = $query;
		$this->registered();
		$this->assertSame( 'popularity', $query->get_catalog_ordering_args( 'popularity', '' )['orderby'] );

		$GLOBALS['pricecloak_test']['logged_in'] = true;
		$this->assertSame( 'price', $query->get_catalog_ordering_args( 'price', 'DESC' )['orderby'] );
	}

	public function test_price_options_leave_the_sort_dropdown_for_a_guest(): void {
		$this->registered();
		$options = [
			'menu_order' => 'Default sorting',
			'popularity' => 'Sort by popularity',
			'price'      => 'Sort by price: low to high',
			'price-desc' => 'Sort by price: high to low',
		];

		$this->assertSame(
			[
				'menu_order' => 'Default sorting',
				'popularity' => 'Sort by popularity',
			],
			apply_filters( 'woocommerce_catalog_orderby', $options )
		);

		$GLOBALS['pricecloak_test']['logged_in'] = true;
		$this->assertSame( $options, apply_filters( 'woocommerce_catalog_orderby', $options ) );
	}

	// -- widget and blocks -------------------------------------------------

	public function test_price_filter_widget_is_not_displayed_to_a_guest(): void {
		$this->registered();
		$instance = [ 'title' => 'Filter by price' ];

		$this->assertFalse( apply_filters( 'widget_display_callback', $instance, new WC_Widget_Price_Filter(), [] ) );
		$this->assertSame( $instance, apply_filters( 'widget_display_callback', $instance, new WC_Widget_Product_Categories(), [] ) );

		$GLOBALS['pricecloak_test']['logged_in'] = true;
		$this->assertSame( $instance, apply_filters( 'widget_display_callback', $instance, new WC_Widget_Price_Filter(), [] ) );
	}

	public function test_price_filter_blocks_render_empty_for_a_guest(): void {
		$this->registered();
		$content = '<div data-wp-context="{&quot;minRange&quot;:101,&quot;maxRange&quot;:202}"></div>';

		foreach ( PriceFilters::BLOCKS as $name ) {
			$this->assertSame( '', apply_filters( 'render_block', $content, [ 'blockName' => $name ] ), "$name rendered for a guest." );
		}
		$this->assertSame(
			'',
			apply_filters(
				'render_block',
				$content,
				[
					'blockName' => 'woocommerce/filter-wrapper',
					'attrs'     => [ 'filterType' => 'price-filter' ],
				]
			)
		);
		$this->assertSame(
			$content,
			apply_filters(
				'render_block',
				$content,
				[
					'blockName' => 'woocommerce/filter-wrapper',
					'attrs'     => [ 'filterType' => 'stock-filter' ],
				]
			)
		);
		$this->assertSame( $content, apply_filters( 'render_block', $content, [ 'blockName' => 'woocommerce/product-filter-attribute' ] ) );
		$this->assertSame( $content, apply_filters( 'render_block', $content, null ) );
	}

	public function test_price_filter_block_state_is_emptied_for_a_guest(): void {
		$this->registered();
		wp_interactivity_state(
			'woocommerce/product-filters',
			[
				'minPrice'          => 101,
				'maxPrice'          => 202,
				'formattedMinPrice' => '$101',
				'formattedMaxPrice' => '$202',
				'rangeStyle'        => '--low: 0%; --high: 100%',
				'activeFilters'     => [],
			]
		);

		apply_filters( 'render_block', '<div></div>', [ 'blockName' => 'woocommerce/product-filter-price' ] );

		$state = $GLOBALS['pricecloak_test']['interactivity_state']['woocommerce/product-filters'];
		$this->assertSame( 0, $state['minPrice'] );
		$this->assertSame( 0, $state['maxPrice'] );
		$this->assertSame( '', $state['formattedMinPrice'] );
		$this->assertSame( '', $state['formattedMaxPrice'] );
		$this->assertSame( '', $state['rangeStyle'] );
		$this->assertSame( [], $state['activeFilters'], 'Unrelated state keys survive.' );
	}

	public function test_price_filter_blocks_render_for_a_logged_in_shopper(): void {
		$this->registered( true );
		$content = '<div>slider</div>';

		foreach ( PriceFilters::BLOCKS as $name ) {
			$this->assertSame( $content, apply_filters( 'render_block', $content, [ 'blockName' => $name ] ) );
		}
		$this->assertArrayNotHasKey( 'interactivity_state', $GLOBALS['pricecloak_test'] );
	}
	// -- Store API request parameters --------------------------------------

	private function products_request( string $route = '/wc/store/v1/products' ): WP_REST_Request {
		return new WP_REST_Request(
			$route,
			'GET',
			[
				'min_price' => '12345',
				'max_price' => '12345',
				'include'   => [ 10 ],
				'orderby'   => 'price',
				'per_page'  => 10,
			]
		);
	}

	public function test_store_api_price_range_params_are_dropped_from_a_guest_request(): void {
		$this->registered();

		foreach (
			[
				'/wc/store/v1/products',
				'/wc/store/products',
				'/wc/store/v1/products/collection-data',
				'/wc/store/v1/products?include[]=10&min_price=12345',
			] as $route
		) {
			$request  = $this->products_request( $route );
			$sentinel = new \stdClass();
			$result   = apply_filters( 'rest_request_before_callbacks', $sentinel, [], $request );

			$this->assertSame( $sentinel, $result, 'The response so far passes through untouched.' );
			$this->assertFalse( $request->has_param( 'min_price' ), "$route: min_price must be gone." );
			$this->assertFalse( $request->has_param( 'max_price' ), "$route: max_price must be gone." );
			$this->assertSame( [ 10 ], $request->get_param( 'include' ), 'Other params survive.' );
			$this->assertSame( 10, $request->get_param( 'per_page' ) );
			// Price ordering is reset by the woocommerce_get_catalog_ordering_args
			// filter, which the Store API runs through as well; the parameter itself is left.
			$this->assertSame( 'price', $request->get_param( 'orderby' ) );
		}
	}

	public function test_store_api_price_range_params_survive_for_members_and_on_other_routes(): void {
		$this->registered( true );
		$request = $this->products_request();
		apply_filters( 'rest_request_before_callbacks', null, [], $request );
		$this->assertSame( '12345', $request->get_param( 'min_price' ) );

		$this->registered();
		foreach ( [ '/wc/store/v1/cart', '/wc/v3/products', '/wp/v2/posts', '/wc/store/v1/productsmith' ] as $route ) {
			$request = $this->products_request( $route );
			apply_filters( 'rest_request_before_callbacks', null, [], $request );
			$this->assertSame( '12345', $request->get_param( 'min_price' ), "$route is not a products route." );
		}

		$this->assertNull( ( new PriceFilters() )->drop_store_api_price_params( null, null, 'not a request' ) );
	}

	public function test_hydrated_products_requests_lose_the_price_range_too(): void {
		$this->registered();

		$request = $this->products_request( '/wc/store/v1/products?include[]=10&min_price=12345&max_price=12345' );
		$result  = apply_filters( 'woocommerce_hydration_dispatch_request', null, $request, '/wc/store/v1/products?include[]=10', [] );

		$this->assertNull( $result, 'Hydration must still dispatch to the controller.' );
		$this->assertFalse( $request->has_param( 'min_price' ) );
		$this->assertFalse( $request->has_param( 'max_price' ) );
		$this->assertSame( [ 10 ], $request->get_param( 'include' ) );
	}

	// -- WordPress core REST: /wp/v2/product ------------------------------

	/**
	 * The args the Product Collection controller hands back from
	 * `rest_product_query` for a request carrying `isProductCollectionBlock`.
	 */
	private function collection_query_args(): array {
		return [
			'post_type'           => 'product',
			'posts_per_page'      => 10,
			'author'              => '',
			'isProductCollection' => true,
			'priceRange'          => [
				'min' => '123',
				'max' => '124',
			],
			'orderby'             => 'price',
			'order'               => 'asc',
			'tax_query'           => [], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		];
	}

	private function collection_request(): WP_REST_Request {
		return new WP_REST_Request(
			'/wp/v2/product',
			'GET',
			[
				'isProductCollectionBlock' => 'true',
				'orderby'                  => 'price',
				'order'                    => 'asc',
				'priceRange'               => [
					'min' => '123',
					'max' => '124',
				],
			]
		);
	}

	public function test_core_rest_product_query_loses_its_price_range_and_price_ordering_for_a_guest(): void {
		$this->registered();

		$args = apply_filters( 'rest_product_query', $this->collection_query_args(), $this->collection_request() );

		$this->assertArrayNotHasKey( 'priceRange', $args, 'The range becomes the meta-lookup min/max clauses; it must be gone.' );
		$this->assertSame( 'date', $args['orderby'], 'Price ordering falls back to the route default.' );
		$this->assertSame( 'desc', $args['order'], 'The direction is meaningless once the key is not price, and must not reveal it.' );
		$this->assertSame( 'product', $args['post_type'] );
		$this->assertSame( 10, $args['posts_per_page'] );
		$this->assertTrue( $args['isProductCollection'], 'The collection marker itself is harmless and stays.' );
	}

	public function test_core_rest_product_query_keeps_a_non_price_ordering_and_drops_only_the_range(): void {
		$this->registered();
		$args            = $this->collection_query_args();
		$args['orderby'] = 'popularity';

		$out = apply_filters( 'rest_product_query', $args, $this->collection_request() );

		$this->assertSame( 'popularity', $out['orderby'] );
		$this->assertSame( 'asc', $out['order'] );
		$this->assertArrayNotHasKey( 'priceRange', $out );
	}

	public function test_core_rest_product_query_is_untouched_for_a_member_and_odd_input_passes_through(): void {
		$this->registered( true );
		$args = $this->collection_query_args();
		$this->assertSame( $args, apply_filters( 'rest_product_query', $args, $this->collection_request() ) );

		$GLOBALS['pricecloak_test']['logged_in'] = false;
		$this->assertSame( 'not args', apply_filters( 'rest_product_query', 'not args', $this->collection_request() ) );
		$this->assertSame( [ 'post_type' => 'product' ], apply_filters( 'rest_product_query', [ 'post_type' => 'product' ], null ), 'A request-less call still works.' );
	}
}
