<?php
require __DIR__ . '/_bootstrap.php';
add_filter( 'intermediate_image_sizes_advanced', static fn( $sizes ) => array_intersect_key( $sizes, array_flip( array( '1536x1536' ) ) ) );
$upload = wp_upload_dir();
$file   = trailingslashit( $upload['path'] ) . 'debug-' . time() . '.png';
$image  = imagecreatetruecolor( 2000, 1500 );
imagepng( $image, $file );
$att = (int) wp_insert_attachment( array( 'post_title' => 'debug', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $file ) );
$t0  = microtime( true );
$url = \Nowera\Search\Index\Thumbnail::url( $att, 99999 );
nwr_test_output(
	array(
		'request_time_float' => $_SERVER['REQUEST_TIME_FLOAT'] ?? null,
		'now'                => microtime( true ),
		'elapsed_since_req'  => microtime( true ) - (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? 0 ),
		'url'                => $url,
		'took'               => round( microtime( true ) - $t0, 3 ),
		'deferred'           => \Nowera\Search\Index\Thumbnail::take_deferred(),
		'sizes'              => array_keys( (array) ( wp_get_attachment_metadata( $att )['sizes'] ?? array() ) ),
	)
);
