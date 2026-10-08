<?php
namespace Nowera\Search\Sync;

use Nowera\Search\Content_Types;
use Nowera\Search\Settings;
use WC_Product;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the post ids touched during a request and hands them to the job
 * queue once, on shutdown. The request itself never talks to Typesense.
 */
final class Change_Tracker {

	/** Meta keys whose change alone (without a post save) alters a document. */
	const WATCHED_META = array( '_bricks_page_content_2', '_elementor_data', '_nwr_search_boost', '_thumbnail_id' );

	/** @var array<int,true> */
	private array $queued = array();

	public function register(): void {
		if ( Content_Types::woocommerce() ) {
			add_action( 'woocommerce_new_product', array( $this, 'product' ), 20 );
			add_action( 'woocommerce_update_product', array( $this, 'product' ), 20 );
			add_action( 'woocommerce_new_product_variation', array( $this, 'variation' ), 20 );
			add_action( 'woocommerce_update_product_variation', array( $this, 'variation' ), 20 );

			// Stock and sales change on checkout without a product save.
			add_action( 'woocommerce_product_set_stock', array( $this, 'product_object' ) );
			add_action( 'woocommerce_variation_set_stock', array( $this, 'product_object' ) );
			add_action( 'woocommerce_product_set_stock_status', array( $this, 'product' ), 20 );
			add_action( 'woocommerce_variation_set_stock_status', array( $this, 'variation' ), 20 );
			add_action( 'woocommerce_updated_product_sales', array( $this, 'product' ) );

			// Woodmart "Frequently bought together" bundles feed related_ids; late priority so the bundle meta is saved first.
			add_action( 'save_post_woodmart_woo_fbt', array( $this, 'woodmart_bundle' ), 99 );
		}

		// Any content type: saves (incl. status changes), trash, delete. Late, so custom fields are stored first.
		add_action( 'save_post', array( $this, 'post' ), 99, 2 );
		add_action( 'trashed_post', array( $this, 'post' ) );
		add_action( 'untrashed_post', array( $this, 'post' ) );
		add_action( 'deleted_post', array( $this, 'post' ), 10, 2 );

		// Builders save their layout as meta, sometimes without a post save.
		add_action( 'added_post_meta', array( $this, 'meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'meta' ), 10, 3 );

		add_action( 'add_attachment', array( $this, 'post' ) );
		add_action( 'attachment_updated', array( $this, 'post' ), 10, 2 );
		add_action( 'delete_attachment', array( $this, 'post' ), 10, 2 );

		add_action( 'edited_term', array( $this, 'term_edited' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'term_deleted' ), 10, 5 );
		add_action( 'set_object_terms', array( $this, 'terms_set' ), 10, 1 );

		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	public function product( $product_id ): void {
		$product_id = absint( $product_id );
		if ( $product_id ) {
			$this->queued[ $product_id ] = true;
		}
	}

	public function variation( $variation_id ): void {
		$this->product( wp_get_post_parent_id( absint( $variation_id ) ) );
	}

	public function product_object( $product ): void {
		if ( $product instanceof WC_Product ) {
			$this->product( $product->get_parent_id() ?: $product->get_id() );
		}
	}

	public function post( $post_id, $post = null ): void {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( 'product_variation' === $post->post_type ) {
			$this->product( $post->post_parent );
		} elseif ( Content_Types::is_enabled( $post->post_type ) ) {
			$this->product( $post->ID );
		}
	}

	public function meta( $meta_ids, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, self::WATCHED_META, true ) ) {
			$this->post( (int) $object_id );
		}
	}

	/** Assigning terms outside a post save (quick edit, bulk, imports). */
	public function terms_set( $object_id ): void {
		$this->post( (int) $object_id );
	}

	public function term_edited( $term_id, $tt_id, $taxonomy ): void {
		if ( $this->is_indexed_taxonomy( (string) $taxonomy ) && $this->enabled() ) {
			Jobs::enqueue_term( (string) $taxonomy, (int) $term_id );
		}
	}

	/** Relationships are already gone by now, so use the object ids WordPress passes along. */
	public function term_deleted( $term, $tt_id, $taxonomy, $deleted_term, $object_ids ): void {
		if ( $this->is_indexed_taxonomy( (string) $taxonomy ) ) {
			foreach ( (array) $object_ids as $object_id ) {
				$this->post( (int) $object_id );
			}
		}
	}

	/** Products inside the bundle plus the products the bundle is attached to. */
	public function woodmart_bundle( $bundle_id ): void {
		global $wpdb;

		$bundle_id = (int) $bundle_id;
		$items     = get_post_meta( $bundle_id, '_woodmart_fbt_products', true );
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( is_array( $item ) && ! empty( $item['id'] ) ) {
				$this->post( (int) $item['id'] );
			}
		}

		$owners = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'woodmart_fbt_bundles_id' AND meta_value LIKE %s",
				'%"' . $wpdb->esc_like( (string) $bundle_id ) . '"%'
			)
		);
		foreach ( $owners as $owner ) {
			$this->post( (int) $owner );
		}
	}

	public function flush(): void {
		if ( ! $this->queued || ! $this->enabled() ) {
			return;
		}
		Jobs::enqueue_sync( array_keys( $this->queued ) );
		$this->queued = array();
	}

	private function enabled(): bool {
		return Settings::is_configured() && ! empty( Settings::get( 'auto_sync' ) );
	}

	private function is_indexed_taxonomy( string $taxonomy ): bool {
		if ( Content_Types::shop_mode() && ( in_array( $taxonomy, array( 'product_cat', 'product_tag', 'product_brand' ), true ) || str_starts_with( $taxonomy, 'pa_' ) ) ) {
			return true;
		}
		return in_array( $taxonomy, Content_Types::indexed_taxonomies(), true );
	}
}
