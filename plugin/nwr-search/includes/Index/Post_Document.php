<?php
namespace Nowera\Search\Index;

use Nowera\Search\Content_Types;
use Nowera\Search\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a page, post, custom post type entry or media file into one Typesense document.
 *
 * The product-era fields that older collections require (on_sale, in_stock,
 * visibility, …) are always filled with neutral values, so the same document
 * shape fits a new universal collection and an existing shop collection.
 */
final class Post_Document {

	const CONTENT_LIMIT = 6000;
	const EXCERPT_LIMIT = 220;

	public function build( WP_Post $post ): ?array {
		if ( ! $this->is_indexable( $post ) ) {
			return null;
		}

		$is_file = Content_Types::ATTACHMENTS === $post->post_type;
		$fields  = $is_file ? array( 'texts' => array(), 'excerpt' => '', 'files' => array() ) : ( new Field_Reader() )->read( $post->ID );
		$file    = $is_file ? $this->file_info( $post->ID, '', '' ) : $this->primary_file( $fields['files'] );

		$body = Text::join(
			array_merge(
				array( $post->post_content ),
				array( $is_file ? '' : Builder_Content::text( $post->ID ) ),
				array_values( $fields['texts'] ),
				$file ? array( $file['file_name'] ) : array()
			),
			self::CONTENT_LIMIT
		);

		$excerpt = Text::snippet( '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : ( '' !== $fields['excerpt'] ? $fields['excerpt'] : $body ), self::EXCERPT_LIMIT );
		$title   = Text::plain( get_the_title( $post ), 300 );

		$document = array(
			'id'           => (string) $post->ID,
			// Named for the shop era; holds the WordPress post ID for every kind of content.
			'product_id'   => $post->ID,
			'post_type'    => $post->post_type,
			'type'         => $post->post_type,
			'title'        => '' !== $title ? $title : ( $file['file_name'] ?? '#' . $post->ID ),
			'excerpt'      => $excerpt,
			'content'      => $body,
			'on_sale'      => false,
			'in_stock'     => true,
			'stock_status' => 'instock',
			'featured'     => is_sticky( $post->ID ),
			'visibility'   => 'visible',
			'popularity'   => 0,
			'menu_order'   => (int) $post->menu_order,
			'boost'        => self::boost( $post->ID ),
			'created_at'   => (int) get_post_timestamp( $post ),
			'modified_at'  => (int) get_post_timestamp( $post, 'modified' ),
			'indexed_at'   => time(),
			'url'          => $is_file ? (string) wp_get_attachment_url( $post->ID ) : (string) get_permalink( $post ),
			'image'        => $this->image( $post ),
		);

		if ( $file ) {
			$document += $file;
		}

		$document += $this->terms( $post );

		$document = array_filter( $document, static fn( $value ): bool => ! ( null === $value || '' === $value || array() === $value ) );

		return apply_filters( 'nwr_search_post_document', $document, $post );
	}

	public function is_indexable( WP_Post $post ): bool {
		$indexable = Content_Types::is_enabled( $post->post_type )
			&& ( Content_Types::ATTACHMENTS === $post->post_type
				? in_array( $post->post_mime_type, Content_Types::mimes(), true )
				: 'publish' === $post->post_status && '' === $post->post_password )
			&& ! $this->is_noindex( $post->ID )
			&& ! in_array( $post->ID, $this->excluded_ids(), true );

		return (bool) apply_filters( 'nwr_search_is_indexable', $indexable, $post );
	}

	/** Manual ranking nudge, shared with products. */
	public static function boost( int $post_id ): int {
		$boost = (int) apply_filters( 'nwr_search_boost', (int) get_post_meta( $post_id, Product_Document::BOOST_META, true ), $post_id );
		return max( -Product_Document::BOOST_LIMIT, min( Product_Document::BOOST_LIMIT, $boost ) );
	}

	/** Pages an SEO plugin hides from search engines stay out of site search as well. */
	private function is_noindex( int $post_id ): bool {
		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}
		$slim = get_post_meta( $post_id, 'slim_seo', true );
		if ( is_array( $slim ) && ! empty( $slim['noindex'] ) ) {
			return true;
		}
		$rank_math = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) {
			return true;
		}
		return 'yes' === (string) get_post_meta( $post_id, '_seopress_robots_index', true );
	}

	/** IDs the admin excluded by hand (thank-you pages, landing pages for ads, …). */
	private function excluded_ids(): array {
		return array_values( array_filter( array_map( 'intval', (array) Settings::get( 'exclude' ) ) ) );
	}

	/** First file a document-like entry points to (ACPT/ACF File fields). */
	private function primary_file( array $files ): ?array {
		foreach ( $files as $file ) {
			$info = $this->file_info( (int) $file['id'], (string) $file['url'], (string) $file['label'] );
			if ( $info ) {
				return $info;
			}
		}
		return null;
	}

	/** file_url / file_type / file_size / file_name — stored for display, file_type is a facet. */
	private function file_info( int $attachment_id, string $url, string $label ): ?array {
		$path = '';
		$mime = '';
		if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
			$url  = (string) wp_get_attachment_url( $attachment_id );
			$path = (string) get_attached_file( $attachment_id );
			$mime = (string) get_post_mime_type( $attachment_id );
		}
		if ( '' === $url ) {
			return null;
		}

		$name      = '' !== $label ? $label : wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$extension = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( '' === $extension && '' !== $mime ) {
			$extension = (string) ( wp_get_default_extension_for_mime_type( $mime ) ?: '' );
		}

		$size = 0;
		if ( $attachment_id > 0 ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			$size = (int) ( is_array( $meta ) ? ( $meta['filesize'] ?? 0 ) : 0 );
		}
		if ( 0 === $size && '' !== $path && is_readable( $path ) ) {
			$size = (int) filesize( $path );
		}

		return array_filter(
			array(
				'file_url'  => $url,
				'file_type' => '' !== $extension ? strtoupper( $extension ) : '',
				'file_size' => $size,
				'file_name' => $name,
			)
		);
	}

	private function image( WP_Post $post ): string {
		$id = Content_Types::ATTACHMENTS === $post->post_type ? $post->ID : (int) get_post_thumbnail_id( $post );
		return Thumbnail::url( $id, $post->ID );
	}

	/**
	 * categories / category_ids: the type's primary taxonomy with ancestors (feeds the panel's
	 * category list); tax_<taxonomy>: every public taxonomy as a facet for the results page.
	 */
	private function terms( WP_Post $post ): array {
		$type = Content_Types::enabled()[ $post->post_type ] ?? null;
		if ( ! $type ) {
			return array();
		}

		$data = array();
		foreach ( array_keys( $type['facets'] ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( ! is_array( $terms ) || ! $terms ) {
				continue;
			}

			$names = array();
			$ids   = array();
			foreach ( $terms as $term ) {
				$chain = array_reverse( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) );
				foreach ( array_merge( $chain, array( (int) $term->term_id ) ) as $term_id ) {
					$node = get_term( $term_id, $taxonomy );
					if ( $node && ! is_wp_error( $node ) ) {
						$names[] = Text::plain( $node->name, 200 );
						$ids[]   = (int) $node->term_id;
					}
				}
			}

			$data[ 'tax_' . $taxonomy ] = array_values( array_unique( $names ) );
			if ( $taxonomy === $type['taxonomy'] ) {
				$data['categories']   = array_values( array_unique( $names ) );
				$data['category_ids'] = array_values( array_unique( $ids ) );
			}
		}
		return $data;
	}
}
