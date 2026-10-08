<?php
// Toggle icon colour: admin setting (sanitised like the accent) + per-shortcode override.
// ?color=0ea5e9 sets the global colour, ?color= clears it.
require __DIR__ . '/_bootstrap.php';

$page      = new \Nowera\Search\Admin\Settings_Page();
$sanitized = array();
foreach ( array( '0EA5E9', '#fff', ' #0ea5e9 ', 'red', '#12345', '' ) as $input ) {
	$sanitized[ $input ] = $page->sanitize( array( 'icon_color' => $input ) )['icon_color'];
}

$settings               = get_option( 'nwr_search_settings' );
$settings['icon_color'] = (string) sanitize_hex_color( '#' . ltrim( (string) ( $_GET['color'] ?? '' ), '#' ) );
$settings['accent']     = (string) sanitize_hex_color( '#' . ltrim( (string) ( $_GET['accent'] ?? '' ), '#' ) );

$id = (int) get_option( 'nwr_test_icon_page' );
if ( ! $id || ! get_post( $id ) ) {
	$id = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Test farby ikonky',
			'post_content' => '<!-- wp:html --><div id="nwr-dark-header" style="display:flex;gap:16px;align-items:center;padding:12px 20px;background:#111827;color:#ffffff"><!-- /wp:html -->'
				. '<!-- wp:shortcode -->[nwr_search layout="bar" label="Globálna"]<!-- /wp:shortcode -->'
				. '<!-- wp:shortcode -->[nwr_search layout="overlay" icon_color="#e11d48"]<!-- /wp:shortcode -->'
				. '<!-- wp:shortcode -->[nwr_search layout="bar" fullwidth="no" icon_color="16a34a" label="Bez mriežky"]<!-- /wp:shortcode -->'
				. '<!-- wp:shortcode -->[nwr_search layout="bar" icon_color="red;background:url(x)" label="Zlá hodnota"]<!-- /wp:shortcode -->'
				. '<!-- wp:html --></div><!-- /wp:html -->',
		)
	);
	update_option( 'nwr_test_icon_page', $id );
	$settings['exclude'] = array_merge( (array) ( $settings['exclude'] ?? array() ), array( $id ) );
}
update_option( 'nwr_search_settings', $settings );

nwr_test_output(
	array(
		'page'       => get_permalink( $id ),
		'icon_color' => \Nowera\Search\Settings::icon_color(),
		'sanitized'  => $sanitized,
	)
);
