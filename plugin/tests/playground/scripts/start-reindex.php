<?php
// Starts a full reindex through the plugin's own job.
require __DIR__ . '/_bootstrap.php';
\Nowera\Search\Content_Types::flush();
$run = \Nowera\Search\Sync\Jobs::start_reindex();
nwr_test_output( array( 'run' => $run, 'pending' => \Nowera\Search\Sync\Jobs::pending_count() ) );
