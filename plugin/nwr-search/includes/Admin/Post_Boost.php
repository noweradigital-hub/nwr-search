<?php
namespace Nowera\Search\Admin;

use Nowera\Search\Content_Types;
use Nowera\Search\Index\Product_Document;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Boost for pages, posts and custom post types: a side meta box, a list
 * column and quick / bulk edit. WooCommerce products use Product_Boost.
 */
final class Post_Boost {

	const NONCE      = 'nwr_search_boost_nonce';
	const BULK_FIELD = 'nwr_search_boost_bulk';

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'current_screen', array( $this, 'list_screen' ) );
		add_action( 'quick_edit_custom_box', array( $this, 'quick_edit' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( $this, 'bulk_edit' ), 10, 2 );
		add_action( 'admin_footer-edit.php', array( $this, 'quick_edit_script' ) );
	}

	/** Post types this class serves: enabled ones, minus WooCommerce products. */
	private function types(): array {
		$types = array_keys( Content_Types::enabled() );
		if ( Content_Types::woocommerce() ) {
			$types = array_diff( $types, array( 'product' ) );
		}
		return array_values( array_diff( $types, array( Content_Types::ATTACHMENTS ) ) );
	}

	public function meta_box(): void {
		foreach ( $this->types() as $type ) {
			add_meta_box( 'nwr-search-boost', 'Nowera Search', array( $this, 'render_box' ), $type, 'side', 'low' );
		}
	}

	public function render_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		$value = (int) get_post_meta( $post->ID, Product_Document::BOOST_META, true );
		?>
		<p>
			<label for="nwr-search-boost-input"><strong>Boost vo vyhľadávaní</strong></label>
			<input id="nwr-search-boost-input" type="number" class="widefat" name="<?php echo esc_attr( Product_Document::BOOST_META ); ?>"
				min="<?php echo esc_attr( (string) -Product_Document::BOOST_LIMIT ); ?>" max="<?php echo esc_attr( (string) Product_Document::BOOST_LIMIT ); ?>" step="1"
				value="<?php echo esc_attr( (string) $value ); ?>">
		</p>
		<p class="description">Kladné číslo posúva položku vo výsledkoch vyššie, záporné nižšie. 0 = bez zásahu.</p>
		<?php
	}

	/** Meta box (own nonce), quick edit (WordPress checked its nonce) and bulk edit. */
	public function save( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! in_array( $post->post_type, $this->types(), true ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$from_box   = isset( $_POST[ self::NONCE ] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE );
		$from_quick = wp_doing_ajax() && 'inline-save' === ( $_POST['action'] ?? '' );
		if ( ( $from_box || $from_quick ) && isset( $_POST[ Product_Document::BOOST_META ] ) ) {
			$this->set( $post_id, wp_unslash( $_POST[ Product_Document::BOOST_META ] ) );
			return;
		}

		$bulk = isset( $_REQUEST['bulk_edit'] ) ? trim( (string) wp_unslash( $_REQUEST[ self::BULK_FIELD ] ?? '' ) ) : '';
		// phpcs:enable
		if ( '' !== $bulk ) {
			$this->set( $post_id, $bulk );
		}
	}

	public function list_screen( $screen ): void {
		if ( ! $screen || 'edit' !== $screen->base || ! in_array( $screen->post_type, $this->types(), true ) ) {
			return;
		}
		add_filter( "manage_{$screen->post_type}_posts_columns", array( $this, 'column' ), 20 );
		$hook = is_post_type_hierarchical( $screen->post_type ) ? 'manage_pages_custom_column' : 'manage_posts_custom_column';
		add_action( $hook, array( $this, 'column_value' ), 20, 2 );
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
		$value = (int) get_post_meta( $post_id, Product_Document::BOOST_META, true );
		printf(
			'<span id="nwr-boost-%1$d">%2$s</span>',
			$post_id,
			0 === $value ? '<span aria-hidden="true">—</span><span class="screen-reader-text">0</span>' : esc_html( sprintf( '%+d', $value ) )
		);
	}

	public function quick_edit( string $column, string $post_type ): void {
		if ( 'nwr_boost' === $column && in_array( $post_type, $this->types(), true ) ) {
			$this->inline_field( Product_Document::BOOST_META, '' );
		}
	}

	public function bulk_edit( string $column, string $post_type ): void {
		if ( 'nwr_boost' === $column && in_array( $post_type, $this->types(), true ) ) {
			$this->inline_field( self::BULK_FIELD, 'bez zmeny' );
		}
	}

	private function inline_field( string $name, string $placeholder ): void {
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="alignleft">
					<span class="title">Boost</span>
					<span class="input-text-wrap">
						<input type="number" name="<?php echo esc_attr( $name ); ?>" class="text" step="1" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>"
							min="<?php echo esc_attr( (string) -Product_Document::BOOST_LIMIT ); ?>" max="<?php echo esc_attr( (string) Product_Document::BOOST_LIMIT ); ?>">
					</span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/** WordPress copies only core fields into quick edit; this fills the boost from the row. */
	public function quick_edit_script(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->types(), true ) ) {
			return;
		}
		$script = 'jQuery(function($){$("#the-list").on("click",".editinline",function(){'
			. 'var id=$(this).closest("tr").attr("id").replace("post-","");'
			. 'var value=$.trim($("#nwr-boost-"+id).text());'
			. '$("#edit-"+id).find("input[name=\'%s\']").val(/^[+-]?\d+$/.test(value)?parseInt(value,10):0);'
			. '});});';
		wp_print_inline_script_tag( sprintf( $script, Product_Document::BOOST_META ), array( 'id' => 'nwr-search-post-quick-edit' ) );
	}

	private function set( int $post_id, $raw ): void {
		$value = max( -Product_Document::BOOST_LIMIT, min( Product_Document::BOOST_LIMIT, (int) $raw ) );
		if ( 0 === $value ) {
			delete_post_meta( $post_id, Product_Document::BOOST_META );
		} else {
			update_post_meta( $post_id, Product_Document::BOOST_META, $value );
		}
	}
}
