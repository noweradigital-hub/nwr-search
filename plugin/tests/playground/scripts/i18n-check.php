<?php
require __DIR__ . '/_bootstrap.php';
if ( isset( $_GET['set'] ) ) {
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	file_put_contents( WPMU_PLUGIN_DIR . '/nwr-test-locale.php', "<?php add_filter( 'locale', static function( \$l ) { \$t = get_option( 'nwr_test_locale' ); return \$t ?: \$l; } );" );
	update_option( 'nwr_test_locale', sanitize_text_field( $_GET['set'] ) );
	echo 'set ' . get_option( 'nwr_test_locale' ); exit;
}
header( 'content-type: application/json' );
$html = do_shortcode( '[nwr_search]' );
echo json_encode( [ 'locale' => determine_locale(), 'loaded' => is_textdomain_loaded( 'nwr-search' ), 'a' => __( 'Najlacnejšie', 'nwr-search' ), 'b' => __( 'Zobraziť všetky výsledky (%d)', 'nwr-search' ), 'placeholder' => preg_match( '/placeholder="([^"]+)"/', $html, $m ) ? $m[1] : '' ], JSON_UNESCAPED_UNICODE );
