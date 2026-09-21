<?php
/**
 * The one guest-context decision. Every module asks Context::is_guest()
 * instead of testing the current user itself, so "guest" means the same
 * thing on every surface: a front-end request from a visitor with no
 * logged-in user. A process with no user that is not such a request --
 * WP-Cron, WP-CLI, an Action Scheduler batch (its async loopback runs as
 * `admin-ajax.php` nopriv, so the current user is 0 there too), an email
 * being rendered -- is not a guest, and gets the real price.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the price being rendered right now is for a guest.
 *
 * Why the decision is not `! is_user_logged_in()`. The product-level filters
 * (`woocommerce_get_price_html`, `woocommerce_available_variation`, the
 * structured data, the Store API response filter) are attached for the
 * whole process, and the current user is 0 in every context that is not a
 * logged-in request: WP-Cron, `wp` on the command line, the Action
 * Scheduler queue whichever runner drives it, and any email built in one of
 * those. WooCommerce 11.1's customer stock notifications are the concrete
 * case: `EmailTemplatesController::email_product_price()` prints
 * `$product->get_price_html()` into the back-in-stock email inside an
 * Action Scheduler job, and into the verification email during the guest's
 * own front-end POST -- both would otherwise carry the replacement text and
 * a login link pointing at `wp-cron.php`. Emails are out of scope, which
 * means they must not be altered.
 *
 * What is deliberately *not* a context here: `wc-ajax`, REST, Store API
 * and hydration requests. A visitor can make every one of those, so they
 * stay front-end requests and a guest on them stays a guest.
 *
 * How each context is recognised, confirmed against WooCommerce 11.1:
 *
 * - WP-Cron: `wp_doing_cron()`, which reads the `DOING_CRON` constant that
 *   `wp-cron.php` and the `ALTERNATE_WP_CRON` path both define.
 * - WP-CLI: the `WP_CLI` constant.
 * - Action Scheduler: `ActionScheduler_QueueRunner::run()` and the WP-CLI
 *   runner both wrap a batch in `action_scheduler_before_process_queue` /
 *   `action_scheduler_after_process_queue`, and every per-action exception
 *   is caught inside that pair, so "before fired more often than after"
 *   holds for the whole batch. `ActionScheduler_Abstract_QueueRunner::process_action()`
 *   -- also called on its own by `wp action-scheduler run <id>` and the
 *   admin list's Run link -- fires `action_scheduler_before_execute` and
 *   ends with exactly one of `after_execute`, `execution_ignored`,
 *   `canceled_corrupted_action`, `failed_execution` or `failed_validation`,
 *   so the same comparison is made per action too.
 * - Email: the classic HTML templates fire `woocommerce_email_header` at
 *   the top and `woocommerce_email_footer` at the bottom
 *   (`templates/emails/*.php`, the stock notification templates included).
 *   The plain-text templates and the block email templates
 *   (`templates/emails/block/general-block-email.php`) fire neither, but
 *   every amount they print is inside `woocommerce_email_order_details`
 *   (`email-order-details.php`, `plain/email-order-details.php`) or, for the
 *   stock notifications, `woocommerce_email_stock_notification_product`
 *   (HTML and plain), so being inside either section counts as well.
 *
 * The `pricecloak_is_guest` filter lets a site extend the rule -- a role-based
 * exception, a headless client, a context this plugin does not know -- and
 * receives the reason a request was judged not to be a guest.
 *
 * Not final: is_cli() reads a constant, which a unit test cannot define
 * without leaking into every other test, so a test subclass answers for it
 * (the same seam pattern as Purchasing::halt()).
 */
class Context {
	/**
	 * The filter run over every decision: `(bool $is_guest, string $reason)`.
	 * `$reason` is one of the REASON_* constants, or '' for a guest.
	 */
	public const FILTER = 'pricecloak_is_guest';

