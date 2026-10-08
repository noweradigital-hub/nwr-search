<?php
namespace Nowera\Search\Frontend;

use Nowera\Search\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * [nwr_search]
 *
 * Attributes:
 *   layout      inline (default) | overlay | bar
 *               overlay — a search icon that opens a full-screen search (mobile headers)
 *               bar     — a search icon that drops a full-width search band from the top of the page
 *   label       text next to the icon (overlay/bar), empty by default
 *   icon_color  colour of the icon and its label (overlay/bar), e.g. #ffffff; overrides the admin setting
 *   fullwidth   yes (default) | no — bar only: "no" opens a box under the icon instead of a band across the page
 *   width       max width of the band content (bar), e.g. 1200px; with fullwidth="no" the box width (default 760px)
 *   top         distance of the band from the top of the viewport (bar), e.g. 80px under a sticky header
 *   size        default | compact           — compact is a lower field for headers
 *   align       left (default) | right | center — where the wide panel anchors to the field
 *   placeholder text in the field
 *   limit       number of products while typing (defaults to the admin setting)
 *   class       extra CSS class, also copied to the floating panel
 *
 * Works anywhere shortcodes render: classic content, Elementor Shortcode widget,
 * Bricks Shortcode element, Woodmart header Text/HTML element, Gutenberg Shortcode block.
 * Without JavaScript or without a search key the inline layout is a normal WooCommerce search form.
 */
final class Shortcode {

	const TAG = 'nwr_search';

	private static int $instances = 0;

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	public function render( $atts ): string {
		$products_only = Content_Types::products_only();
		$atts          = shortcode_atts(
			array(
				'layout'      => 'inline',
				'size'        => 'default',
				'align'       => 'left',
				'placeholder' => $products_only ? __( 'Hľadať produkty…', 'nwr-search' ) : __( 'Hľadať…', 'nwr-search' ),
				'limit'       => '',
				'class'       => '',
				'label'       => '',
				'width'       => '',
				'top'         => '',
				'fullwidth'   => 'yes',
				'icon_color'  => '',
			),
			$atts,
			self::TAG
		);

		$enhanced = Assets::enqueue();
		// Icon layouts need the script; without it the field stays a plain form.
		$layout   = in_array( $atts['layout'], array( 'overlay', 'bar' ), true ) && $enhanced ? $atts['layout'] : 'inline';
		$is_sheet = 'inline' !== $layout;
		$align    = in_array( $atts['align'], array( 'left', 'right', 'center' ), true ) ? $atts['align'] : 'left';
		$boxed    = 'bar' === $layout && in_array( strtolower( (string) $atts['fullwidth'] ), array( 'no', '0', 'false', 'off' ), true );
		$classes  = array_filter(
			array(
				'nwr-search',
				'nwr-search--' . $layout,
				$boxed ? 'nwr-search--boxed' : '',
				'compact' === $atts['size'] ? 'nwr-search--compact' : '',
				sanitize_html_class( $atts['class'] ),
			)
		);
		$data     = array(
			'data-layout' => $layout,
			'data-align'  => $align,
		);
		if ( '' !== (string) $atts['limit'] ) {
			$data['data-limit'] = (string) max( 1, min( 24, (int) $atts['limit'] ) );
		}

		$sheet_style = '';
		foreach ( array( 'width' => '--nwr-bar-width', 'top' => '--nwr-bar-top' ) as $att => $property ) {
			$length = self::css_length( (string) $atts[ $att ] );
			if ( 'bar' === $layout && '' !== $length ) {
				$sheet_style .= $property . ':' . $length . ';';
			}
		}

		$icon_color   = (string) sanitize_hex_color( '#' . ltrim( trim( (string) $atts['icon_color'] ), '#' ) );
		$toggle_style = '' !== $icon_color ? ' style="' . esc_attr( '--nwr-icon:' . $icon_color ) . '"' : '';

		$input_id = 'nwr-search-input-' . ( ++self::$instances );
		$label    = $products_only ? __( 'Hľadať produkty', 'nwr-search' ) : __( 'Hľadať na webe', 'nwr-search' );
		$search   = '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>';
		$cross    = '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"<?php echo $enhanced ? ' data-nwr-search' : ''; ?><?php foreach ( $data as $name => $value ) { printf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) ); } ?>>
			<?php if ( $is_sheet ) : ?>
				<button type="button" class="nwr-search__toggle<?php echo '' !== $atts['label'] ? ' nwr-search__toggle--label' : ''; ?>"<?php echo $toggle_style; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?> aria-label="<?php echo esc_attr( $label ); ?>" aria-haspopup="dialog" aria-expanded="false"><?php echo $search; // phpcs:ignore WordPress.Security.EscapeOutput ?><?php if ( '' !== $atts['label'] ) : ?><span class="nwr-search__toggle-text"><?php echo esc_html( $atts['label'] ); ?></span><?php endif; ?></button>
				<div class="nwr-search__sheet" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $label ); ?>"<?php echo '' !== $sheet_style ? ' style="' . esc_attr( $sheet_style ) . '"' : ''; ?> hidden>
					<div class="nwr-search__sheet-bar">
			<?php endif; ?>
			<form class="nwr-search__form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<label class="nwr-search__label" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
				<input id="<?php echo esc_attr( $input_id ); ?>" class="nwr-search__input" type="search" name="s"
					value="<?php echo esc_attr( get_search_query() ); ?>"
					placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
					autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search">
				<?php if ( $products_only ) : ?>
					<input type="hidden" name="post_type" value="product">
				<?php endif; ?>
				<button class="nwr-search__clear" type="button" aria-label="<?php esc_attr_e( 'Vymazať', 'nwr-search' ); ?>" hidden><?php echo $cross; // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
				<button class="nwr-search__submit" type="submit" aria-label="<?php esc_attr_e( 'Hľadať', 'nwr-search' ); ?>"><?php echo $search; // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
			</form>
			<?php if ( $is_sheet ) : ?>
						<button type="button" class="nwr-search__close" aria-label="<?php esc_attr_e( 'Zavrieť vyhľadávanie', 'nwr-search' ); ?>"><?php echo $cross; // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
					</div>
					<div class="nwr-search__panel" hidden></div>
				</div>
			<?php else : ?>
				<div class="nwr-search__panel" hidden></div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** "1200", "1200px", "80rem", "90%", "100vw" → a safe CSS length; anything else → ''. */
	private static function css_length( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
			return $value . 'px';
		}
		return preg_match( '/^\d+(\.\d+)?(px|rem|em|%|vw|vh|dvh)$/', $value ) ? $value : '';
	}
}
