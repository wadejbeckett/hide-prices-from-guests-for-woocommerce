<?php
/**
 * Legacy REST module: unauthenticated /wc/v3/products responses carry no
 * price fields, authenticated ones are untouched, other routes pass through.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit\Modules;

use PriceCloak\Modules;
use PriceCloak\Modules\LegacyRest;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

final class LegacyRestTest extends TestCase {
	private const HOOK = 'rest_request_after_callbacks';

	protected function setUp(): void {
		pricecloak_test_reset();
	}

	/**
	 * One item shaped like WC_REST_Products_Controller::prepare_object_for_response().
	 *
	 * @return array<string, mixed>
	 */
	private function product(): array {
		return [
			'id'            => 7,
			'name'          => 'Probe Simple',
			'slug'          => 'probe-simple',
			'type'          => 'simple',
			'sku'           => 'PROBE-SIMPLE',
			'price'         => '12.00',
			'regular_price' => '15.00',
			'sale_price'    => '12.00',
			'on_sale'       => true,
			'price_html'    => '<span class="woocommerce-Price-amount">$12.00</span>',
			'stock_status'  => 'instock',
			'meta_data'     => [],
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

	public function test_id_is_legacy_rest(): void {
		$this->assertSame( 'legacy_rest', ( new LegacyRest() )->id() );
	}

	public function test_module_is_in_the_registry(): void {
		$ids = array_map( static fn( $m ) => $m->id(), Modules::all() );
		$this->assertContains( 'legacy_rest', $ids );
	}

	public function test_register_hooks_the_rest_filter_at_max_priority(): void {
		( new LegacyRest() )->register();

		$this->assertArrayHasKey( self::HOOK, $GLOBALS['pricecloak_test']['filters'] );
		$this->assertSame( PHP_INT_MAX, $GLOBALS['pricecloak_test']['filters'][ self::HOOK ][0][1] );
		$this->assertSame( 3, $GLOBALS['pricecloak_test']['filters'][ self::HOOK ][0][2] );
	}

	public function test_unregister_removes_exactly_what_register_added(): void {
		$module = new LegacyRest();
		$module->register();
		$module->unregister();

		$this->assertSame( [], $GLOBALS['pricecloak_test']['filters'] );
	}

	public function test_guest_product_list_has_every_price_field_blanked(): void {
		( new LegacyRest() )->register();

		$item = $this->dispatch( [ $this->product() ], '/wc/v3/products' )[0];

		foreach ( LegacyRest::PRICE_KEYS as $key ) {
			$this->assertSame( '', $item[ $key ], "$key should be blank." );
		}
	}

	public function test_non_price_fields_are_preserved(): void {
		( new LegacyRest() )->register();

		$item = $this->dispatch( [ $this->product() ], '/wc/v3/products' )[0];

		$this->assertSame( 7, $item['id'] );
		$this->assertSame( 'PROBE-SIMPLE', $item['sku'] );
		$this->assertSame( 'instock', $item['stock_status'] );
		$this->assertTrue( $item['on_sale'] );
	}

	public function test_single_product_and_variations_routes_are_covered(): void {
		( new LegacyRest() )->register();

		foreach ( [ '/wc/v3/products/7', '/wc/v3/products/11/variations', '/wc/v3/products/11/variations/13' ] as $route ) {
			$item = $this->dispatch( [ $this->product() ], $route )[0];
			$this->assertSame( '', $item['price'], "Route $route should be filtered." );
		}
	}

	public function test_older_namespaces_are_covered(): void {
		( new LegacyRest() )->register();

		foreach ( [ '/wc/v2/products', '/wc/v1/products/7' ] as $route ) {
			$item = $this->dispatch( [ $this->product() ], $route )[0];
			$this->assertSame( '', $item['price'], "Route $route should be filtered." );
		}
	}

	public function test_nested_price_fields_are_blanked_too(): void {
		( new LegacyRest() )->register();

		$data = $this->dispatch(
			[
				[
					'id'         => 11,
					'variations' => [
						[
							'id'    => 13,
							'price' => '20.00',
						],
					],
				],
			],
			'/wc/v3/products'
		);

		$this->assertSame( '', $data[0]['variations'][0]['price'] );
		$this->assertSame( 13, $data[0]['variations'][0]['id'] );
	}

	public function test_logged_in_responses_are_untouched(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new LegacyRest() )->register();

		$this->assertSame( $this->product(), $this->dispatch( [ $this->product() ], '/wc/v3/products' )[0] );
	}

	public function test_other_routes_pass_through_untouched(): void {
		( new LegacyRest() )->register();

		foreach ( [ '/wc/v3/orders', '/wc/store/v1/products', '/wp/v2/posts', '/wc/v3/productsmith' ] as $route ) {
			$this->assertSame( $this->product(), $this->dispatch( [ $this->product() ], $route )[0], "Route $route should not be filtered." );
		}
	}

	public function test_a_wp_error_response_passes_through(): void {
		( new LegacyRest() )->register();

		$sentinel = new \stdClass();

		$this->assertSame( $sentinel, apply_filters( self::HOOK, $sentinel, null, new WP_REST_Request( '/wc/v3/products' ) ) );
	}

	public function test_non_array_response_data_passes_through(): void {
		( new LegacyRest() )->register();

		$this->assertSame( 'plain', $this->dispatch( 'plain', '/wc/v3/products' ) );
	}
}
