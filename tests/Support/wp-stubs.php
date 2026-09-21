<?php
/**
 * WordPress function stubs for unit tests. Under WordPress this file defines nothing.
 * The stubs record hooks and options in $GLOBALS['pricecloak_test'] so tests can inspect them.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( function_exists( 'add_filter' ) ) {
	return;
}

function pricecloak_test_state(): array {
	return [
		'filters'       => [],
		'options'       => [],
		'logged_in'     => false,
		'notices'       => [],
		'redirects'     => [],
		'compatibility' => [],
		'activation'    => [],
	];
}

$GLOBALS['pricecloak_test'] = pricecloak_test_state();

function pricecloak_test_reset(): void {
	$GLOBALS['pricecloak_test'] = pricecloak_test_state();
}

function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['pricecloak_test']['filters'][ $hook ][] = [ $callback, $priority, $accepted_args ];
	return true;
}

function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function remove_filter( string $hook, mixed $callback, int $priority = 10 ): bool {
	if ( empty( $GLOBALS['pricecloak_test']['filters'][ $hook ] ) ) {
		return false;
	}
	foreach ( $GLOBALS['pricecloak_test']['filters'][ $hook ] as $i => $entry ) {
		if ( $entry[0] == $callback && $entry[1] === $priority ) { // phpcs:ignore Universal.Operators.StrictComparisons
			unset( $GLOBALS['pricecloak_test']['filters'][ $hook ][ $i ] );
			if ( empty( $GLOBALS['pricecloak_test']['filters'][ $hook ] ) ) {
				unset( $GLOBALS['pricecloak_test']['filters'][ $hook ] );
			}
			return true;
		}
	}
	return false;
}

function remove_action( string $hook, mixed $callback, int $priority = 10 ): bool {
	return remove_filter( $hook, $callback, $priority );
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$entries = $GLOBALS['pricecloak_test']['filters'][ $hook ] ?? [];
	usort( $entries, static fn( $a, $b ) => $a[1] <=> $b[1] );
	foreach ( $entries as [ $callback, , $accepted ] ) {
		$value = $callback( ...array_slice( [ $value, ...$args ], 0, max( 1, $accepted ) ) );
	}
	return $value;
}

function do_action( string $hook, mixed ...$args ): void {
	$GLOBALS['pricecloak_test']['did_actions'][ $hook ] = ( $GLOBALS['pricecloak_test']['did_actions'][ $hook ] ?? 0 ) + 1;
	apply_filters( $hook, ...( [] === $args ? [ '' ] : $args ) );
}

function did_action( string $hook ): int {
	return (int) ( $GLOBALS['pricecloak_test']['did_actions'][ $hook ] ?? 0 );
}

function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['pricecloak_test']['options'][ $name ] ?? $default;
}

function update_option( string $name, mixed $value ): bool {
	$GLOBALS['pricecloak_test']['options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	unset( $GLOBALS['pricecloak_test']['options'][ $name ] );
	return true;
}

function register_activation_hook( string $file, mixed $callback ): void {
	$GLOBALS['pricecloak_test']['activation'][] = [ $file, $callback ];
}

function is_user_logged_in(): bool {
	return (bool) $GLOBALS['pricecloak_test']['logged_in'];
}

function __( string $text, string $domain = 'default' ): string { // phpcs:ignore WordPress.WP.I18n
	return $text;
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( string $url ): string {
	return str_replace( '&', '&#038;', $url );
}

function esc_url_raw( string $url ): string {
	return $url;
}

function wp_unslash( string $value ): string {
	return stripslashes( $value );
}

function home_url( string $path = '' ): string {
	// A test may point the home URL at a subdirectory install.
	return ( $GLOBALS['pricecloak_test']['home_url'] ?? 'http://example.test' ) . $path;
}

function add_query_arg( string $key, string $value, string $url ): string {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value;
}

function wc_get_page_permalink( string $page, mixed $fallback = null ): string {
	// Mirrors WooCommerce: a missing page yields the home URL unless a fallback is given.
	$permalink = $GLOBALS['pricecloak_test']['pages'][ $page ] ?? 'http://example.test/my-account/';
	if ( '' === $permalink ) {
		return is_null( $fallback ) ? home_url() : (string) $fallback;
	}
	return $permalink;
}

function wp_login_url( string $redirect = '' ): string {
	return 'http://example.test/wp-login.php?redirect_to=' . rawurlencode( $redirect );
}

function is_ssl(): bool {
	return (bool) ( $GLOBALS['pricecloak_test']['is_ssl'] ?? false );
}

function sanitize_text_field( string $str ): string {
	return trim( strip_tags( $str ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
}

/**
 * A port of WordPress core's wp_validate_redirect(): only http(s) URLs whose
 * host is the home URL's host survive; anything else becomes the fallback.
 */
