<?php
// boostBuckets follows whether anything is boosted; the cache must drop when a boost changes.
require __DIR__ . '/_bootstrap.php';
$ids    = get_option( 'nwr_uni_ids' );
$config = static function (): array {
	$method = new ReflectionMethod( \Nowera\Search\Frontend\Assets::class, 'config' );
	$method->setAccessible( true );
	$c = $method->invoke( null );
	return array( 'buckets' => $c['boostBuckets'], 'typo' => $c['typoTokens'] );
};
\Nowera\Search\Status::schema_fields( array( 'boost', 'popularity', 'modified_at', 'title', 'content', 'excerpt' ) );
$with = $config();
delete_post_meta( $ids['product'], '_nwr_search_boost' );
$without = $config();
update_post_meta( $ids['product'], '_nwr_search_boost', 5 );
$again = $config();
nwr_test_output( compact( 'with', 'without', 'again' ) );
