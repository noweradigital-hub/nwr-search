<?php
// Edits that must reach the index without a manual reindex: Bricks layout meta, trash, boost meta, term rename.
require __DIR__ . '/_bootstrap.php';

$ids = get_option( 'nwr_uni_ids' );

$layout   = get_post_meta( $ids['home'], '_bricks_page_content_2', true );
$layout[] = array( 'id' => 'new001', 'name' => 'heading', 'parent' => 'lvifhm', 'settings' => array( 'text' => 'Nový banner: zimná akcia na hydroizolácie' ) );
update_post_meta( $ids['home'], '_bricks_page_content_2', $layout );

wp_trash_post( $ids['news'] );

update_post_meta( $ids['document'], '_nwr_search_boost', 10 );

$term = get_term_by( 'name', 'Legislatíva a normy', 'kategoria_poradne' );
wp_update_term( $term->term_id, 'kategoria_poradne', array( 'name' => 'Legislatíva' ) );

nwr_test_output( array( 'edited' => true ) );
