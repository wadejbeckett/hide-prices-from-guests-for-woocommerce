<?php
/**
 * Store API module: unauthenticated product, cart, checkout and order
 * responses carry no amounts -- on the versioned and the unversioned
 * namespace, and when WooCommerce hydrates them into a page -- while
 * authenticated ones are untouched and unclaimed routes pass through.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit\Modules;

use PriceCloak\Modules;
use PriceCloak\Modules\StoreApi;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

final class StoreApiTest extends TestCase {
	private const HOOK           = 'rest_request_after_callbacks';
	private const HYDRATION_HOOK = 'woocommerce_hydration_request_after_callbacks';

	protected function setUp(): void {
		pricecloak_test_reset();
	}

	/**
	 * One product item shaped like ProductSchema::get_item_response() builds it:
	 * `prices` is a stdClass, price_range an array or null.
	 *
	 * @return array<string, mixed>
	 */
	private function product(): array {
		return [
			'id'          => 11,
			'name'        => 'Probe Variable',
			'slug'        => 'probe-variable',
			'sku'         => 'PROBE-VARIABLE',
			'prices'      => (object) [
				'price'                       => '1500',
				'regular_price'               => '2000',
				'sale_price'                  => '1500',
				'price_range'                 => [
					'min_amount' => '1500',
					'max_amount' => '2500',
				],
				'currency_code'               => 'USD',
				'currency_symbol'             => '$',
				'currency_minor_unit'         => 2,
				'currency_decimal_separator'  => '.',
				'currency_thousand_separator' => ',',
				'currency_prefix'             => '$',
				'currency_suffix'             => '',
			],
			'price_html'  => '<span class="woocommerce-Price-amount">$15.00</span>',
			'is_in_stock' => true,
		];
	}

	/**
	 * Run the filter as WordPress would and return the response data.
	 *
	 * @param mixed  $data  Response data.
	 * @param string $route REST route.
	 * @return mixed
	 */
	private function dispatch( mixed $data, string $route ): mixed {
		$response = new WP_REST_Response( $data );
		$result   = apply_filters( self::HOOK, $response, null, new WP_REST_Request( $route ) );

		return $result instanceof WP_REST_Response ? $result->get_data() : $result;
	}

	public function test_id_is_store_api(): void {
		$this->assertSame( 'store_api', ( new StoreApi() )->id() );
	}

	public function test_module_is_in_the_registry(): void {
		$ids = array_map( static fn( $m ) => $m->id(), Modules::all() );
		$this->assertContains( 'store_api', $ids );
	}

	public function test_register_hooks_the_rest_and_hydration_filters_at_max_priority(): void {
		( new StoreApi() )->register();

		foreach ( [ self::HOOK, self::HYDRATION_HOOK ] as $hook ) {
			$this->assertArrayHasKey( $hook, $GLOBALS['pricecloak_test']['filters'], "$hook must be hooked." );
			$this->assertSame( PHP_INT_MAX, $GLOBALS['pricecloak_test']['filters'][ $hook ][0][1] );
			$this->assertSame( 3, $GLOBALS['pricecloak_test']['filters'][ $hook ][0][2] );
		}
	}

	public function test_register_hooks_render_block_for_the_product_grids_and_the_guest_stylesheet(): void {
		( new StoreApi() )->register();

		$this->assertArrayHasKey( 'render_block', $GLOBALS['pricecloak_test']['filters'] );
		$this->assertSame( 2, $GLOBALS['pricecloak_test']['filters']['render_block'][0][2] );
		$this->assertArrayHasKey( 'wp_enqueue_scripts', $GLOBALS['pricecloak_test']['filters'] );
	}

	public function test_guest_stylesheet_hides_the_zeroed_line_item_prices(): void {
		( new StoreApi() )->enqueue_guest_styles();

		$this->assertSame( [ StoreApi::STYLE_HANDLE ], $GLOBALS['pricecloak_test']['enqueued_styles'] );
		$css = implode( '', $GLOBALS['pricecloak_test']['styles'][ StoreApi::STYLE_HANDLE ]['inline'] );
		foreach ( [ '.wp-block-woocommerce-cart .wc-block-components-product-price', '.wp-block-woocommerce-checkout .wc-block-components-product-price', '.wc-block-mini-cart__drawer .wc-block-components-product-price', '.wc-block-components-sale-badge' ] as $selector ) {
			$this->assertStringContainsString( $selector, $css );
		}
		$this->assertStringContainsString( 'display: none !important', $css );
	}

	public function test_guest_stylesheet_is_not_enqueued_for_members(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new StoreApi() )->enqueue_guest_styles();

		$this->assertArrayNotHasKey( 'enqueued_styles', $GLOBALS['pricecloak_test'] );
	}

	public function test_unregister_removes_exactly_what_register_added(): void {
		$module = new StoreApi();
		$module->register();
		$module->unregister();

		$this->assertSame( [], $GLOBALS['pricecloak_test']['filters'] );
	}

	public function test_guest_product_collection_has_blank_amounts_and_a_kept_prices_object(): void {
		( new StoreApi() )->register();

		$data = $this->dispatch( [ $this->product() ], '/wc/store/v1/products' );

		$prices = $data[0]['prices'];
		$this->assertInstanceOf( \stdClass::class, $prices, 'prices must stay an object for the blocks JS.' );
		$this->assertSame( '', $prices->price );
		$this->assertSame( '', $prices->regular_price );
		$this->assertSame( '', $prices->sale_price );
		$this->assertNull( $prices->price_range );
	}

	public function test_currency_metadata_survives_so_the_blocks_can_still_format(): void {
		( new StoreApi() )->register();

		$prices = $this->dispatch( [ $this->product() ], '/wc/store/v1/products' )[0]['prices'];

		$this->assertSame( 'USD', $prices->currency_code );
		$this->assertSame( 2, $prices->currency_minor_unit );
		$this->assertSame( '$', $prices->currency_symbol );
	}

	public function test_price_html_is_replaced_for_guests(): void {
		( new StoreApi() )->register();

		$data = $this->dispatch( [ $this->product() ], '/wc/store/v1/products' );

		$this->assertStringContainsString( 'Sign in to see prices', $data[0]['price_html'] );
		$this->assertStringNotContainsString( 'woocommerce-Price-amount', $data[0]['price_html'] );
	}

	public function test_non_price_fields_are_preserved(): void {
		( new StoreApi() )->register();

		$data = $this->dispatch( [ $this->product() ], '/wc/store/v1/products/11' );

		$this->assertSame( 11, $data[0]['id'] );
		$this->assertSame( 'probe-variable', $data[0]['slug'] );
		$this->assertTrue( $data[0]['is_in_stock'] );
	}

	public function test_single_product_route_is_covered(): void {
		( new StoreApi() )->register();

		$data = $this->dispatch( $this->product(), '/wc/store/v1/products/11' );

		$this->assertSame( '', $data['prices']->price );
	}

	public function test_collection_data_price_range_is_nulled(): void {
		( new StoreApi() )->register();

		$data = $this->dispatch(
			[
				'price_range'         => [
					'min_price'     => '1200',
					'max_price'     => '2500',
					'currency_code' => 'USD',
				],
				'attribute_counts'    => null,
				'rating_counts'       => null,
				'stock_status_counts' => [
					[
						'status' => 'instock',
						'count'  => 3,
					],
				],
			],
			'/wc/store/v1/products/collection-data'
		);

		$this->assertNull( $data['price_range'] );
		$this->assertSame(
			[
				[
					'status' => 'instock',
					'count'  => 3,
				],
			],
			$data['stock_status_counts']
		);
	}

	public function test_logged_in_responses_are_untouched(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new StoreApi() )->register();

		$data = $this->dispatch( [ $this->product() ], '/wc/store/v1/products' );

		$this->assertEquals( $this->product(), $data[0] );
	}

	public function test_other_routes_pass_through_untouched(): void {
		( new StoreApi() )->register();

		foreach ( [ '/wc/v3/products', '/wp/v2/posts', '/wc/store/v1/productsmith', '/wc/store/v1/cartography', '/wc/storefront/products', '/wc/store/v1/shopper-lists' ] as $route ) {
			$data = $this->dispatch( [ $this->product() ], $route );
			$this->assertEquals( $this->product(), $data[0], "Route $route should not be filtered." );
		}
	}

	public function test_a_wp_error_response_passes_through(): void {
		( new StoreApi() )->register();

		$sentinel = new \stdClass();
		$result   = apply_filters( self::HOOK, $sentinel, null, new WP_REST_Request( '/wc/store/v1/products' ) );

		$this->assertSame( $sentinel, $result );
	}

	public function test_non_array_response_data_passes_through(): void {
		( new StoreApi() )->register();

		$this->assertSame( 'plain', $this->dispatch( 'plain', '/wc/store/v1/products' ) );
	}

	public function test_a_missing_request_object_passes_through(): void {
		( new StoreApi() )->register();

		$response = new WP_REST_Response( [ $this->product() ] );
		$result   = apply_filters( self::HOOK, $response, null, null );

		$this->assertEquals( $this->product(), $result->get_data()[0] );
	}

	public function test_a_null_prices_object_is_left_alone(): void {
		( new StoreApi() )->register();

		$data = $this->dispatch(
			[
				[
					'id'     => 3,
					'prices' => null,
				],
			],
			'/wc/store/v1/products'
		);

		$this->assertNull( $data[0]['prices'] );
	}
	// -- namespaces and hydration ------------------------------------------

	public function test_the_unversioned_store_namespace_is_claimed_too(): void {
		( new StoreApi() )->register();

		foreach ( [ '/wc/store/products', '/wc/store/products/11', '/wc/store/products/probe-variable' ] as $route ) {
			$data = $this->dispatch( $this->product(), $route );
			$this->assertSame( '', $data['prices']->price, "$route must be blanked." );
			$this->assertStringContainsString( 'Sign in to see prices', $data['price_html'] );
		}

		$data = $this->dispatch( [ 'price_range' => [ 'min_price' => '1200' ] ], '/wc/store/products/collection-data' );
		$this->assertNull( $data['price_range'] );
	}

	public function test_a_route_with_a_query_string_still_matches(): void {
		// Hydration builds `new WP_REST_Request( 'GET', $path )` with the query
		// string still in the path, so get_route() carries it.
		( new StoreApi() )->register();

		foreach ( [ '/wc/store/v1/products?parent[]=11&type=variation', '/wc/store/v1/products?include[]=10&include[]=14' ] as $route ) {
			$data = $this->dispatch( [ $this->product() ], $route );
			$this->assertSame( '', $data[0]['prices']->price, "$route must be blanked." );
		}
	}

	public function test_hydrated_responses_are_blanked_through_the_hydration_filter(): void {
		( new StoreApi() )->register();

		$response = new WP_REST_Response( [ $this->product() ] );
		$result   = apply_filters( self::HYDRATION_HOOK, $response, [ 'callback' => 'x' ], new WP_REST_Request( '/wc/store/v1/products?parent[]=11&type=variation' ) );

		$this->assertSame( '', $result->get_data()[0]['prices']->price );
		$this->assertNull( $result->get_data()[0]['prices']->price_range );
	}

	public function test_matches_route_is_anchored_and_case_insensitive(): void {
		$module = new StoreApi();

		$this->assertTrue( $module->matches_route( '/wc/store/v1/products' ) );
		$this->assertTrue( $module->matches_route( 'wc/store/v1/products/7' ) );
		$this->assertTrue( $module->matches_route( '/wc/store/v2/cart' ) );
		$this->assertTrue( $module->matches_route( '/WC/Store/V1/Cart/items' ) );
		$this->assertTrue( $module->matches_route( '/wc/store/v1/order/20?key=abc' ) );
		$this->assertTrue( $module->matches_route( '/wc/store/v1/checkout/20' ) );
		$this->assertTrue( $module->matches_route( '/wc/store/v1/batch' ) );
		$this->assertFalse( $module->matches_route( '/wc/store/v1/productsmith' ) );
		$this->assertFalse( $module->matches_route( '/wc/store/v1/cartography' ) );
		$this->assertFalse( $module->matches_route( '/x/wc/store/v1/products' ) );
		$this->assertFalse( $module->matches_route( '/wc/store/v1' ) );
		$this->assertFalse( $module->matches_route( '/wc/store/v1/shopper-lists' ) );
	}

	// -- cart, checkout and order ------------------------------------------

	/**
	 * A cart shaped like CartSchema::get_item_response() builds it, with one
	 * line item, a shipping package, a coupon, a fee, a cross-sell and
	 * itemised tax lines. Every amount is a minor-unit string.
	 *
	 * @return array<string, mixed>
	 */
	private function cart(): array {
		$currency = [
			'currency_code'               => 'USD',
			'currency_symbol'             => '$',
			'currency_minor_unit'         => 2,
			'currency_decimal_separator'  => '.',
			'currency_thousand_separator' => ',',
			'currency_prefix'             => '$',
			'currency_suffix'             => '',
		];
		$prices   = (object) array_merge(
			[
				'price'         => '12345',
				'regular_price' => '12345',
				'sale_price'    => '12345',
				'price_range'   => null,
			],
			$currency,
			[
				'raw_prices' => [
					'precision'     => 6,
					'price'         => '123450000',
					'regular_price' => '123450000',
					'sale_price'    => '123450000',
				],
			]
		);

		return [
			'items'                   => [
				[
					'key'             => 'c4ca4238a0b923820dcc509a6f75849b',
					'id'              => 10,
					'type'            => 'simple',
					'quantity'        => 2,
					'quantity_limits' => (object) [
						'minimum'     => 1,
						'maximum'     => 1,
						'multiple_of' => 1,
						'editable'    => false,
					],
					'name'            => 'Probe Simple Product',
					'prices'          => $prices,
					'totals'          => (object) array_merge(
						[
							'line_subtotal'     => '24690',
							'line_subtotal_tax' => '3704',
							'line_total'        => '22221',
							'line_total_tax'    => '3333',
						],
						$currency
					),
				],
			],
			'coupons'                 => [
				[
					'code'          => 'probe10',
					'discount_type' => 'percent',
					'totals'        => (object) array_merge(
						[
							'total_discount'     => '2469',
							'total_discount_tax' => '370',
						],
						$currency
					),
				],
			],
			'fees'                    => [
				[
					'key'    => 'handling',
					'name'   => 'Handling',
					'totals' => (object) array_merge(
						[
							'total'     => '500',
							'total_tax' => '75',
						],
						$currency
					),
				],
			],
			'totals'                  => (object) array_merge(
				[
					'total_items'        => '24690',
					'total_items_tax'    => '3704',
					'total_fees'         => '500',
					'total_fees_tax'     => '75',
					'total_discount'     => '2469',
					'total_discount_tax' => '370',
					'total_shipping'     => null,
					'total_shipping_tax' => null,
					'total_price'        => '26330',
					'total_tax'          => '3408',
					'tax_lines'          => [
						[
							'name'  => 'VAT',
							'price' => '3408',
							'rate'  => '15',
						],
					],
				],
				$currency
			),
			'shipping_rates'          => [
				[
					'package_id'     => 0,
					'name'           => 'Shipment 1',
					'shipping_rates' => [
						array_merge(
							[
								'rate_id'   => 'flat_rate:1',
								'name'      => 'Flat rate',
								'price'     => '1000',
								'taxes'     => '150',
								'method_id' => 'flat_rate',
								'selected'  => true,
							],
							$currency
						),
					],
				],
			],
			'items_count'             => 2,
			'items_weight'            => 0,
			'needs_payment'           => true,
			'has_calculated_shipping' => false,
			'cross_sells'             => [
				[
					'id'     => 11,
					'name'   => 'Probe Variable Product',
					'prices' => (object) array_merge(
						[
							'price'         => '10100',
							'regular_price' => '10100',
							'sale_price'    => '10100',
							'price_range'   => [
								'min_amount' => '10100',
								'max_amount' => '20200',
							],
						],
						$currency
					),
				],
			],
			'errors'                  => [],
			'payment_methods'         => [ 'cod' ],
			'extensions'              => (object) [],
		];
	}

	/**
	 * Every value that is a string of digits, with its dotted path, at any depth.
	 *
	 * @param mixed  $node Data.
	 * @param string $path Path so far.
	 * @return string[]
	 */
	private static function numeric_strings( mixed $node, string $path = '$' ): array {
		if ( $node instanceof \stdClass ) {
			$node = get_object_vars( $node );
		}
		if ( ! is_array( $node ) ) {
			// A bare '0' is the integer the blocks' arithmetic needs, not an amount.
			return is_string( $node ) && preg_match( '/^\d+$/', $node ) && '0' !== $node ? [ $path ] : [];
		}
		$found = [];
		foreach ( $node as $key => $value ) {
			$found = array_merge( $found, self::numeric_strings( $value, "$path.$key" ) );
		}
		return $found;
	}

	public function test_a_guest_cart_carries_no_amount_anywhere(): void {
		( new StoreApi() )->register();

		foreach ( [ '/wc/store/v1/cart', '/wc/store/cart', '/wc/store/v1/cart/add-item', '/wc/store/v1/cart/update-item' ] as $route ) {
			$data = $this->dispatch( $this->cart(), $route );

			$leaks = array_filter(
				self::numeric_strings( $data ),
				// Precision is a decimal count, rate a percentage, neither an amount.
				static fn( string $path ): bool => ! str_ends_with( $path, '.precision' ) && ! str_ends_with( $path, '.rate' )
			);
			$this->assertSame( [], array_values( $leaks ), "$route still carries an amount." );
			$this->assertSame( [ '$.totals.tax_lines.0.rate' ], array_values( self::numeric_strings( $data ) ), "$route: nothing but the tax rate and bare zeros may remain numeric." );
		}
	}

	public function test_a_guest_cart_keeps_its_shape_and_metadata(): void {
		( new StoreApi() )->register();

		$data = $this->dispatch( $this->cart(), '/wc/store/v1/cart' );
		$item = $data['items'][0];

		$this->assertSame( 2, $item['quantity'] );
		$this->assertSame( 'Probe Simple Product', $item['name'] );
		$this->assertSame( 2, $data['items_count'] );
		$this->assertSame( [ 'cod' ], $data['payment_methods'] );

		$this->assertInstanceOf( \stdClass::class, $item['prices'] );
		$this->assertSame( '', $item['prices']->price );
		$this->assertSame( 'USD', $item['prices']->currency_code );
		$this->assertSame( 6, $item['prices']->raw_prices['precision'], 'raw_prices keeps its precision.' );
		// Parsed with parseInt() into Dinero by the line-item components: an integer, not a blank.
		$this->assertSame( '0', $item['prices']->raw_prices['price'] );
		$this->assertSame( '0', $item['prices']->raw_prices['regular_price'] );

		$this->assertInstanceOf( \stdClass::class, $item['totals'] );
		$this->assertSame( '0', $item['totals']->line_total );
		$this->assertSame( '0', $item['totals']->line_subtotal );
		$this->assertSame( '0', $item['totals']->line_subtotal_tax );
		$this->assertSame( '$', $item['totals']->currency_prefix );

		$this->assertInstanceOf( \stdClass::class, $data['totals'] );
		$this->assertSame( '', $data['totals']->total_price );
		$this->assertSame( '', $data['totals']->total_shipping, 'null becomes the same blank string as every other amount.' );
		$this->assertSame( 2, $data['totals']->currency_minor_unit );
		$this->assertSame( 'VAT', $data['totals']->tax_lines[0]['name'] );
		$this->assertSame( '15', $data['totals']->tax_lines[0]['rate'] );
		$this->assertSame( '', $data['totals']->tax_lines[0]['price'] );

		$rate = $data['shipping_rates'][0]['shipping_rates'][0];
		$this->assertSame( 'Flat rate', $rate['name'] );
		$this->assertSame( 'flat_rate:1', $rate['rate_id'] );
		$this->assertTrue( $rate['selected'] );
		$this->assertSame( '', $rate['price'] );
		$this->assertSame( '', $rate['taxes'] );

		$this->assertSame( 'probe10', $data['coupons'][0]['code'] );
		$this->assertSame( '', $data['coupons'][0]['totals']->total_discount );
		$this->assertSame( 'Handling', $data['fees'][0]['name'] );
		$this->assertSame( '', $data['fees'][0]['totals']->total );
		$this->assertSame( '', $data['cross_sells'][0]['prices']->price );
		$this->assertNull( $data['cross_sells'][0]['prices']->price_range );
	}

	public function test_a_logged_in_cart_is_untouched(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new StoreApi() )->register();

		$this->assertEquals( $this->cart(), $this->dispatch( $this->cart(), '/wc/store/v1/cart' ) );
	}

	public function test_checkout_and_order_responses_are_blanked(): void {
		( new StoreApi() )->register();

		$checkout = [
			'order_id'           => 20,
			'status'             => 'processing',
			'order_key'          => 'wc_order_abc',
			'payment_method'     => 'cod',
			'payment_result'     => [
				'payment_status'  => 'success',
				'payment_details' => [],
				'redirect_url'    => 'http://example.test/checkout/order-received/20/?key=wc_order_abc',
			],
			'__experimentalCart' => (object) $this->cart(),
		];
		foreach ( [ '/wc/store/v1/checkout', '/wc/store/checkout', '/wc/store/v1/checkout/20' ] as $route ) {
			$data = $this->dispatch( $checkout, $route );
			$this->assertSame( 20, $data['order_id'] );
			$this->assertSame( 'wc_order_abc', $data['order_key'] );
			$this->assertSame( 'success', $data['payment_result']['payment_status'] );
			$this->assertSame( '', $data['__experimentalCart']->totals->total_price, "$route cart totals must be blank." );
			$this->assertSame( '', $data['__experimentalCart']->items[0]['prices']->price );
		}

		$order = [
			'id'     => 20,
			'status' => 'processing',
			'items'  => $this->cart()['items'],
			'totals' => (object) [
				'subtotal'      => '24690',
				'total_refund'  => '0',
				'total_price'   => '26330',
				'currency_code' => 'USD',
			],
		];
		foreach ( [ '/wc/store/v1/order/20', '/wc/store/order/20?key=wc_order_abc&billing_email=guest@example.com' ] as $route ) {
			$data = $this->dispatch( $order, $route );
			$this->assertSame( 'processing', $data['status'] );
			$this->assertSame( '', $data['totals']->total_price, "$route must be blank." );
			$this->assertSame( '', $data['totals']->total_refund );
			$this->assertSame( 'USD', $data['totals']->currency_code );
			$this->assertSame( '0', $data['items'][0]['totals']->line_total );
		}
	}

	public function test_batch_envelope_sub_responses_are_blanked(): void {
		( new StoreApi() )->register();

		$data = $this->dispatch(
			[
				'responses' => [
					[
						'status'  => 200,
						'headers' => [],
						'body'    => $this->cart(),
					],
				],
			],
			'/wc/store/v1/batch'
		);

		$this->assertSame( 200, $data['responses'][0]['status'] );
		$this->assertSame( '', $data['responses'][0]['body']['totals']->total_price );
		$this->assertSame( '', $data['responses'][0]['body']['items'][0]['prices']->price );
	}

	public function test_blank_totals_returns_odd_input_as_is(): void {
		$this->assertNull( StoreApi::blank_totals( null ) );
		$this->assertSame( 'x', StoreApi::blank_totals( 'x' ) );
		$this->assertSame( [], StoreApi::blank_totals( [] ) );
	}

	// -- product grid blocks' inline script --------------------------------

	/**
	 * The `wp-hooks` inline script AbstractProductGrid::render() adds, with
	 * its product list encoded the way WooCommerce encodes it.
	 */
	private function grid_script( array $products ): string {
		return "\n\t\t\twindow.addEventListener( \"DOMContentLoaded\", () => {\n\t\t\t\twp.hooks.doAction(\n\t\t\t\t\t\"experimental__woocommerce_blocks-product-list-render\",\n\t\t\t\t\t{\n\t\t\t\t\t\tproducts: JSON.parse( decodeURIComponent( \""
			. esc_js( rawurlencode( (string) wp_json_encode( $products ) ) )
			. "\" ) ),\n\t\t\t\t\t\tlistName: \"product-new\"\n\t\t\t\t\t}\n\t\t\t\t);\n\t\t\t} );\n\t\t\t";
	}

	public function test_product_grid_inline_script_loses_its_amounts_for_a_guest(): void {
		$module = new StoreApi();
		$module->register();
		wp_scripts()->add_data( 'wp-hooks', 'after', [ false, 'window.x = 1;', $this->grid_script( [ $this->product() ] ) ] );

		$content = '<div class="wc-block-grid"><ul class="wc-block-grid__products"></ul></div>';
		$result  = apply_filters( 'render_block', $content, [ 'blockName' => 'woocommerce/product-new' ] );

		$this->assertSame( $content, $result, 'The rendered grid is not touched, only the script.' );
		$after = wp_scripts()->get_data( 'wp-hooks', 'after' );
		$this->assertFalse( $after[0] );
		$this->assertSame( 'window.x = 1;', $after[1] );
		$this->assertStringContainsString( 'experimental__woocommerce_blocks-product-list-render', $after[2] );
		$this->assertStringContainsString( 'listName: "product-new"', $after[2] );

		preg_match( '#decodeURIComponent\( "([^"]*)" \)#', $after[2], $m );
		$products = json_decode( rawurldecode( $m[1] ), true );
		$this->assertSame( 11, $products[0]['id'] );
		$this->assertSame( '', $products[0]['prices']['price'] );
		$this->assertSame( '', $products[0]['prices']['regular_price'] );
		$this->assertNull( $products[0]['prices']['price_range'] );
		$this->assertSame( 'USD', $products[0]['prices']['currency_code'] );
		$this->assertStringContainsString( 'Sign in to see prices', $products[0]['price_html'] );
		$this->assertStringNotContainsString( '1500', $after[2] );
	}

	public function test_product_grid_script_is_left_alone_for_members_other_blocks_and_odd_data(): void {
		$module = new StoreApi();
		$script = $this->grid_script( [ $this->product() ] );

		wp_scripts()->add_data( 'wp-hooks', 'after', [ $script ] );
		$module->scrub_product_grid_script( '', [ 'blockName' => 'core/paragraph' ] );
		$this->assertSame( $script, wp_scripts()->get_data( 'wp-hooks', 'after' )[0], 'Not a grid block.' );

		$module->scrub_product_grid_script( '', null );
		$this->assertSame( $script, wp_scripts()->get_data( 'wp-hooks', 'after' )[0] );

		wp_scripts()->add_data( 'wp-hooks', 'after', false );
		$this->assertSame( '', $module->scrub_product_grid_script( '', [ 'blockName' => 'woocommerce/product-on-sale' ] ), 'No inline scripts registered yet.' );

		$GLOBALS['pricecloak_test']['logged_in'] = true;
		wp_scripts()->add_data( 'wp-hooks', 'after', [ $script ] );
		$module->scrub_product_grid_script( '', [ 'blockName' => 'woocommerce/product-new' ] );
		$this->assertSame( $script, wp_scripts()->get_data( 'wp-hooks', 'after' )[0], 'Members keep their amounts.' );
	}

	public function test_every_product_grid_block_is_claimed(): void {
		foreach ( [ 'product-best-sellers', 'product-top-rated', 'products-by-attribute', 'product-new', 'product-category', 'product-on-sale', 'product-tag', 'handpicked-products' ] as $grid ) {
			$this->assertContains( 'woocommerce/' . $grid, StoreApi::PRODUCT_GRID_BLOCKS );
		}
	}
}
