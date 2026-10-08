<?php
namespace Nowera\Search\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Visible text of pages built with a page builder, whose post_content is
 * usually empty: Bricks keeps its element tree in `_bricks_page_content_2`,
 * Elementor in `_elementor_data`.
 */
final class Builder_Content {

	/** Setting keys that hold visitor-facing text in core and common add-on elements. */
	const TEXT_KEYS = array( 'text', 'title', 'subtitle', 'heading', 'subheading', 'subHeading', 'sub_heading', 'content', 'description', 'label', 'caption', 'question', 'answer', 'excerpt', 'prefix', 'suffix', 'quote', 'meta', 'message', 'tagline', 'editor', 'title_text', 'description_text', 'tab_title', 'tab_content', 'item_description', 'testimonial_content', 'testimonial_name', 'name' );

	/** Elements whose settings are code, media or forms rather than page text. */
	const SKIP_ELEMENTS = array( 'code', 'svg', 'video', 'audio', 'map', 'map-leaflet', 'form', 'nav-menu', 'nav-nested', 'offcanvas', 'template', 'shortcode', 'wordpress', 'search', 'posts', 'pagination', 'post-content', 'post-comments', 'facebook-page', 'instagram-feed', 'html', 'google_maps', 'icon', 'image', 'divider', 'spacer' );

	const MAX_DEPTH = 6;

	public static function text( int $post_id ): string {
		$fragments = array();

		$bricks = get_post_meta( $post_id, '_bricks_page_content_2', true );
		if ( is_array( $bricks ) && $bricks ) {
			$fragments = array_merge( $fragments, self::bricks( $bricks ) );
		}

		$elementor = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $elementor ) && '' !== $elementor && 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			$decoded = json_decode( $elementor, true );
			if ( is_array( $decoded ) ) {
				$fragments = array_merge( $fragments, self::elementor( $decoded ) );
			}
		}

		return Text::join( (array) apply_filters( 'nwr_search_builder_text', $fragments, $post_id ) );
	}

	/** Bricks stores a flat list of elements; order in the list is the order on the page. */
	public static function bricks( array $elements ): array {
		$fragments = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || in_array( $element['name'] ?? '', self::SKIP_ELEMENTS, true ) ) {
				continue;
			}
			self::collect( $element['settings'] ?? array(), $fragments, 0 );
		}
		return $fragments;
	}

	/** Elementor nests elements; widget text sits in `settings`. */
	public static function elementor( array $elements ): array {
		$fragments = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( ! in_array( $element['widgetType'] ?? '', self::SKIP_ELEMENTS, true ) ) {
				self::collect( $element['settings'] ?? array(), $fragments, 0 );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$fragments = array_merge( $fragments, self::elementor( $element['elements'] ) );
			}
		}
		return $fragments;
	}

	/** Walks one element's settings; repeaters (accordion items, tabs, list rows) are nested arrays. */
	private static function collect( $settings, array &$fragments, int $depth ): void {
		if ( ! is_array( $settings ) || $depth > self::MAX_DEPTH ) {
			return;
		}
		foreach ( $settings as $key => $value ) {
			// "_cssCustom", "_typography" … are styling; "link", "icon", "image" are never text.
			if ( is_string( $key ) && ( str_starts_with( $key, '_' ) || in_array( $key, array( 'link', 'icon', 'image', 'file', 'query', 'css', 'svg', 'code', 'url' ), true ) ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				self::collect( $value, $fragments, $depth + 1 );
			} elseif ( is_string( $value ) && is_string( $key ) && in_array( $key, self::TEXT_KEYS, true ) && self::is_text( $value ) ) {
				$fragments[] = $value;
			}
		}
	}

	/** Real words, not a CSS value, colour, URL or a bare dynamic tag. */
	private static function is_text( string $value ): bool {
		$plain = Text::plain( $value );
		return '' !== $plain
			&& (bool) preg_match( '/\p{L}/u', $plain )
			&& ! preg_match( '#^(https?:)?//#i', $plain )
			&& ! preg_match( '/^(var\(|#[0-9a-f]{3,8}$|rgba?\()/i', $plain );
	}
}
