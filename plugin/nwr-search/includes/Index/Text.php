<?php
namespace Nowera\Search\Index;

defined( 'ABSPATH' ) || exit;

/** Turns stored markup into the plain text Typesense should see. */
final class Text {

	/** No tags, no block comments, no builder shortcodes or dynamic tags, decoded entities, collapsed whitespace. */
	public static function plain( $html, int $limit = 0 ): string {
		if ( ! is_scalar( $html ) ) {
			return '';
		}
		$text = (string) $html;
		// Block-level tags become spaces so "</p><p>" never glues two words together.
		$text = (string) preg_replace( '#<(?:/p|br|/li|/h[1-6]|/div|/td|/th|hr)\b[^>]*>#i', ' ', $text );
		$text = strip_shortcodes( $text );
		$text = (string) preg_replace( '/\[\/?[a-z][a-z0-9_\-]*[^\]]*\]/i', ' ', $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Unrendered dynamic data: Bricks {post_title}, {acpt_…}, Elementor-style [[…]].
		$text = (string) preg_replace( '/\{[a-z_][^{}\s]*\}/i', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		if ( $limit > 0 && mb_strlen( $text ) > $limit ) {
			$cut  = mb_substr( $text, 0, $limit );
			$last = mb_strrpos( $cut, ' ' );
			$cut  = false !== $last && $last > $limit * 0.7 ? mb_substr( $cut, 0, $last ) : $cut;
			$text = (string) preg_replace( '/[\s,.;:\-–]+$/u', '', $cut );
		}
		return $text;
	}

	/** Joins text fragments once each, skipping empties and repeats (headings reused as buttons etc.). */
	public static function join( array $fragments, int $limit = 0 ): string {
		$seen = array();
		foreach ( $fragments as $fragment ) {
			$fragment = self::plain( $fragment );
			$key      = mb_strtolower( $fragment );
			if ( '' !== $fragment && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = $fragment;
			}
		}
		return self::plain( implode( ' ', $seen ), $limit );
	}

	/** A readable one-liner for the result list. */
	public static function snippet( string $text, int $limit = 180 ): string {
		return self::plain( $text, $limit );
	}
}
