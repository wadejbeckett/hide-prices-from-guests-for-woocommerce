<?php
/**
 * Module 1: price HTML. Every filter through which WooCommerce returns a
 * rendered price string is replaced, for guests, with the replacement markup:
 * catalogue and product prices, cart and checkout totals, the Mini Cart
 * block's subtotal state, the grouped product's sold-individually label
 * (classic template and block alike), the product grid blocks' add-to-cart
 * button attributes, and -- on the order-received and order-pay pages and
 * the order tracking result only -- an order's line subtotals and totals.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Modules;

use PriceCloak\Context;
use PriceCloak\Module;
use PriceCloak\Options;
use PriceCloak\Replacement;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces rendered price HTML for logged-out visitors.
 *
 * `wc_price()` itself is deliberately not filtered. It is one function for
 * every amount WooCommerce ever formats, and the request that renders a
 * guest's checkout also builds the admin "New order" email and the customer's
 * own receipt through it; a blanket filter would blank those too. Each
 * surface is hooked by the filter WooCommerce runs *at that surface* instead,
 * and every callback asks Context::is_guest(), which is false while an
 * email renders, so an email built in the same request is never touched.
 */
final class PriceHtml implements Module {
	public const ID = 'price_html';

	/**
	 * Every filter whose whole value is a rendered price. The value is
	 * replaced outright.
	 *
	 * `woocommerce_get_price_html` covers the product classes' own output, so
	 * the type-specific filters below are strictly belt and braces: they run
	 * inside `get_price_html()` and a theme or plugin may call them directly.
	 * The `variation_*` names are legacy (absent from current WooCommerce) and
	 * are hooked so older stacks and back-ported themes are covered too.
	 *
	 * The cart and checkout totals -- `woocommerce_cart_total` (order total,
	 * `WC_Cart::get_total()`), `woocommerce_cart_contents_total`, the
	 * `wc_cart_totals_*_html()` template functions and the coupon discount --
	 * are what the classic cart-totals and review-order templates print and
	 * what `?wc-ajax=get_cart_totals`, `get_refreshed_fragments` and
	 * `update_order_review` re-render.
	 *
	 * @var string[]
	 */
	public const FILTERS = [
		'woocommerce_get_price_html',
		'woocommerce_empty_price_html',
		'woocommerce_free_price_html',
		'woocommerce_variable_price_html',
		'woocommerce_variable_empty_price_html',
		'woocommerce_variable_free_price_html',
		'woocommerce_grouped_price_html',
		'woocommerce_grouped_empty_price_html',
		'woocommerce_grouped_free_price_html',
		'woocommerce_variation_price_html',
		'woocommerce_variation_empty_price_html',
		'woocommerce_get_variation_price_html',
		'woocommerce_cart_item_price',
		'woocommerce_cart_item_subtotal',
		'woocommerce_cart_product_price',
		'woocommerce_cart_product_subtotal',
		'woocommerce_cart_subtotal',
		'woocommerce_cart_total',
		'woocommerce_cart_contents_total',
		'woocommerce_cart_totals_order_total_html',
		'woocommerce_cart_totals_fee_html',
		'woocommerce_cart_totals_taxes_total_html',
		'woocommerce_coupon_discount_amount_html',
	];

	/**
	 * Filters whose value mixes a price with content worth keeping -- a
	 * shipping method's name ("Flat rate: $10"), the coupon row's [Remove]
	 * link, the mini-cart line's quantity ("3 × $10"), a coupon's validation
	 * error ("The minimum spend for this coupon is $50.00.", built with
	 * `wc_price()` in `WC_Discounts` and `WC_Coupon` and shown to whoever
	 * applies the code on the cart or checkout). Each `wc_price()` span inside
	 * the value is replaced in place; the rest stays.
	 *
	 * @var string[]
	 */
	public const SCRUB_FILTERS = [
		'woocommerce_cart_shipping_method_full_label',
		'woocommerce_cart_totals_coupon_html',
		'woocommerce_widget_cart_item_quantity',
		'woocommerce_coupon_error',
	];

	/**
	 * Blocks that register the cart subtotal as Interactivity API state.
	 *
	 * Both compute it with `wc_price()` and register it as `formattedSubtotal`
	 * in their own namespace, and it is printed into every page's
	 * `@wordpress/interactivity` script-module data whether or not the block
	 * shows it. The Mini Cart block's `buttonAriaLabel` is a derived closure
	 * that reads `formattedSubtotal` at directive-processing time, so
	 * overriding the one value also keeps the amount out of the aria-label.
	 *
	 * @var string[]
	 */
	public const MINI_CART_STATE_BLOCKS = [
		'woocommerce/mini-cart',
		'woocommerce/mini-cart-footer-block',
	];

