#!/usr/bin/env bash
#
# Seeds the fixtures the anonymous HTTP probe needs, idempotently.
#
# Creates (or updates, matched by SKU) one simple product (sold individually),
# one variable product with two priced variations, and one grouped product
# containing the simple one.
# All published, in stock and visible in the catalogue. Also sets pretty
# permalinks, makes sure WooCommerce is active and switches "Coming soon" mode
# off, so an anonymous client can actually reach the shop.
#
# The result is written to tests/probe/seed.json (and echoed to stdout) in the
# shape probe.py expects.
#
# Usage:
#   bash tests/probe/seed.sh
#   WP_CMD="npx wp-env run --quiet cli wp" bash tests/probe/seed.sh   # wp-env
#   WP_CMD="docker compose exec -T cli wp" bash tests/probe/seed.sh   # plain docker
#
# Environment:
#   WP_CMD     the WP-CLI invocation to use. Default: wp
#   SEED_JSON  where to write the fixture description. Default: tests/probe/seed.json
#
# The plugin's master switch is a plain option, so the probe's two passes are:
#   $WP_CMD option update pricecloak_enabled no    # then probe --expect visible
#   $WP_CMD option update pricecloak_enabled yes   # then probe --expect hidden
#
# The Purchasing module (module 6) has its own switch, off by default, and the
# probe's purchasing group is told which state to expect:
#   $WP_CMD option update pricecloak_block_purchasing no   # probe --expect-purchasing allowed
#   $WP_CMD option update pricecloak_block_purchasing yes  # probe --expect-purchasing blocked
# "allowed" is the default, so a shop with prices hidden but purchasing left on
# is probed with --expect hidden and no purchasing flag at all.
#
# Each module can also be switched off on its own, which hands that one surface
# back to guests while the rest stay hidden. The option is pricecloak_module_<id>,
# default yes (Purchasing is the exception: its switch is pricecloak_block_purchasing,
# default no). The ids, and the surface each covers:
#
#   price_html         prices on the shop, product pages, blocks and widgets, the
#                      product grids' data-price attribute, a guest's own order
#   variation_payload  the variation data a variable product page sends out
#   structured_data    the offers block in product JSON-LD
#   store_api          every Store API response (products, cart, checkout, order, batch;
#                      /wc/store/v1/ and the /wc/store/ alias) and the copies of those
#                      responses embedded into the block pages
#   legacy_rest        /wc/v3/products* responses
#   price_filters      price-range filtering and price sorting (shop, Store API and
#                      /wp/v2/product), the price filter widget and blocks
#   purchasing         guest add-to-cart, checkout processing, cart and checkout pages
#                      (pricecloak_block_purchasing)
#
# Tell the probe which are off so it expects those surfaces to show prices:
#   $WP_CMD option update pricecloak_module_structured_data no
#   python3 tests/probe/probe.py ... --expect hidden --modules-off structured_data
#
# The classic checkout POST needs state across two runs: pass the same
# --session-file PATH to the allowed run (it saves a guest's cookies and
# process-checkout nonce when the checkout page carries the classic
# [woocommerce_checkout] form) and to the blocked run (it replays them against
# ?wc-ajax=checkout). On the default Checkout block page that row is a SKIP.
#
set -euo pipefail

WP_CMD=${WP_CMD:-wp}
SEED_JSON=${SEED_JSON:-"$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/seed.json"}

# Word-split WP_CMD deliberately: it is a command line, not a single binary.
# Named wpcli, not wp: WP_CMD may itself be "wp", and a shell function called
# wp would then call itself forever.
wpcli() {
	# shellcheck disable=SC2086
	$WP_CMD "$@"
}

wpcli plugin is-active woocommerce >/dev/null 2>&1 || wpcli plugin activate woocommerce

wpcli rewrite structure '/%postname%/' --hard >/dev/null
wpcli rewrite flush --hard >/dev/null

# WooCommerce 8.6+ "Coming soon" / launch-your-store mode hides the whole site
# from logged-out visitors, which would make every probe check meaningless.
wpcli option update woocommerce_coming_soon no >/dev/null
wpcli option update woocommerce_store_pages_only no >/dev/null

