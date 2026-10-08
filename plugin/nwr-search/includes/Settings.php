<?php
namespace Nowera\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings. Every value can be pinned in wp-config.php with a constant,
 * which keeps the write key out of the database and out of the admin form.
 */
final class Settings {

	const OPTION = 'nwr_search_settings';

	const CONSTANTS = array(
		'host'       => 'NWR_SEARCH_HOST',
		'site_key'   => 'NWR_SEARCH_SITE_KEY',
		'search_key' => 'NWR_SEARCH_SEARCH_KEY',
		'collection' => 'NWR_SEARCH_COLLECTION',
	);

	/**
	 * Panel sections. "browse" = empty field on focus, "search" = while typing.
	 * Products are always shown while typing; everything else can be switched off.
	 */
	const SECTIONS = array(
		'browse'     => array(
			'recent'  => array( 'enabled' => 1, 'limit' => 4, 'order' => 1 ),
			'related' => array( 'enabled' => 1, 'limit' => 4, 'order' => 2 ),
			'popular' => array( 'enabled' => 1, 'limit' => 6, 'order' => 3 ),
		),
		'search'     => array(
			'products' => array( 'enabled' => 1, 'limit' => 6, 'order' => 1 ),
			'related'  => array( 'enabled' => 1, 'limit' => 3, 'order' => 2 ),
		),
		'categories' => array(
			'browse'       => 1,
			'search'       => 1,
			'limit'        => 8,
			'mobile_limit' => 6,
		),
	);

	/**
	 * How strongly the per-product boost may reorder search results: Typesense
	 * groups hits into N relevance buckets and boost decides inside a bucket.
	 * Fewer buckets = wider groups = stronger effect. 0 keeps pure relevance.
	 */
	const BOOST_BUCKETS         = array( 0, 20, 10, 4 );
	const BOOST_BUCKETS_DEFAULT = 10;

	/** Filters offered on the results page; attributes are picked per shop. */
	const FILTERS = array(
		'categories'   => 1,
		'brands'       => 1,
		'price'        => 1,
		'availability' => 1,
		'sale'         => 0,
	);

	const RESULTS_PER_PAGE = 24;