	/**
	 * The block-theme counterpart of the grouped template's checkbox column.
	 * `GroupedProductItemSelector::get_checkbox_markup()` (the Add to Cart
	 * with Options family, WooCommerce 11.1) builds "Buy one of %1$s for %2$s"
	 * -- or the on-sale variant with two amounts -- with `wc_price()` directly
	 * and prints it as the checkbox's `aria-label`; no filter runs on the way,
	 * so the rendered block is post-processed instead.
	 */
	public const GROUPED_SELECTOR_BLOCK = 'woocommerce/add-to-cart-with-options-grouped-product-item-selector';

	/**
	 * The filter `AbstractProductGrid::get_add_to_cart()` (Product New, On
	 * Sale, Best Sellers, Top Rated, By Category, By Tag, By Attribute,
	 * Hand-picked; WooCommerce 11.1) runs over its add-to-cart button's
	 * attributes, which carry `data-price="<wc_get_price_to_display()>"` --
	 * the amount as a bare number, printed into the grid's HTML on every page
	 * carrying one of those blocks, the default Cart page's empty-cart
	 * "New in store" grid included. The grid's visible price goes through
	 * `get_price_html()` and is replaced above; this attribute has no other
	 * filter on the way.
	 */
	public const GRID_BUTTON_ATTRIBUTES_FILTER = 'woocommerce_blocks_product_grid_add_to_cart_attributes';

	/**
	 * An attribute name that carries an amount: `data-price` today, and
	 * whatever a theme or plugin adds through the same filter at a lower
	 * priority under a price-ish name.
	 */
	private const PRICE_ATTRIBUTE = '#price#i';

	/**
	 * The action `[woocommerce_order_tracking]`
	 * (`WC_Shortcode_Order_Tracking::output()`) fires, with the order id,
	 * right before it renders `order/tracking.php`, whose
	 * `woocommerce_view_order` action prints the order details table -- the
	 * same amounts as the order-received page, reached by a guest with the
	 * order number and billing email instead of the order key.
	 */
	public const TRACK_ORDER_ACTION = 'woocommerce_track_order';

	/**
	 * The three order-level filters the order-received and order-pay
	 * templates (`checkout/thankyou.php`, `order/order-details.php`,
	 * `order/order-details-item.php`, `checkout/form-pay.php`), the Order
	 * Confirmation blocks and the order tracking result print prices through.
	 *
	 * @var string[]
	 */
	public const ORDER_FILTERS = [
		'woocommerce_order_formatted_line_subtotal',
		'woocommerce_get_formatted_order_total',
		'woocommerce_get_order_item_totals',
	];

	/**
	 * The `wc_price()` wrapper: an amount span holding, optionally inside a
	 * `<bdi>`, the number and the currency-symbol span in either order (the
	 * currency position setting puts the symbol before or after the digits).
	 * Older markup without the `<bdi>` matches too.
	 */
	private const PRICE_SPAN = '#<span class="woocommerce-Price-amount amount"[^>]*>(?:<bdi>)?(?:[^<]|<span class="woocommerce-Price-currencySymbol"[^>]*>[^<]*</span>)*(?:</bdi>)?</span>#s';

	/**
	 * Whether the order filters are currently attached, so unregister() can
	 * take back exactly what maybe_hook_order_pages() or hook_order_tracking()
	 * added.
	 *
	 * @var bool
	 */
	private bool $order_filters_hooked = false;

	/**
	 * Stable identifier.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Hook every price filter last, so anything that formats a price earlier
	 * (WooCommerce itself, a currency switcher, a B2B plugin) has already run.
	 */
	public function register(): void {
		foreach ( self::FILTERS as $filter ) {
			add_filter( $filter, [ $this, 'replace' ], PHP_INT_MAX );
		}
		foreach ( self::SCRUB_FILTERS as $filter ) {
			add_filter( $filter, [ $this, 'scrub_for_guest' ], PHP_INT_MAX );
		}
		add_filter( 'woocommerce_cart_tax_totals', [ $this, 'replace_tax_totals' ], PHP_INT_MAX );
		add_filter( 'woocommerce_grouped_product_list_column_quantity', [ $this, 'replace_grouped_label' ], PHP_INT_MAX, 2 );
		add_filter( 'render_block', [ $this, 'override_mini_cart_state' ], PHP_INT_MAX, 2 );
		add_filter( 'render_block', [ $this, 'scrub_grouped_selector_block' ], PHP_INT_MAX, 2 );
		add_filter( self::GRID_BUTTON_ATTRIBUTES_FILTER, [ $this, 'drop_grid_button_price' ], PHP_INT_MAX, 2 );
		add_action( 'template_redirect', [ $this, 'maybe_hook_order_pages' ] );
		add_action( self::TRACK_ORDER_ACTION, [ $this, 'hook_order_tracking' ] );
	}

