<?php
/**
 * Module 4: Store API. Every Store API route that carries an amount -- the
 * products routes, the cart, checkout and order routes, the batch envelope --
 * serves it to anyone, including guests, and WooCommerce hydrates the same
 * payloads into its block pages. For guests the amounts are blanked and the
 * rendered price replaced, at the response and at the hydration source.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Modules;

use PriceCloak\Context;
use PriceCloak\Plugin;
use PriceCloak\Replacement;
use stdClass;

defined( 'ABSPATH' ) || exit;

/**
 * Blanks Store API amounts for logged-out visitors.
 *
 * Routes. WooCommerce registers every v1 route twice --
 * `RoutesController::register_all_routes()` calls `register_routes( 'v1', 'wc/store' )`
 * and then `register_routes( 'v1', 'wc/store/v1' )` -- so the unversioned
 * `/wc/store/...` alias answers exactly like `/wc/store/v1/...` and is
 * claimed with it. The claimed segments are `products` (`/products`,
 * `/products/{id}`, `/products/{slug}`, `/products/collection-data`),
 * `cart` (`/cart`, `/cart/items`, `/cart/items/{key}` and the response of
 * every cart write, which is the full cart), `checkout` (`/checkout` and
 * `/checkout/{id}`), `order` (`/order/{id}`, which a guest reads with the
 * order key and billing email) and `batch`. The batch route hands its
 * sub-requests to `WP_REST_Server::serve_batch_request_v1()`, which
 * dispatches each through `respond_to_request()`, so the response filter
 * fires once per sub-request with that sub-request's own route; the envelope
 * itself is claimed as well so its `responses[].body` copies are walked too.
 *
 * Shapes. Every amount in the Store API is a minor-unit string produced by
 * `AbstractSchema::prepare_money_response()`, and every object that holds
 * amounts is decorated by `prepare_currency_response()` with `currency_code`,
 * `currency_symbol`, `currency_minor_unit`, the separators, prefix and suffix.
 * The rules below are keyed on the field names those schemas use
 * (ProductSchema, CartSchema, CartItemSchema, CartShippingRateSchema,
 * CartCouponSchema, CartFeeSchema, CheckoutSchema, OrderSchema,
 * OrderItemSchema, OrderFeeSchema, OrderCouponSchema in WooCommerce 11.1):
 *
 * - `prices` -- a product's or line item's price object: `price`,
 *   `regular_price`, `sale_price`, `price_range` (`min_amount`/`max_amount`),
 *   plus on cart and order items `raw_prices` (`precision` and the same three
 *   amounts at rounding precision).
 * - `totals` -- on the cart, an order, a line item, a coupon, a fee: every
 *   `total_*`, `line_*`, `subtotal`, `total`, `total_tax` key, plus the cart's
 *   `tax_lines[]` (`name`, `price`, `rate`).
 * - `price` and `taxes` -- a shipping rate's cost and tax (`shipping_rates[].shipping_rates[]`).
 * - `price_range` -- `collection-data`'s catalogue range.
 * - `price_html` -- the rendered price.
 *
 * Why blank strings and kept metadata rather than null or removal. The
 * schemas type `prices` and `totals` as `object`, the amounts inside them as
 * `string`, and `price_range` as `object|null`; blank strings and a null
 * range validate, `null` objects do not. The client cares too: the blocks'
 * `formatPrice()` (`assets/client/blocks/price-format.js`) returns `''` for
 * an empty-string amount explicitly, and reads its currency settings from
 * the `currency_*` keys of the same object, so an emptied object renders as
 * nothing while a null one throws on the first property read. The Checkout
 * block likewise omits `expected_total` from its place-order request when
 * `totals.total_price` is `''`, so a guest can still check out (while the
 * Purchasing module is off) without the server rejecting a total mismatch.
 * `tax_lines[].name` and `rate` and `raw_prices.precision` are kept: a tax
 * label, a percentage and a decimal count are not amounts.
 *
 * Why a few fields become `'0'` rather than `''`. The Cart and Checkout
 * blocks' line items (`cart-line-item.tsx` and `order-summary-item.tsx`,
 * bundled in `wc-cart-checkout-base-frontend.js`) do arithmetic before they
 * format: they build Dinero objects from `parseInt( raw_prices.price )`,
 * `parseInt( raw_prices.regular_price )` and `parseInt( totals.line_subtotal )`
 * (plus `line_subtotal_tax` when prices display inclusive of tax) to derive
 * the sale badge and the line subtotal, and Dinero's `onCreate` asserts
 * `Number.isInteger( amount )` -- `parseInt( '' )` is NaN, the assertion
 * throws "[Dinero.js] Amount is invalid" and the whole line-items block
 * unmounts, leaving a guest a cart with no rows. So the amounts that are
 * parsed rather than formatted -- everything under `raw_prices`, and the
 * four `line_*` keys of a line item's `totals` -- become `'0'`, the one
 * integer that carries no price, and the blocks then paint those rows with
 * a zero. Every amount that is only ever formatted stays `''` and paints as
 * nothing. So every amount goes, everything else stays, and the blocks keep
 * rendering. The zero those rows paint ("$0.00" beside a line item) is
 * not a price, but it reads like one, so a guest-only inline stylesheet
 * hides the line-item price and sale-badge components (and the order
 * summary item's screen-reader "Total price for ..." sentence) inside the
 * Cart and Checkout blocks and the Mini Cart drawer; the totals rows, which
 * format `''`, need no such help and simply show nothing.
 *
 * The product grid blocks. `AbstractProductGrid::render()` (Product New,
 * On Sale, Best Sellers, Top Rated, By Category, By Tag, By Attribute,
 * Hand-picked) prints its product list, "shaped like the Store API
 * responses" -- built by calling `ProductSchema::get_item_response()`
 * directly, with no filter, no request and no hydration service on the way
 * -- into an inline `wp-hooks` script for a tracking hook, on every page
 * that carries one of those blocks; the default Cart page's empty-cart
 * block carries Product New. The rendered grid itself prints prices through
 * `get_price_html()` and is already replaced, so the script is rewritten
 * instead: on `render_block` for those eight blocks (which runs after the
 * block's render() has registered the script) every `wp-hooks` inline
 * script carrying the `experimental__woocommerce_blocks-product-list-render`
 * marker has its URL-encoded JSON decoded, walked with the same rules as a
 * response, and re-encoded exactly as `AbstractProductGrid` encodes it.
 *
 * Ownership. This module owns what a Store API *response* carries. The
 * price-shaped *request* parameters on the products routes (`min_price`,
 * `max_price`, `orderby=price`), through which a guest could infer a price
 * from a blanked result set, are the Price filters module's, which strips
 * them at `rest_request_before_callbacks` and on the hydration path.
 */
