<?php
require __DIR__ . '/_bootstrap.php';
$ids      = get_option( 'nwr_uni_ids' );
$settings = \Nowera\Search\Settings::all();
$client   = new \Nowera\Search\Typesense\Client( $settings['host'], $settings['site_key'] );
$doc      = ( new \Nowera\Search\Index\Builder() )->build( $ids['product'] );
$import   = $client->import( $settings['collection'], array( $doc ) );
$after    = wp_remote_get( rtrim( $settings['host'], '/' ) . '/collections/' . $settings['collection'] . '/documents/' . $ids['product'], array( 'headers' => array( 'X-TYPESENSE-API-KEY' => $settings['site_key'] ) ) );
$body     = json_decode( wp_remote_retrieve_body( $after ), true );
nwr_test_output(
	array(
		'collection'   => $settings['collection'],
		'doc_keys'     => array_keys( $doc ),
		'doc_image'    => $doc['image'] ?? null,
		'indexed_at'   => $doc['indexed_at'],
		'import'       => is_wp_error( $import ) ? $import->get_error_message() : $import,
		'after_status' => wp_remote_retrieve_response_code( $after ),
		'after'        => array( 'image' => $body['image'] ?? null, 'indexed_at' => $body['indexed_at'] ?? null ),
	)
);