# The seeding itself runs inside WordPress so it can use the WooCommerce CRUD
# classes; doing it with raw post meta would mean reimplementing variation sync.
SEED_PHP=$(cat <<'PHP'
$upsert = function ( $class, $sku, $name ) {
	$id      = wc_get_product_id_by_sku( $sku );
	$product = $id ? new $class( $id ) : new $class();
	$product->set_sku( $sku );
	$product->set_name( $name );
	$product->set_description( 'Fixture created by tests/probe/seed.sh.' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	return $product;
};

$simple = $upsert( 'WC_Product_Simple', 'pricecloak-simple', 'Probe Simple Product' );
$simple->set_regular_price( '123.45' );
$simple->set_price( '123.45' );
$simple->set_stock_status( 'instock' );
// Sold individually on purpose: as a grouped child this makes WooCommerce's
// grouped add-to-cart template print its "Buy one of X for $Y" checkbox label,
// which is built with wc_price() directly rather than through a price filter.
$simple->set_sold_individually( true );
$simple_id = $simple->save();

$variable = $upsert( 'WC_Product_Variable', 'pricecloak-variable', 'Probe Variable Product' );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Size' );
$attribute->set_options( array( 'Small', 'Large' ) );
$attribute->set_position( 0 );
$attribute->set_visible( true );
$attribute->set_variation( true );
$variable->set_attributes( array( $attribute ) );
$variable->set_stock_status( 'instock' );
$variable_id = $variable->save();

$variations = array();
foreach ( array( 'Small' => '101.00', 'Large' => '202.00' ) as $option => $price ) {
	$vsku      = 'pricecloak-variable-' . strtolower( $option );
	$vid       = wc_get_product_id_by_sku( $vsku );
	$variation = $vid ? new WC_Product_Variation( $vid ) : new WC_Product_Variation();
	$variation->set_parent_id( $variable_id );
	$variation->set_sku( $vsku );
	$variation->set_status( 'publish' );
	$variation->set_attributes( array( 'size' => $option ) );
	$variation->set_regular_price( $price );
	$variation->set_price( $price );
	$variation->set_stock_status( 'instock' );
	$vid = $variation->save();

	$variations[] = array(
		'id'         => (int) $vid,
		'sku'        => $vsku,
		'price'      => $price,
		'attributes' => array( 'attribute_size' => $option ),
	);
}
WC_Product_Variable::sync( $variable_id );

$grouped = $upsert( 'WC_Product_Grouped', 'pricecloak-grouped', 'Probe Grouped Product' );
$grouped->set_children( array( (int) $simple_id ) );
$grouped_id = $grouped->save();

$describe = function ( $id ) {
	$post = get_post( $id );
	return array(
		'id'   => (int) $id,
		'slug' => $post->post_name,
		'path' => wp_make_link_relative( get_permalink( $id ) ),
	);
};

$seed = array(
	'shop_path'       => wp_make_link_relative( get_permalink( wc_get_page_id( 'shop' ) ) ),
	'cart_path'       => wp_make_link_relative( wc_get_cart_url() ),
	'checkout_path'   => wp_make_link_relative( wc_get_checkout_url() ),
	'account_path'    => wp_make_link_relative( wc_get_page_permalink( 'myaccount' ) ),
	'currency'        => get_woocommerce_currency(),
	'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
	'simple'          => $describe( $simple_id ) + array( 'price' => '123.45' ),
	'grouped'         => $describe( $grouped_id ),
	'variable'        => $describe( $variable_id ) + array( 'variations' => $variations ),
);

echo "PRICECLOAK_SEED_BEGIN\n" . wp_json_encode( $seed ) . "\nPRICECLOAK_SEED_END\n";
PHP
)

RAW=$(wpcli eval "$SEED_PHP")
printf '%s\n' "$RAW" | sed -n '/PRICECLOAK_SEED_BEGIN/,/PRICECLOAK_SEED_END/p' | sed '1d;$d' > "$SEED_JSON"

if [ ! -s "$SEED_JSON" ]; then
	echo "seed.sh: WP-CLI produced no seed JSON; raw output was:" >&2
	printf '%s\n' "$RAW" >&2
	exit 1
fi

cat "$SEED_JSON"
echo "seed.sh: wrote $SEED_JSON" >&2
