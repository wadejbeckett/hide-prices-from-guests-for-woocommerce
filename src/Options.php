<?php
/**
 * Option names and defaults. The single place that knows the pricecloak_ keys.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak;

defined( 'ABSPATH' ) || exit;

/**
 * Reads options with the defaults agreed in docs/SPEC.md.
 */
final class Options {
	public const ENABLED          = 'pricecloak_enabled';
	public const REPLACEMENT_TEXT = 'pricecloak_replacement_text';
	public const LINK_TO_LOGIN    = 'pricecloak_link_to_login';
	public const BLOCK_PURCHASING = 'pricecloak_block_purchasing';
	public const MODULE_PREFIX    = 'pricecloak_module_';

	/**
	 * Prefix every option name carried by this plugin.
	 */
	public const PREFIX = 'pricecloak_';

	/**
	 * Prefix the plugin used before the 1.0.0 rename to PriceCloak.
	 */
	public const LEGACY_PREFIX = 'hpfg_';

	/**
	 * Set once the legacy options have been looked at, so the migration costs
	 * one `get_option()` per request forever after.
	 */
	public const MIGRATED = 'pricecloak_migrated_from_hpfg';

	/**
	 * Master switch.
	 */
	public static function enabled(): bool {
		return 'yes' === get_option( self::ENABLED, 'no' );
	}

	/**
	 * Text shown where a price would be; blank falls back to the default.
	 */
	public static function replacement_text(): string {
		$text = (string) get_option( self::REPLACEMENT_TEXT, '' );
		return '' === $text ? __( 'Sign in to see prices', 'pricecloak-for-woocommerce' ) : $text;
	}

	/**
	 * Whether the replacement text links to the login page.
	 */
	public static function link_to_login(): bool {
		return 'yes' === get_option( self::LINK_TO_LOGIN, 'yes' );
	}

	/**
	 * Whether the Purchasing module is on.
	 */
	public static function block_purchasing(): bool {
		return 'yes' === get_option( self::BLOCK_PURCHASING, 'no' );
	}

	/**
	 * Modules whose toggle is one of the named options above rather than a
	 * generated `pricecloak_module_<id>` checkbox, with that option's default.
	 *
	 * The specification calls "Block purchasing for logged-out visitors" the switch
	 * that enables the Purchasing module, so it *is* that module's toggle.
	 * Giving the module a second `pricecloak_module_purchasing` checkbox as well
	 * would mean two controls for one behaviour, in two places in the same
	 * settings screen, either of which could silently veto the other.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const MODULE_OPTION_ALIASES = [
		'purchasing' => [ self::BLOCK_PURCHASING, 'no' ],
	];

	/**
	 * The option name that switches a module on and off.
	 *
	 * @param string $id Module id.
	 */
	public static function module_option( string $id ): string {
		return self::MODULE_OPTION_ALIASES[ $id ][0] ?? self::MODULE_PREFIX . $id;
	}

	/**
	 * What that option means when it has never been saved.
	 *
	 * @param string $id Module id.
	 */
	public static function module_default( string $id ): string {
		return self::MODULE_OPTION_ALIASES[ $id ][1] ?? 'yes';
	}

	/**
	 * Per-module toggle; on unless explicitly turned off, except where the
	 * alias table says otherwise.
	 *
	 * @param string $id Module id.
	 */
	public static function module_enabled( string $id ): bool {
		return 'yes' === get_option( self::module_option( $id ), self::module_default( $id ) );
	}

	/**
	 * Every option this plugin stores; uninstall deletes each.
	 *
	 * @param string[] $module_ids Known module ids.
	 * @return string[]
	 */
	public static function all_names( array $module_ids ): array {
		$names = [ self::ENABLED, self::REPLACEMENT_TEXT, self::LINK_TO_LOGIN, self::BLOCK_PURCHASING ];
		foreach ( $module_ids as $id ) {
			$names[] = self::module_option( $id );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * The name this option had under the plugin's previous name.
	 *
	 * @param string $name A current `pricecloak_` option name.
	 */
	public static function legacy_name( string $name ): string {
		return str_starts_with( $name, self::PREFIX )
			? self::LEGACY_PREFIX . substr( $name, strlen( self::PREFIX ) )
			: $name;
	}

	/**
	 * Carry settings over from the plugin's previous name, once.
	 *
	 * A site that had "Hide Prices from Guests for WooCommerce" installed keeps
	 * its configuration: every `hpfg_*` option whose `pricecloak_*` counterpart
	 * has never been saved is copied across and the old row removed. Runs on
	 * activation and, for sites whose plugin folder was replaced in place
	 * without a reactivation, on every `plugins_loaded` until it has run.
	 *
	 * @param string[] $module_ids Known module ids.
	 */
	public static function migrate_legacy( array $module_ids ): void {
		if ( 'yes' === get_option( self::MIGRATED, 'no' ) ) {
			return;
		}
		$missing = '__pricecloak_missing__';
		foreach ( self::all_names( $module_ids ) as $name ) {
			$legacy = self::legacy_name( $name );
			$value  = get_option( $legacy, $missing );
			if ( $missing === $value ) {
				continue;
			}
			if ( get_option( $name, $missing ) === $missing ) {
				update_option( $name, $value );
			}
			delete_option( $legacy );
		}
		update_option( self::MIGRATED, 'yes' );
	}
}
