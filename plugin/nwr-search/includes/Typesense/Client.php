<?php
namespace Nowera\Search\Typesense;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal Typesense REST client on top of the WordPress HTTP API.
 * Every method returns decoded data or a WP_Error carrying the HTTP status.
 */
final class Client {

	private string $base_url;

	public function __construct( string $host, private string $api_key, private int $timeout = 30 ) {
		$this->base_url = self::normalize_url( $host );
	}

	/** "search.example.com" → "https://search.example.com" */
	public static function normalize_url( string $host ): string {
		$host = trim( $host );
		if ( '' !== $host && ! preg_match( '#^https?://#i', $host ) ) {
			$host = 'https://' . $host;
		}
		return untrailingslashit( $host );
	}

	public function health(): array|WP_Error {
		return $this->request( 'GET', '/health' );
	}

	public function get_collection( string $name ): array|WP_Error {
		return $this->request( 'GET', '/collections/' . rawurlencode( $name ) );
	}

	public function create_collection( array $schema ): array|WP_Error {
		return $this->request( 'POST', '/collections', $schema );
	}

	public function update_collection( string $name, array $changes ): array|WP_Error {
		return $this->request( 'PATCH', '/collections/' . rawurlencode( $name ), $changes );
	}

	public function search( string $collection, array $params ): array|WP_Error {
		return $this->request( 'GET', '/collections/' . rawurlencode( $collection ) . '/documents/search?' . http_build_query( $params ) );
	}

	public function delete_by_filter( string $collection, string $filter_by ): array|WP_Error {
		return $this->request( 'DELETE', '/collections/' . rawurlencode( $collection ) . '/documents?' . http_build_query( array( 'filter_by' => $filter_by ) ) );
	}

	/**
	 * Upserts documents in one JSONL import.
	 *
	 * @return array{ok:int, failed:array<int,array{id:?string,error:string}>}|WP_Error
	 */
	public function import( string $collection, array $documents ): array|WP_Error {
		if ( ! $documents ) {
			return array( 'ok' => 0, 'failed' => array() );
		}

		$lines = array();
		foreach ( $documents as $document ) {
			$lines[] = wp_json_encode( $document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$body = $this->send( 'POST', '/collections/' . rawurlencode( $collection ) . '/documents/import?action=upsert', implode( "\n", $lines ), 'text/plain' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// Typesense answers with one JSON line per document, in input order.
		$failed = array();
		foreach ( preg_split( '/\r?\n/', trim( $body ) ) as $i => $line ) {
			$row = json_decode( $line, true );
			if ( ! is_array( $row ) || empty( $row['success'] ) ) {
				$failed[] = array(
					'id'    => $documents[ $i ]['id'] ?? null,
					'error' => is_array( $row ) ? (string) ( $row['error'] ?? $line ) : $line,
				);
			}
		}

		return array(
			'ok'     => count( $documents ) - count( $failed ),
			'failed' => $failed,
		);
	}

	private function request( string $method, string $path, ?array $payload = null ): array|WP_Error {
		$body = $this->send( $method, $path, null === $payload ? null : wp_json_encode( $payload ), 'application/json' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : new WP_Error( 'nwr_search_bad_response', 'Typesense vrátil neplatnú odpoveď.', array( 'body' => $body ) );
	}

	private function send( string $method, string $path, ?string $body, string $content_type ): string|WP_Error {
		if ( '' === $this->base_url ) {
			return new WP_Error( 'nwr_search_not_configured', 'Nie je nastavený server Nowera Search.' );
		}

		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => array(
				'Content-Type'        => $content_type,
				'Accept'              => 'application/json',
				'X-TYPESENSE-API-KEY' => $this->api_key,
			),
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			$decoded = json_decode( $body, true );
			$message = is_array( $decoded ) && isset( $decoded['message'] ) ? (string) $decoded['message'] : $body;
			return new WP_Error( 'nwr_search_http_' . $status, sprintf( 'Typesense %d: %s', $status, $message ), array( 'status' => $status ) );
		}

		return $body;
	}
}
