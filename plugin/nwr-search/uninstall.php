<?php
/**
 * Removes local settings and queued jobs. The remote Typesense collection is
 * left alone on purpose — it is managed from the Nowera side.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'nwr_search_settings' );
delete_option( 'nwr_search_status' );

delete_transient( 'nwr_search_categories' );

foreach ( array( 'nwr_search_sync_products', 'nwr_search_reindex', 'nwr_search_sync_term' ) as $hook ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $hook );
	}
	wp_unschedule_hook( $hook );
}
