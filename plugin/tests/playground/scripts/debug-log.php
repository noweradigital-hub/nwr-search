<?php
// Shows the tail of the PHP error log inside Playground.
define( 'WP_DEBUG', true );
require '/wordpress/wp-load.php';
header( 'Content-Type: text/plain; charset=utf-8' );
$candidates = array_filter( array( ini_get( 'error_log' ), WP_CONTENT_DIR . '/debug.log', '/wordpress/wp-content/debug.log', '/tmp/php-errors.log' ) );
foreach ( $candidates as $log ) {
	if ( is_readable( $log ) ) {
		echo "== $log\n";
		$lines = file( $log );
		echo implode( '', array_slice( $lines, -25 ) );
	}
}
echo "error_log ini: " . ini_get( 'error_log' ) . "\n";
