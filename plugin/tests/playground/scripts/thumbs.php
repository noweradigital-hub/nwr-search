<?php
// A large product image without small sizes (seen on a live site): indexing must produce a 160×160 copy.
require __DIR__ . '/_bootstrap.php';

$ids = get_option( 'nwr_uni_ids' );

// Only the big sizes, as on the live site.
add_filter( 'intermediate_image_sizes_advanced', static fn( $sizes ) => array_intersect_key( $sizes, array_flip( array( '1536x1536' ) ) ) );

$upload = wp_upload_dir();
$file   = trailingslashit( $upload['path'] ) . 'demopond-hero.png';
$image  = imagecreatetruecolor( 2400, 1800 );
imagefill( $image, 0, 0, imagecolorallocate( $image, 39, 134, 178 ) );
imagepng( $image, $file );
imagedestroy( $image );

$attachment = (int) wp_insert_attachment( array( 'post_title' => 'demopond-hero', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file, $ids['product'] );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $file ) );
set_post_thumbnail( $ids['product'], $attachment );

$before = array_keys( (array) ( wp_get_attachment_metadata( $attachment )['sizes'] ?? array() ) );
$doc    = \Nowera\Search\Index\Indexer::from_settings()->sync( array( $ids['product'] ) );
$meta   = wp_get_attachment_metadata( $attachment );

$settings           = get_option( 'nwr_search_settings' );
$settings['accent'] = '#2786b2';
update_option( 'nwr_search_settings', $settings );

nwr_test_output(
	array(
		'sizes_before'  => $before,
		'sizes_after'   => array_keys( (array) ( $meta['sizes'] ?? array() ) ),
		'thumb'         => $meta['sizes']['nwr-search-thumb'] ?? null,
		'thumb_on_disk' => isset( $meta['sizes']['nwr-search-thumb'] ) && file_exists( dirname( $file ) . '/' . $meta['sizes']['nwr-search-thumb']['file'] ),
		'sync'          => $doc,
		'accent'        => \Nowera\Search\Settings::accent(),
	)
);
