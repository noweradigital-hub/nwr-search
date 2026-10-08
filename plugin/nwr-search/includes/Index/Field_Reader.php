<?php
namespace Nowera\Search\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Reads custom fields (ACPT, ACF) into searchable text and attached files,
 * without depending on either plugin's API.
 *
 * ACPT writes every field as `{box}_{field}` next to `{box}_{field}_type`
 * (field type) and `{box}_{field}_id` (UUID). Repeaters are serialized as
 * `[sub_field => [ ['type' => …, 'value' => …], … ]]`; File values are URLs
 * with `{field}_attachment_id` and `{field}_label` alongside.
 */
final class Field_Reader {

	/** Field types whose value is text a visitor reads. */
	const TEXT_TYPES = array( 'Text', 'Textarea', 'Editor', 'Html', 'Select', 'SelectMulti', 'Radio', 'Checkbox', 'Email', 'Phone', 'Number', 'List', 'Address', 'AddressMulti', 'Country', 'Currency', 'Length', 'Weight', 'Rating', 'Date', 'text', 'textarea', 'wysiwyg', 'select', 'radio', 'checkbox', 'email', 'number', 'button_group' );

	/** Names that usually hold a short summary suitable for the result list. */
	const EXCERPT_NAMES = '/(perex|excerpt|short|kratk|popis|description|intro|summary|subtitle|anotac)/i';

	/**
	 * @return array{texts:array<string,string>,excerpt:string,files:array<int,array{id:int,url:string,label:string}>}
	 */
	public function read( int $post_id ): array {
		$result = array(
			'texts'   => array(),
			'excerpt' => '',
			'files'   => array(),
		);

		$this->acpt( $post_id, $result );
		$this->acf( $post_id, $result );

		foreach ( $result['texts'] as $name => $text ) {
			if ( '' === $result['excerpt'] && preg_match( self::EXCERPT_NAMES, $name ) && mb_strlen( $text ) >= 20 ) {
				$result['excerpt'] = $text;
			}
		}

		return (array) apply_filters( 'nwr_search_post_fields', $result, $post_id );
	}

