<?php
/**
 * Price HTML module: guests get the replacement markup on every price filter,
 * logged-in users get the original HTML untouched.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit\Modules;

use PriceCloak\Modules;
use PriceCloak\Modules\PriceHtml;
use PriceCloak\Options;
use PHPUnit\Framework\TestCase;

final class PriceHtmlTest extends TestCase {
	private const PRICE = '<span class="woocommerce-Price-amount amount">&#36;12.00</span>';

	protected function setUp(): void {
		pricecloak_test_reset();
		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['REQUEST_URI'] = '/shop/page/2/?orderby=price';
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
	}

	public function test_id_is_price_html(): void {
		$this->assertSame( 'price_html', ( new PriceHtml() )->id() );
	}

	public function test_module_is_in_the_registry(): void {
		$ids = array_map( static fn( $m ) => $m->id(), Modules::all() );
		$this->assertContains( 'price_html', $ids );
	}

	public function test_register_adds_every_price_filter_at_max_priority(): void {
		( new PriceHtml() )->register();

		foreach ( PriceHtml::FILTERS as $hook ) {
			$this->assertArrayHasKey( $hook, $GLOBALS['pricecloak_test']['filters'], "Missing filter $hook." );
			$this->assertSame( PHP_INT_MAX, $GLOBALS['pricecloak_test']['filters'][ $hook ][0][1], "Wrong priority on $hook." );
		}
		$this->assertContains( 'woocommerce_get_price_html', PriceHtml::FILTERS );
		$this->assertContains( 'woocommerce_variable_price_html', PriceHtml::FILTERS );
		$this->assertContains( 'woocommerce_grouped_price_html', PriceHtml::FILTERS );
		$this->assertContains( 'woocommerce_variation_price_html', PriceHtml::FILTERS );
		$this->assertContains( 'woocommerce_get_variation_price_html', PriceHtml::FILTERS );
		$this->assertContains( 'woocommerce_cart_item_price', PriceHtml::FILTERS );
		$this->assertContains( 'woocommerce_cart_item_subtotal', PriceHtml::FILTERS );
	}

	public function test_unregister_removes_exactly_what_register_added(): void {
		$module = new PriceHtml();
		$module->register();
		$module->unregister();

		$this->assertSame( [], $GLOBALS['pricecloak_test']['filters'], 'unregister() must leave no filter behind.' );
	}

	public function test_guest_gets_a_login_link(): void {
		( new PriceHtml() )->register();

		$html = apply_filters( 'woocommerce_get_price_html', self::PRICE );

		$this->assertStringContainsString( 'class="pricecloak-login-link"', $html );
		$this->assertStringContainsString( 'Sign in to see prices', $html );
		$this->assertStringNotContainsString( 'woocommerce-Price-amount', $html );
	}

	public function test_redirect_target_is_the_current_request_url(): void {
		( new PriceHtml() )->register();

		$html = apply_filters( 'woocommerce_get_price_html', self::PRICE );

		$this->assertStringContainsString(
			'redirect_to=' . rawurlencode( 'http://example.test/shop/page/2/?orderby=price' ),
			html_entity_decode( $html, ENT_QUOTES, 'UTF-8' )
		);
	}

	public function test_guest_gets_a_span_when_linking_is_off(): void {
		update_option( Options::LINK_TO_LOGIN, 'no' );
		( new PriceHtml() )->register();

		$html = apply_filters( 'woocommerce_get_price_html', self::PRICE );

		$this->assertSame( '<span class="pricecloak-price-hidden">Sign in to see prices</span>', $html );
	}

	public function test_replacement_text_is_escaped(): void {
		update_option( Options::REPLACEMENT_TEXT, 'Prices <b>hidden</b> & "quiet"' );
		update_option( Options::LINK_TO_LOGIN, 'no' );
		( new PriceHtml() )->register();

		$html = apply_filters( 'woocommerce_get_price_html', self::PRICE );

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;', $html );
		$this->assertStringContainsString( '&amp;', $html );
	}

	public function test_logged_in_user_gets_the_original_html(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new PriceHtml() )->register();

		foreach ( PriceHtml::FILTERS as $hook ) {
			$this->assertSame( self::PRICE, apply_filters( $hook, self::PRICE ), "Filter $hook altered a logged-in price." );
		}
	}

	public function test_every_filter_replaces_for_a_guest(): void {
		( new PriceHtml() )->register();

		foreach ( PriceHtml::FILTERS as $hook ) {
			$this->assertStringNotContainsString(
				'woocommerce-Price-amount',
				(string) apply_filters( $hook, self::PRICE ),
				"Filter $hook let a price through."
			);
		}
	}

	// -- cart and checkout totals (T16) ------------------------------------

	private const SHIPPING_LABEL = 'Flat rate: <span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol" translate="no">&#36;</span>10.00</bdi></span> <small class="tax_label">(ex. VAT)</small>';

	public function test_cart_and_checkout_totals_filters_are_hooked(): void {
		foreach ( [
			'woocommerce_cart_total',
			'woocommerce_cart_contents_total',
			'woocommerce_cart_totals_order_total_html',
			'woocommerce_cart_totals_fee_html',
			'woocommerce_cart_totals_taxes_total_html',
			'woocommerce_coupon_discount_amount_html',
		] as $hook ) {
			$this->assertContains( $hook, PriceHtml::FILTERS, "$hook is not a whole-value price filter." );
		}
		foreach ( [
			'woocommerce_cart_shipping_method_full_label',
			'woocommerce_cart_totals_coupon_html',
			'woocommerce_widget_cart_item_quantity',
			'woocommerce_coupon_error',
		] as $hook ) {
			$this->assertContains( $hook, PriceHtml::SCRUB_FILTERS, "$hook is not a scrubbed filter." );
		}

		( new PriceHtml() )->register();

		foreach ( [ ...PriceHtml::SCRUB_FILTERS, 'woocommerce_cart_tax_totals', 'woocommerce_grouped_product_list_column_quantity', 'render_block', 'template_redirect', 'woocommerce_blocks_product_grid_add_to_cart_attributes', 'woocommerce_track_order' ] as $hook ) {
			$this->assertArrayHasKey( $hook, $GLOBALS['pricecloak_test']['filters'], "Missing hook $hook." );
		}
	}

	// -- product grid buttons (T20) ----------------------------------------

	private function grid_button_attributes(): array {
		return [
			'aria-label'         => 'Add to cart: “Probe Simple Product”',
			'data-quantity'      => '1',
			'data-product_id'    => 10,
			'data-product_sku'   => 'pricecloak-simple',
			'data-price'         => '123.45',
			'data-display_price' => '123.45',
			'rel'                => 'nofollow',
			'class'              => 'wp-block-button__link add_to_cart_button ajax_add_to_cart',
		];
	}

	public function test_grid_add_to_cart_button_loses_every_price_attribute_for_a_guest(): void {
		( new PriceHtml() )->register();

		$attributes = apply_filters( 'woocommerce_blocks_product_grid_add_to_cart_attributes', $this->grid_button_attributes(), null );

		$this->assertSame(
			[
				'aria-label'       => 'Add to cart: “Probe Simple Product”',
				'data-quantity'    => '1',
				'data-product_id'  => 10,
				'data-product_sku' => 'pricecloak-simple',
				'rel'              => 'nofollow',
				'class'            => 'wp-block-button__link add_to_cart_button ajax_add_to_cart',
			],
			$attributes,
			'data-price (wc_get_price_to_display) and any other price-named attribute go; the rest stays in order.'
		);
	}

	public function test_grid_add_to_cart_button_is_untouched_for_a_member_and_odd_input_passes_through(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new PriceHtml() )->register();
		$this->assertSame( $this->grid_button_attributes(), apply_filters( 'woocommerce_blocks_product_grid_add_to_cart_attributes', $this->grid_button_attributes(), null ) );

		$GLOBALS['pricecloak_test']['logged_in'] = false;
		$this->assertSame( 'not attributes', apply_filters( 'woocommerce_blocks_product_grid_add_to_cart_attributes', 'not attributes', null ) );
	}

	public function test_order_total_html_is_replaced_whole_for_a_guest(): void {
		( new PriceHtml() )->register();

		$html = apply_filters( 'woocommerce_cart_totals_order_total_html', '<strong>' . self::PRICE . '</strong> <small class="includes_tax">(includes ' . self::PRICE . ' VAT)</small>' );

		$this->assertStringNotContainsString( 'woocommerce-Price-amount', $html );
		$this->assertStringNotContainsString( '12.00', $html );
		$this->assertStringContainsString( 'Sign in to see prices', $html );
	}

	public function test_shipping_method_label_keeps_its_name_and_loses_the_amount(): void {
		( new PriceHtml() )->register();

		$label = apply_filters( 'woocommerce_cart_shipping_method_full_label', self::SHIPPING_LABEL, (object) [ 'id' => 'flat_rate:1' ] );

		$this->assertStringStartsWith( 'Flat rate: ', $label );
		$this->assertStringContainsString( 'Sign in to see prices', $label );
		$this->assertStringContainsString( '(ex. VAT)', $label );
		$this->assertStringNotContainsString( 'woocommerce-Price-amount', $label );
		$this->assertStringNotContainsString( '10.00', $label );
	}

	public function test_scrub_replaces_every_amount_in_a_mixed_string(): void {
		$html = 'From ' . self::PRICE . ' to ' . self::PRICE . ' [Remove]';

		$scrubbed = PriceHtml::scrub( $html );

		$this->assertSame( 0, substr_count( $scrubbed, 'woocommerce-Price-amount' ) );
		$this->assertSame( 2, substr_count( $scrubbed, 'Sign in to see prices' ) );
		$this->assertStringEndsWith( ' [Remove]', $scrubbed );
	}

	public function test_scrubbed_filters_leave_a_logged_in_string_alone(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new PriceHtml() )->register();

		foreach ( PriceHtml::SCRUB_FILTERS as $hook ) {
			$this->assertSame( self::SHIPPING_LABEL, apply_filters( $hook, self::SHIPPING_LABEL ), "$hook altered a logged-in value." );
		}
	}

	public function test_widget_cart_item_quantity_keeps_the_quantity(): void {
		( new PriceHtml() )->register();

		$html = apply_filters( 'woocommerce_widget_cart_item_quantity', '<span class="quantity">3 &times; ' . self::PRICE . '</span>', [ 'quantity' => 3 ], 'abc' );

		$this->assertStringContainsString( '3 &times; ', $html );
		$this->assertStringNotContainsString( 'woocommerce-Price-amount', $html );
	}

	public function test_cart_tax_totals_get_the_replacement_as_formatted_amount(): void {
		( new PriceHtml() )->register();
		$vat                   = new \stdClass();
		$vat->amount           = 2.4;
		$vat->label            = 'VAT';
		$vat->formatted_amount = self::PRICE;

		$totals = apply_filters( 'woocommerce_cart_tax_totals', [ 'GB-VAT-1' => $vat ], null );

		$this->assertStringContainsString( 'Sign in to see prices', $totals['GB-VAT-1']->formatted_amount );
		$this->assertStringNotContainsString( 'woocommerce-Price-amount', $totals['GB-VAT-1']->formatted_amount );
		$this->assertSame( 2.4, $totals['GB-VAT-1']->amount, 'The numeric amount is arithmetic input and must survive.' );
		$this->assertSame( 'VAT', $totals['GB-VAT-1']->label );
	}

	public function test_cart_tax_totals_are_untouched_for_a_logged_in_user(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new PriceHtml() )->register();
		$vat                   = new \stdClass();
		$vat->formatted_amount = self::PRICE;

		$totals = apply_filters( 'woocommerce_cart_tax_totals', [ 'x' => $vat ], null );

		$this->assertSame( self::PRICE, $totals['x']->formatted_amount );
	}

	// -- grouped product label ---------------------------------------------

	private function grouped_checkbox_column(): string {
		return '<input type="checkbox" name="quantity[10]" value="1" class="wc-grouped-product-add-to-cart-checkbox" id="quantity-10" />'
			. '<label for="quantity-10" class="screen-reader-text">Buy one of Probe Simple Product for &#036;123.45</label>';
	}

	private function grouped_child( string $name = 'Probe Simple Product' ): object {
		return new class( $name ) {
			public function __construct( private string $name ) {}
			public function get_name(): string {
				return $this->name;
			}
		};
	}

	public function test_grouped_sold_individually_label_loses_the_amount_for_a_guest(): void {
		( new PriceHtml() )->register();

		$html = apply_filters( 'woocommerce_grouped_product_list_column_quantity', $this->grouped_checkbox_column(), $this->grouped_child() );

		$this->assertStringContainsString( 'class="wc-grouped-product-add-to-cart-checkbox"', $html );
		$this->assertStringContainsString( '<label for="quantity-10" class="screen-reader-text">Buy one of Probe Simple Product</label>', $html );
		$this->assertStringNotContainsString( '123.45', $html );
		$this->assertStringNotContainsString( ' for ', $html );
	}

	public function test_grouped_label_escapes_the_product_name(): void {
		( new PriceHtml() )->register();

		$html = apply_filters( 'woocommerce_grouped_product_list_column_quantity', $this->grouped_checkbox_column(), $this->grouped_child( 'Tom & Jerry <b>$1</b>' ) );

		$this->assertStringContainsString( 'Buy one of Tom &amp; Jerry &lt;b&gt;$1&lt;/b&gt;</label>', $html );
	}

	public function test_grouped_quantity_input_column_is_untouched(): void {
		( new PriceHtml() )->register();
		$input = '<div class="quantity"><input type="number" name="quantity[10]" value="" /></div>';

		$this->assertSame( $input, apply_filters( 'woocommerce_grouped_product_list_column_quantity', $input, $this->grouped_child() ) );
	}

	public function test_grouped_label_is_untouched_for_a_logged_in_user(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new PriceHtml() )->register();

		$this->assertSame( $this->grouped_checkbox_column(), apply_filters( 'woocommerce_grouped_product_list_column_quantity', $this->grouped_checkbox_column(), $this->grouped_child() ) );
	}

	// -- Mini Cart block state ---------------------------------------------

	public function test_mini_cart_block_state_is_overridden_for_a_guest(): void {
		( new PriceHtml() )->register();
		wp_interactivity_state(
			'woocommerce/mini-cart',
			[
				'totalItemsInCart'  => 1,
				'formattedSubtotal' => '$123.45',
			]
		);
		wp_interactivity_state( 'woocommerce/mini-cart-footer-block', [ 'formattedSubtotal' => '$123.45' ] );

		$content = '<div data-wp-interactive="woocommerce/mini-cart"></div>';
		$this->assertSame( $content, apply_filters( 'render_block', $content, [ 'blockName' => 'woocommerce/mini-cart' ] ) );
		$this->assertSame( '', apply_filters( 'render_block', '', [ 'blockName' => 'woocommerce/mini-cart-footer-block' ] ) );

		$state = $GLOBALS['pricecloak_test']['interactivity_state'];
		$this->assertSame( 'Sign in to see prices', $state['woocommerce/mini-cart']['formattedSubtotal'] );
		$this->assertSame( 'Sign in to see prices', $state['woocommerce/mini-cart-footer-block']['formattedSubtotal'] );
		$this->assertSame( 1, $state['woocommerce/mini-cart']['totalItemsInCart'], 'Only the subtotal is overridden.' );
	}

	public function test_mini_cart_state_override_is_plain_text_even_when_linking(): void {
		update_option( Options::REPLACEMENT_TEXT, 'Members <only>' );
		( new PriceHtml() )->register();

		apply_filters( 'render_block', '', [ 'blockName' => 'woocommerce/mini-cart' ] );

		$this->assertSame( 'Members', $GLOBALS['pricecloak_test']['interactivity_state']['woocommerce/mini-cart']['formattedSubtotal'] );
	}

	public function test_mini_cart_state_is_untouched_for_a_logged_in_user_and_other_blocks(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new PriceHtml() )->register();
		apply_filters( 'render_block', '', [ 'blockName' => 'woocommerce/mini-cart' ] );
		$this->assertArrayNotHasKey( 'interactivity_state', $GLOBALS['pricecloak_test'] );

		$GLOBALS['pricecloak_test']['logged_in'] = false;
		apply_filters( 'render_block', '', [ 'blockName' => 'woocommerce/product-price' ] );
		apply_filters( 'render_block', '', null );
		$this->assertArrayNotHasKey( 'interactivity_state', $GLOBALS['pricecloak_test'] );
	}

	// -- order pages -------------------------------------------------------

	private const ORDER_HOOKS = [
		'woocommerce_order_formatted_line_subtotal',
		'woocommerce_get_formatted_order_total',
		'woocommerce_get_order_item_totals',
	];

	private function order_hooks_present(): array {
		return array_values( array_intersect( self::ORDER_HOOKS, array_keys( $GLOBALS['pricecloak_test']['filters'] ) ) );
	}

	public function test_order_filters_are_not_hooked_by_register_alone(): void {
		( new PriceHtml() )->register();

		$this->assertSame( [], $this->order_hooks_present(), 'Order filters must wait for template_redirect page gating.' );
	}

	public function test_order_filters_are_hooked_on_the_order_received_page_for_a_guest(): void {
		$GLOBALS['pricecloak_test']['is_order_received'] = true;
		$module = new PriceHtml();
		$module->register();

		$module->maybe_hook_order_pages();

		$this->assertSame( self::ORDER_HOOKS, $this->order_hooks_present() );
	}

	public function test_order_filters_are_hooked_on_the_pay_page_for_a_guest(): void {
		$GLOBALS['pricecloak_test']['is_checkout_pay'] = true;
		$module                                        = new PriceHtml();
		$module->register();

		$module->maybe_hook_order_pages();

		$this->assertSame( self::ORDER_HOOKS, $this->order_hooks_present() );
	}

	public function test_order_filters_stay_off_elsewhere_and_for_logged_in_shoppers(): void {
		$module = new PriceHtml();
		$module->register();
		$GLOBALS['pricecloak_test']['is_cart'] = true;
		$module->maybe_hook_order_pages();
		$this->assertSame( [], $this->order_hooks_present(), 'The cart page is not an order page.' );

		$GLOBALS['pricecloak_test']['is_order_received'] = true;
		$GLOBALS['pricecloak_test']['logged_in']         = true;
		$module->maybe_hook_order_pages();
		$this->assertSame( [], $this->order_hooks_present(), 'A logged-in customer sees their order.' );
	}

	public function test_order_filters_are_hooked_when_a_guest_tracks_an_order(): void {
		$module = new PriceHtml();
		$module->register();

		do_action( 'woocommerce_track_order', 42 );

		$this->assertSame( self::ORDER_HOOKS, $this->order_hooks_present(), 'The [woocommerce_order_tracking] shortcode renders the order details table right after this action.' );
		$this->assertStringContainsString( 'Sign in to see prices', (string) apply_filters( 'woocommerce_get_formatted_order_total', self::PRICE, null ) );

		do_action( 'woocommerce_track_order', 42 );
		$this->assertCount( 1, $GLOBALS['pricecloak_test']['filters']['woocommerce_get_formatted_order_total'], 'Tracking twice hooks once.' );

		$module->unregister();
		$this->assertSame( [], $GLOBALS['pricecloak_test']['filters'] );
	}

	public function test_order_tracking_leaves_a_members_order_alone(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		( new PriceHtml() )->register();

		do_action( 'woocommerce_track_order', 42 );

		$this->assertSame( [], $this->order_hooks_present() );
	}

	public function test_unregister_removes_the_order_filters_too(): void {
		$GLOBALS['pricecloak_test']['is_order_received'] = true;
		$module = new PriceHtml();
		$module->register();
		$module->maybe_hook_order_pages();

		$module->unregister();

		$this->assertSame( [], $GLOBALS['pricecloak_test']['filters'] );
	}

	public function test_order_line_subtotal_and_total_are_replaced_on_an_order_page(): void {
		$GLOBALS['pricecloak_test']['is_order_received'] = true;
		$module = new PriceHtml();
		$module->register();
		$module->maybe_hook_order_pages();

		foreach ( [ 'woocommerce_order_formatted_line_subtotal', 'woocommerce_get_formatted_order_total' ] as $hook ) {
			$html = (string) apply_filters( $hook, self::PRICE, null, null );
			$this->assertStringNotContainsString( 'woocommerce-Price-amount', $html, "$hook let the amount through." );
			$this->assertStringContainsString( 'Sign in to see prices', $html );
		}
	}

	public function test_order_item_totals_rows_are_blanked_except_the_payment_method(): void {
		$GLOBALS['pricecloak_test']['is_checkout_pay'] = true;
		$module                                        = new PriceHtml();
		$module->register();
		$module->maybe_hook_order_pages();
		$rows = [
			'cart_subtotal'  => [
				'type'  => 'subtotal',
				'label' => 'Subtotal:',
				'value' => self::PRICE,
			],
			'shipping'       => [
				'type'  => 'shipping',
				'label' => 'Shipping:',
				'value' => self::PRICE . ' <small>via Flat rate</small>',
			],
			'payment_method' => [
				'type'  => 'payment_method',
				'label' => 'Payment method:',
				'value' => 'Cash on delivery',
			],
			'order_total'    => [
				'type'  => 'total',
				'label' => 'Total:',
				'value' => self::PRICE,
			],
		];

		$out = apply_filters( 'woocommerce_get_order_item_totals', $rows, null, 'excl' );

		$this->assertSame( array_keys( $rows ), array_keys( $out ), 'Rows keep their order and keys.' );
		foreach ( [ 'cart_subtotal', 'shipping', 'order_total' ] as $key ) {
			$this->assertStringNotContainsString( 'woocommerce-Price-amount', $out[ $key ]['value'], "$key kept its amount." );
			$this->assertStringContainsString( 'Sign in to see prices', $out[ $key ]['value'] );
			$this->assertSame( $rows[ $key ]['label'], $out[ $key ]['label'] );
		}
		$this->assertSame( 'Cash on delivery', $out['payment_method']['value'] );
	}

	public function test_order_filters_leave_an_email_alone_even_on_an_order_page(): void {
		$GLOBALS['pricecloak_test']['is_order_received'] = true;
		$module = new PriceHtml();
		$module->register();
		$module->maybe_hook_order_pages();
		$GLOBALS['pricecloak_test']['doing_actions'] = [ 'woocommerce_email_order_details' ];
		$rows                                        = [
			'order_total' => [
				'type'  => 'total',
				'label' => 'Total:',
				'value' => self::PRICE,
			],
		];

		$this->assertSame( self::PRICE, apply_filters( 'woocommerce_get_formatted_order_total', self::PRICE, null ) );
		$this->assertSame( self::PRICE, apply_filters( 'woocommerce_order_formatted_line_subtotal', self::PRICE, null, null ) );
		$this->assertSame( $rows, apply_filters( 'woocommerce_get_order_item_totals', $rows, null, 'excl' ) );
	}
	// -- grouped product: Add to Cart with Options block --------------------

	private function grouped_selector_block(): string {
		return '<div class="wp-block-woocommerce-add-to-cart-with-options-grouped-product-item-selector">'
			. '<input type="checkbox" name="quantity[10]" value="1" class="wc-grouped-product-add-to-cart-checkbox" id="quantity_10" data-wp-interactive="woocommerce/add-to-cart-with-options-quantity-selector" data-wp-on--change="actions.handleQuantityCheckboxChange" data-wp-context=\'{"productId":10,"variationId":null}\' aria-label="Buy one of Probe &lt;b&gt;Simple&lt;/b&gt; for &#036;123.45"/>'
			. '</div>';
	}

	public function test_grouped_selector_block_checkbox_label_loses_the_amount_for_a_guest(): void {
		$GLOBALS['pricecloak_test']['products'][10] = new \PriceCloakFakeProduct( 'Probe <b>Simple</b>' );
		( new PriceHtml() )->register();

		$html = apply_filters( 'render_block', $this->grouped_selector_block(), [ 'blockName' => PriceHtml::GROUPED_SELECTOR_BLOCK ] );

		$this->assertStringContainsString( 'aria-label="Buy one of Probe &lt;b&gt;Simple&lt;/b&gt;"', $html );
		$this->assertStringNotContainsString( '123.45', $html );
		$this->assertStringContainsString( 'name="quantity[10]"', $html );
		$this->assertStringContainsString( 'data-wp-on--change="actions.handleQuantityCheckboxChange"', $html, 'The directives stay.' );
	}

	public function test_grouped_selector_block_label_keeps_a_name_with_dollar_signs_and_backslashes(): void {
		$GLOBALS['pricecloak_test']['products'][10] = new \PriceCloakFakeProduct( '$25 Gift Card \\ Special ${1} \\1' );
		( new PriceHtml() )->register();

		$html = apply_filters( 'render_block', $this->grouped_selector_block(), [ 'blockName' => PriceHtml::GROUPED_SELECTOR_BLOCK ] );

		$this->assertStringContainsString( 'aria-label="Buy one of $25 Gift Card \\ Special ${1} \\1"', $html, 'A name is not a replacement pattern: no backreference expansion.' );
		$this->assertStringNotContainsString( '123.45', $html );
	}

	public function test_grouped_selector_block_on_sale_label_and_unknown_product(): void {
		( new PriceHtml() )->register();
		$sale = str_replace( 'aria-label="Buy one of Probe &lt;b&gt;Simple&lt;/b&gt; for &#036;123.45"', 'aria-label="Buy one of Probe Simple on sale for &#036;99.00, original price was &#036;123.45"', $this->grouped_selector_block() );

		$html = apply_filters( 'render_block', $sale, [ 'blockName' => PriceHtml::GROUPED_SELECTOR_BLOCK ] );

		// No product registered under id 10: the generic label, still no amount.
		$this->assertStringContainsString( 'aria-label="Buy one"', $html );
		$this->assertStringNotContainsString( '99.00', $html );
		$this->assertStringNotContainsString( '123.45', $html );
	}

	public function test_grouped_selector_block_is_untouched_for_members_other_blocks_and_buttons(): void {
		$original = $this->grouped_selector_block();
		$module   = new PriceHtml();

		$this->assertSame( $original, $module->scrub_grouped_selector_block( $original, [ 'blockName' => 'woocommerce/product-button' ] ) );
		$this->assertSame( $original, $module->scrub_grouped_selector_block( $original, null ) );

		$button = '<a href="?add-to-cart=10" class="button">Add to cart</a>';
		$this->assertSame( $button, $module->scrub_grouped_selector_block( $button, [ 'blockName' => PriceHtml::GROUPED_SELECTOR_BLOCK ] ), 'A child rendered as a button has no label to scrub.' );

		$GLOBALS['pricecloak_test']['logged_in'] = true;
		$this->assertSame( $original, $module->scrub_grouped_selector_block( $original, [ 'blockName' => PriceHtml::GROUPED_SELECTOR_BLOCK ] ) );
	}
}
