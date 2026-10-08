<?php
// Shared bootstrap for the local Playground test scripts. Never deploy these.
require '/wordpress/wp-load.php';

header( 'Content-Type: application/json; charset=utf-8' );

function nwr_test_env(): array {
	return nwr_test_env_for( 'nwr_dev' );
}

function nwr_test_env_for( string $collection ): array {
	$env = array();
	// infra/sites is mounted outside the web root, so the keys are readable by PHP but never served.
	foreach ( file( '/nwr-sites/' . $collection . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		if ( '#' !== $line[0] && str_contains( $line, '=' ) ) {
			[ $key, $value ] = explode( '=', $line, 2 );
			$env[ $key ] = trim( $value );
		}
	}
	return $env;
}

function nwr_test_output( array $data ): void {
	echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}
