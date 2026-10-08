<?php
// Playground's rewrite rules do not pick up pages created by a script; plain permalinks keep the test simple.
require __DIR__ . '/_bootstrap.php';
update_option( 'permalink_structure', '' );
flush_rewrite_rules( true );
$id = (int) get_option( 'nwr_results_page_id' );
nwr_test_output( array( 'permalinks' => get_option( 'permalink_structure' ), 'page' => get_permalink( $id ), 'resolved' => url_to_postid( get_permalink( $id ) ) ) );
