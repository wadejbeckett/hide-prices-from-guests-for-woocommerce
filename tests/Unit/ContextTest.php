<?php
/**
 * The one guest-context decision: a plain anonymous request is a guest;
 * a logged-in user, WP-Cron, WP-CLI, an executing Action Scheduler action
 * and an email being rendered are not, whoever the current user is.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit;

use PriceCloak\Context;
use PriceCloak\Modules;
use PriceCloak\Modules\PriceHtml;
use PHPUnit\Framework\TestCase;

/**
 * The WP-CLI seam: the constant cannot be defined in one test without
 * leaking into every other, so the subclass answers for it.
 */
final class CliContext extends Context {
	public static function is_cli(): bool {
		return true;
	}
}

final class ContextTest extends TestCase {
	private const PRICE = '<span class="woocommerce-Price-amount amount">&#36;12.00</span>';

	protected function setUp(): void {
		pricecloak_test_reset();
	}

	public function test_a_plain_anonymous_request_is_a_guest(): void {
		$this->assertTrue( Context::is_guest() );
		$this->assertSame( '', Context::non_guest_reason() );
	}

	public function test_a_logged_in_user_is_not_a_guest(): void {
		$GLOBALS['pricecloak_test']['logged_in'] = true;

		$this->assertFalse( Context::is_guest() );
		$this->assertSame( Context::REASON_LOGGED_IN, Context::non_guest_reason() );
	}

	public function test_wp_cron_is_not_a_guest(): void {
		$GLOBALS['pricecloak_test']['doing_cron'] = true;

		$this->assertFalse( Context::is_guest() );
		$this->assertSame( Context::REASON_CRON, Context::non_guest_reason() );
	}

	public function test_wp_cli_is_not_a_guest(): void {
		$this->assertFalse( Context::is_cli(), 'The suite itself does not run under WP-CLI.' );
		$this->assertFalse( CliContext::is_guest() );
		$this->assertSame( Context::REASON_CLI, CliContext::non_guest_reason() );
	}

	public function test_an_action_scheduler_queue_run_is_not_a_guest(): void {
		do_action( Context::QUEUE_START_ACTION );

		$this->assertFalse( Context::is_guest() );
		$this->assertSame( Context::REASON_ACTION_SCHEDULER, Context::non_guest_reason() );

		do_action( Context::QUEUE_END_ACTION );

		$this->assertTrue( Context::is_guest(), 'After the queue run the request is a guest again.' );
	}

	public function test_a_single_action_scheduler_action_is_not_a_guest(): void {
		// `wp action-scheduler run <id>` and the admin "Run" link call
		// process_action() directly, with no queue hooks around it.
		do_action( Context::ACTION_START_ACTION, 7, 'Action Scheduler CLI' );

		$this->assertFalse( Context::is_guest() );
		$this->assertSame( Context::REASON_ACTION_SCHEDULER, Context::non_guest_reason() );
	}

	public function test_every_way_an_action_can_end_restores_the_guest(): void {
		foreach ( Context::ACTION_END_ACTIONS as $end ) {
			pricecloak_test_reset();
			do_action( Context::ACTION_START_ACTION, 7, 'WP Cron' );
			$this->assertFalse( Context::is_guest(), "Not a guest while the action runs (ending with $end)." );

			do_action( $end, 7 );
			$this->assertTrue( Context::is_guest(), "$end ends the action." );
		}
	}

	public function test_a_failed_action_inside_a_queue_run_keeps_the_batch_non_guest(): void {
		do_action( Context::QUEUE_START_ACTION );
		do_action( Context::ACTION_START_ACTION, 1 );
		do_action( 'action_scheduler_after_execute', 1 );
		// mark_complete() threw after after_execute: both terminal hooks fire for one action.
		do_action( 'action_scheduler_failed_execution', 1 );
		do_action( Context::ACTION_START_ACTION, 2 );

		$this->assertFalse( Context::is_guest(), 'The queue-level counter is not confused by a double-counted action.' );
	}

	public function test_an_html_email_being_rendered_is_not_a_guest(): void {
		do_action( Context::EMAIL_START_ACTION, 'Heading', null );

		$this->assertFalse( Context::is_guest() );
		$this->assertSame( Context::REASON_EMAIL, Context::non_guest_reason() );

		do_action( Context::EMAIL_END_ACTION, null );

		$this->assertTrue( Context::is_guest(), 'After the footer the request is a guest again.' );
	}

