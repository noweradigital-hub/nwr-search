<?php
namespace Nowera\Search\CLI;

use Nowera\Search\Index\Indexer;
use Nowera\Search\Settings;
use Nowera\Search\Status;
use Nowera\Search\Typesense\Client;
use Throwable;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the Nowera Search index.
 */
final class Command {

	/**
	 * Shows the connection and index status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nwr-search status
	 */
	public function status(): void {
		$settings = Settings::all();
		WP_CLI::log( 'Server:     ' . ( $settings['host'] ?: '—' ) );
		WP_CLI::log( 'Kolekcia:   ' . $settings['collection'] );

		if ( ! Settings::is_configured() ) {
			WP_CLI::error( 'Plugin nie je nastavený (server, kolekcia, kľúč pre zápis).' );
		}

		$collection = ( new Client( $settings['host'], $settings['site_key'], 10 ) )->get_collection( $settings['collection'] );
		WP_CLI::log( 'Dokumenty:  ' . ( is_wp_error( $collection ) ? $collection->get_error_message() : (int) ( $collection['num_documents'] ?? 0 ) ) );
		WP_CLI::log( 'Stav:       ' . wp_json_encode( Status::all() ) );
	}

	/**
	 * Rebuilds the whole index in this process (no Action Scheduler).
	 *
	 * ## EXAMPLES
	 *
	 *     wp nwr-search reindex
	 */
	public function reindex(): void {
		$this->require_configured();
		$indexer = Indexer::from_settings();
		$run     = time();

		try {
			$indexer->ensure_collection();
			$after = 0;
			$total = 0;
			do {
				$batch  = $indexer->reindex_batch( $after );
				$after  = $batch['last_id'];
				$total += $batch['indexed'];
				if ( $after ) {
					WP_CLI::log( sprintf( '… po ID %d, zatiaľ položky: %d', $after, $total ) );
				}
			} while ( $after > 0 );

			$purged = $indexer->purge_stale( $run );
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( sprintf( 'Hotovo — položky: %d, odstránené staré dokumenty: %d.', $total, $purged ) );
	}

	/**
	 * Sends the given posts/products to the index (or removes them if they are no longer searchable).
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Post or product IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nwr-search sync 123 456
	 *
	 * @param array<int,string> $args
	 */
	public function sync( array $args ): void {
		$this->require_configured();
		try {
			$result = Indexer::from_settings()->sync( array_map( 'intval', $args ) );
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf( 'Odoslané: %d, odstránené: %d.', $result['indexed'], $result['removed'] ) );
	}

	private function require_configured(): void {
		if ( ! Settings::is_configured() ) {
			WP_CLI::error( 'Plugin nie je nastavený (server, kolekcia, kľúč pre zápis).' );
		}
	}
}
