<?php
namespace Nowera\Search;

defined( 'ABSPATH' ) || exit;

/** Writes to WooCommerce → Stav → Logy, source "nwr-search". */
final class Log {

	public static function info( string $message ): void {
		self::write( 'info', $message );
	}

	public static function error( string $message ): void {
		self::write( 'error', $message );
	}

	private static function write( string $level, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'nwr-search' ) );
		}
	}
}
