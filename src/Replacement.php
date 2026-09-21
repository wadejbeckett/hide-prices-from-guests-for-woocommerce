<?php
/**
 * The replacement markup shown where a price would be. One builder, shared by
 * every module that has to put something in a price's place.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the guest-facing replacement for a price.
 */
final class Replacement {
	/**
	 * The replacement markup: a login link, or plain text in a span.
	 */
	public static function markup(): string {
		return self::markup_for( Options::replacement_text() );
	}

	/**
	 * The same builder with wording of the caller's choosing.
	 *
	 * @param string $text        Visible wording, escaped here.
	 * @param string $extra_class Classes added to the element, e.g. a theme's
	 *                            button class so a call to action looks like one.
	 * @param bool   $force_link  Render a link even when the "link to login"
	 *                            setting is off. The Purchasing module passes
	 *                            true: "Log in to buy" that is not a link to the
	 *                            login page is a dead end, whereas replacement
	 *                            price text reads perfectly well as plain text.
	 */
	public static function markup_for( string $text, string $extra_class = '', bool $force_link = false ): string {
		$label = esc_html( $text );

		if ( ! $force_link && ! Options::link_to_login() ) {
			$class = trim( 'pricecloak-price-hidden ' . $extra_class );
			return '<span class="' . esc_attr( $class ) . '">' . $label . '</span>';
		}

		$class = trim( 'pricecloak-login-link ' . $extra_class );
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::login_url() ) . '">' . $label . '</a>';
	}

	/**
	 * Where the link goes.
	 *
	 * WooCommerce sends its own visitors to the My Account page rather than
	 * wp-login.php -- that is the page its login form, its "you must be logged
	 * in" notices and its `woocommerce_login_redirect` flow all use -- so the
	 * My Account permalink is preferred here for a consistent shop experience.
	 * `wp_login_url()` is the fallback for a site with no My Account page;
	 * `wc_get_page_permalink()` is asked for '' rather than its default
	 * home-URL fallback so that case can be told apart.
	 *
	 * The current URL rides along as `redirect_to`, which both destinations
	 * honour. wp-login.php reads it directly. The My Account login form does
	 * not read the query string itself -- in WooCommerce 11.1
	 * `WC_Form_Handler::process_login()` reads a hidden `redirect` POST field,
	 * then the raw referer, then the My Account page -- but WooCommerce's
	 * blocks package prints exactly that field from `$_GET['redirect_to']` on
	 * `woocommerce_login_form_end` (`BlockTypesController::redirect_to_field()`,
	 * registered on every front-end request), so a customer who logs in there
	 * is sent back to the page this link was on. Both destinations pass the
	 * target through `wp_validate_redirect()` before following it.
	 */
	public static function login_url(): string {
		$current = self::current_url();
		$account = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount', '' ) : '';

		if ( '' === $account ) {
			return wp_login_url( $current );
		}

		return add_query_arg( 'redirect_to', rawurlencode( $current ), $account );
	}

	/**
	 * The URL being requested, used as the post-login redirect target.
	 *
	 * Rebuilt from the scheme, the Host header and the request URI rather than
	 * through `home_url( REQUEST_URI )`: on a subdirectory install the home
	 * URL already carries the directory the request URI starts with, and
	 * joining the two doubled it (`/shop/shop/...`). The result is validated
	 * with `wp_validate_redirect()`, so a forged Host header cannot make the
	 * link carry an off-site target; the home page is the fallback.
	 */
	public static function current_url(): string {
		$fallback = home_url( '/' );
		$host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		if ( '' === $host ) {
			return $fallback;
		}
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$scheme      = is_ssl() ? 'https' : 'http';

		return wp_validate_redirect( esc_url_raw( $scheme . '://' . $host . $request_uri ), $fallback );
	}
}
