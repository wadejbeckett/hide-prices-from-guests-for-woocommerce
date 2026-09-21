<?php
/**
 * Plugin boot behaviour: off means nothing registered; on registers enabled modules.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit;

use PriceCloak\Modules;
use PriceCloak\Options;
use PriceCloak\Plugin;
use PriceCloak\Tests\Support\FakeModule;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/FakeModule.php';

final class PluginTest extends TestCase {
	protected function setUp(): void {
		pricecloak_test_reset();
	}

	public function test_master_off_by_default_registers_nothing(): void {
		$a      = new FakeModule( 'a' );
		$plugin = new Plugin( [ $a ] );
		$plugin->boot();

		$this->assertSame( 0, $a->registered );
		$this->assertSame( [], $plugin->registered_module_ids() );
		$this->assertSame(
			[ 'woocommerce_get_sections_products', 'woocommerce_get_settings_products' ],
			array_keys( $GLOBALS['pricecloak_test']['filters'] ),
			'Master off must register nothing beyond the settings section.'
		);
	}

	public function test_master_on_registers_every_enabled_module(): void {
		update_option( Options::ENABLED, 'yes' );
		$a      = new FakeModule( 'a' );
		$b      = new FakeModule( 'b' );
		$plugin = new Plugin( [ $a, $b ] );
		$plugin->boot();

		$this->assertSame( 1, $a->registered );
		$this->assertSame( 1, $b->registered );
		$this->assertSame( [ 'a', 'b' ], $plugin->registered_module_ids() );
		$this->assertArrayHasKey( 'pricecloak_fake_a', $GLOBALS['pricecloak_test']['filters'] );
	}

	public function test_module_toggle_off_skips_that_module_only(): void {
		update_option( Options::ENABLED, 'yes' );
		update_option( Options::MODULE_PREFIX . 'b', 'no' );
		$a      = new FakeModule( 'a' );
		$b      = new FakeModule( 'b' );
		$plugin = new Plugin( [ $a, $b ] );
		$plugin->boot();

		$this->assertSame( [ 'a' ], $plugin->registered_module_ids() );
		$this->assertSame( 0, $b->registered );
	}

	public function test_shutdown_unregisters_and_leaves_no_filters(): void {
		update_option( Options::ENABLED, 'yes' );
		$a      = new FakeModule( 'a' );
		$plugin = new Plugin( [ $a ] );
		$plugin->boot();
		$plugin->shutdown();

		$this->assertSame( 1, $a->unregistered );
		$this->assertSame( [], $plugin->registered_module_ids() );
		$this->assertArrayNotHasKey( 'pricecloak_fake_a', $GLOBALS['pricecloak_test']['filters'] );
	}

	public function test_one_real_module_off_leaves_the_others_registered(): void {
		update_option( Options::ENABLED, 'yes' );
		update_option( Options::MODULE_PREFIX . 'structured_data', 'no' );
		$plugin = new Plugin( Modules::all() );
		$plugin->boot();

		$registered = $plugin->registered_module_ids();
		$this->assertNotContains( 'structured_data', $registered );
		foreach ( [ 'price_html', 'variation_payload', 'store_api', 'legacy_rest' ] as $id ) {
			$this->assertContains( $id, $registered, "$id must still be registered." );
		}
		$this->assertArrayNotHasKey(
			'woocommerce_structured_data_product',
			$GLOBALS['pricecloak_test']['filters'],
			'A disabled module must attach none of its hooks.'
		);
	}

	public function test_known_module_ids_lists_all_regardless_of_state(): void {
		$plugin = new Plugin( [ new FakeModule( 'x' ), new FakeModule( 'y' ) ] );
		$this->assertSame( [ 'x', 'y' ], $plugin->known_module_ids() );
	}
}
