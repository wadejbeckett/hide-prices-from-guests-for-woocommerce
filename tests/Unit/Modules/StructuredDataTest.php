<?php
/**
 * Structured data module: no `offers` node in product JSON-LD for guests,
 * everything else untouched, logged-in markup unchanged.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit\Modules;

use PriceCloak\Modules;
use PriceCloak\Modules\StructuredData;
use PHPUnit\Framework\TestCase;

final class StructuredDataTest extends TestCase {
	private const PRODUCT_HOOK = 'woocommerce_structured_data_product';
	private const OFFER_HOOK   = 'woocommerce_structured_data_product_offer';

	protected function setUp(): void {
		pricecloak_test_reset();
	}

	/**
	 * Markup shaped like WC_Structured_Data::generate_product_data() builds.
	 *
	 * @return array<string, mixed>
	 */
	private function markup(): array {
		return [
			'@type'       => 'Product',
			'@id'         => 'http://example.test/product/probe-simple/#product',
			'name'        => 'Probe Simple',
			'url'         => 'http://example.test/product/probe-simple/',
			'description' => 'A product.',
			'sku'         => 'PROBE-SIMPLE',
			'image'       => 'http://example.test/img.png',
			'offers'      => [
				[
					'@type'         => 'Offer',
					'price'         => '12.00',
					'priceCurrency' => 'USD',
					'availability'  => 'https://schema.org/InStock',
				],
			],
		];
	}

	public function test_id_is_structured_data(): void {
		$this->assertSame( 'structured_data', ( new StructuredData() )->id() );
	}

	public function test_module_is_in_the_registry(): void {
		$ids = array_map( static fn( $m ) => $m->id(), Modules::all() );
		$this->assertContains( 'structured_data', $ids );
	}

	public function test_register_hooks_both_filters_at_max_priority(): void {
		( new StructuredData() )->register();

		foreach ( [ self::PRODUCT_HOOK, self::OFFER_HOOK ] as $hook ) {
			$this->assertArrayHasKey( $hook, $GLOBALS['pricecloak_test']['filters'], "Missing $hook." );
			$this->assertSame( PHP_INT_MAX, $GLOBALS['pricecloak_test']['filters'][ $hook ][0][1], "Wrong priority on $hook." );
			$this->assertSame( 2, $GLOBALS['pricecloak_test']['filters'][ $hook ][0][2], "Wrong arg count on $hook." );
		}
	}

	public function test_unregister_removes_exactly_what_register_added(): void {
		$module = new StructuredData();
		$module->register();
		$module->unregister();

		$this->assertSame( [], $GLOBALS['pricecloak_test']['filters'] );
	}

	public function test_guest_markup_has_no_offers_key_at_all(): void {
		( new StructuredData() )->register();

		$markup = apply_filters( self::PRODUCT_HOOK, $this->markup(), null );

		$this->assertArrayNotHasKey( 'offers', $markup, 'The key must be gone, not merely empty.' );
	}

	public function test_guest_offer_filter_returns_an_empty_array(): void {
		( new StructuredData() )->register();

		$this->assertSame( [], apply_filters( self::OFFER_HOOK, $this->markup()['offers'][0], null ) );
	}

	public function test_every_other_key_is_preserved(): void {
		( new StructuredData() )->register();

		$markup = apply_filters( self::PRODUCT_HOOK, $this->markup(), null );

		foreach ( $this->markup() as $key => $value ) {
			if ( 'offers' === $key ) {
				continue;
			}
			$this->assertSame( $value, $markup[ $key ], "$key was altered." );
		}
	}

	public function test_logged_in_markup_is_untouched(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new StructuredData() )->register();

		$this->assertSame( $this->markup(), apply_filters( self::PRODUCT_HOOK, $this->markup(), null ) );
		$this->assertSame(
			$this->markup()['offers'][0],
			apply_filters( self::OFFER_HOOK, $this->markup()['offers'][0], null )
		);
	}

	public function test_a_non_array_markup_is_returned_unchanged(): void {
		( new StructuredData() )->register();

		$this->assertSame( '', apply_filters( self::PRODUCT_HOOK, '', null ) );
	}
}
