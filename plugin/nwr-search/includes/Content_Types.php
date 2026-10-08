<?php
namespace Nowera\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Which kinds of content end up in the index, and how the widget presents them.
 *
 * Candidates are the public post types a visitor can search (pages, posts,
 * custom post types from ACPT/ACF/JetEngine…) plus media library files.
 * Without saved settings the plugin behaves like its WooCommerce-only
 * predecessor on shops, and indexes everything public elsewhere.
 *
 * Post types only exist after `init`, so everything here is resolved lazily.
 */
final class Content_Types {

	const ATTACHMENTS = 'attachment';

	/** Public types that are builder/theme internals, never search results. */
	const EXCLUDED = array( 'bricks_template', 'elementor_library', 'e-landing-page', 'e-floating-buttons', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'ct_template', 'oxy_user_library', 'brx_component' );

	/** Taxonomies that only carry internal flags. */
	const INTERNAL_TAXONOMIES = array( 'product_type', 'product_visibility', 'product_shipping_class', 'post_format', 'template_tag', 'template_bundle' );

	const LINK_DETAIL = 'detail';
	const LINK_FILE   = 'file';

	const DEFAULT_MIMES = array( 'application/pdf' );

	/** @var array<string,array>|null */
	private static ?array $cache = null;

	public static function woocommerce(): bool {
		return class_exists( 'WooCommerce' );
	}

