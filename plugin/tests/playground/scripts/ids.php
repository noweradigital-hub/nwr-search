<?php
require __DIR__ . '/_bootstrap.php';
nwr_test_output( array( 'ids' => get_option( 'nwr_uni_ids' ), 'results_url' => \Nowera\Search\Settings::results_url() ) );