	public const REASON_LOGGED_IN        = 'logged_in';
	public const REASON_CLI              = 'cli';
	public const REASON_CRON             = 'cron';
	public const REASON_ACTION_SCHEDULER = 'action_scheduler';
	public const REASON_EMAIL            = 'email';

	/**
	 * Action Scheduler: a queue run, and a single action's execution.
	 */
	public const QUEUE_START_ACTION  = 'action_scheduler_before_process_queue';
	public const QUEUE_END_ACTION    = 'action_scheduler_after_process_queue';
	public const ACTION_START_ACTION = 'action_scheduler_before_execute';

	/**
	 * Every way process_action() ends one action, exactly one of which fires.
	 *
	 * @var string[]
	 */
	public const ACTION_END_ACTIONS = [
		'action_scheduler_after_execute',
		'action_scheduler_execution_ignored',
		'action_scheduler_canceled_corrupted_action',
		'action_scheduler_failed_execution',
		'action_scheduler_failed_validation',
	];

	/**
	 * Email: the classic HTML wrapper, and the sections every amount-bearing
	 * template prints its amounts inside.
	 */
	public const EMAIL_START_ACTION = 'woocommerce_email_header';
	public const EMAIL_END_ACTION   = 'woocommerce_email_footer';

	/**
	 * The email sections every amount is printed inside.
	 *
	 * @var string[]
	 */
	public const EMAIL_SECTION_ACTIONS = [
		'woocommerce_email_order_details',
		'woocommerce_email_stock_notification_product',
	];

	/**
	 * Whether the price being rendered is for a guest.
	 */
	public static function is_guest(): bool {
		$reason = static::non_guest_reason();

		/**
		 * Filters the guest decision.
		 *
		 * @param bool   $is_guest The decision so far.
		 * @param string $reason   Why the request is not a guest ('' when it is).
		 */
		return (bool) apply_filters( 'pricecloak_is_guest', '' === $reason, $reason );
	}

	/**
	 * Why the current request is not a guest, or '' when it is. Cheapest
	 * and most common signal first.
	 */
	public static function non_guest_reason(): string {
		if ( is_user_logged_in() ) {
			return self::REASON_LOGGED_IN;
		}
		if ( static::is_cli() ) {
			return self::REASON_CLI;
		}
		if ( self::is_cron() ) {
			return self::REASON_CRON;
		}
		if ( self::is_running_scheduled_action() ) {
			return self::REASON_ACTION_SCHEDULER;
		}
		if ( self::is_rendering_email() ) {
			return self::REASON_EMAIL;
		}
		return '';
	}

	/**
	 * Whether this process is WP-CLI.
	 */
	public static function is_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Whether this request is WP-Cron.
	 */
	public static function is_cron(): bool {
		return function_exists( 'wp_doing_cron' ) && wp_doing_cron();
	}

	/**
	 * Whether an Action Scheduler queue run, or a single scheduled action,
	 * is executing right now.
	 */
	public static function is_running_scheduled_action(): bool {
		if ( self::count( self::QUEUE_START_ACTION ) > self::count( self::QUEUE_END_ACTION ) ) {
			return true;
		}
		$ended = 0;
		foreach ( self::ACTION_END_ACTIONS as $action ) {
			$ended += self::count( $action );
		}
		return self::count( self::ACTION_START_ACTION ) > $ended;
	}

	/**
	 * Whether a WooCommerce email is being rendered right now.
	 */
	public static function is_rendering_email(): bool {
		if ( self::count( self::EMAIL_START_ACTION ) > self::count( self::EMAIL_END_ACTION ) ) {
			return true;
		}
		if ( ! function_exists( 'doing_action' ) ) {
			return false;
		}
		foreach ( self::EMAIL_SECTION_ACTIONS as $action ) {
			if ( doing_action( $action ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * How many times an action has fired in this process.
	 *
	 * @param string $action Action name.
	 */
	private static function count( string $action ): int {
		return function_exists( 'did_action' ) ? (int) did_action( $action ) : 0;
	}
}
