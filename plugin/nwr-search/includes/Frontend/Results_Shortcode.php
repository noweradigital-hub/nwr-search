<?php
namespace Nowera\Search\Frontend;

use Nowera\Search\Content_Types;
use Nowera\Search\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * [nwr_search_results] — full search results page with content-type tabs,
 * facet filters, sorting and endless scrolling. Reads ?q= (or ?s=) from the
 * URL and keeps its own state there, so results stay linkable.
 *
 * Attributes:
 *   field    yes (default) | no   — render the search field above the results
 *   per_page results per batch (default 24)
 *   class    extra CSS class
 */
final class Results_Shortcode {

	const TAG = 'nwr_search_results';

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'field'    => 'yes',
				'per_page' => (string) Settings::RESULTS_PER_PAGE,
				'class'    => '',
			),
			$atts,
			self::TAG
		);

		$products_only = Content_Types::products_only();

		if ( ! Assets::enqueue_results() ) {
			// No search key yet: keep the page usable with the site's own search form.
			return $products_only && function_exists( 'get_product_search_form' ) ? (string) get_product_search_form( false ) : (string) get_search_form( array( 'echo' => false ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only search term.
		$query    = isset( $_GET[ Search_Takeover::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ Search_Takeover::QUERY_VAR ] ) ) : (string) get_search_query();
		$per_page = max( 6, min( 48, (int) $atts['per_page'] ) );
		// nwr-search carries the --nwr-* tokens and the shared price styles.
		$classes = array_filter( array( 'nwr-search', 'nwr-results', sanitize_html_class( $atts['class'] ) ) );

		$sorts = array( 'relevance' => __( 'Najlepšia zhoda', 'nwr-search' ) );
		if ( Content_Types::shop_mode() ) {
			$sorts += array(
				'popular'    => __( 'Najobľúbenejšie', 'nwr-search' ),
				'price_asc'  => __( 'Najlacnejšie', 'nwr-search' ),
				'price_desc' => __( 'Najdrahšie', 'nwr-search' ),
			);
		}
		$sorts['newest'] = __( 'Najnovšie', 'nwr-search' );

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-nwr-results data-per-page="<?php echo esc_attr( (string) $per_page ); ?>">
			<?php if ( 'no' !== $atts['field'] ) : ?>
				<form class="nwr-results__search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
					<label class="screen-reader-text" for="nwr-results-input"><?php esc_html_e( 'Hľadať', 'nwr-search' ); ?></label>
					<?php if ( $products_only ) : ?>
						<input type="hidden" name="post_type" value="product">
					<?php endif; ?>
					<input id="nwr-results-input" class="nwr-results__input" type="search" name="s" value="<?php echo esc_attr( $query ); ?>"
						placeholder="<?php echo esc_attr( $products_only ? __( 'Hľadať produkty…', 'nwr-search' ) : __( 'Hľadať…', 'nwr-search' ) ); ?>"
						autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search">
					<button class="nwr-results__submit" type="submit"><?php esc_html_e( 'Hľadať', 'nwr-search' ); ?></button>
				</form>
			<?php endif; ?>

			<div class="nwr-results__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Typ obsahu', 'nwr-search' ); ?>" hidden></div>

			<div class="nwr-results__bar">
				<p class="nwr-results__count" role="status" aria-live="polite"></p>
				<div class="nwr-results__tools">
					<button type="button" class="nwr-results__toggle" aria-expanded="false"><?php esc_html_e( 'Filtre', 'nwr-search' ); ?></button>
					<label class="nwr-results__sort">
						<span class="screen-reader-text"><?php esc_html_e( 'Zoradiť', 'nwr-search' ); ?></span>
						<select class="nwr-results__sort-select">
							<?php foreach ( $sorts as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
			</div>

			<div class="nwr-results__layout">
				<aside class="nwr-results__sidebar" aria-label="<?php esc_attr_e( 'Filtre', 'nwr-search' ); ?>">
					<div class="nwr-results__sidebar-head">
						<strong><?php esc_html_e( 'Filtre', 'nwr-search' ); ?></strong>
						<button type="button" class="nwr-results__sidebar-close" aria-label="<?php esc_attr_e( 'Zavrieť filtre', 'nwr-search' ); ?>">&times;</button>
					</div>
					<div class="nwr-results__facets"></div>
					<button type="button" class="nwr-results__apply"><?php esc_html_e( 'Zobraziť výsledky', 'nwr-search' ); ?></button>
				</aside>
				<div class="nwr-results__main">
					<div class="nwr-results__chips"></div>
					<div class="nwr-results__grid"></div>
					<div class="nwr-results__status"></div>
					<div class="nwr-results__sentinel" aria-hidden="true"></div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
