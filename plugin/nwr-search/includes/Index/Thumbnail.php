<?php
namespace Nowera\Search\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Small result images. Some sites switch off WordPress's small image sizes,
 * and "thumbnail" then resolves to the full upload (megabytes for a 64 px
 * square). Such images get their own 160×160 copy, made while indexing and
 * recorded in the attachment metadata; the size is never registered, so new
 * uploads are not affected.
 */
final class Thumbnail {

	const SIZE = 'nwr-search-thumb';
	const EDGE = 160;

	/** Seconds of a request that may go into resizing before the rest is postponed. */
	const BUDGET_SECONDS = 20;

	/** @var array<int,int> posts whose image could not be resized within this request */
	private static array $deferred = array();

	public static function url( int $attachment_id, int $post_id = 0 ): string {
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			return '';
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		$meta = is_array( $meta ) ? $meta : array();

		if ( isset( $meta['sizes'][ self::SIZE ] ) ) {
			$url = wp_get_attachment_image_url( $attachment_id, self::SIZE );
			if ( $url ) {
				return (string) $url;
			}
		}

		// A small size the site already generates is good enough.
		foreach ( array( 'thumbnail', 'woocommerce_gallery_thumbnail', 'woocommerce_thumbnail', 'medium' ) as $size ) {
			$small = $meta['sizes'][ $size ] ?? null;
			if ( is_array( $small ) && (int) $small['width'] <= 600 && (int) $small['height'] <= 600 ) {
				$url = wp_get_attachment_image_url( $attachment_id, $size );
				if ( $url ) {
					return (string) $url;
				}
			}
		}

		// Vector images and small originals need no copy.
		$width  = (int) ( $meta['width'] ?? 0 );
		$height = (int) ( $meta['height'] ?? 0 );
		if ( 'image/svg+xml' === get_post_mime_type( $attachment_id ) || ( $width && $height && $width <= 400 && $height <= 400 ) ) {
			return (string) wp_get_attachment_url( $attachment_id );
		}

		if ( self::within_budget() ) {
			$made = self::make( $attachment_id, $meta );
			if ( '' !== $made ) {
				return $made;
			}
		} elseif ( $post_id > 0 ) {
			self::$deferred[ $post_id ] = $post_id;
		}

		return self::smallest_existing( $attachment_id, $meta );
	}

	/** Posts to index again once a later request has time to resize their image. */
	public static function take_deferred(): array {
		$ids            = array_values( self::$deferred );
		self::$deferred = array();
		return $ids;
	}

	private static function make( int $attachment_id, array $meta ): string {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_readable( $file ) ) {
			return '';
		}
		if ( ! function_exists( 'image_make_intermediate_size' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$resized = image_make_intermediate_size( $file, self::EDGE, self::EDGE, true );
		if ( ! is_array( $resized ) ) {
			return '';
		}

		$meta['sizes']               = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();
		$meta['sizes'][ self::SIZE ] = $resized;
		wp_update_attachment_metadata( $attachment_id, $meta );

		$url = wp_get_attachment_image_url( $attachment_id, self::SIZE );
		return $url ? (string) $url : '';
	}

	/** Until a copy exists: the smallest file WordPress has, rather than the original. */
	private static function smallest_existing( int $attachment_id, array $meta ): string {
		$best = '';
		$area = PHP_INT_MAX;
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $data ) {
			$pixels = (int) ( $data['width'] ?? 0 ) * (int) ( $data['height'] ?? 0 );
			if ( $pixels > 0 && $pixels < $area ) {
				$area = $pixels;
				$best = (string) $size;
			}
		}
		$url = '' !== $best ? wp_get_attachment_image_url( $attachment_id, $best ) : wp_get_attachment_url( $attachment_id );
		return $url ? (string) $url : '';
	}

	private static function within_budget(): bool {
		$start = (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) );
		return microtime( true ) - $start < self::BUDGET_SECONDS;
	}
}