	private function acpt( int $post_id, array &$result ): void {
		$meta = get_post_meta( $post_id );
		if ( ! is_array( $meta ) ) {
			return;
		}

		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( str_starts_with( $key, '_' ) || ! str_ends_with( $key, '_type' ) ) {
				continue;
			}
			$field = substr( $key, 0, -5 );
			// The UUID sibling tells an ACPT field apart from any other "*_type" meta.
			if ( ! isset( $meta[ $field ], $meta[ $field . '_id' ] ) || ! preg_match( '/^[0-9a-f-]{36}$/i', (string) $meta[ $field . '_id' ][0] ) ) {
				continue;
			}

			$type  = (string) $values[0];
			$value = maybe_unserialize( $meta[ $field ][0] );
			$label = isset( $meta[ $field . '_label' ] ) ? (string) $meta[ $field . '_label' ][0] : '';

			switch ( $type ) {
				case 'Url':
					$this->add_text( $result, $field, $label );
					break;
				case 'File':
					$this->add_file( $result, (int) ( $meta[ $field . '_attachment_id' ][0] ?? 0 ), (string) $value, $label );
					break;
				case 'Repeater':
				case 'FlexibleContent':
					$this->acpt_rows( $result, $field, $value );
					break;
				default:
					if ( in_array( $type, self::TEXT_TYPES, true ) ) {
						$this->add_text( $result, $field, $value );
					}
			}
		}
	}

	/** Repeater storage: sub-field name => list of cells, each cell carrying its own type. */
	private function acpt_rows( array &$result, string $field, $value, int $depth = 0 ): void {
		if ( ! is_array( $value ) || $depth > 3 ) {
			return;
		}
		$parts = array();
		foreach ( $value as $cells ) {
			foreach ( is_array( $cells ) ? $cells : array() as $cell ) {
				if ( ! is_array( $cell ) || ! isset( $cell['type'] ) ) {
					continue;
				}
				$type = (string) $cell['type'];
				if ( 'File' === $type ) {
					$this->add_file( $result, (int) ( $cell['attachment_id'] ?? 0 ), (string) ( $cell['value'] ?? '' ), (string) ( $cell['label'] ?? '' ) );
				} elseif ( 'Url' === $type ) {
					$parts[] = (string) ( $cell['label'] ?? '' );
				} elseif ( in_array( $type, array( 'Repeater', 'FlexibleContent' ), true ) ) {
					$this->acpt_rows( $result, $field, $cell['value'] ?? null, $depth + 1 );
				} elseif ( in_array( $type, self::TEXT_TYPES, true ) ) {
					$parts[] = is_array( $cell['value'] ?? null ) ? implode( ' ', array_filter( $cell['value'], 'is_scalar' ) ) : (string) ( $cell['value'] ?? '' );
				}
			}
		}
		$this->add_text( $result, $field, implode( ' ', $parts ) );
	}

	private function acf( int $post_id, array &$result ): void {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return;
		}
		$objects = get_field_objects( $post_id, false );
		foreach ( is_array( $objects ) ? $objects : array() as $name => $object ) {
			$this->acf_value( $result, (string) $name, (array) $object, $object['value'] ?? null, 0 );
		}
	}

	private function acf_value( array &$result, string $name, array $object, $value, int $depth ): void {
		if ( $depth > 3 || null === $value || '' === $value ) {
			return;
		}
		$type = (string) ( $object['type'] ?? '' );

		if ( 'file' === $type ) {
			$id = is_array( $value ) ? (int) ( $value['ID'] ?? $value['id'] ?? 0 ) : ( is_numeric( $value ) ? (int) $value : 0 );
			$this->add_file( $result, $id, is_string( $value ) && ! is_numeric( $value ) ? $value : '', '' );
		} elseif ( 'link' === $type ) {
			$this->add_text( $result, $name, is_array( $value ) ? (string) ( $value['title'] ?? '' ) : '' );
		} elseif ( in_array( $type, array( 'repeater', 'group', 'flexible_content' ), true ) && is_array( $value ) ) {
			$subs = array();
			foreach ( (array) ( $object['sub_fields'] ?? array() ) as $sub ) {
				$subs[ $sub['name'] ?? '' ] = $sub;
				$subs[ $sub['key'] ?? '' ]  = $sub;
			}
			foreach ( ( 'group' === $type ? array( $value ) : $value ) as $row ) {
				foreach ( is_array( $row ) ? $row : array() as $sub_name => $sub_value ) {
					if ( isset( $subs[ $sub_name ] ) ) {
						$this->acf_value( $result, $name, $subs[ $sub_name ], $sub_value, $depth + 1 );
					}
				}
			}
		} elseif ( in_array( $type, self::TEXT_TYPES, true ) ) {
			$this->add_text( $result, $name, is_array( $value ) ? implode( ' ', array_filter( $value, 'is_scalar' ) ) : $value );
		}
	}

	private function add_text( array &$result, string $name, $value ): void {
		$text = Text::plain( is_scalar( $value ) ? (string) $value : '' );
		if ( '' === $text || ! preg_match( '/\p{L}|\d/u', $text ) ) {
			return;
		}
		$result['texts'][ $name ] = isset( $result['texts'][ $name ] ) ? $result['texts'][ $name ] . ' ' . $text : $text;
	}

	private function add_file( array &$result, int $attachment_id, string $url, string $label ): void {
		if ( $attachment_id <= 0 && '' === $url ) {
			return;
		}
		if ( $attachment_id <= 0 ) {
			$attachment_id = (int) attachment_url_to_postid( $url );
		}
		$result['files'][] = array(
			'id'    => $attachment_id,
			'url'   => $url,
			'label' => $label,
		);
	}
}
