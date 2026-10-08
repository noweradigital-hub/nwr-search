<?php
namespace Nowera\Search\Index;

use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one WooCommerce product into one Typesense document.
 * Variations are folded into their parent: one search hit per product, not per variant.
 */
final class Product_Document {

	const TEXT_LIMIT = 1000;

	/** Per-product ranking nudge, edited in the product screen. */
	const BOOST_META  = '_nwr_search_boost';
	const BOOST_LIMIT = 100;

	/** Returns null when the product does not belong in the index. */
	public function build( WC_Product $product ): ?array {
		if ( ! $this->is_indexable( $product ) ) {
			return null;
		}

		$id      = $product->get_id();
		$created = $product->get_date_created();
		$title   = $this->text( $product->get_name(), 300 );

		$document = array(
			'id'                => (string) $id,
			'product_id'        => $id,
			'post_type'         => 'product',
			'type'              => $product->get_type(),
			'title'             => '' !== $title ? $title : '#' . $id,
			'sku'               => (string) $product->get_sku(),
			'short_description' => $this->text( $product->get_short_description() ),
			'description'       => $this->text( $product->get_description() ),
			'brands'            => $this->term_names( $id, 'product_brand' ),
			'tags'              => $this->term_names( $id, 'product_tag' ),
			'on_sale'           => $product->is_on_sale(),
			'in_stock'          => $product->is_in_stock(),
			'stock_status'      => (string) $product->get_stock_status(),
			'featured'          => $product->is_featured(),
			'visibility'        => (string) $product->get_catalog_visibility(),
			'rating'            => (float) $product->get_average_rating(),
			'review_count'      => (int) $product->get_review_count(),
			'popularity'        => (int) round( log( 1 + max( 0, (int) $product->get_total_sales() ), 2 ) * 10 ),
			'menu_order'        => (int) $product->get_menu_order(),
			'boost'             => self::boost( $product ),
			'created_at'        => $created ? $created->getTimestamp() : 0,
			'modified_at'       => $product->get_date_modified() ? $product->get_date_modified()->getTimestamp() : 0,
			'indexed_at'        => time(),
			'url'               => (string) get_permalink( $id ),
			'image'             => $this->image( $product ),
			'related_ids'       => $this->related_ids( $product ),
		);

		$attributes = $this->attributes( $product );
		$document  += $this->prices( $product ) + $this->codes( $product ) + $this->categories( $id ) + $attributes;
		// Every attribute value as searchable text, so a variant name ("šalviová", "2,5 l")
		// finds its product without a separate hit per variation.
		$document['attributes'] = $attributes ? array_values( array_unique( array_merge( ...array_values( $attributes ) ) ) ) : array();

		// Typesense rejects null for typed fields and empty values only pollute facets.
		$document = array_filter( $document, static fn( $value ): bool => ! ( null === $value || '' === $value || array() === $value ) );

		return apply_filters( 'nwr_search_product_document', $document, $product );
	}

	/** Manual ranking nudge (−100…100, 0 = untouched), clamped so one product cannot own every result. */
	public static function boost( WC_Product $product ): int {
		$boost = $product->get_meta( self::BOOST_META );
		$boost = (int) apply_filters( 'nwr_search_boost', (int) $boost, $product );
		return max( -self::BOOST_LIMIT, min( self::BOOST_LIMIT, $boost ) );
	}

	public function is_indexable( WC_Product $product ): bool {
		$indexable = ! $product->is_type( 'variation' )
			&& 'publish' === $product->get_status()
			&& 'hidden' !== $product->get_catalog_visibility()
			&& '' === (string) get_post_field( 'post_password', $product->get_id() );

		return (bool) apply_filters( 'nwr_search_is_indexable', $indexable, $product );
	}