function wp_validate_redirect( string $location, string $fallback_url = '' ): string {
	$location = trim( $location, " \t\n\r\0\x08\x0B" );
	if ( str_starts_with( $location, '//' ) ) {
		$location = 'http:' . $location;
	}
	$cut  = strpos( $location, '?' );
	$test = $cut ? substr( $location, 0, $cut ) : $location;
	$lp   = wp_parse_url( $test );
	if ( false === $lp ) {
		return $fallback_url;
	}
	if ( isset( $lp['scheme'] ) && ! in_array( $lp['scheme'], [ 'http', 'https' ], true ) ) {
		return $fallback_url;
	}
	if ( ! isset( $lp['host'] ) && ( isset( $lp['scheme'] ) || isset( $lp['user'] ) || isset( $lp['pass'] ) || isset( $lp['port'] ) ) ) {
		return $fallback_url;
	}
	foreach ( [ 'user', 'pass', 'host' ] as $component ) {
		if ( isset( $lp[ $component ] ) && strpbrk( (string) $lp[ $component ], ':/?#@' ) ) {
			return $fallback_url;
		}
	}
	$home = wp_parse_url( home_url() );
	if ( isset( $lp['host'] ) && strtolower( $lp['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
		return $fallback_url;
	}
	return $location;
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}

function plugin_dir_path( string $file ): string {
	return rtrim( dirname( $file ), '/' ) . '/';
}

// -- Purchasing module (T9) ------------------------------------------------
// The shop-state functions read $GLOBALS['pricecloak_test'] so a test can say "this
// request is the cart page" without a WordPress query.

function wc_add_notice( string $message, string $type = 'success' ): void {
	$GLOBALS['pricecloak_test']['notices'][] = [
		'message' => $message,
		'type'    => $type,
	];
}

function wp_safe_redirect( string $location, int $status = 302 ): bool {
	$GLOBALS['pricecloak_test']['redirects'][] = [
		'location' => $location,
		'status'   => $status,
	];
	return true;
}

function is_cart(): bool {
	return (bool) ( $GLOBALS['pricecloak_test']['is_cart'] ?? false );
}

function is_checkout(): bool {
	return (bool) ( $GLOBALS['pricecloak_test']['is_checkout'] ?? false );
}

function is_order_received_page(): bool {
	return (bool) ( $GLOBALS['pricecloak_test']['is_order_received'] ?? false );
}

function wp_kses_post( string $content ): string {
	return $content;
}

// -- Price HTML completeness (T16) -----------------------------------------
// Order-page gating, the email guard, the Interactivity API state merge and
// the tag stripper the mini-cart override uses.

function is_checkout_pay_page(): bool {
	return (bool) ( $GLOBALS['pricecloak_test']['is_checkout_pay'] ?? false );
}

function doing_action( string $hook ): bool {
	return in_array( $hook, $GLOBALS['pricecloak_test']['doing_actions'] ?? [], true );
}

function wp_interactivity_state( string $store_namespace, array $state = [] ): array {
	$current = $GLOBALS['pricecloak_test']['interactivity_state'][ $store_namespace ] ?? [];
	$merged  = array_replace_recursive( $current, $state );

	$GLOBALS['pricecloak_test']['interactivity_state'][ $store_namespace ] = $merged;
	return $merged;
}

// -- Store API and hydration coverage (T15) --------------------------------
// The inline-script registry the product grid blocks write their Store
// API-shaped product list into, and the two encoders that list goes through.

function wp_json_encode( mixed $data, int $flags = 0 ): string|false {
	return json_encode( $data, $flags );
}

function esc_js( string $text ): string {
	return str_replace( [ '"', "'" ], [ '\\"', "\\'" ], $text );
}

function wp_register_style( string $handle, mixed $src = false, array $deps = [], mixed $ver = false ): bool {
	$GLOBALS['pricecloak_test']['styles'][ $handle ] = [
		'src'    => $src,
		'ver'    => $ver,
		'inline' => [],
	];
	return true;
}

function wp_enqueue_style( string $handle ): void {
	$GLOBALS['pricecloak_test']['enqueued_styles'][] = $handle;
}

function wp_add_inline_style( string $handle, string $data ): bool {
	$GLOBALS['pricecloak_test']['styles'][ $handle ]['inline'][] = $data;
	return true;
}

// PriceCloakFakeScripts itself lives in wc-stubs.php beside the other fake classes.
function wp_scripts(): PriceCloakFakeScripts {
	$GLOBALS['pricecloak_test']['scripts'] ??= new PriceCloakFakeScripts();
	return $GLOBALS['pricecloak_test']['scripts'];
}

// -- One guest-context decision (T21) ---------------------------------------
// The process-level signals Context reads: WP-Cron, and the did_action()
// counters kept by do_action() above (a test can also set them directly).

function wp_doing_cron(): bool {
	return (bool) ( $GLOBALS['pricecloak_test']['doing_cron'] ?? false );
}

function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
	$text = strip_tags( (string) $text );
	return trim( $text );
}
