<?php
/**
 * A site upgrading from the plugin's previous name ("Hide Prices from Guests
 * for WooCommerce", option prefix `hpfg_`) keeps its settings.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit;

use PriceCloak\Options;
use PriceCloak\Plugin;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase {
	/**
	 * Module ids of the shipped build.
	 *
	 * @var string[]
	 */
	private array $ids = [];

	protected function setUp(): void {
		pricecloak_test_reset();
		$this->ids = Plugin::module_ids();
	}

	public function test_legacy_name_maps_the_prefix_and_leaves_others_alone(): void {
		$this->assertSame( 'hpfg_enabled', Options::legacy_name( 'pricecloak_enabled' ) );
		$this->assertSame( 'hpfg_module_price_html', Options::legacy_name( 'pricecloak_module_price_html' ) );
		$this->assertSame( 'unrelated_option', Options::legacy_name( 'unrelated_option' ) );
	}

	public function test_legacy_values_move_across_and_the_old_rows_go(): void {
		update_option( 'hpfg_enabled', 'yes' );
		update_option( 'hpfg_replacement_text', 'Legacy text' );
		update_option( 'hpfg_module_store_api', 'no' );
		update_option( 'unrelated_option', 'keep' );

		Options::migrate_legacy( $this->ids );

		$this->assertTrue( Options::enabled() );
		$this->assertSame( 'Legacy text', Options::replacement_text() );
		$this->assertFalse( Options::module_enabled( 'store_api' ) );

		foreach ( array_keys( $GLOBALS['pricecloak_test']['options'] ) as $name ) {
			$this->assertFalse( str_starts_with( (string) $name, 'hpfg_' ), 'No legacy option may survive the migration.' );
		}
		$this->assertSame( 'keep', get_option( 'unrelated_option' ) );
	}

	public function test_an_existing_new_value_wins_and_the_legacy_row_is_still_removed(): void {
		update_option( Options::REPLACEMENT_TEXT, 'Current text' );
		update_option( 'hpfg_replacement_text', 'Legacy text' );

		Options::migrate_legacy( $this->ids );

		$this->assertSame( 'Current text', Options::replacement_text() );
		$this->assertArrayNotHasKey( 'hpfg_replacement_text', $GLOBALS['pricecloak_test']['options'] );
	}

	public function test_it_runs_once_and_is_cheap_afterwards(): void {
		update_option( 'hpfg_enabled', 'yes' );
		Options::migrate_legacy( $this->ids );
		$this->assertSame( 'yes', get_option( Options::MIGRATED ) );

		// A later stray legacy row is not picked up: the flag short-circuits.
		update_option( 'hpfg_replacement_text', 'Too late' );
		Options::migrate_legacy( $this->ids );
		$this->assertSame( 'Too late', get_option( 'hpfg_replacement_text' ) );
	}

	public function test_a_fresh_install_migrates_nothing_but_still_marks_itself_done(): void {
		Options::migrate_legacy( $this->ids );

		$this->assertSame( [ Options::MIGRATED => 'yes' ], $GLOBALS['pricecloak_test']['options'] );
	}

	public function test_the_plugin_file_migrates_on_activation_and_on_plugins_loaded(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/pricecloak-for-woocommerce.php' );
		$this->assertStringContainsString( 'register_activation_hook(', $source );
		$this->assertSame(
			2,
			substr_count( $source, 'Options::migrate_legacy(' ),
			'Both the activation hook and plugins_loaded must run the migration.'
		);
		// It must not sit behind the WooCommerce guard: settings are carried
		// over even on a site whose WooCommerce is temporarily inactive.
		$this->assertLessThan(
			strpos( $source, "if ( ! class_exists( 'WooCommerce' ) )" ),
			strrpos( $source, 'Options::migrate_legacy(' ),
			'The plugins_loaded migration runs before the WooCommerce guard.'
		);
	}

	public function test_uninstall_removes_both_prefixes_and_the_flag(): void {
		foreach ( Options::all_names( $this->ids ) as $name ) {
			update_option( $name, 'yes' );
			update_option( Options::legacy_name( $name ), 'yes' );
		}
		update_option( Options::MIGRATED, 'yes' );
		update_option( 'unrelated_option', 'keep' );

		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', true );
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( [ 'unrelated_option' => 'keep' ], $GLOBALS['pricecloak_test']['options'] );
	}
}
