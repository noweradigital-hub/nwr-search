<?php
// Test page for the full-width search band and its boxed variant.
require __DIR__ . '/_bootstrap.php';
$id = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Test pásu',
		'post_content' => '<!-- wp:shortcode -->[nwr_search layout="bar" label="Hľadať" width="1100px"]<!-- /wp:shortcode -->'
			. '<!-- wp:html --><div style="display:flex;justify-content:flex-end"><!-- /wp:html -->'
			. '<!-- wp:shortcode -->[nwr_search layout="bar" fullwidth="no" align="right" label="Box vpravo"]<!-- /wp:shortcode -->'
			. '<!-- wp:html --></div><!-- /wp:html -->'
			. '<!-- wp:shortcode -->[nwr_search layout="bar" fullwidth="no" align="center" width="640" label="Box na stred"]<!-- /wp:shortcode -->'
			. '<div style="height:1400px;background:linear-gradient(#eef,#fee)"></div>',
	)
);
$settings            = get_option( 'nwr_search_settings' );
$settings['exclude'] = array_merge( (array) ( $settings['exclude'] ?? array() ), array( $id ) );
update_option( 'nwr_search_settings', $settings );
nwr_test_output( array( 'page' => get_permalink( $id ), 'id' => $id ) );
