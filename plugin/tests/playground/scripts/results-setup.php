<?php
// Results page against real collection data, with the PUBLIC search key only.
// No site key → Settings::is_configured() is false, so this sandbox can never write into it.
require __DIR__ . '/_bootstrap.php';

// ?site=<collection> reads /nwr-sites/<collection>.env (mounted from infra/sites, never committed).
$site = preg_replace( '/[^a-z0-9_]/', '', (string) ( $_GET['site'] ?? 'nwr_dev' ) );

$env = array();
foreach ( file( "/nwr-sites/{$site}.env", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	if ( '#' !== $line[0] && str_contains( $line, '=' ) ) {
		[ $key, $value ] = explode( '=', $line, 2 );
		$env[ $key ] = trim( $value );
	}
}

$page_id = (int) get_option( 'nwr_results_page_id' );
$page    = array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'Vyhľadávanie',
	'post_content' => '<!-- wp:shortcode -->[nwr_search_results]<!-- /wp:shortcode -->',
);
if ( $page_id && get_post( $page_id ) ) {
	wp_update_post( $page + array( 'ID' => $page_id ) );
} else {
	$page_id = wp_insert_post( $page );
	update_option( 'nwr_results_page_id', $page_id );
}

update_option(
	'nwr_search_settings',
	array(
		'host'          => $env['NWR_SEARCH_HOST'],
		'collection'    => $env['NWR_SEARCH_COLLECTION'],
		'site_key'      => '',
		'search_key'    => $env['NWR_SEARCH_SEARCH_KEY'],
		'auto_sync'     => 0,
		'results_page'  => $page_id,
		'takeover'      => 1,
		'filters'       => array(
			'categories'   => 1,
			'brands'       => 1,
			'price'        => 1,
			'availability' => 1,
			'sale'         => 1,
			'attributes'   => array(),
		),
		'attach'        => '',
		'boost_buckets' => 10,
	)
);
update_option( 'woocommerce_currency_pos', 'right_space' );

nwr_test_output(
	array(
		'configured_for_sync' => \Nowera\Search\Settings::is_configured(),
		'frontend_ready'      => \Nowera\Search\Settings::frontend_ready(),
		'results_url'         => \Nowera\Search\Settings::results_url(),
		'filters'             => \Nowera\Search\Settings::filters(),
		'page'                => get_permalink( $page_id ),
	)
);
