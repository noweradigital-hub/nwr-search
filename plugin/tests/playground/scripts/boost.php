<?php
// Per-product boost: admin save paths, document field, schema patch, and the ranking it produces.
require __DIR__ . '/_bootstrap.php';

use Nowera\Search\Admin\Product_Boost;
use Nowera\Search\Index\Indexer;
use Nowera\Search\Index\Product_Document;
use Nowera\Search\Settings;
use Nowera\Search\Typesense\Client;

$ids    = get_option( 'nwr_test_ids' );
$admin  = new Product_Boost();
$report = array();

// 1. Product screen: WooCommerce hands over the object and saves it afterwards.
$product                                    = wc_get_product( $ids['simple'] );
$_POST[ Product_Document::BOOST_META ]      = '40';
$admin->save_product( $product );
$product->save();
$report['product_screen'] = (int) wc_get_product( $ids['simple'] )->get_meta( Product_Document::BOOST_META );

// 1b. An editor that never rendered the field (BrikPanel) fires the same hook: nothing may change.
unset( $_POST[ Product_Document::BOOST_META ] );
$untouched = wc_get_product( $ids['simple'] );
$admin->save_product( $untouched );
$untouched->save();
$report['absent_field_keeps_value'] = 40 === (int) wc_get_product( $ids['simple'] )->get_meta( Product_Document::BOOST_META );

// 2. Quick edit: WooCommerce has already saved, so the handler must save itself.
$_REQUEST[ Product_Document::BOOST_META ] = '-250'; // out of range → clamped
$admin->quick_edit_save( wc_get_product( $ids['variable'] ) );
$report['quick_edit_clamped'] = (int) wc_get_product( $ids['variable'] )->get_meta( Product_Document::BOOST_META );

// 3. Bulk edit with an empty value must not touch anything.
unset( $_REQUEST[ Product_Document::BOOST_META ] );
$_REQUEST[ Product_Boost::BULK_FIELD ] = '';
$admin->bulk_edit_save( wc_get_product( $ids['catalog'] ) );
$report['bulk_empty_noop'] = '' === (string) wc_get_product( $ids['catalog'] )->get_meta( Product_Document::BOOST_META );

// 4. Zero clears the meta instead of storing a useless row.
$_POST[ Product_Document::BOOST_META ] = '0';
$zeroed                                 = wc_get_product( $ids['simple'] );
$admin->save_product( $zeroed );
$zeroed->save();
$report['zero_clears_meta'] = '' === (string) wc_get_product( $ids['simple'] )->get_meta( Product_Document::BOOST_META );

// Put the boost back for the ranking check below.
$_POST[ Product_Document::BOOST_META ] = '40';
$boosted                                = wc_get_product( $ids['simple'] );
$admin->save_product( $boosted );
$boosted->save();

// 5. The document always carries boost, 0 included.
$documents = array();
foreach ( array( 'simple', 'variable', 'catalog' ) as $key ) {
	$document           = ( new Product_Document() )->build( wc_get_product( $ids[ $key ] ) );
	$documents[ $key ] = $document['boost'] ?? 'missing';
}
$report['documents'] = $documents;

// 6. Schema patch + ranking against the real dev collection.
$indexer = Indexer::from_settings();
$indexer->ensure_collection();
$indexer->sync( array( $ids['simple'], $ids['variable'], $ids['catalog'] ) );

$settings   = Settings::all();
$client     = new Client( $settings['host'], $settings['search_key'] );
$collection = ( new Client( $settings['host'], $settings['site_key'] ) )->get_collection( $settings['collection'] );

$report['schema_has_boost'] = in_array( 'boost', array_column( $collection['fields'] ?? array(), 'name' ), true );
$report['boost_buckets']    = array(
	'saved_10'      => Settings::boost_buckets( 10 ),
	'nonsense_to_default' => Settings::boost_buckets( 7 ),
	'off'           => Settings::boost_buckets( 0 ),
);

$rank = 'boost(missing_values: last):desc,popularity:desc';
foreach ( array( 'browse' => $rank, 'search_buckets' => '_text_match(buckets: 10):desc,' . $rank, 'search_plain' => '_text_match:desc,' . $rank ) as $label => $sort ) {
	$query  = 'browse' === $label ? '*' : 'produkt';
	$result = $client->search(
		$settings['collection'],
		array(
			'q'              => $query,
			'query_by'       => 'title,short_description',
			'sort_by'        => $sort,
			'per_page'       => 5,
			'include_fields' => 'id,title,boost',
		)
	);
	$report['ranking'][ $label ] = is_wp_error( $result )
		? $result->get_error_message()
		: array_map( static fn( array $hit ): string => $hit['document']['title'] . ' (' . ( $hit['document']['boost'] ?? '—' ) . ')', $result['hits'] );
}

nwr_test_output( $report );
