<?php
// Plants a document no product owns, then starts a full reindex that must purge it.
require __DIR__ . '/_bootstrap.php';

use Nowera\Search\Settings;
use Nowera\Search\Sync\Jobs;
use Nowera\Search\Typesense\Client;

$settings = Settings::all();
$client   = new Client( $settings['host'], $settings['site_key'] );
$planted  = $client->import(
	$settings['collection'],
	array(
		array(
			'id'           => '999999',
			'product_id'   => 999999,
			'type'         => 'simple',
			'title'        => 'Zombie dokument',
			'on_sale'      => false,
			'in_stock'     => true,
			'stock_status' => 'instock',
			'featured'     => false,
			'visibility'   => 'visible',
			'popularity'   => 0,
			'menu_order'   => 0,
			'created_at'   => 0,
			'indexed_at'   => 1,
		),
	)
);

$run = Jobs::start_reindex();

nwr_test_output(
	array(
		'planted' => $planted,
		'run'     => $run,
	)
);