	/**
	 * Remove exactly what register() added, and whatever maybe_hook_order_pages()
	 * added on top.
	 */
	public function unregister(): void {
		foreach ( self::FILTERS as $filter ) {
			remove_filter( $filter, [ $this, 'replace' ], PHP_INT_MAX );
		}
		foreach ( self::SCRUB_FILTERS as $filter ) {
			remove_filter( $filter, [ $this, 'scrub_for_guest' ], PHP_INT_MAX );
		}
		remove_filter( 'woocommerce_cart_tax_totals', [ $this, 'replace_tax_totals' ], PHP_INT_MAX );
		remove_filter( 'woocommerce_grouped_product_list_column_quantity', [ $this, 'replace_grouped_label' ], PHP_INT_MAX );
		remove_filter( 'render_block', [ $this, 'override_mini_cart_state' ], PHP_INT_MAX );
		remove_filter( 'render_block', [ $this, 'scrub_grouped_selector_block' ], PHP_INT_MAX );
		remove_filter( self::GRID_BUTTON_ATTRIBUTES_FILTER, [ $this, 'drop_grid_button_price' ], PHP_INT_MAX );
		remove_action( 'template_redirect', [ $this, 'maybe_hook_order_pages' ] );
		remove_action( self::TRACK_ORDER_ACTION, [ $this, 'hook_order_tracking' ] );
		$this->unhook_order_pages();
	}

	/**
	 * Filter callback: guests get the replacement, everyone else the original.
	 *
	 * @param mixed $price_html Price HTML as built so far.
	 * @return mixed
	 */
	public function replace( mixed $price_html ): mixed {
		return Context::is_guest() ? Replacement::markup() : $price_html;
	}

	// -- mixed values -----------------------------------------------------

	/**
	 * Filter callback for the mixed-content filters.
	 *
	 * @param mixed $html Value as built so far.
	 * @return mixed
	 */
	public function scrub_for_guest( mixed $html ): mixed {
		if ( ! Context::is_guest() || ! is_string( $html ) ) {
			return $html;
		}
		return self::scrub( $html );
	}

	/**
	 * Replace every `wc_price()` span in a string with the replacement markup,
	 * leaving everything around it alone.
	 *
	 * @param string $html Markup that may contain one or more rendered prices.
	 */
	public static function scrub( string $html ): string {
		$replacement = Replacement::markup();
		return (string) preg_replace_callback(
			self::PRICE_SPAN,
			static fn(): string => $replacement,
			$html
		);
	}

	/**
	 * Filter callback for `woocommerce_cart_tax_totals`: the itemised tax rows
	 * of the cart-totals template print each object's `formatted_amount`.
	 * Only that field is replaced; `amount` is arithmetic input elsewhere.
	 *
	 * @param mixed $tax_totals Array of tax total objects, keyed by rate code.
	 * @return mixed
	 */
	public function replace_tax_totals( mixed $tax_totals ): mixed {
		if ( ! Context::is_guest() || ! is_array( $tax_totals ) ) {
			return $tax_totals;
		}
		foreach ( $tax_totals as $tax ) {
			if ( is_object( $tax ) && property_exists( $tax, 'formatted_amount' ) ) {
				$tax->formatted_amount = Replacement::markup();
			}
		}
		return $tax_totals;
	}

	// -- grouped product ---------------------------------------------------

	/**
	 * Filter callback for `woocommerce_grouped_product_list_column_quantity`.
	 *
	 * For a sold-individually child the grouped add-to-cart template prints a
	 * checkbox and a screen-reader label built with `wc_price()` directly:
	 * "Buy one of X for $Y" (or the sale variant with two amounts). The
	 * checkbox stays; the label keeps the product name and loses the amount.
	 * The quantity-input branch has no price and passes through.
	 *
	 * @param mixed $value The rendered quantity column.
	 * @param mixed $child The child product. Only its name is read.
	 * @return mixed
	 */
	public function replace_grouped_label( mixed $value, mixed $child = null ): mixed {
		if ( ! Context::is_guest() || ! is_string( $value ) || ! str_contains( $value, 'wc-grouped-product-add-to-cart-checkbox' ) ) {
			return $value;
		}

		$label = self::grouped_label( is_object( $child ) && method_exists( $child, 'get_name' ) ? (string) $child->get_name() : '' );

		return (string) preg_replace_callback(
			'#(<label\b[^>]*>).*?(</label>)#s',
			static fn( array $m ): string => $m[1] . $label . $m[2],
			$value,
			1
		);
	}

