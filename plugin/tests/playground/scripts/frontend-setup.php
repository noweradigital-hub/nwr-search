<?php
// Frontend-only test: point the widget at a real collection with its PUBLIC search key.
// No site key → Settings::is_configured() is false, so this sandbox can never sync into it.
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

update_option(
	'nwr_search_settings',
	array(
		'host'       => $env['NWR_SEARCH_HOST'],
		'collection' => $env['NWR_SEARCH_COLLECTION'],
		'site_key'   => '',
		'search_key' => $env['NWR_SEARCH_SEARCH_KEY'],
		'auto_sync'  => 0,
	)
);
update_option( 'woocommerce_currency_pos', 'right_space' );

$content = implode(
	"\n\n",
	array(
		'<!-- wp:paragraph --><p>Skúšobná stránka pre Nowera Search.</p><!-- /wp:paragraph -->',
		'<!-- wp:heading {"level":3} --><h3>Inline</h3><!-- /wp:heading -->',
		'<!-- wp:shortcode -->[nwr_search]<!-- /wp:shortcode -->',
		'<!-- wp:heading {"level":3} --><h3>Header (kompaktné, zarovnané vpravo)</h3><!-- /wp:heading -->',
		'<!-- wp:shortcode -->[nwr_search size="compact" align="right" class="test-header"]<!-- /wp:shortcode -->',
		'<!-- wp:heading {"level":3} --><h3>Mobilný header (ikona)</h3><!-- /wp:heading -->',
		'<!-- wp:shortcode -->[nwr_search layout="overlay"]<!-- /wp:shortcode -->',
		'<!-- wp:html --><div style="height:1400px"></div><!-- /wp:html -->',
	)
);

$page_id = (int) get_option( 'nwr_test_page_id' );
$page    = array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'Test vyhľadávania',
	'post_content' => $content,
);
if ( $page_id && get_post( $page_id ) ) {
	wp_update_post( $page + array( 'ID' => $page_id ) );
} else {
	$page_id = wp_insert_post( $page );
	update_option( 'nwr_test_page_id', $page_id );
}

nwr_test_output(
	array(
		'configured_for_sync' => \Nowera\Search\Settings::is_configured(),
		'frontend_ready'      => \Nowera\Search\Settings::frontend_ready(),
		'page'                => get_permalink( $page_id ),
	)
);
