<?php
namespace Nowera\Search\Admin;

use Nowera\Search\Index\Product_Document;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Per-product ranking nudge ("boost"), editable in the product screen, in the
 * product list column, and in quick / bulk edit.
 *
 * The value is stored as product meta and written through the product object,
 * so the normal change tracker picks the product up and re-syncs it.
 */
final class Product_Boost {

	const BULK_FIELD = 'nwr_search_boost_bulk';

	public function register(): void {
		add_action( 'woocommerce_product_options_advanced', array( $this, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ) );

		// The post-type filter, not the screen one: BrikPanel's products list replays only this one.
		add_filter( 'manage_product_posts_columns', array( $this, 'column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'column_value' ), 20, 2 );
		add_action( 'quick_edit_custom_box', array( $this, 'quick_edit' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( $this, 'bulk_edit' ), 10, 2 );
		add_action( 'admin_footer-edit.php', array( $this, 'quick_edit_script' ) );
		add_action( 'woocommerce_product_quick_edit_save', array( $this, 'quick_edit_save' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'bulk_edit_save' ) );
	}

	/** Product data → Advanced. */
	public function product_field(): void {
		woocommerce_wp_text_input(
			array(
				'id'                => Product_Document::BOOST_META,
				'label'             => 'Nowera Search – boost',
				'desc_tip'          => true,
				'description'       => 'Ručné doladenie poradia vo vyhľadávaní: kladné číslo posúva produkt vyššie, záporné nižšie. 0 = bez zásahu.',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => -Product_Document::BOOST_LIMIT,
					'max'  => Product_Document::BOOST_LIMIT,
					'step' => '1',
				),
			)
		);
	}

	/**
	 * WooCommerce saves the object right after this hook, so no save() here.
	 *
	 * Alternative editors (BrikPanel) fire this hook for forms that never rendered
	 * the field, so a missing field means "not edited", never "set to 0".
	 */
	public function save_product( WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product nonce.
		if ( ! isset( $_POST[ Product_Document::BOOST_META ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->set( $product, wp_unslash( $_POST[ Product_Document::BOOST_META ] ) );
	}

	public function column( array $columns ): array {
		$boost    = array( 'nwr_boost' => 'Boost' );
		$position = array_search( 'date', array_keys( $columns ), true );
		return false === $position
			? $columns + $boost
			: array_merge( array_slice( $columns, 0, $position ), $boost, array_slice( $columns, $position ) );
	}

	public function column_value( string $column, int $post_id ): void {
		if ( 'nwr_boost' !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		$value   = $product ? (int) $product->get_meta( Product_Document::BOOST_META ) : 0;

		printf(
			'<span id="nwr-boost-%1$d">%2$s</span>',
			$post_id,
			0 === $value ? '<span aria-hidden="true">—</span><span class="screen-reader-text">0</span>' : esc_html( sprintf( '%+d', $value ) )
		);
	}

	public function quick_edit( string $column, string $post_type ): void {
		if ( 'nwr_boost' !== $column || 'product' !== $post_type ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="alignleft">
					<span class="title">Boost</span>
					<span class="input-text-wrap">
						<input type="number" name="<?php echo esc_attr( Product_Document::BOOST_META ); ?>" class="text"
							min="<?php echo esc_attr( (string) -Product_Document::BOOST_LIMIT ); ?>"
							max="<?php echo esc_attr( (string) Product_Document::BOOST_LIMIT ); ?>" step="1" value="">
					</span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	public function bulk_edit( string $column, string $post_type ): void {
		if ( 'nwr_boost' !== $column || 'product' !== $post_type ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="alignleft">
					<span class="title">Boost</span>
					<span class="input-text-wrap">
						<input type="number" name="<?php echo esc_attr( self::BULK_FIELD ); ?>" class="text"
							min="<?php echo esc_attr( (string) -Product_Document::BOOST_LIMIT ); ?>"
							max="<?php echo esc_attr( (string) Product_Document::BOOST_LIMIT ); ?>" step="1"
							value="" placeholder="bez zmeny">
					</span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/** Copies the row value into the quick edit form, which WordPress only does for core fields. */
	public function quick_edit_script(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		$script = 'jQuery(function($){$("#the-list").on("click",".editinline",function(){'
			. 'var id=$(this).closest("tr").attr("id").replace("post-","");'
			. 'var value=$.trim($("#nwr-boost-"+id).text());'
			. '$("#edit-"+id).find("input[name=\'%s\']").val(/^[+-]?\d+$/.test(value)?parseInt(value,10):0);'
			. '});});';

		wp_print_inline_script_tag( sprintf( $script, Product_Document::BOOST_META ), array( 'id' => 'nwr-search-quick-edit' ) );
	}

	/** WooCommerce saves the product before these hooks run, so changes are saved here. */
	public function quick_edit_save( WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress verified the inline-edit nonce.
		if ( isset( $_REQUEST[ Product_Document::BOOST_META ] ) && $this->set( $product, wp_unslash( $_REQUEST[ Product_Document::BOOST_META ] ) ) ) {
			$product->save();
		}
	}

	public function bulk_edit_save( WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress verified the bulk-edit nonce.
		$value = trim( (string) wp_unslash( $_REQUEST[ self::BULK_FIELD ] ?? '' ) );
		if ( '' !== $value && $this->set( $product, $value ) ) {
			$product->save();
		}
	}

	/** @return bool Whether the stored value changed. */
	private function set( WC_Product $product, $raw ): bool {
		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return false;
		}

		$limit = Product_Document::BOOST_LIMIT;
		$value = max( -$limit, min( $limit, (int) $raw ) );
		if ( $value === (int) $product->get_meta( Product_Document::BOOST_META ) ) {
			return false;
		}

		if ( 0 === $value ) {
			$product->delete_meta_data( Product_Document::BOOST_META );
		} else {
			$product->update_meta_data( Product_Document::BOOST_META, $value );
		}
		return true;
	}
}
