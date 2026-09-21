<?php
/**
 * Variation payload module: guests get blank numeric prices and replacement
 * price HTML; every other key, and every logged-in payload, is untouched.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit\Modules;

use PriceCloak\Modules;
use PriceCloak\Modules\VariationPayload;
use PriceCloak\Options;
use PHPUnit\Framework\TestCase;

final class VariationPayloadTest extends TestCase {
	private const HOOK = 'woocommerce_available_variation';

	protected function setUp(): void {
		pricecloak_test_reset();
		$_SERVER['REQUEST_URI'] = '/product/probe-variable/';
	}

	/**
	 * A payload shaped like the one WooCommerce builds.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		return [
			'variation_id'          => 42,
			'variation_is_active'   => true,
			'variation_is_visible'  => true,
			'attributes'            => [ 'attribute_pa_size' => 'large' ],
			'sku'                   => 'PROBE-VAR-L',
			'image'                 => [ 'src' => 'http://example.test/img.png' ],
			'is_in_stock'           => true,
			'is_purchasable'        => true,
			'is_sold_individually'  => 'no',
			'max_qty'               => 10,
			'min_qty'               => 1,
			'availability_html'     => '<p class="stock in-stock">In stock</p>',
			'display_price'         => 12.0,
			'display_regular_price' => 15.0,
			'price_html'            => '<span class="woocommerce-Price-amount amount">&#36;12.00</span>',
		];
	}

	public function test_id_is_variation_payload(): void {
		$this->assertSame( 'variation_payload', ( new VariationPayload() )->id() );
	}

	public function test_module_is_in_the_registry(): void {
		$ids = array_map( static fn( $m ) => $m->id(), Modules::all() );
		$this->assertContains( 'variation_payload', $ids );
	}

	public function test_register_hooks_the_filter_at_max_priority_with_three_args(): void {
		( new VariationPayload() )->register();

		$this->assertArrayHasKey( self::HOOK, $GLOBALS['pricecloak_test']['filters'] );
		$entry = $GLOBALS['pricecloak_test']['filters'][ self::HOOK ][0];
		$this->assertSame( PHP_INT_MAX, $entry[1], 'Wrong priority.' );
		$this->assertSame( 3, $entry[2], 'The filter passes $data, $product and $variation.' );
	}

	public function test_unregister_removes_exactly_what_register_added(): void {
		$module = new VariationPayload();
		$module->register();
		$module->unregister();

		$this->assertSame( [], $GLOBALS['pricecloak_test']['filters'] );
	}

	public function test_guest_gets_blank_numeric_prices(): void {
		( new VariationPayload() )->register();

		$data = apply_filters( self::HOOK, $this->payload(), null, null );

		foreach ( VariationPayload::PRICE_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $data, "$key must stay present." );
			$this->assertSame( '', $data[ $key ], "$key must be blank for a guest." );
		}
	}

	public function test_guest_gets_the_replacement_markup_as_price_html(): void {
		( new VariationPayload() )->register();

		$data = apply_filters( self::HOOK, $this->payload(), null, null );

		$this->assertStringContainsString( 'Sign in to see prices', $data['price_html'] );
		$this->assertStringNotContainsString( 'woocommerce-Price-amount', $data['price_html'] );
	}

	public function test_replacement_markup_matches_the_price_html_module(): void {
		update_option( Options::LINK_TO_LOGIN, 'no' );
		( new VariationPayload() )->register();
		( new \PriceCloak\Modules\PriceHtml() )->register();

		$data = apply_filters( self::HOOK, $this->payload(), null, null );

		$this->assertSame(
			apply_filters( 'woocommerce_get_price_html', 'anything' ),
			$data['price_html'],
			'Both modules must render the same replacement.'
		);
	}

	public function test_every_other_key_is_preserved(): void {
		( new VariationPayload() )->register();

		$original = $this->payload();
		$data     = apply_filters( self::HOOK, $original, null, null );

		$this->assertSame( array_keys( $original ), array_keys( $data ), 'No key may be added or removed.' );
		foreach ( $original as $key => $value ) {
			if ( in_array( $key, VariationPayload::PRICE_KEYS, true ) || 'price_html' === $key ) {
				continue;
			}
			$this->assertSame( $value, $data[ $key ], "$key was altered." );
		}
	}

	public function test_logged_in_payload_is_untouched(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new VariationPayload() )->register();

		$this->assertSame( $this->payload(), apply_filters( self::HOOK, $this->payload(), null, null ) );
	}

	public function test_a_payload_without_price_keys_is_left_alone(): void {
		( new VariationPayload() )->register();

		$data = apply_filters( self::HOOK, [ 'variation_id' => 7 ], null, null );

		$this->assertSame( [ 'variation_id' => 7 ], $data );
	}

	public function test_a_non_array_payload_is_returned_unchanged(): void {
		( new VariationPayload() )->register();

		$this->assertFalse( apply_filters( self::HOOK, false, null, null ) );
	}
}
