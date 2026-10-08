<?php
require __DIR__ . '/_bootstrap.php';
$ids = get_option( 'nwr_uni_ids' );
add_filter( 'intermediate_image_sizes_advanced', static fn( $sizes ) => array_intersect_key( $sizes, array_flip( array( '1536x1536' ) ) ) );
$upload = wp_upload_dir();
$file   = trailingslashit( $upload['path'] ) . 'debug3-' . time() . '.png';
$image  = imagecreatetruecolor( 2000, 1500 );
imagepng( $image, $file );
$att = (int) wp_insert_attachment( array( 'post_title' => 'debug3', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file, $ids['product'] );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $file ) );
$set = set_post_thumbnail( $ids['product'], $att );
$thumb_id = (int) get_post_thumbnail_id( $ids['product'] );
$doc = ( new \Nowera\Search\Index\Builder() )->build( $ids['product'] );
nwr_test_output(
	array(
		'att'       => $att,
		'set'       => $set,
		'thumb_id'  => $thumb_id,
		'doc_image' => $doc['image'] ?? '(missing)',
		'sizes'     => array_keys( (array) ( wp_get_attachment_metadata( $att )['sizes'] ?? array() ) ),
	)
);
