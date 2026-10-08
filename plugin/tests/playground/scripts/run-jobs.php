<?php
// Runs every due nwr-search Action Scheduler job synchronously and reports the outcome.
require __DIR__ . '/_bootstrap.php';

$runner = ActionScheduler::runner();
$store  = ActionScheduler::store();
$report = array();

for ( $round = 0; $round < 20; $round++ ) {
	$ids = as_get_scheduled_actions(
		array(
			'group'        => 'nwr-search',
			'status'       => ActionScheduler_Store::STATUS_PENDING,
			'date'         => as_get_datetime_object(),
			'date_compare' => '<=',
			'per_page'     => 50,
			'orderby'      => 'date',
			'order'        => 'ASC',
		),
		'ids'
	);
	if ( ! $ids ) {
		break;
	}
	foreach ( $ids as $id ) {
		$runner->process_action( $id, 'nwr-test' );
		$action   = $store->fetch_action( $id );
		$logs     = ActionScheduler::logger()->get_logs( $id );
		$report[] = array(
			'id'     => $id,
			'hook'   => $action->get_hook(),
			'args'   => $action->get_args(),
			'status' => $store->get_status( $id ),
			'log'    => $logs ? end( $logs )->get_message() : '',
		);
	}
}

nwr_test_output(
	array(
		'processed' => $report,
		'retries'   => as_get_scheduled_actions( array( 'group' => 'nwr-search', 'status' => 'pending', 'per_page' => 50 ), 'ids' ),
		'status'    => get_option( 'nwr_search_status' ),
	)
);
