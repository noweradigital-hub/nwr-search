<?php
namespace Nowera\Search\Index;

use Nowera\Search\Content_Types;

defined( 'ABSPATH' ) || exit;

/** Picks the right document builder for a post ID; null means "not in the index". */
final class Builder {

	public function __construct(
		private Product_Document $products = new Product_Document(),
		private Post_Document $posts = new Post_Document()
	) {}

	public function build( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || ! Content_Types::is_enabled( $post->post_type ) ) {
			return null;
		}

		if ( 'product' === $post->post_type && Content_Types::woocommerce() ) {
			$product = wc_get_product( $post );
			return $product ? $this->products->build( $product ) : null;
		}

		return $this->posts->build( $post );
	}
}
