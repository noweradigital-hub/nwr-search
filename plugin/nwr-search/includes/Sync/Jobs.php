<?php
namespace Nowera\Search\Sync;

use Nowera\Search\Content_Types;
use Nowera\Search\Index\Indexer;
use Nowera\Search\Settings;
use Nowera\Search\Status;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Background jobs. All network calls to Typesense happen here — never inside
 * the request that saved a post or placed an order.
 *
 * Action Scheduler is used when some plugin ships it (WooCommerce and many
 * others do); otherwise the same hooks run as single WP-Cron events.
 */
final class Jobs {

	const GROUP        = 'nwr-search';
	const SYNC         = 'nwr_search_sync_products';
	const REINDEX      = 'nwr_search_reindex';
	const TERM         = 'nwr_search_sync_term';
	const HOOKS        = array( self::SYNC, self::REINDEX, self::TERM );
	const MAX_ATTEMPTS = 3;

	public function register(): void {
		add_action( self::SYNC, array( $this, 'sync_products' ), 10, 2 );
		add_action( self::REINDEX, array( $this, 'reindex' ), 10, 3 );
		add_action( self::TERM, array( $this, 'sync_term' ), 10, 4 );
	}

	public static function uses_action_scheduler(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	public static function enqueue_sync( array $post_ids ): void {
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		foreach ( array_chunk( $post_ids, Indexer::BATCH ) as $chunk ) {
			self::dispatch( self::SYNC, array( 'ids' => $chunk, 'attempt' => 1 ) );
		}
	}

	public static function enqueue_term( string $taxonomy, int $term_id ): void {
		self::dispatch( self::TERM, array( 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'after_id' => 0, 'attempt' => 1 ) );
	}

	public static function start_reindex(): int {
		if ( self::uses_action_scheduler() ) {
			as_unschedule_all_actions( self::REINDEX );
		} else {
			wp_unschedule_hook( self::REINDEX );
		}
		$run = time();
		Status::reindex_started( $run );
		self::dispatch( self::REINDEX, array( 'run' => $run, 'after_id' => 0, 'attempt' => 1 ) );
		return $run;
	}

	/** Jobs waiting to run, for the admin screen. */
	public static function pending_count(): int {
		if ( self::uses_action_scheduler() ) {
			return count(
				as_get_scheduled_actions(
					array(
						'group'    => self::GROUP,
						'status'   => \ActionScheduler_Store::STATUS_PENDING,
						'per_page' => 1000,
					),
					'ids'
				)
			);
		}
		$count = 0;
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( self::HOOKS as $hook ) {
				$count += isset( $events[ $hook ] ) ? count( $events[ $hook ] ) : 0;
			}
		}
		return $count;
	}

	public function sync_products( array $ids, int $attempt = 1 ): void {
		if ( ! Settings::is_configured() ) {
			return;
		}
		try {
			$result = Indexer::from_settings()->sync( $ids );
			Status::synced( $result['indexed'], $result['removed'] );
		} catch ( Throwable $e ) {
			$this->fail( self::SYNC, array( 'ids' => $ids ), $attempt, $e );
		}
	}

	public function reindex( int $run, int $after_id, int $attempt = 1 ): void {
		if ( ! Settings::is_configured() || Status::current_run() !== $run ) {
			return; // Superseded by a newer run.
		}
		try {
			$indexer = Indexer::from_settings();
			if ( 0 === $after_id ) {
				$indexer->ensure_collection();
			}

			$batch = $indexer->reindex_batch( $after_id );
			if ( $batch['last_id'] > 0 ) {
				Status::reindex_progress( $run, $batch['last_id'], $batch['indexed'] );
				self::dispatch( self::REINDEX, array( 'run' => $run, 'after_id' => $batch['last_id'], 'attempt' => 1 ) );
				return;
			}

			Status::reindex_finished( $run, $indexer->purge_stale( $run ) );
		} catch ( Throwable $e ) {
			if ( $attempt >= self::MAX_ATTEMPTS ) {
				Status::reindex_failed( $run );
			}
			$this->fail( self::REINDEX, array( 'run' => $run, 'after_id' => $after_id ), $attempt, $e );
		}
	}

	/** A renamed term changes the documents of every entry filed under it (or under its children). */
	public function sync_term( string $taxonomy, int $term_id, int $after_id = 0, int $attempt = 1 ): void {
		if ( ! Settings::is_configured() ) {
			return;
		}
		try {
			$ids = self::post_ids_in_term( $taxonomy, $term_id, $after_id );
			if ( ! $ids ) {
				return;
			}
			$result = Indexer::from_settings()->sync( $ids );
			Status::synced( $result['indexed'], $result['removed'] );
			if ( count( $ids ) === Indexer::BATCH ) {
				self::dispatch( self::TERM, array( 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'after_id' => end( $ids ), 'attempt' => 1 ) );
			}
		} catch ( Throwable $e ) {
			$this->fail( self::TERM, array( 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'after_id' => $after_id ), $attempt, $e );
		}
	}

	/** Next batch of indexed entries in a term, keyset-paginated by id. */
	private static function post_ids_in_term( string $taxonomy, int $term_id, int $after_id ): array {
		global $wpdb;

		$types = array_values( array_diff( array_keys( Content_Types::enabled() ), array( Content_Types::ATTACHMENTS ) ) );
		if ( ! $types ) {
			return array();
		}

		$term_ids = array( $term_id );
		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			$children = get_term_children( $term_id, $taxonomy );
			if ( is_array( $children ) ) {
				$term_ids = array_merge( $term_ids, $children );
			}
		}

		$tt_ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => $term_ids,
				'hide_empty' => false,
				'fields'     => 'tt_ids',
			)
		);
		if ( ! is_array( $tt_ids ) || ! $tt_ids ) {
			return array();
		}

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')
					WHERE tr.term_taxonomy_id IN (' . implode( ',', array_map( 'intval', $tt_ids ) ) . ') AND tr.object_id > %d
					ORDER BY tr.object_id ASC LIMIT %d',
					array_merge( $types, array( $after_id, Indexer::BATCH ) )
				)
			)
		);
	}

	/**
	 * Arguments reach the handler positionally in key order, with both runners
	 * (string keys would otherwise become PHP 8 named arguments under WP-Cron).
	 */
	private static function dispatch( string $hook, array $args, int $delay = 0 ): void {
		if ( self::uses_action_scheduler() ) {
			if ( $delay > 0 ) {
				as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
			} else {
				as_enqueue_async_action( $hook, $args, self::GROUP );
			}
			return;
		}
		wp_schedule_single_event( time() + $delay, $hook, array_values( $args ) );
	}

	/** Records the error, retries with backoff, then rethrows so the runner marks the job failed. */
	private function fail( string $hook, array $args, int $attempt, Throwable $e ): void {
		if ( $attempt < self::MAX_ATTEMPTS ) {
			self::dispatch( $hook, $args + array( 'attempt' => $attempt + 1 ), 300 * $attempt );
			Status::error( sprintf( '%s (pokus %d/%d, ďalší o %d min)', $e->getMessage(), $attempt, self::MAX_ATTEMPTS, 5 * $attempt ) );
		} else {
			Status::error( sprintf( '%s (pokus %d/%d, vzdávam to)', $e->getMessage(), $attempt, self::MAX_ATTEMPTS ) );
		}
		// WP-Cron has no failed state to record; rethrowing there would only surface as a fatal.
		if ( self::uses_action_scheduler() ) {
			throw $e;
		}
	}
}