	public function test_the_email_sections_without_a_header_are_not_a_guest_either(): void {
		// Plain-text and block email templates fire no header or footer; the
		// order details and the stock notification product sections do.
		foreach ( Context::EMAIL_SECTION_ACTIONS as $section ) {
			pricecloak_test_reset();
			$GLOBALS['pricecloak_test']['doing_actions'] = [ $section ];

			$this->assertFalse( Context::is_guest(), "$section is an email section." );
			$this->assertSame( Context::REASON_EMAIL, Context::non_guest_reason() );
		}
	}

	public function test_the_email_hooks_are_the_ones_woocommerce_fires(): void {
		$this->assertSame( 'woocommerce_email_header', Context::EMAIL_START_ACTION );
		$this->assertSame( 'woocommerce_email_footer', Context::EMAIL_END_ACTION );
		$this->assertContains( 'woocommerce_email_order_details', Context::EMAIL_SECTION_ACTIONS );
		$this->assertContains( 'woocommerce_email_stock_notification_product', Context::EMAIL_SECTION_ACTIONS );
		$this->assertSame( 'action_scheduler_before_process_queue', Context::QUEUE_START_ACTION );
		$this->assertSame( 'action_scheduler_after_process_queue', Context::QUEUE_END_ACTION );
		$this->assertSame( 'action_scheduler_before_execute', Context::ACTION_START_ACTION );
		$this->assertSame(
			[
				'action_scheduler_after_execute',
				'action_scheduler_execution_ignored',
				'action_scheduler_canceled_corrupted_action',
				'action_scheduler_failed_execution',
				'action_scheduler_failed_validation',
			],
			Context::ACTION_END_ACTIONS
		);
	}

	public function test_the_filter_can_extend_the_rule_either_way(): void {
		add_filter( Context::FILTER, static fn(): bool => false );
		$this->assertFalse( Context::is_guest(), 'A site can declare a guest a non-guest.' );

		pricecloak_test_reset();
		$GLOBALS['pricecloak_test']['doing_cron'] = true;
		$seen                                     = [];
		add_filter(
			Context::FILTER,
			static function ( bool $is_guest, string $reason ) use ( &$seen ): bool {
				$seen = [ $is_guest, $reason ];
				return true;
			},
			10,
			2
		);

		$this->assertTrue( Context::is_guest(), 'A site can declare cron a guest.' );
		$this->assertSame( [ false, Context::REASON_CRON ], $seen, 'The filter is told the decision and its reason.' );
	}

	public function test_ajax_rest_and_hydration_requests_stay_guest_contexts(): void {
		// A `wc-ajax`, REST, Store API or hydration request is a front-end
		// request a visitor can make; nothing about it may count as a
		// non-guest signal, so the decision must not read those flags.
		$code = '';
		foreach ( token_get_all( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Context.php' ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}
		foreach ( [ 'wp_doing_ajax', 'DOING_AJAX', 'REST_REQUEST', 'wp_is_json_request', 'wc-ajax', 'WC_DOING_AJAX', 'wp_is_serving_rest_request' ] as $flag ) {
			$this->assertStringNotContainsString( $flag, $code, "$flag is a front-end request, not a context." );
		}
	}

	public function test_price_html_passes_the_original_through_inside_an_email(): void {
		( new PriceHtml() )->register();
		do_action( Context::EMAIL_START_ACTION, 'Heading', null );

		$this->assertSame( self::PRICE, apply_filters( 'woocommerce_get_price_html', self::PRICE ) );

		do_action( Context::EMAIL_END_ACTION, null );

		$this->assertStringContainsString( 'Sign in to see prices', (string) apply_filters( 'woocommerce_get_price_html', self::PRICE ) );
	}

	public function test_price_html_passes_the_original_through_inside_a_scheduled_action_and_cron(): void {
		( new PriceHtml() )->register();

		do_action( Context::ACTION_START_ACTION, 3 );
		$this->assertSame( self::PRICE, apply_filters( 'woocommerce_get_price_html', self::PRICE ) );
		do_action( 'action_scheduler_after_execute', 3 );

		$GLOBALS['pricecloak_test']['doing_cron'] = true;
		$this->assertSame( self::PRICE, apply_filters( 'woocommerce_get_price_html', self::PRICE ) );
	}

	public function test_no_module_decides_guest_on_its_own(): void {
		$src = dirname( __DIR__, 2 ) . '/src';
		foreach ( (array) glob( "$src/{*,*/*}.php", GLOB_BRACE ) as $file ) {
			if ( 'Context.php' === basename( (string) $file ) ) {
				continue;
			}
			$this->assertStringNotContainsString( 'is_user_logged_in', (string) file_get_contents( (string) $file ), basename( (string) $file ) . ' must ask Context::is_guest().' );
		}
		$this->assertNotEmpty( Modules::all() );
	}
}
