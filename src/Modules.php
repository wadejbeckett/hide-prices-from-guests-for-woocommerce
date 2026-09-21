<?php
/**
 * The build's module list. Tickets T4 to T9 each add one entry; T16 adds
 * the price filters module.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak;

use PriceCloak\Modules\LegacyRest;
use PriceCloak\Modules\PriceFilters;
use PriceCloak\Modules\PriceHtml;
use PriceCloak\Modules\Purchasing;
use PriceCloak\Modules\StoreApi;
use PriceCloak\Modules\StructuredData;
use PriceCloak\Modules\VariationPayload;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of every module in this build.
 */
final class Modules {
	/**
	 * Every module, enabled or not.
	 *
	 * @return Module[]
	 */
	public static function all(): array {
		return [
			new PriceHtml(),
			new VariationPayload(),
			new StructuredData(),
			new StoreApi(),
			new LegacyRest(),
			new Purchasing(),
			new PriceFilters(),
		];
	}
}
