<?php
/**
 * Settings section under WooCommerce → Settings → Products, through the native
 * WooCommerce settings API. Registered whether or not the master switch is on.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and registers the settings section.
 */
final class Settings {
	public const SECTION = 'pricecloak';

	/**
	 * Constructor.
	 *
	 * @param string[] $module_ids Known module ids, each gets a toggle.
	 */
	public function __construct( private array $module_ids = [] ) {}

	/**
	 * Attach the two WooCommerce settings filters.
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_sections_products', [ $this, 'add_section' ] );
		add_filter( 'woocommerce_get_settings_products', [ $this, 'add_settings' ], 10, 2 );
	}

	/**
	 * Detach the filters.
	 */
	public function unregister(): void {
		remove_filter( 'woocommerce_get_sections_products', [ $this, 'add_section' ] );
		remove_filter( 'woocommerce_get_settings_products', [ $this, 'add_settings' ], 10 );
	}

	/**
	 * Add the section tab.
	 *
	 * @param array<string,string> $sections Existing sections.
	 * @return array<string,string>
	 */
	public function add_section( array $sections ): array {
		$sections[ self::SECTION ] = __( 'Guest price visibility', 'pricecloak-for-woocommerce' );
		return $sections;
	}

	/**
	 * Return this section's fields when it is the current section.
	 *
	 * @param array<int,array<string,mixed>> $settings        Settings for the current section.
	 * @param string                         $current_section Section slug being rendered.
	 * @return array<int,array<string,mixed>>
	 */
	public function add_settings( array $settings, string $current_section = '' ): array {
		return self::SECTION === $current_section ? $this->fields() : $settings;
	}

	/**
	 * The field definitions, in WooCommerce settings API shape.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function fields(): array {
		$fields = [
			[
				'title' => __( 'Guest price visibility', 'pricecloak-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'When on, logged-out visitors see no prices anywhere WooCommerce renders or serves them. When off, this plugin does nothing.', 'pricecloak-for-woocommerce' ),
				'id'    => 'pricecloak_section_title',
			],
			[
				'title'   => __( 'Hide prices from logged-out visitors', 'pricecloak-for-woocommerce' ),
				'type'    => 'checkbox',
				'id'      => Options::ENABLED,
				'default' => 'no',
			],
			[
				'title'       => __( 'Replacement text', 'pricecloak-for-woocommerce' ),
				'type'        => 'text',
				'id'          => Options::REPLACEMENT_TEXT,
				'default'     => '',
				'placeholder' => __( 'Sign in to see prices', 'pricecloak-for-woocommerce' ),
				'desc_tip'    => __( 'Shown where a price would be. Leave blank for the default.', 'pricecloak-for-woocommerce' ),
			],
			[
				'title'   => __( 'Link replacement text to the login page', 'pricecloak-for-woocommerce' ),
				'type'    => 'checkbox',
				'id'      => Options::LINK_TO_LOGIN,
				'default' => 'yes',
			],
			[
				'title'   => __( 'Block purchasing for logged-out visitors', 'pricecloak-for-woocommerce' ),
				'type'    => 'checkbox',
				'id'      => Options::BLOCK_PURCHASING,
				'default' => 'no',
				'desc'    => __( 'Refuses add-to-cart and checkout processing for guests on every path (product form, wc-ajax, Store API), sends the cart and checkout pages to the login page and shows a "Log in to buy" button; paying an existing order by its link stays possible.', 'pricecloak-for-woocommerce' ),
			],
		];

		$already = array_column( $fields, 'id' );
		foreach ( $this->module_ids as $id ) {
			// A module whose toggle is one of the named options above already
			// has its checkbox; the Purchasing module is the case today.
			if ( in_array( Options::module_option( $id ), $already, true ) ) {
				continue;
			}
			$module   = self::module_labels()[ $id ] ?? [ $id, '' ];
			$fields[] = [
				'title'   => sprintf(
					/* translators: %s: module name */
					__( 'Module: %s', 'pricecloak-for-woocommerce' ),
					$module[0]
				),
				'type'    => 'checkbox',
				'id'      => Options::module_option( $id ),
				'default' => Options::module_default( $id ),
				'desc'    => $module[1],
			];
		}

		$fields[] = [
			'type' => 'sectionend',
			'id'   => 'pricecloak_section_end',
		];

		return $fields;
	}

	/**
	 * A one-line name and plain-language description of the surface each
	 * module covers, so the checkbox says what turning it off gives back.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function module_labels(): array {
		return [
			'price_html'        => [
				__( 'Price HTML', 'pricecloak-for-woocommerce' ),
				__( 'Prices on the shop, product pages, blocks and widgets; cart and checkout totals; the Mini Cart subtotal; grouped product labels; a guest\'s own order-received, order-pay and order tracking pages.', 'pricecloak-for-woocommerce' ),
			],
			'variation_payload' => [
				__( 'Variation payload', 'pricecloak-for-woocommerce' ),
				__( 'Prices in the variation data a variable product page sends to the browser, inline and over wc-ajax=get_variation.', 'pricecloak-for-woocommerce' ),
			],
			'structured_data'   => [
				__( 'Structured data', 'pricecloak-for-woocommerce' ),
				__( 'The offers node in the product JSON-LD search engines read.', 'pricecloak-for-woocommerce' ),
			],
			'store_api'         => [
				__( 'Store API', 'pricecloak-for-woocommerce' ),
				__( 'Amounts in the Store API products, cart, checkout, order and batch responses (versioned and unversioned) and in the copies WooCommerce embeds into its block pages.', 'pricecloak-for-woocommerce' ),
			],
			'legacy_rest'       => [
				__( 'Legacy REST API', 'pricecloak-for-woocommerce' ),
				__( 'Prices in the /wc/v3/products and variations responses (and v1/v2), where a site lets guests read them.', 'pricecloak-for-woocommerce' ),
			],
			'price_filters'     => [
				__( 'Price filters', 'pricecloak-for-woocommerce' ),
				__( 'Price-range filtering and price sorting on the shop, the Store API and the /wp/v2/product route, and the price filter widget and blocks, which let a guest infer a price.', 'pricecloak-for-woocommerce' ),
			],
		];
	}
}
