<?php
namespace Nowera\Search\Frontend;

use Nowera\Search\Content_Types;
use Nowera\Search\Index\Text;
use Nowera\Search\Settings;
use Nowera\Search\Status;
use Nowera\Search\Typesense\Client;

use const Nowera\Search\PLUGIN_FILE;
use const Nowera\Search\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Widget script, styles and browser config. Loaded only on pages that render
 * the widget, so the rest of the site pays nothing for it.
 */
final class Assets {

	const HANDLE         = 'nwr-search';
	const RESULTS_HANDLE = 'nwr-search-results';
	const CATEGORY_CACHE = 'nwr_search_categories';
	const BOOSTS_CACHE   = 'nwr_search_has_boosts';

	/** Shape of the cached category map; a cache written in another shape is rebuilt. */
	const CATEGORY_FORMAT = 2;

	const RECENT_STORAGE_KEY = 'nwr_search_recent';

	/** Searchable fields and their weights; only fields the collection has are sent. */
	const QUERY_FIELDS = array(
		'title'             => 12,
		'sku'               => 10,
		'skus'              => 10,
		'gtins'             => 10,
		'brands'            => 4,
		'categories'        => 6,
		'attributes'        => 3,
		'short_description' => 1,
		'excerpt'           => 2,
		'content'           => 1,
	);

