<?php
// Runs the due nwr-search WP-Cron events synchronously (sites without Action Scheduler).
require __DIR__ . '/_bootstrap.php';

$hooks  = array( 'nwr_search_sync_products', 'nwr_search_reindex', 'nwr_search_sync_term' );
$report = array();

for ( $round = 0; $round < 30; $round++ ) {
	$ran = false;
	foreach ( (array) _get_cron_array() as $timestamp => $events ) {
		if ( $timestamp > time() ) {
			continue;
		}
		foreach ( $hooks as $hook ) {
			foreach ( $events[ $hook ] ?? array() as $event ) {
				wp_unschedule_event( $timestamp, $hook, $event['args'] );
				$error = null;
				try {
					do_action_ref_array( $hook, $event['args'] );
				} catch ( Throwable $e ) {
					$error = $e->getMessage();
				}
				$report[] = array( 'hook' => $hook, 'args' => $event['args'], 'error' => $error );
				$ran      = true;
			}
		}
	}
	if ( ! $ran ) {
		break;
	}
}

$status = get_option( 'nwr_search_status' );
unset( $status['schema_fields'] );
nwr_test_output(
	array(
		'ran'     => count( $report ),
		'errors'  => array_values( array_filter( wp_list_pluck( $report, 'error' ) ) ),
		'hooks'   => array_count_values( wp_list_pluck( $report, 'hook' ) ),
		'pending' => \Nowera\Search\Sync\Jobs::pending_count(),
		'status'  => $status,
	)
);
