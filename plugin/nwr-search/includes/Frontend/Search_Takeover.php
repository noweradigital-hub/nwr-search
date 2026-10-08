<?php
namespace Nowera\Search\Frontend;

use Nowera\Search\Content_Types;
use Nowera\Search\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Optional: sends the site's own product searches (?s=…) to the page holding
 * [nwr_search_results], so every theme search field ends up in Nowera Search
 * without touching the header markup.
 */
final class Search_Takeover {

	/** The results page reads its query from ?q=, because ?s= on a page is a search inside that page. */
	const QUERY_VAR = 'q';

	public function register(): void {
		add_action( 'parse_query', array( $this, 'release_page_query' ) );
		add_action( 'template_redirect', array( $this, 'redirect' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
	}

	/**
	 * A shared ?s= link must still open the results page. WordPress would turn it
	 * into "search within this page" and answer 404, so the term is dropped from
	 * the main query — the app picks it up from the URL either way.
	 */
	public function release_page_query( $query ): void {
		if ( is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() || '' === (string) $query->get( 's' ) ) {
			return;
		}

		$page = (int) Settings::get( 'results_page' );
		if ( $page <= 0 ) {
			return;
		}

		$target = (int) $query->get( 'page_id' );
		if ( ! $target && '' !== (string) $query->get( 'pagename' ) ) {
			$found  = get_page_by_path( (string) $query->get( 'pagename' ) );
			$target = $found ? (int) $found->ID : 0;
		}
		if ( $target !== $page ) {
			return;
		}

		$query->set( 's', '' );
		unset( $query->query_vars['s'] );
		$query->is_search = false;
	}

	/** Result listings are not content search engines should index. */
	public function robots( array $robots ): array {
		$page = (int) Settings::get( 'results_page' );
		if ( $page > 0 && is_page( $page ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
		return $robots;
	}

	public function redirect(): void {
		if ( ! is_search() || is_singular() || is_admin() || is_feed() || is_robots() || wp_doing_ajax() ) {
			return;
		}
		if ( empty( Settings::get( 'takeover' ) ) || ! Settings::frontend_ready() ) {
			return;
		}

		$url = Settings::results_url();
		if ( '' === $url || get_queried_object_id() === (int) Settings::get( 'results_page' ) ) {
			return;
		}

		// Searches explicitly scoped to content that is not in the index are left alone.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $post_type && ! Content_Types::is_enabled( $post_type ) ) {
			return;
		}

		wp_safe_redirect( add_query_arg( self::QUERY_VAR, get_search_query( false ), $url ), 302 );
		exit;
	}
}
