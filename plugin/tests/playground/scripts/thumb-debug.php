<?php
require __DIR__ . '/_bootstrap.php';
$ids = get_option( 'nwr_uni_ids' );
$att = (int) get_post_thumbnail_id( $ids['product'] );
$doc = ( new \Nowera\Search\Index\Builder() )->build( $ids['product'] );
nwr_test_output(
	array(
		'thumbnail_id' => $att,
		'is_image'     => wp_attachment_is_image( $att ),
		'direct'       => wp_get_attachment_image_url( $att, 'nwr-search-thumb' ),
		'src'          => wp_get_attachment_image_src( $att, 'nwr-search-thumb' ),
		'helper'       => \Nowera\Search\Index\Thumbnail::url( $att, $ids['product'] ),
		'doc_image'    => $doc['image'] ?? '(missing)',
		'meta_file'    => wp_get_attachment_metadata( $att )['file'] ?? null,
		'attached'     => get_post_meta( $att, '_wp_attached_file', true ),
	)
);
