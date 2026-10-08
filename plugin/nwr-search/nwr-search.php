<?php
/**
 * Plugin Name:          Nowera Search
 * Description:          Rýchle vyhľadávanie cez Nowera Search (Typesense) — produkty WooCommerce, stránky, články, vlastné typy obsahu aj dokumenty. Funguje s Bricks, Elementorom aj klasickou témou.
 * Version:              0.6.8
 * Author:               Nowera
 * License:              GPL-2.0-or-later
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * WC requires at least: 9.0
 * Text Domain:          nwr-search
 * Domain Path:          /languages
 */

namespace Nowera\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '0.6.8';
const PLUGIN_FILE = __FILE__;

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PLUGIN_FILE, true );
		}
	}
);

// Czech and Polish ship with the plugin (languages/); other locales fall back to Slovak.
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'nwr-search', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}
);

// WooCommerce is optional since 0.6: without it the plugin indexes pages, posts, custom post types and files.
add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