	/** Fields every collection has had since 0.1, used before the live schema is known. */
	const LEGACY_QUERY_FIELDS = array( 'title', 'sku', 'skus', 'gtins', 'brands', 'categories', 'short_description' );

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_for_classic_content' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_for_attached_field' ), 20 );
		add_action( 'wp_footer', array( $this, 'track_recently_viewed' ) );
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush_categories' ) );
		}
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'flush_categories' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'boost_changed' ), 10, 3 );
		}
	}

	public static function boost_changed( $meta_ids, $object_id, $meta_key ): void {
		if ( '_nwr_search_boost' === $meta_key ) {
			delete_transient( self::BOOSTS_CACHE );
		}
	}

	/**
	 * Relevance buckets only pay off when something is boosted: without boosts they
	 * just blur the text match (a product with the word once can outrank the article
	 * about it). Cached until a boost changes.
	 */
	private static function site_has_boosts(): bool {
		$cached = get_transient( self::BOOSTS_CACHE );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		global $wpdb;
		$found = (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_nwr_search_boost' AND meta_value NOT IN ('', '0') LIMIT 1" );
		set_transient( self::BOOSTS_CACHE, $found ? '1' : '0', 12 * HOUR_IN_SECONDS );
		return $found;
	}

	public function register_assets(): void {
		$base = plugins_url( 'assets/', PLUGIN_FILE );
		wp_register_style( self::HANDLE, $base . 'nwr-search.css', array(), VERSION );
		wp_register_script(
			self::HANDLE,
			$base . 'nwr-search.js',
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_register_style( self::RESULTS_HANDLE, $base . 'nwr-search-results.css', array( self::HANDLE ), VERSION );
		wp_register_script(
			self::RESULTS_HANDLE,
			$base . 'nwr-search-results.js',
			array( self::HANDLE ),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/** Enhancing a theme's own search field means the widget must load everywhere. */
	public function enqueue_for_attached_field(): void {
		if ( '' !== (string) Settings::get( 'attach' ) ) {
			self::enqueue();
		}
	}

	/**
	 * Classic post content is known before the head is printed, so the styles
	 * can load there. Builder layouts (Elementor, Bricks) fall back to the late
	 * enqueue done by the shortcode or element itself.
	 */
	public function enqueue_for_classic_content(): void {
		$post = get_post();
		if ( ! is_singular() || ! $post ) {
			return;
		}
		if ( has_shortcode( $post->post_content, Results_Shortcode::TAG ) ) {
			self::enqueue_results();
		} elseif ( has_shortcode( $post->post_content, Shortcode::TAG ) ) {
			self::enqueue();
		}
	}

	/** Widget assets plus the results app. */
	public static function enqueue_results(): bool {
		if ( ! self::enqueue() ) {
			return false;
		}
		if ( ! wp_script_is( self::RESULTS_HANDLE, 'enqueued' ) ) {
			wp_enqueue_style( self::RESULTS_HANDLE );
			wp_enqueue_script( self::RESULTS_HANDLE );
		}
		return true;
	}

	/** Returns false when the search key is missing, so the markup stays a plain search form. */
	public static function enqueue(): bool {
		if ( ! Settings::frontend_ready() ) {
			return false;
		}
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			( new self() )->register_assets();
		}
		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			wp_enqueue_style( self::HANDLE );
			wp_enqueue_script( self::HANDLE );
			wp_add_inline_script( self::HANDLE, 'window.nwrSearchConfig = ' . wp_json_encode( self::config() ) . ';', 'before' );
			$css = self::inline_css();
			if ( '' !== $css ) {
				wp_add_inline_style( self::HANDLE, $css );
			}
		}
		return true;
	}

	/**
	 * Remembers the entry being viewed in the visitor's browser (ids only, last 12).
	 * Runs on every indexed detail page, whether or not the widget is on it, and survives page cache.
	 */
	public function track_recently_viewed(): void {
		if ( ! Settings::frontend_ready() || ! self::current_entry() ) {
			return;
		}
		$sections = Settings::sections();
		if ( ! $sections['browse']['recent']['enabled'] && ! $sections['browse']['related']['enabled'] ) {
			return;
		}
		wp_print_inline_script_tag(
			sprintf(
				'(function(k,id){try{var a=JSON.parse(localStorage.getItem(k)||"[]").filter(function(x){return x!==id});a.unshift(id);localStorage.setItem(k,JSON.stringify(a.slice(0,12)))}catch(e){}})(%s,%d);',
				wp_json_encode( self::RECENT_STORAGE_KEY ),
				self::current_entry()
			),
			array( 'id' => 'nwr-search-recent' )
		);
	}

	/** The indexed product/post/CPT entry being viewed, or 0. Plain pages (Contact, About) are not "viewed items". */
	private static function current_entry(): int {
		if ( ! is_singular() || is_page() ) {
			return 0;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post && Content_Types::is_enabled( $post->post_type ) ? (int) $post->ID : 0;
	}

	/** Colours chosen in the settings: the accent (with a readable text colour on top of it) and the toggle icon. */
	private static function inline_css(): string {
		$vars = array();
		if ( '' !== Settings::accent() ) {
			$vars[] = self::accent_vars( Settings::accent() );
		}
		if ( '' !== Settings::icon_color() ) {
			$vars[] = '--nwr-icon:' . Settings::icon_color();
		}
		return $vars ? '.nwr-search{' . implode( ';', $vars ) . '}' : '';
	}

	private static function accent_vars( string $accent ): string {
		$hex = ltrim( $accent, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$channel   = static function ( string $pair ): float {
			$value = hexdec( $pair ) / 255;
			return $value <= 0.03928 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
		};
		$luminance = 0.2126 * $channel( substr( $hex, 0, 2 ) ) + 0.7152 * $channel( substr( $hex, 2, 2 ) ) + 0.0722 * $channel( substr( $hex, 4, 2 ) );
		$on_accent = $luminance > 0.45 ? '#111827' : '#ffffff';

		return sprintf( '--nwr-accent:#%s;--nwr-on-accent:%s', $hex, $on_accent );
	}

	public static function flush_categories(): void {
		delete_transient( self::CATEGORY_CACHE );
	}

	private static function config(): array {
		$settings  = Settings::all();
		$has_boost = Status::has_field( 'boost' );
		$universal = ! Content_Types::products_only();

		$config = array(
			'host'           => Client::normalize_url( $settings['host'] ),
			'collection'     => $settings['collection'],
			'key'            => $settings['search_key'], // search-only key, scoped to this collection
			'mode'           => $universal ? 'universal' : 'products',
			'types'          => self::types_config(),
			'queryBy'        => implode( ',', array_keys( self::query_fields() ) ),
			'queryWeights'   => implode( ',', self::query_fields() ),
			// Browsing (no query): manual boost, then sales, then freshness. Typesense allows three sort fields.
			'rank'           => implode(
				',',
				array_filter(
					array(
						$has_boost ? 'boost(missing_values: last):desc' : '',
						'popularity:desc',
						$universal && Status::has_field( 'modified_at' ) ? 'modified_at(missing_values: last):desc' : '',
					)
				)
			),
			// Where "show all results" goes: the results page when there is one.
			'searchUrl'      => Settings::results_url() ?: home_url( '/' ),
			'searchParam'    => Settings::results_url() ? Search_Takeover::QUERY_VAR : 's',
			// Where a category in the panel goes: the results page filtered to it, or the term archive.
			'categoryLinks'  => Settings::results_url() && ! empty( $settings['category_links'] ) ? 'results' : 'archive',
			'attach'         => (string) $settings['attach'],
			'results'        => array(
				'perPage'     => Settings::RESULTS_PER_PAGE,
				'filters'     => $universal ? self::universal_filters() : self::shop_filters(),
				'typeFilters' => $universal ? self::type_filters() : (object) array(),
			),
			'hideOutOfStock' => Content_Types::shop_mode() && 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ),
			'currency'       => Content_Types::woocommerce() ? array(
				'symbol'            => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'position'          => (string) get_option( 'woocommerce_currency_pos', 'left' ),
				'decimals'          => wc_get_price_decimals(),
				'decimalSeparator'  => wc_get_price_decimal_separator(),
				'thousandSeparator' => wc_get_price_thousand_separator(),
			) : null,
			// 0 = sort by pure relevance; otherwise hits are grouped into this many
			// relevance buckets and the per-item boost decides inside a bucket.
			'boostBuckets'   => $has_boost && self::site_has_boosts() ? (int) $settings['boost_buckets'] : 0,
			// Inflected languages (Slovak, Czech…) need typo variants even when exact hits exist:
			// "strecha" should also reach "strechy" and "striech". Typesense only expands typos while
			// fewer results than this were found, so a high value means "always". Shops keep the default.
			'typoTokens'     => $universal ? 100 : 1,
			'hasBoost'       => $has_boost,
			'categories'     => (object) self::categories(),
			'sections'       => self::sections_config(),
			'recentKey'      => self::RECENT_STORAGE_KEY,
			// Excluded from "recently viewed" while the visitor is on that very entry.
			'currentProduct' => self::current_entry(),
			'i18n'           => self::i18n( $universal ),
		);

		return (array) apply_filters( 'nwr_search_frontend_config', $config );
	}

	/** Weighted query fields present in the live collection. */
	private static function query_fields(): array {
		$known  = Status::schema_fields();
		$fields = array();
		foreach ( self::QUERY_FIELDS as $field => $weight ) {
			if ( $known ? in_array( $field, $known, true ) : in_array( $field, self::LEGACY_QUERY_FIELDS, true ) ) {
				$fields[ $field ] = $weight;
			}
		}
		return $fields;
	}

	/** Enabled content types in display order: id, label, per-type limit, link target, category taxonomy. */
	private static function types_config(): array {
		$types = array();
		foreach ( Content_Types::enabled() as $type ) {
			$types[] = array(
				'id'       => $type['name'],
				'label'    => $type['label'],
				'limit'    => $type['limit'],
				'link'     => $type['link'],
				'product'  => 'product' === $type['name'] && Content_Types::woocommerce(),
				'taxonomy' => $type['taxonomy'],
			);
		}
		return $types;
	}

	/** "All" tab: the primary category list and, when files are indexed, their format. */
	private static function universal_filters(): array {
		$filters = array( array( 'id' => 'category_ids', 'type' => 'categories', 'label' => __( 'Kategórie', 'nwr-search' ) ) );
		if ( self::files_indexed() ) {
			$filters[] = array( 'id' => 'file_type', 'type' => 'terms', 'label' => __( 'Formát súboru', 'nwr-search' ) );
		}
		return $filters;
	}

	/** Per content type: its taxonomies (products keep the shop filters). */
	private static function type_filters(): object {
		$map = array();
		foreach ( Content_Types::enabled() as $name => $type ) {
			if ( 'product' === $name && Content_Types::woocommerce() ) {
				$map[ $name ] = self::shop_filters();
				continue;
			}
			$filters = array();
			foreach ( $type['facets'] as $taxonomy => $label ) {
				$filters[] = array(
					'id'    => 'tax_' . $taxonomy,
					'type'  => 'terms',
					'label' => $label,
				);
			}
			if ( Content_Types::ATTACHMENTS === $name || Content_Types::LINK_FILE === $type['link'] ) {
				$filters[] = array( 'id' => 'file_type', 'type' => 'terms', 'label' => __( 'Formát súboru', 'nwr-search' ) );
			}
			$map[ $name ] = $filters;
		}
		return (object) $map;
	}

	private static function files_indexed(): bool {
		foreach ( Content_Types::enabled() as $type ) {
			if ( Content_Types::LINK_FILE === $type['link'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Shop filters, in display order. Attribute filters map to the attr_<slug>
	 * facets the product indexer writes.
	 *
	 * @return array<int,array{id:string,type:string,label:string}>
	 */
	private static function shop_filters(): array {
		$enabled = Settings::filters();
		$filters = array();

		if ( $enabled['categories'] ) {
			$filters[] = array( 'id' => 'category_ids', 'type' => 'categories', 'label' => __( 'Kategórie', 'nwr-search' ) );
		}
		if ( $enabled['brands'] ) {
			$filters[] = array( 'id' => 'brands', 'type' => 'terms', 'label' => __( 'Značka', 'nwr-search' ) );
		}
		foreach ( $enabled['attributes'] as $slug ) {
			$label     = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( 'pa_' . $slug ) : $slug;
			$filters[] = array(
				'id'    => 'attr_' . str_replace( '-', '_', $slug ),
				'type'  => 'terms',
				'label' => (string) $label,
			);
		}
		if ( $enabled['price'] ) {
			$filters[] = array( 'id' => 'price', 'type' => 'range', 'label' => __( 'Cena', 'nwr-search' ) );
		}
		// The group heading names the topic; the checkbox itself says "only …".
		if ( $enabled['availability'] ) {
			$filters[] = array( 'id' => 'in_stock', 'type' => 'toggle', 'label' => __( 'Dostupnosť', 'nwr-search' ) );
		}
		if ( $enabled['sale'] ) {
			$filters[] = array( 'id' => 'on_sale', 'type' => 'toggle', 'label' => __( 'Zľavy', 'nwr-search' ) );
		}

		return $filters;
	}

	/** Enabled sections per state in display order, in the shape the script expects. */
	private static function sections_config(): array {
		$sections = Settings::sections();
		$ordered  = static function ( array $rows ): array {
			$rows = array_filter( $rows, static fn( array $row ): bool => (bool) $row['enabled'] );
			uasort( $rows, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
			$list = array();
			foreach ( $rows as $id => $row ) {
				$list[] = array(
					'id'    => $id,
					'limit' => $row['limit'],
				);
			}
			return $list;
		};

		return array(
			'browse'     => $ordered( $sections['browse'] ),
			'search'     => $ordered( $sections['search'] ),
			'categories' => array(
				'browse'      => (bool) $sections['categories']['browse'],
				'search'      => (bool) $sections['categories']['search'],
				'limit'       => $sections['categories']['limit'],
				'mobileLimit' => $sections['categories']['mobile_limit'],
			),
		);
	}

	/**
	 * term_id → [name, url, parent_id, taxonomy(, facet value)] for the primary taxonomy of
	 * every enabled type, cached until a term or the settings change. The facet value (the
	 * name as the indexer writes it into tax_<taxonomy>) is only sent when it differs from the name.
	 */
	private static function categories(): array {
		$taxonomies = Content_Types::primary_taxonomies();
		$cached     = get_transient( self::CATEGORY_CACHE );
		if ( is_array( $cached ) && ( $cached['taxonomies'] ?? null ) === $taxonomies && self::CATEGORY_FORMAT === ( $cached['format'] ?? 1 ) ) {
			return (array) $cached['terms'];
		}

		$categories = array();
		if ( $taxonomies ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomies,
					'hide_empty' => true,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$link = get_term_link( $term );
				if ( is_wp_error( $link ) ) {
					continue;
				}
				$name  = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
				$facet = Text::plain( $term->name, 200 );
				$entry = array( $name, $link, (int) $term->parent, $term->taxonomy );
				if ( $facet !== $name ) {
					$entry[] = $facet;
				}
				$categories[ (string) $term->term_id ] = $entry;
			}
		}

		set_transient(
			self::CATEGORY_CACHE,
			array(
				'format'     => self::CATEGORY_FORMAT,
				'taxonomies' => $taxonomies,
				'terms'      => $categories,
			),
			12 * HOUR_IN_SECONDS
		);
		return $categories;
	}

	private static function i18n( bool $universal ): array {
		return array(
			'categories'    => __( 'Kategórie', 'nwr-search' ),
			'products'      => $universal ? __( 'Výsledky', 'nwr-search' ) : __( 'Produkty', 'nwr-search' ),
			'popular'       => $universal ? __( 'Odporúčané', 'nwr-search' ) : __( 'Obľúbené produkty', 'nwr-search' ),
			'recent'        => __( 'Naposledy prezerané', 'nwr-search' ),
			'relatedBrowse' => __( 'Mohlo by sa vám páčiť', 'nwr-search' ),
			/* translators: %s: product name */
			'relatedSearch' => __( 'K produktu %s odporúčame', 'nwr-search' ),
			'results'       => __( 'Výsledky vyhľadávania', 'nwr-search' ),
			/* translators: %d: number of matching results */
			'showAll'       => __( 'Zobraziť všetky výsledky (%d)', 'nwr-search' ),
			/* translators: %s: search query */
			'noResults'     => $universal ? __( 'Pre „%s“ sme nič nenašli.', 'nwr-search' ) : __( 'Pre „%s“ sme nenašli žiadne produkty.', 'nwr-search' ),
			'error'         => __( 'Vyhľadávanie je dočasne nedostupné.', 'nwr-search' ),
			'from'          => __( 'od', 'nwr-search' ),
			'outOfStock'    => __( 'Vypredané', 'nwr-search' ),
			'clear'         => __( 'Vymazať', 'nwr-search' ),
			'close'         => __( 'Zavrieť vyhľadávanie', 'nwr-search' ),
			/* translators: %d: number of results found */
			'found'         => $universal ? __( 'Nájdené výsledky: %d', 'nwr-search' ) : __( 'Nájdených produktov: %d', 'nwr-search' ),
			'filters'       => __( 'Filtre', 'nwr-search' ),
			'clearFilters'  => __( 'Zrušiť filtre', 'nwr-search' ),
			'apply'         => __( 'Zobraziť výsledky', 'nwr-search' ),
			'priceFrom'     => __( 'od', 'nwr-search' ),
			'priceTo'       => __( 'do', 'nwr-search' ),
			'inStockOnly'   => __( 'Len skladom', 'nwr-search' ),
			'onSaleOnly'    => __( 'Len v zľave', 'nwr-search' ),
			'more'          => __( 'Zobraziť viac', 'nwr-search' ),
			'less'          => __( 'Zobraziť menej', 'nwr-search' ),
			'loading'       => __( 'Načítavam…', 'nwr-search' ),
			'emptyQuery'    => __( 'Zadajte, čo hľadáte.', 'nwr-search' ),
			'noFilterHits'  => $universal ? __( 'Zvoleným filtrom nezodpovedá žiadny výsledok.', 'nwr-search' ) : __( 'Zvoleným filtrom nezodpovedá žiadny produkt.', 'nwr-search' ),
			'allTypes'      => __( 'Všetko', 'nwr-search' ),
			'download'      => __( 'Stiahnuť', 'nwr-search' ),
			'open'          => __( 'Otvoriť', 'nwr-search' ),
		);
	}
}