	/** Post types an admin can choose from, keyed by name. */
	public static function candidates(): array {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( self::ATTACHMENTS === $type->name || $type->exclude_from_search || in_array( $type->name, self::EXCLUDED, true ) ) {
				continue;
			}
			$types[ $type->name ] = $type;
		}
		return (array) apply_filters( 'nwr_search_candidate_post_types', $types );
	}

	/**
	 * Effective settings for every candidate (saved values over defaults), in display order.
	 *
	 * @return array<string,array{name:string,enabled:int,label:string,order:int,limit:int,link:string,taxonomy:string,facets:array,builtin:bool}>
	 */
	public static function all( ?array $saved = null ): array {
		if ( null === $saved && null !== self::$cache ) {
			return self::$cache;
		}
		// Before `init` custom post types are not registered yet; never cache that partial view.
		$use_cache = null === $saved && did_action( 'init' );
		$saved     = $saved ?? (array) Settings::get( 'types' );
		$woo       = self::woocommerce();
		$types     = array();
		$position  = 0;

		foreach ( self::candidates() as $name => $object ) {
			++$position;
			$row      = isset( $saved[ $name ] ) && is_array( $saved[ $name ] ) ? $saved[ $name ] : array();
			$is_shop  = $woo && 'product' === $name;
			$facets   = self::facet_taxonomies( $name );
			$taxonomy = (string) ( $row['taxonomy'] ?? self::default_taxonomy( $name ) );

			$types[ $name ] = array(
				'name'     => $name,
				// Shops keep the product-only behaviour until someone opts more types in.
				'enabled'  => isset( $row['enabled'] ) ? ( empty( $row['enabled'] ) ? 0 : 1 ) : ( $woo ? (int) $is_shop : 1 ),
				'label'    => '' !== trim( (string) ( $row['label'] ?? '' ) ) ? trim( (string) $row['label'] ) : html_entity_decode( (string) $object->labels->name, ENT_QUOTES, 'UTF-8' ),
				'order'    => self::clamp( $row['order'] ?? $position, 1, 99 ),
				'limit'    => self::clamp( $row['limit'] ?? ( $is_shop ? 6 : 4 ), 1, 24 ),
				'link'     => self::LINK_FILE === ( $row['link'] ?? '' ) ? self::LINK_FILE : self::LINK_DETAIL,
				'taxonomy' => '' === $taxonomy || isset( $facets[ $taxonomy ] ) ? $taxonomy : self::default_taxonomy( $name ),
				'facets'   => $facets,
				'builtin'  => (bool) $object->_builtin,
			);
		}

		$row = isset( $saved[ self::ATTACHMENTS ] ) && is_array( $saved[ self::ATTACHMENTS ] ) ? $saved[ self::ATTACHMENTS ] : array();
		$types[ self::ATTACHMENTS ] = array(
			'name'     => self::ATTACHMENTS,
			'enabled'  => empty( $row['enabled'] ) ? 0 : 1,
			'label'    => '' !== trim( (string) ( $row['label'] ?? '' ) ) ? trim( (string) $row['label'] ) : __( 'Súbory', 'nwr-search' ),
			'order'    => self::clamp( $row['order'] ?? ( $position + 1 ), 1, 99 ),
			'limit'    => self::clamp( $row['limit'] ?? 4, 1, 24 ),
			'link'     => self::LINK_FILE,
			'taxonomy' => '',
			'facets'   => array(),
			'builtin'  => true,
		);

		uasort( $types, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] ?: strcmp( $a['name'], $b['name'] ) );

		if ( $use_cache ) {
			self::$cache = $types;
		}
		return $types;
	}

	/** Enabled types only, in display order. */
	public static function enabled(): array {
		return array_filter( self::all(), static fn( array $type ): bool => (bool) $type['enabled'] );
	}

	public static function is_enabled( string $post_type ): bool {
		return isset( self::enabled()[ $post_type ] );
	}

	/** WooCommerce products are indexed with prices, stock and attributes. */
	public static function shop_mode(): bool {
		return self::woocommerce() && self::is_enabled( 'product' );
	}

	/** Only WooCommerce products — the original, product-only widget. */
	public static function products_only(): bool {
		return self::shop_mode() && 1 === count( self::enabled() );
	}

	/** Media library MIME types indexed as files. */
	public static function mimes(): array {
		$saved = Settings::get( 'mimes' );
		$mimes = is_array( $saved ) && $saved ? $saved : self::DEFAULT_MIMES;
		return array_values( array_filter( array_map( 'sanitize_mime_type', $mimes ) ) );
	}

	/** Public, non-internal taxonomies of a post type: name => label. */
	public static function facet_taxonomies( string $post_type ): array {
		$facets = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public || in_array( $taxonomy->name, self::INTERNAL_TAXONOMIES, true ) || str_starts_with( $taxonomy->name, 'pa_' ) ) {
				continue;
			}
			$facets[ $taxonomy->name ] = html_entity_decode( (string) $taxonomy->labels->singular_name, ENT_QUOTES, 'UTF-8' );
		}
		return $facets;
	}

	/** The taxonomy shown as "categories" in the panel: the first hierarchical one. */
	public static function default_taxonomy( string $post_type ): string {
		if ( 'product' === $post_type && taxonomy_exists( 'product_cat' ) ) {
			return 'product_cat';
		}
		foreach ( array_keys( self::facet_taxonomies( $post_type ) ) as $taxonomy ) {
			if ( is_taxonomy_hierarchical( $taxonomy ) ) {
				return $taxonomy;
			}
		}
		return '';
	}

	/** Primary taxonomies of all enabled types (for the category map). */
	public static function primary_taxonomies(): array {
		$taxonomies = array();
		foreach ( self::enabled() as $type ) {
			if ( '' !== $type['taxonomy'] ) {
				$taxonomies[ $type['taxonomy'] ] = $type['taxonomy'];
			}
		}
		return array_values( $taxonomies );
	}

	/** Every taxonomy that feeds a facet of an enabled type. */
	public static function indexed_taxonomies(): array {
		$taxonomies = array();
		foreach ( self::enabled() as $type ) {
			foreach ( array_keys( $type['facets'] ) as $taxonomy ) {
				$taxonomies[ $taxonomy ] = $taxonomy;
			}
		}
		return array_values( $taxonomies );
	}

	public static function flush(): void {
		self::$cache = null;
	}

	private static function clamp( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}
}
