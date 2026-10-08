<?php
// 0.6.6: switches "categories open the results page" (?on=1|0) and shows what the panel config carries.
require __DIR__ . '/_bootstrap.php';

if ( isset( $_GET['on'] ) ) {
	$settings                   = get_option( 'nwr_search_settings' );
	$settings['category_links'] = '1' === $_GET['on'] ? 1 : 0;
	update_option( 'nwr_search_settings', $settings );
}
\Nowera\Search\Content_Types::flush();

$config = ( new ReflectionMethod( \Nowera\Search\Frontend\Assets::class, 'config' ) )->invoke( null );

nwr_test_output(
	array(
		'category_links' => \Nowera\Search\Settings::get( 'category_links' ),
		'categoryLinks'  => $config['categoryLinks'],
		'searchUrl'      => $config['searchUrl'],
		'types'          => wp_list_pluck( $config['types'], 'taxonomy', 'id' ),
		'categories'     => $config['categories'],
		'filters'        => wp_list_pluck( $config['results']['filters'], 'id' ),
		'typeFilters'    => array_map( static fn( $filters ) => wp_list_pluck( $filters, 'id' ), (array) $config['results']['typeFilters'] ),
		'test_page'      => get_permalink( (int) get_option( 'nwr_uni_ids' )['test'] ),
	)
);
