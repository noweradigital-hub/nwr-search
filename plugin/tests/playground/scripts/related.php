<?php
// Upsell + cross-sell on the simple product → its document must carry related_ids after the sync job.
require __DIR__ . '/_bootstrap.php';

$ids     = get_option( 'nwr_test_ids' );
$product = wc_get_product( $ids['simple'] );
$product->set_upsell_ids( array( $ids['variable'], $ids['catalog'] ) );
$product->set_cross_sell_ids( array( $ids['catalog'], $ids['simple'] ) ); // duplicate + self must be dropped
$product->save();

$document = ( new \Nowera\Search\Index\Product_Document() )->build( wc_get_product( $ids['simple'] ) );

nwr_test_output(
	array(
		'expected'    => array( $ids['variable'], $ids['catalog'] ),
		'related_ids' => $document['related_ids'] ?? null,
	)
);
