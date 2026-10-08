<?php
namespace Nowera\Search;

defined( 'ABSPATH' ) || exit;

/** Sync state shown in the admin: last sync, last error, full reindex progress. */
final class Status {

	const OPTION = 'nwr_search_status';

	public static function all(): array {
		$status = get_option( self::OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	public static function synced( int $indexed, int $removed ): void {
		self::merge(
			array(
				'last_sync' => array(
					'time'    => time(),
					'indexed' => $indexed,
					'removed' => $removed,
				),
			)
		);
	}

	public static function error( string $message ): void {
		self::merge(
			array(
				'last_error' => array(
					'time'    => time(),
					'message' => $message,
				),
			)
		);
		Log::error( $message );
	}

	public static function reindex_started( int $run ): void {
		self::merge(
			array(
				'reindex' => array(
					'run'     => $run,
					'state'   => 'running',
					'indexed' => 0,
					'last_id' => 0,
				),
			)
		);
	}

	public static function reindex_progress( int $run, int $last_id, int $indexed ): void {
		$reindex = self::all()['reindex'] ?? array();
		if ( ( $reindex['run'] ?? 0 ) !== $run ) {
			return;
		}
		$reindex['last_id'] = $last_id;
		$reindex['indexed'] = (int) ( $reindex['indexed'] ?? 0 ) + $indexed;
		self::merge( array( 'reindex' => $reindex ) );
	}

	public static function reindex_finished( int $run, int $purged ): void {
		$reindex = self::all()['reindex'] ?? array();
		if ( ( $reindex['run'] ?? 0 ) !== $run ) {
			return;
		}
		$reindex['state']    = 'done';
		$reindex['purged']   = $purged;
		$reindex['finished'] = time();
		self::merge( array( 'reindex' => $reindex ) );
	}

	public static function reindex_failed( int $run ): void {
		$reindex = self::all()['reindex'] ?? array();
		if ( ( $reindex['run'] ?? 0 ) === $run ) {
			$reindex['state'] = 'failed';
			self::merge( array( 'reindex' => $reindex ) );
		}
	}

	/**
	 * Fields the live collection is known to have. A plugin update adds fields to
	 * the schema only on the next sync, so the frontend must not sort by a field
	 * the collection does not have yet.
	 */
	public static function schema_fields( ?array $fields = null ): array {
		$collection = (string) Settings::get( 'collection' );
		if ( null !== $fields ) {
			self::merge(
				array(
					'schema_fields'     => array_values( $fields ),
					'schema_collection' => $collection,
				)
			);
		}
		$status = self::all();
		// A list recorded for another collection (the setting changed since) says nothing about this one.
		if ( ( $status['schema_collection'] ?? $collection ) !== $collection ) {
			return array();
		}
		$known = $status['schema_fields'] ?? array();
		return is_array( $known ) ? $known : array();
	}

	public static function has_field( string $field ): bool {
		return in_array( $field, self::schema_fields(), true );
	}

	public static function current_run(): int {
		$reindex = self::all()['reindex'] ?? array();
		return 'running' === ( $reindex['state'] ?? '' ) ? (int) $reindex['run'] : 0;
	}

	private static function merge( array $changes ): void {
		update_option( self::OPTION, array_merge( self::all(), $changes ), false );
	}
}
