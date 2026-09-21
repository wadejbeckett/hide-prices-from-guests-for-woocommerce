<?php
/**
 * Minimal stand-in for WooCommerce's FeaturesUtil, recording what the plugin
 * file declares. Defined only when WooCommerce itself is absent.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Utilities;

if ( ! class_exists( FeaturesUtil::class, false ) ) {
	/**
	 * Records declare_compatibility() calls in the shared test state.
	 */
	class FeaturesUtil {
		/**
		 * Record one declaration.
		 *
		 * @param string $feature_id Feature slug.
		 * @param string $file       Plugin file making the declaration.
		 * @param bool   $compatible Whether it is declared compatible.
		 */
		public static function declare_compatibility( string $feature_id, string $file, bool $compatible = true ): bool {
			$GLOBALS['pricecloak_test']['compatibility'][ $feature_id ] = [
				'file'       => $file,
				'compatible' => $compatible,
			];
			return true;
		}
	}
}
