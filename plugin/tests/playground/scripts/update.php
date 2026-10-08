<?php
// Delta paths that do not go through a normal product save.
require __DIR__ . '/_bootstrap.php';

$ids = get_option( 'nwr_test_ids' );

// 1. Checkout-style stock change → woocommerce_product_set_stock (+ stock status).
$simple = wc_get_product( $ids['simple'] );
wc_update_product_stock( $simple, 0, 'set' );

// 2. Price change through a regular save.
$simple = wc_get_product( $ids['simple'] );
$simple->set_sale_price( '17.90' );
$simple->save();

// 3. Trash the variable product → trashed_post.
wp_trash_post( $ids['variable'] );

// 4. Rename a category → edited_term → term job for every product in it.
wp_update_term( $ids['wood_cat'], 'product_cat', array( 'name' => 'Drevo' ) );

nwr_test_output(
	array(
		'pending' => array_map(
			static fn( $id ) => ActionScheduler::store()->fetch_action( $id )->get_hook(),
			as_get_scheduled_actions( array( 'group' => 'nwr-search', 'status' => 'pending', 'per_page' => 50 ), 'ids' )
		),
	)
);
