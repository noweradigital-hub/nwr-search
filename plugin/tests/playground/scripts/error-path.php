<?php
// ?mode=break → wrong site key + product save (queues a job that must fail and schedule a retry)
// ?mode=restore → put the real key back
require __DIR__ . '/_bootstrap.php';

$settings = get_option( 'nwr_search_settings' );
$env      = nwr_test_env();
$ids      = get_option( 'nwr_test_ids' );

if ( 'break' === ( $_GET['mode'] ?? '' ) ) {
	$settings['site_key'] = 'invalid-key';
	update_option( 'nwr_search_settings', $settings );
	$product = wc_get_product( $ids['catalog'] );
	$product->set_name( 'Len v katalógu (upravené)' );
	$product->save();
} else {
	$settings['site_key'] = $env['NWR_SEARCH_SITE_KEY'];
	update_option( 'nwr_search_settings', $settings );
}

nwr_test_output( array( 'mode' => $_GET['mode'] ?? 'restore' ) );
