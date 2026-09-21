<?php
/**
 * Settings section shape and defaults.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit;

use PriceCloak\Modules;
use PriceCloak\Options;
use PriceCloak\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {
	protected function setUp(): void {
		pricecloak_test_reset();
	}

	public function test_adds_section_to_products_tab(): void {
		$sections = ( new Settings() )->add_section( [ '' => 'General' ] );
		$this->assertArrayHasKey( 'pricecloak', $sections );
		$this->assertSame( 'Guest price visibility', $sections['pricecloak'] );
	}

	public function test_returns_own_fields_only_for_own_section(): void {
		$settings = new Settings();
		$other    = [ [ 'id' => 'other' ] ];
		$this->assertSame( $other, $settings->add_settings( $other, '' ) );
		$this->assertSame( $other, $settings->add_settings( $other, 'inventory' ) );
		$this->assertNotSame( $other, $settings->add_settings( $other, 'pricecloak' ) );
	}

	public function test_fields_carry_spec_defaults_and_one_toggle_per_module(): void {
		$fields = ( new Settings( [ 'price_html', 'store_api' ] ) )->fields();
		$by_id  = [];
		foreach ( $fields as $f ) {
			$by_id[ $f['id'] ] = $f;
		}

		$this->assertSame( 'no', $by_id[ Options::ENABLED ]['default'] );
		$this->assertSame( 'checkbox', $by_id[ Options::ENABLED ]['type'] );
		$this->assertSame( '', $by_id[ Options::REPLACEMENT_TEXT ]['default'] );
		$this->assertSame( 'Sign in to see prices', $by_id[ Options::REPLACEMENT_TEXT ]['placeholder'] );
		$this->assertSame( 'yes', $by_id[ Options::LINK_TO_LOGIN ]['default'] );
		$this->assertSame( 'no', $by_id[ Options::BLOCK_PURCHASING ]['default'] );
		$this->assertSame( 'yes', $by_id[ Options::MODULE_PREFIX . 'price_html' ]['default'] );
		$this->assertSame( 'yes', $by_id[ Options::MODULE_PREFIX . 'store_api' ]['default'] );
		$this->assertSame( 'title', $fields[0]['type'] );
		$this->assertSame( 'sectionend', $fields[ count( $fields ) - 1 ]['type'] );
	}

	public function test_every_module_checkbox_names_its_surface_in_one_line(): void {
		$ids    = array_map( static fn( $m ) => $m->id(), Modules::all() );
		$fields = ( new Settings( $ids ) )->fields();

		foreach ( $fields as $field ) {
			if ( 'checkbox' !== ( $field['type'] ?? '' ) || Options::ENABLED === $field['id'] || Options::LINK_TO_LOGIN === $field['id'] ) {
				continue;
			}
			$desc = (string) ( $field['desc'] ?? '' );
			$this->assertNotSame( '', $desc, "Module checkbox {$field['id']} has no description." );
			$this->assertStringNotContainsString( "\n", $desc, 'A module description is one line.' );
			$this->assertLessThan( 260, strlen( $desc ), "Module description for {$field['id']} is not one line's worth." );
			$this->assertStringNotContainsString( '_', (string) $field['title'], 'The title names the surface, not the module id.' );
		}
	}

	/**
	 * The descriptions were written before the T16-T21 coverage landed; each
	 * checkbox now names everything its module covers, in words a shop owner
	 * reads without the README.
	 */
	public function test_module_descriptions_name_the_surfaces_each_module_covers(): void {
		$ids    = array_map( static fn( $m ) => $m->id(), Modules::all() );
		$fields = ( new Settings( $ids ) )->fields();
		$desc   = [];
		foreach ( $fields as $field ) {
			$desc[ $field['id'] ] = (string) ( $field['desc'] ?? '' );
		}

		$expect = [
			Options::MODULE_PREFIX . 'price_html'        => [ 'shop', 'product pages', 'cart and checkout totals', 'Mini Cart', 'grouped', 'order-received', 'order-pay', 'order tracking' ],
			Options::MODULE_PREFIX . 'variation_payload' => [ 'variation', 'get_variation' ],
			Options::MODULE_PREFIX . 'structured_data'   => [ 'offers', 'JSON-LD' ],
			Options::MODULE_PREFIX . 'store_api'         => [ 'products', 'cart', 'checkout', 'order', 'batch', 'unversioned', 'block pages' ],
			Options::MODULE_PREFIX . 'legacy_rest'       => [ '/wc/v3/products', 'variations' ],
			Options::MODULE_PREFIX . 'price_filters'     => [ 'range', 'sorting', 'shop', 'Store API', '/wp/v2/product', 'widget', 'blocks' ],
			Options::BLOCK_PURCHASING                    => [ 'add-to-cart', 'checkout processing', 'wc-ajax', 'Store API', 'cart and checkout', 'Log in to buy', 'existing order' ],
		];
		foreach ( $expect as $id => $words ) {
			$this->assertArrayHasKey( $id, $desc );
			foreach ( $words as $word ) {
				$this->assertStringContainsString( $word, $desc[ $id ], "The $id description does not mention '$word'." );
			}
		}
	}

	public function test_every_option_name_has_a_field(): void {
		$ids    = [ 'a', 'b' ];
		$fields = ( new Settings( $ids ) )->fields();
		$in_ui  = array_column( $fields, 'id' );
		foreach ( Options::all_names( $ids ) as $name ) {
			$this->assertContains( $name, $in_ui, "Option $name has no settings field." );
		}
	}

	public function test_register_and_unregister_are_symmetric(): void {
		$settings = new Settings();
		$settings->register();
		$this->assertCount( 2, $GLOBALS['pricecloak_test']['filters'] );
		$settings->unregister();
		$this->assertSame( [], $GLOBALS['pricecloak_test']['filters'] );
	}
}
