<?php
// Configures the plugin against the nwr_dev collection and creates test products.
// Saving products fires the plugin's change tracker, so this also queues delta sync jobs.
require __DIR__ . '/_bootstrap.php';

$env = nwr_test_env();
update_option(
	'nwr_search_settings',
	array(
		'host'       => $env['NWR_SEARCH_HOST'],
		'collection' => $env['NWR_SEARCH_COLLECTION'],
		'site_key'   => $env['NWR_SEARCH_SITE_KEY'],
		'search_key' => $env['NWR_SEARCH_SEARCH_KEY'],
		'auto_sync'  => 1,
	)
);

$toys  = wp_insert_term( 'Hračky', 'product_cat' );
$wood  = wp_insert_term( 'Drevené hračky', 'product_cat', array( 'parent' => $toys['term_id'] ) );
$brand = taxonomy_exists( 'product_brand' ) ? wp_insert_term( 'Demo', 'product_brand' ) : null;

$attribute_id = wc_create_attribute( array( 'name' => 'Farba', 'slug' => 'farba', 'type' => 'select' ) );
register_taxonomy( 'pa_farba', array( 'product' ) );
$red  = wp_insert_term( 'Červená', 'pa_farba', array( 'slug' => 'cervena' ) );
$blue = wp_insert_term( 'Modrá', 'pa_farba', array( 'slug' => 'modra' ) );

$simple = new WC_Product_Simple();
$simple->set_name( 'Drevený vláčik' );
$simple->set_sku( 'DM-VLAK-01' );
$simple->set_regular_price( '24.90' );
$simple->set_sale_price( '19.90' );
$simple->set_manage_stock( true );
$simple->set_stock_quantity( 5 );
$simple->set_category_ids( array( $wood['term_id'] ) );
$simple->set_short_description( '<p>Krásny <strong>drevený</strong> vláčik &amp; koľajnice [vc_row]pre deti[/vc_row]</p>' );
$simple->set_global_unique_id( '8588001234567' );
$simple->save();
if ( $brand ) {
	wp_set_object_terms( $simple->get_id(), array( (int) $brand['term_id'] ), 'product_brand' );
	$simple->save(); // term change alone does not fire product hooks
}

$variable  = new WC_Product_Variable();
$variable->set_name( 'Detské tričko' );
$variable->set_category_ids( array( $toys['term_id'] ) );
$attribute = new WC_Product_Attribute();
$attribute->set_id( $attribute_id );
$attribute->set_name( 'pa_farba' );
$attribute->set_options( array( $red['term_id'], $blue['term_id'] ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$variable->set_attributes( array( $attribute ) );
$variable->save();

foreach ( array( 'cervena' => '12.00', 'modra' => '14.50' ) as $slug => $price ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable->get_id() );
	$variation->set_attributes( array( 'pa_farba' => $slug ) );
	$variation->set_regular_price( $price );
	$variation->set_sku( 'DM-TRICKO-' . strtoupper( $slug ) );
	$variation->save();
}
WC_Product_Variable::sync( $variable->get_id() );

$make = static function ( string $name, array $props ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( '9.99' );
	$product->set_props( $props );
	return $product->save();
};

$ids = array(
	'simple'   => $simple->get_id(),
	'variable' => $variable->get_id(),
	'hidden'   => $make( 'Skrytý produkt', array( 'catalog_visibility' => 'hidden' ) ),
	'draft'    => $make( 'Rozpracovaný koncept', array( 'status' => 'draft' ) ),
	'catalog'  => $make( 'Len v katalógu', array( 'catalog_visibility' => 'catalog' ) ),
	'wood_cat' => (int) $wood['term_id'],
);
update_option( 'nwr_test_ids', $ids );

nwr_test_output(
	array(
		'ids'     => $ids,
		'pending' => as_get_scheduled_actions( array( 'group' => 'nwr-search', 'status' => 'pending', 'per_page' => 100 ), 'ids' ),
	)
);