	/**
	 * The amount-free label for a grouped child's checkbox, escaped.
	 *
	 * @param string $name The child product's name, or '' when unknown.
	 */
	private static function grouped_label( string $name ): string {
		$label = '' === $name
			? __( 'Buy one', 'pricecloak-for-woocommerce' )
			/* translators: %s: product name */
			: sprintf( __( 'Buy one of %s', 'pricecloak-for-woocommerce' ), $name );

		return esc_html( $label );
	}

	/**
	 * Filter callback for `render_block`: the Grouped Product Item Selector
	 * block's sold-individually checkbox keeps its name, its directives and
	 * its checkbox, and its `aria-label` loses the amount.
	 *
	 * The child is identified from the input's own `name="quantity[ID]"`
	 * rather than by parsing the label, whose wording is translatable; the
	 * label is rebuilt from the product's name, or as a generic "Buy one"
	 * when the product cannot be loaded. A child rendered as a button or a
	 * quantity input carries no such checkbox and passes through.
	 *
	 * @param mixed $content Rendered block HTML.
	 * @param mixed $block   Parsed block, with its `blockName`.
	 * @return mixed
	 */
	public function scrub_grouped_selector_block( mixed $content, mixed $block = null ): mixed {
		if ( ! Context::is_guest() || ! is_array( $block ) || ! is_string( $content ) ) {
			return $content;
		}
		if ( self::GROUPED_SELECTOR_BLOCK !== ( $block['blockName'] ?? '' ) || ! str_contains( $content, 'wc-grouped-product-add-to-cart-checkbox' ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			'#<input\b[^>]*\bclass="[^"]*wc-grouped-product-add-to-cart-checkbox[^"]*"[^>]*>#',
			static function ( array $m ): string {
				$input = $m[0];
				$name  = '';
				if ( preg_match( '#\bname="quantity\[(\d+)\]"#', $input, $id ) && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( (int) $id[1] );
					$name    = is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
				}
				$label = self::grouped_label( $name );

				// A callback, not a replacement string: a name such as "$25 Gift
				// Card \ Special" must not be read as backreferences.
				return (string) preg_replace_callback(
					'#\baria-label="[^"]*"#',
					static fn(): string => 'aria-label="' . $label . '"',
					$input,
					1
				);
			},
			$content
		);
	}

	// -- product grid blocks -----------------------------------------------

	/**
	 * Filter callback for `woocommerce_blocks_product_grid_add_to_cart_attributes`:
	 * a guest's grid button carries no `data-price`, nor any other attribute
	 * named after a price. The product id, SKU, quantity, classes and label
	 * -- what the add-to-cart script reads -- stay, in their order.
	 *
	 * @param mixed $attributes Attribute name => value, as the grid built them.
	 * @param mixed $product    The product. Unused.
	 * @return mixed
	 */
	public function drop_grid_button_price( mixed $attributes, mixed $product = null ): mixed {
		if ( ! Context::is_guest() || ! is_array( $attributes ) ) {
			return $attributes;
		}
		foreach ( array_keys( $attributes ) as $name ) {
			if ( is_string( $name ) && preg_match( self::PRICE_ATTRIBUTE, $name ) ) {
				unset( $attributes[ $name ] );
			}
		}
		return $attributes;
	}

	// -- Mini Cart block ---------------------------------------------------

	/**
	 * Filter callback for `render_block`: after a Mini Cart block (or its
	 * footer inner block) has rendered and registered its state, merge the
	 * replacement text over `formattedSubtotal`.
	 *
	 * `wp_interactivity_state()` merges into what the block registered, so the
	 * override wins; and WordPress processes a root interactive block's
	 * directives only after every `render_block` filter has run, so the
	 * server-rendered `data-wp-text` and the aria-label closure both see the
	 * overridden value. The plain text is used, not the link markup: the
	 * value is printed through `data-wp-text` as text and into an aria-label.
	 *
	 * @param mixed $content Rendered block HTML, returned unchanged.
	 * @param mixed $block   Parsed block, with its `blockName`.
	 * @return mixed
	 */
	public function override_mini_cart_state( mixed $content, mixed $block = null ): mixed {
		if ( ! Context::is_guest() || ! is_array( $block ) || ! function_exists( 'wp_interactivity_state' ) ) {
			return $content;
		}
		$name = $block['blockName'] ?? '';
		if ( ! in_array( $name, self::MINI_CART_STATE_BLOCKS, true ) ) {
			return $content;
		}

		wp_interactivity_state( $name, [ 'formattedSubtotal' => wp_strip_all_tags( Options::replacement_text() ) ] );

		return $content;
	}

	// -- order pages -------------------------------------------------------

	/**
	 * Action callback for `template_redirect`: on the order-received and
	 * order-pay pages, and only there, hook the order-level price filters for
	 * a guest.
	 *
	 * Those two pages, and the order tracking shortcode's result (hooked from
	 * its own action below), are the only front-end places a guest can read
	 * an order (the order key in the URL, or the order number and billing
	 * email, authorise them). The filters are shared with the order emails,
	 * which is why they are attached per page request rather than in
	 * register(): an email built from cron, from the admin or from a REST
	 * request never passes through either gate, and one built during the
	 * page request itself is left alone because Context::is_guest() is
	 * false while an email renders.
	 */
	public function maybe_hook_order_pages(): void {
		if ( ! Context::is_guest() || ! $this->is_guest_order_page() ) {
			return;
		}
		$this->hook_order_filters();
	}

	/**
	 * Action callback for `woocommerce_track_order`: the order tracking
	 * shortcode has matched a guest's order number and billing email and is
	 * about to print the order details table, so the same three filters are
	 * attached now. There is no page to gate on -- the shortcode fires the
	 * action only on a successful lookup -- and the email context still applies.
	 *
	 * @param mixed $order_id The order about to be shown. Unused.
	 */
	public function hook_order_tracking( mixed $order_id = null ): void {
		if ( ! Context::is_guest() ) {
			return;
		}
		$this->hook_order_filters();
	}

	/**
	 * Attach the three order-level filters, once.
	 */
	private function hook_order_filters(): void {
		if ( $this->order_filters_hooked ) {
			return;
		}
		add_filter( 'woocommerce_order_formatted_line_subtotal', [ $this, 'replace' ], PHP_INT_MAX );
		add_filter( 'woocommerce_get_formatted_order_total', [ $this, 'replace' ], PHP_INT_MAX );
		add_filter( 'woocommerce_get_order_item_totals', [ $this, 'blank_order_item_totals' ], PHP_INT_MAX );
		$this->order_filters_hooked = true;
	}

	/**
	 * Whether this request renders an order to a visitor: the order-received
	 * endpoint or the pay-for-order endpoint of the checkout page.
	 */
	public function is_guest_order_page(): bool {
		$received = function_exists( 'is_order_received_page' ) && is_order_received_page();
		$pay      = function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page();
		return $received || $pay;
	}

	/**
	 * Detach the order filters, if attached.
	 */
	private function unhook_order_pages(): void {
		if ( ! $this->order_filters_hooked ) {
			return;
		}
		remove_filter( 'woocommerce_order_formatted_line_subtotal', [ $this, 'replace' ], PHP_INT_MAX );
		remove_filter( 'woocommerce_get_formatted_order_total', [ $this, 'replace' ], PHP_INT_MAX );
		remove_filter( 'woocommerce_get_order_item_totals', [ $this, 'blank_order_item_totals' ], PHP_INT_MAX );
		$this->order_filters_hooked = false;
	}

	/**
	 * Filter callback for `woocommerce_get_order_item_totals`: every totals
	 * row's value becomes the replacement, except the payment method row,
	 * whose value is the gateway's title and not an amount.
	 *
	 * The filters attached above are shared with the order emails, and an
	 * order-page request can build one (a status change on the pay page);
	 * Context::is_guest() is false while an email renders, so the amounts
	 * pass through there as they do everywhere else.
	 *
	 * @param mixed $rows Totals rows, each with `type`, `label` and `value`.
	 * @return mixed
	 */
	public function blank_order_item_totals( mixed $rows ): mixed {
		if ( ! Context::is_guest() || ! is_array( $rows ) ) {
			return $rows;
		}
		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) || 'payment_method' === ( $row['type'] ?? $key ) ) {
				continue;
			}
			$rows[ $key ]['value'] = Replacement::markup();
		}
		return $rows;
	}
}