final class StoreApi extends RestResponseFilter {
	public const ID = 'store_api';

	/**
	 * Keys inside a `prices` or `totals` object that are metadata, not amounts.
	 * Everything else in there is a number a customer could read.
	 */
	public const CURRENCY_PREFIX = 'currency';

	/**
	 * Other metadata keys kept as they are: `raw_prices.precision` is a
	 * decimal count, `tax_lines[].rate` a percentage, `tax_lines[].name` a
	 * label.
	 *
	 * @var string[]
	 */
	public const METADATA_KEYS = [ 'precision', 'rate', 'name' ];

	/**
	 * Amounts the Cart and Checkout blocks parse as integers (Dinero) rather
	 * than format: they become `'0'`, not `''`, so the blocks keep rendering.
	 * Everything under `raw_prices` is treated the same way.
	 *
	 * @var string[]
	 */
	public const INTEGER_KEYS = [ 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_total_tax' ];

	/**
	 * The `prices` sub-object whose amounts are parsed as integers.
	 */
	public const RAW_PRICES_KEY = 'raw_prices';

	/**
	 * The guest stylesheet's handle (inline, no file).
	 */
	public const STYLE_HANDLE = 'pricecloak-store-api-guest';

	/**
	 * The guest stylesheet: the line-item price components the Cart and
	 * Checkout blocks and the Mini Cart drawer paint from the zeroed
	 * integer amounts.
	 */
	public const GUEST_CSS = '.wp-block-woocommerce-cart .wc-block-components-product-price,'
		. '.wp-block-woocommerce-cart .wc-block-cart-item__prices,'
		. '.wp-block-woocommerce-cart .wc-block-cart-item__total,'
		. '.wp-block-woocommerce-cart .wc-block-components-sale-badge,'
		. '.wp-block-woocommerce-checkout .wc-block-components-product-price,'
		. '.wp-block-woocommerce-checkout .wc-block-components-order-summary-item__individual-prices,'
		. '.wp-block-woocommerce-checkout .wc-block-components-order-summary-item__total-price,'
		. '.wp-block-woocommerce-checkout .wc-block-components-sale-badge,'
		// The order summary item's screen-reader sentence ("Total price for 1 X item: $0.00").
		. '.wp-block-woocommerce-checkout .wc-block-components-order-summary-item > .screen-reader-text,'
		. '.wc-block-mini-cart__drawer .wc-block-components-product-price,'
		. '.wc-block-mini-cart__drawer .wc-block-cart-item__prices,'
		. '.wc-block-mini-cart__drawer .wc-block-cart-item__total,'
		. '.wc-block-mini-cart__drawer .wc-block-components-sale-badge'
		. '{display: none !important;}';

	/**
	 * The blocks that extend AbstractProductGrid in WooCommerce 11.1.
	 *
	 * @var string[]
	 */
	public const PRODUCT_GRID_BLOCKS = [
		'woocommerce/product-best-sellers',
		'woocommerce/product-top-rated',
		'woocommerce/products-by-attribute',
		'woocommerce/product-new',
		'woocommerce/product-category',
		'woocommerce/product-on-sale',
		'woocommerce/product-tag',
		'woocommerce/handpicked-products',
	];

	/**
	 * The script handle the grids attach their inline script to, and the
	 * hook name that identifies that script among the handle's others.
	 */
	public const GRID_SCRIPT_HANDLE = 'wp-hooks';
	public const GRID_SCRIPT_MARKER = 'experimental__woocommerce_blocks-product-list-render';

	/**
	 * Stable identifier.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * The response filters, plus the grid script rewrite.
	 */
	public function register(): void {
		parent::register();
		add_filter( 'render_block', [ $this, 'scrub_product_grid_script' ], PHP_INT_MAX, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_guest_styles' ] );
	}

	/**
	 * Remove exactly what register() added.
	 */
	public function unregister(): void {
		parent::unregister();
		remove_filter( 'render_block', [ $this, 'scrub_product_grid_script' ], PHP_INT_MAX );
		remove_action( 'wp_enqueue_scripts', [ $this, 'enqueue_guest_styles' ] );
	}

	/**
	 * Action callback for `wp_enqueue_scripts`: the guest stylesheet, inline
	 * under a file-less handle so it needs no asset on disk.
	 */
	public function enqueue_guest_styles(): void {
		if ( ! Context::is_guest() ) {
			return;
		}
		wp_register_style( self::STYLE_HANDLE, false, [], Plugin::VERSION );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, self::GUEST_CSS );
	}

	/**
	 * Every Store API surface that carries an amount, under both namespaces.
	 */
	protected function route_pattern(): string {
		return '#^/wc/store(?:/v\d+)?/(?:products|cart|checkout|order|batch)(?:/|$)#i';
	}

	/**
	 * Blank the amounts, null the ranges, replace the rendered price.
	 *
	 * @return array<string, callable>
	 */
	protected function rules(): array {
		// The current value is irrelevant to three of these: a range always
		// becomes null, a rendered price the replacement markup, a shipping
		// rate's cost or tax an empty string.
		$blank = static fn( mixed $value ): string => ''; // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		return [
			'prices'      => [ self::class, 'blank_prices' ],
			'totals'      => [ self::class, 'blank_totals' ],
			'price'       => $blank,
			'taxes'       => $blank,
			'price_range' => static fn( mixed $range ): mixed => null, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			'price_html'  => static fn( mixed $html ): string => Replacement::markup(), // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		];
	}

	/**
	 * Filter callback for `render_block`: after a product grid block has
	 * rendered and registered its inline script, rewrite that script's
	 * product list with the response rules. The block's own HTML is returned
	 * unchanged.
	 *
	 * @param mixed $content Rendered block HTML.
	 * @param mixed $block   Parsed block, with its `blockName`.
	 * @return mixed
	 */
	public function scrub_product_grid_script( mixed $content, mixed $block = null ): mixed {
		if ( ! Context::is_guest() || ! is_array( $block ) || ! function_exists( 'wp_scripts' ) ) {
			return $content;
		}
		if ( ! in_array( $block['blockName'] ?? '', self::PRODUCT_GRID_BLOCKS, true ) ) {
			return $content;
		}

		$scripts = wp_scripts();
		$after   = $scripts->get_data( self::GRID_SCRIPT_HANDLE, 'after' );
		if ( ! is_array( $after ) ) {
			return $content;
		}

		$changed = false;
		foreach ( $after as $index => $script ) {
			if ( ! is_string( $script ) || ! str_contains( $script, self::GRID_SCRIPT_MARKER ) ) {
				continue;
			}
			$scrubbed = $this->scrub_encoded_products( $script );
			if ( $scrubbed !== $script ) {
				$after[ $index ] = $scrubbed;
				$changed         = true;
			}
		}
		if ( $changed ) {
			$scripts->add_data( self::GRID_SCRIPT_HANDLE, 'after', $after );
		}

		return $content;
	}

	/**
	 * Rewrite every `decodeURIComponent( "..." )` payload in a grid script.
	 * The payload is `esc_js( rawurlencode( wp_json_encode( $products ) ) )`,
	 * and rawurlencode's output has nothing esc_js changes, so decoding is
	 * rawurldecode() and JSON, and re-encoding is the same three calls.
	 *
	 * @param string $script The inline script.
	 */
	private function scrub_encoded_products( string $script ): string {
		$rules = $this->rules();

		return (string) preg_replace_callback(
			'#decodeURIComponent\( "([A-Za-z0-9%._~-]*)" \)#',
			static function ( array $m ) use ( $rules ): string {
				$data = json_decode( rawurldecode( $m[1] ) );
				if ( null === $data ) {
					return $m[0];
				}
				return 'decodeURIComponent( "' . esc_js( rawurlencode( (string) wp_json_encode( self::rewrite( $data, $rules ) ) ) ) . '" )';
			},
			$script
		);
	}

	/**
	 * Empty every amount in a `prices` object, keeping its currency metadata,
	 * its `raw_prices` precision and its shape. A null or otherwise unexpected
	 * value is returned as is.
	 *
	 * @param mixed $prices The `prices` value from a product or line item response.
	 * @return mixed
	 */
	public static function blank_prices( mixed $prices ): mixed {
		return self::blank_amounts( $prices, true );
	}

	/**
	 * Empty every amount in a `totals` object (cart, order, line item, coupon
	 * or fee), keeping its currency metadata and, inside `tax_lines`, each
	 * line's name and rate. A null or otherwise unexpected value is returned
	 * as is.
	 *
	 * @param mixed $totals The `totals` value from a response.
	 * @return mixed
	 */
	public static function blank_totals( mixed $totals ): mixed {
		return self::blank_amounts( $totals, false );
	}

	/**
	 * The shared walk: metadata keys survive, nested arrays and objects are
	 * walked with the same rule, `price_range` becomes null when ranges are
	 * expected, the integer-parsed amounts become `'0'`, every other value
	 * becomes an empty string.
	 *
	 * @param mixed $node    The object or array to blank.
	 * @param bool  $ranges  Whether a `price_range` key means a range (null) rather than an amount.
	 * @param bool  $integer Whether every amount in this node is parsed as an integer (inside `raw_prices`).
	 * @return mixed
	 */
	private static function blank_amounts( mixed $node, bool $ranges, bool $integer = false ): mixed {
		$is_object = $node instanceof stdClass;
		if ( ! $is_object && ! is_array( $node ) ) {
			return $node;
		}

		$fields = $is_object ? get_object_vars( $node ) : $node;
		foreach ( $fields as $key => $value ) {
			if ( is_string( $key ) && ( str_starts_with( $key, self::CURRENCY_PREFIX ) || in_array( $key, self::METADATA_KEYS, true ) ) ) {
				continue;
			}
			if ( $ranges && 'price_range' === $key ) {
				$fields[ $key ] = null;
				continue;
			}
			if ( $value instanceof stdClass || is_array( $value ) ) {
				$fields[ $key ] = self::blank_amounts( $value, $ranges, $integer || self::RAW_PRICES_KEY === $key );
				continue;
			}
			$fields[ $key ] = $integer || in_array( $key, self::INTEGER_KEYS, true ) ? '0' : '';
		}

		return $is_object ? (object) $fields : $fields;
	}
}