	public static function all(): array {
		$saved    = get_option( self::OPTION, array() );
		$settings = wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'host'           => '',
				'site_key'       => '',
				'search_key'     => '',
				'collection'     => '',
				'auto_sync'      => 1,
				'sections'       => array(),
				'boost_buckets'  => self::BOOST_BUCKETS_DEFAULT,
				// Page holding [nwr_search_results]; 0 = results stay on the WooCommerce search page.
				'results_page'   => 0,
				'takeover'       => 0,
				// 1 = a category in the panel opens the results page filtered to it instead of the term archive.
				'category_links' => 0,
				'filters'        => array(),
				// CSS selector of a theme search form the widget should enhance (optional, advanced).
				'attach'         => '',
				// Per post type: enabled/label/order/limit/link/taxonomy — see Content_Types::all().
				'types'          => array(),
				'mimes'          => Content_Types::DEFAULT_MIMES,
				// Post IDs kept out of the index by hand.
				'exclude'        => array(),
				// Accent colour of the widget; '' = take it from the theme.
				'accent'         => '',
				// Colour of the magnifier that opens the overlay/bar search; '' = header text colour.
				'icon_color'     => '',
			)
		);

		foreach ( self::CONSTANTS as $key => $constant ) {
			if ( self::is_locked( $key ) ) {
				$settings[ $key ] = (string) constant( $constant );
			}
		}

		if ( '' === $settings['collection'] ) {
			$settings['collection'] = self::default_collection();
		}

		$settings['boost_buckets'] = self::boost_buckets( $settings['boost_buckets'] );

		return $settings;
	}

	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	public static function is_locked( string $key ): bool {
		$constant = self::CONSTANTS[ $key ] ?? null;
		return $constant && defined( $constant ) && '' !== (string) constant( $constant );
	}

	/** Sync needs the write key. */
	public static function is_configured(): bool {
		$settings = self::all();
		return '' !== $settings['host'] && '' !== $settings['site_key'] && '' !== $settings['collection'];
	}

	/** The browser widget only needs the public search key. */
	public static function frontend_ready(): bool {
		$settings = self::all();
		return '' !== $settings['host'] && '' !== $settings['search_key'] && '' !== $settings['collection'];
	}

	/** Saved section settings merged over the defaults, clamped to sane values. */
	public static function sections( ?array $saved = null ): array {
		$saved    = $saved ?? (array) self::all()['sections'];
		$sections = array();

		foreach ( array( 'browse', 'search' ) as $state ) {
			foreach ( self::SECTIONS[ $state ] as $id => $defaults ) {
				$row = isset( $saved[ $state ][ $id ] ) && is_array( $saved[ $state ][ $id ] ) ? $saved[ $state ][ $id ] : $defaults;

				$sections[ $state ][ $id ] = array(
					'enabled' => 'products' === $id ? 1 : ( empty( $row['enabled'] ) ? 0 : 1 ),
					'limit'   => self::clamp( $row['limit'] ?? $defaults['limit'], 1, 24 ),
					'order'   => self::clamp( $row['order'] ?? $defaults['order'], 1, 99 ),
				);
			}
		}

		$defaults   = self::SECTIONS['categories'];
		$categories = isset( $saved['categories'] ) && is_array( $saved['categories'] ) ? $saved['categories'] : $defaults;

		$sections['categories'] = array(
			'browse'       => empty( $categories['browse'] ) ? 0 : 1,
			'search'       => empty( $categories['search'] ) ? 0 : 1,
			'limit'        => self::clamp( $categories['limit'] ?? $defaults['limit'], 1, 30 ),
			'mobile_limit' => self::clamp( $categories['mobile_limit'] ?? $defaults['mobile_limit'], 0, 30 ),
		);

		return $sections;
	}

	/** Results page filters merged over the defaults; 'attributes' holds product attribute slugs. */
	public static function filters( ?array $saved = null ): array {
		$saved   = $saved ?? (array) self::all()['filters'];
		$filters = array();

		foreach ( self::FILTERS as $id => $default ) {
			$filters[ $id ] = isset( $saved[ $id ] ) ? ( empty( $saved[ $id ] ) ? 0 : 1 ) : $default;
		}

		$attributes = isset( $saved['attributes'] ) && is_array( $saved['attributes'] ) ? $saved['attributes'] : array();
		$filters['attributes'] = array_values( array_unique( array_filter( array_map( 'sanitize_title', $attributes ) ) ) );

		return $filters;
	}

	/** Permalink of the results page, or '' when results stay on the WooCommerce search page. */
	public static function results_url(): string {
		$page = (int) self::get( 'results_page' );
		if ( $page <= 0 || 'publish' !== get_post_status( $page ) ) {
			return '';
		}
		$url = get_permalink( $page );
		return $url ? (string) $url : '';
	}

	/** The widget accent as #rrggbb, or '' to follow the theme. */
	public static function accent(): string {
		return (string) sanitize_hex_color( (string) self::get( 'accent' ) );
	}

	/** The toggle icon colour as #rrggbb, or '' to inherit it from the header. */
	public static function icon_color(): string {
		return (string) sanitize_hex_color( (string) self::get( 'icon_color' ) );
	}

	/** Only the offered bucket counts are accepted; anything else falls back to the default. */
	public static function boost_buckets( $value ): int {
		return in_array( (int) $value, self::BOOST_BUCKETS, true ) ? (int) $value : self::BOOST_BUCKETS_DEFAULT;
	}

	private static function clamp( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}

	/** www.example.com → example_com */
	public static function default_collection(): string {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $host ), '_' );
	}
}