	/** Prices as displayed in the shop (respects the "display prices incl. tax" setting). */
	private function prices( WC_Product $product ): array {
		if ( $product instanceof WC_Product_Variable ) {
			$min     = (float) $product->get_variation_price( 'min', true );
			$max     = (float) $product->get_variation_price( 'max', true );
			$regular = (float) $product->get_variation_regular_price( 'min', true );
		} else {
			$min     = (float) wc_get_price_to_display( $product );
			$max     = $min;
			$regular = '' === $product->get_regular_price() ? 0.0 : (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
		}

		if ( $min <= 0 ) {
			return array();
		}

		$prices = array( 'price' => round( $min, 2 ) );
		if ( $max > $min ) {
			$prices['price_max'] = round( $max, 2 );
		}
		if ( $regular > $min ) {
			$prices['regular_price']    = round( $regular, 2 );
			$prices['discount_percent'] = (int) round( ( 1 - $min / $regular ) * 100 );
		}
		return $prices;
	}

	/** SKUs and GTINs of the product and its visible variations, read in one query. */
	private function codes( WC_Product $product ): array {
		global $wpdb;

		$gtins = array();
		if ( method_exists( $product, 'get_global_unique_id' ) && '' !== (string) $product->get_global_unique_id() ) {
			$gtins[] = (string) $product->get_global_unique_id();
		}

		$skus     = array();
		$children = $product instanceof WC_Product_Variable ? array_map( 'intval', $product->get_visible_children() ) : array();
		if ( $children ) {
			$rows = $wpdb->get_results(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE post_id IN (" . implode( ',', $children ) . ") AND meta_key IN ('_sku', '_global_unique_id') AND meta_value <> ''"
			);
			foreach ( $rows as $row ) {
				if ( '_sku' === $row->meta_key ) {
					$skus[] = (string) $row->meta_value;
				} else {
					$gtins[] = (string) $row->meta_value;
				}
			}
		}

		return array(
			'skus'  => array_values( array_unique( array_diff( $skus, array( (string) $product->get_sku() ) ) ) ),
			'gtins' => array_values( array_unique( $gtins ) ),
		);
	}

	/** Category names, ids and hierarchical levels ("Hračky", "Hračky > Drevené"). */
	private function categories( int $product_id ): array {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$uncategorized = (int) get_option( 'default_product_cat' );
		$names         = array();
		$ids           = array();
		$levels        = array();

		foreach ( $terms as $term ) {
			if ( (int) $term->term_id === $uncategorized ) {
				continue;
			}

			$chain   = array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
			$chain[] = (int) $term->term_id;
			$path    = array();

			foreach ( $chain as $depth => $term_id ) {
				$node = get_term( $term_id, 'product_cat' );
				if ( ! $node || is_wp_error( $node ) ) {
					break;
				}
				$path[]  = $this->text( $node->name, 200 );
				$names[] = end( $path );
				$ids[]   = (int) $node->term_id;
				if ( $depth < 3 ) {
					$levels[ 'category_lvl' . $depth ][] = implode( ' > ', $path );
				}
			}
		}

		$categories = array(
			'categories'   => array_values( array_unique( $names ) ),
			'category_ids' => array_values( array_unique( $ids ) ),
		);
		foreach ( $levels as $field => $values ) {
			$categories[ $field ] = array_values( array_unique( $values ) );
		}
		return $categories;
	}

	/** Visible and variation attributes as attr_<slug> facets. */
	private function attributes( WC_Product $product ): array {
		$attributes = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ( ! $attribute->get_visible() && ! $attribute->get_variation() ) ) {
				continue;
			}

			if ( $attribute->is_taxonomy() ) {
				$slug   = preg_replace( '/^pa_/', '', $attribute->get_name() );
				$values = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
			} else {
				$slug   = sanitize_title( $attribute->get_name() );
				$values = $attribute->get_options();
			}

			$field  = 'attr_' . str_replace( '-', '_', (string) $slug );
			$values = array_filter( array_map( fn( $value ): string => $this->text( (string) $value, 200 ), (array) $values ) );
			if ( $values ) {
				$attributes[ $field ] = array_values( array_unique( array_merge( $attributes[ $field ] ?? array(), $values ) ) );
			}
		}

		return $attributes;
	}

	/**
	 * Products worth recommending next to this one: upsells, Woodmart "Frequently
	 * bought together" bundles, then cross-sells. Stored, not indexed.
	 */
	private function related_ids( WC_Product $product ): array {
		$ids = array_merge(
			array_map( 'intval', $product->get_upsell_ids() ),
			self::woodmart_bundle_product_ids( $product->get_id() ),
			array_map( 'intval', $product->get_cross_sell_ids() )
		);

		$related = array();
		foreach ( $ids as $id ) {
			if ( 'product_variation' === get_post_type( $id ) ) {
				$id = (int) wp_get_post_parent_id( $id );
			}
			if ( $id > 0 && $id !== $product->get_id() ) {
				$related[ $id ] = $id;
			}
		}

		$related = (array) apply_filters( 'nwr_search_related_ids', array_values( $related ), $product );
		return array_slice( array_values( array_unique( array_map( 'intval', $related ) ) ), 0, 12 );
	}

	/** Product ids from the Woodmart FBT bundles attached to a product (empty on other themes). */
	public static function woodmart_bundle_product_ids( int $product_id ): array {
		$ids     = array();
		$bundles = get_post_meta( $product_id, 'woodmart_fbt_bundles_id', true );
		foreach ( is_array( $bundles ) ? $bundles : array() as $bundle_id ) {
			$items = get_post_meta( (int) $bundle_id, '_woodmart_fbt_products', true );
			foreach ( is_array( $items ) ? $items : array() as $item ) {
				if ( is_array( $item ) && ! empty( $item['id'] ) ) {
					$ids[] = (int) $item['id'];
				}
			}
		}
		return $ids;
	}

	private function term_names( int $product_id, string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_the_terms( $product_id, $taxonomy );
		return is_array( $terms ) ? array_values( array_unique( array_map( fn( $term ): string => $this->text( $term->name, 200 ), $terms ) ) ) : array();
	}

	private function image( WC_Product $product ): string {
		$url = $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : '';
		return $url ? (string) $url : (string) wc_placeholder_img_src( 'woocommerce_thumbnail' );
	}

	/** Plain text: no tags, no builder shortcodes, decoded entities, collapsed whitespace. */
	private function text( ?string $html, int $limit = self::TEXT_LIMIT ): string {
		$text = strip_shortcodes( (string) $html );
		$text = (string) preg_replace( '/\[\/?[a-z][a-z0-9_\-]*[^\]]*\]/i', ' ', $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text ) > $limit ? rtrim( mb_substr( $text, 0, $limit ) ) : $text;
	}
}
