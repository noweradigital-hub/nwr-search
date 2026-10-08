<?php
namespace Nowera\Search\Index;

use Nowera\Search\Content_Types;
use Nowera\Search\Settings;
use Nowera\Search\Status;
use Nowera\Search\Sync\Jobs;
use Nowera\Search\Typesense\Client;
use RuntimeException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the site's searchable content into one Typesense collection.
 *
 * A full reindex runs in place: every upsert stamps indexed_at, and once all
 * products are sent, documents stamped before the run started are deleted.
 * No collection swap or alias is needed, so the site key never needs rights
 * outside its own collection.
 */
final class Indexer {

	const BATCH = 100;

	public function __construct(
		private Client $client,
		private string $collection,
		private Builder $builder = new Builder()
	) {}

	public static function from_settings(): self {
		$settings = Settings::all();
		return new self( new Client( $settings['host'], $settings['site_key'] ), $settings['collection'] );
	}

	public function collection(): string {
		return $this->collection;
	}

	/** Creates the collection, or adds fields a newer plugin version introduced. */
	public function ensure_collection(): void {
		$existing = $this->client->get_collection( $this->collection );

		if ( is_wp_error( $existing ) ) {
			if ( 404 !== self::status( $existing ) ) {
				throw new RuntimeException( $existing->get_error_message() );
			}
			$this->ok( $this->client->create_collection( Schema::collection( $this->collection ) ) );
			Status::schema_fields( array_column( Schema::fields(), 'name' ) );
			return;
		}

		$fields  = array_column( $existing['fields'] ?? array(), 'name' );
		$missing = Schema::missing_fields( $existing['fields'] ?? array() );
		if ( $missing ) {
			$this->ok( $this->client->update_collection( $this->collection, array( 'fields' => $missing ) ) );
			$fields = array_merge( $fields, array_column( $missing, 'name' ) );
		}
		// Remembered for the frontend: sorting by a field the collection lacks is an error.
		Status::schema_fields( $fields );
	}

	/**
	 * Upserts the given products and deletes the ones that should not be searchable.
	 *
	 * @return array{indexed:int, removed:int}
	 */
	public function sync( array $product_ids ): array {
		$this->ensure_schema();

		$documents = array();
		$removed   = array();

		foreach ( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) as $post_id ) {
			$document = $this->builder->build( $post_id );
			if ( $document ) {
				$documents[] = $document;
			} else {
				$removed[] = $post_id;
			}
		}

		foreach ( array_chunk( $documents, self::BATCH ) as $chunk ) {
			$result = $this->client->import( $this->collection, $chunk );
			if ( is_wp_error( $result ) && 404 === self::status( $result ) ) {
				// First change arrived before any reindex created the collection.
				$this->ensure_collection();
				$result = $this->client->import( $this->collection, $chunk );
			}
			$result = $this->ok( $result );
			if ( $result['failed'] ) {
				$first = $result['failed'][0];
				throw new RuntimeException( sprintf( 'Typesense odmietol dokumenty (%d), napr. #%s: %s', count( $result['failed'] ), $first['id'], $first['error'] ) );
			}
		}

		$this->remove( $removed );

		// Images this request had no time to resize: index those entries again in a later job.
		$deferred = Thumbnail::take_deferred();
		if ( $deferred ) {
			Jobs::enqueue_sync( $deferred );
		}

		return array(
			'indexed' => count( $documents ),
			'removed' => count( $removed ),
		);
	}

	/**
	 * After a plugin update the collection can still miss fields the new version
	 * writes. This costs one request right after the update and a plain array
	 * comparison afterwards.
	 */
	private function ensure_schema(): void {
		if ( array_diff( array_column( Schema::fields(), 'name' ), Status::schema_fields() ) ) {
			$this->ensure_collection();
		}
	}

	public function remove( array $product_ids ): void {
		foreach ( array_chunk( array_map( 'intval', $product_ids ), self::BATCH ) as $chunk ) {
			$result = $this->client->delete_by_filter( $this->collection, 'id:[' . implode( ',', $chunk ) . ']' );
			if ( is_wp_error( $result ) && 404 === self::status( $result ) ) {
				return; // No collection yet, so nothing to remove.
			}
			$this->ok( $result );
		}
	}

	/**
	 * Indexes the next batch of published content after $after_id (keyset
	 * pagination, so entries changing status mid-run cannot shift pages).
	 *
	 * @return array{last_id:int, indexed:int} last_id 0 means the run is done.
	 */
	public function reindex_batch( int $after_id ): array {
		global $wpdb;

		$types = array_keys( Content_Types::enabled() );
		$posts = array_values( array_diff( $types, array( Content_Types::ATTACHMENTS ) ) );
		$where = array();
		$args  = array();

		if ( $posts ) {
			$where[] = "(post_type IN (" . implode( ',', array_fill( 0, count( $posts ), '%s' ) ) . ") AND post_status = 'publish')";
			$args    = array_merge( $args, $posts );
		}
		$mimes = Content_Types::mimes();
		if ( in_array( Content_Types::ATTACHMENTS, $types, true ) && $mimes ) {
			$where[] = "(post_type = 'attachment' AND post_mime_type IN (" . implode( ',', array_fill( 0, count( $mimes ), '%s' ) ) . '))';
			$args    = array_merge( $args, $mimes );
		}
		if ( ! $where ) {
			return array( 'last_id' => 0, 'indexed' => 0 );
		}

		$args[] = $after_id;
		$args[] = self::BATCH;
		$ids    = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE (" . implode( ' OR ', $where ) . ') AND ID > %d ORDER BY ID ASC LIMIT %d',
					$args
				)
			)
		);

		if ( ! $ids ) {
			return array( 'last_id' => 0, 'indexed' => 0 );
		}

		$result = $this->sync( $ids );
		return array(
			'last_id' => end( $ids ),
			'indexed' => $result['indexed'],
		);
	}

	/** Deletes documents no product refreshed since the full reindex started. */
	public function purge_stale( int $run_started ): int {
		$result = $this->ok( $this->client->delete_by_filter( $this->collection, 'indexed_at:<' . $run_started ) );
		return (int) ( $result['num_deleted'] ?? 0 );
	}

	private function ok( array|WP_Error $result ): array {
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		return $result;
	}

	private static function status( WP_Error $error ): int {
		$data = $error->get_error_data();
		return is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	}
}
