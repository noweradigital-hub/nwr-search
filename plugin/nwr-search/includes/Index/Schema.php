<?php
namespace Nowera\Search\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Typesense collection schema for products, pages, posts, custom post types and files.
 * url, image, related_ids and file_url/file_size/file_name are not declared:
 * Typesense stores undeclared fields without indexing them.
 */
final class Schema {

	public static function fields(): array {
		$fields = array(
			// int64: some stores (e.g. WooMultistore networks) use ids close to the int32 limit.
			// The WordPress post ID of any kind of content (the name dates from the product-only era).
			array( 'name' => 'product_id', 'type' => 'int64' ),
			array( 'name' => 'type', 'type' => 'string', 'facet' => true ),
			// page, post, product, a custom post type, or attachment. Groups results in the panel.
			array( 'name' => 'post_type', 'type' => 'string', 'facet' => true, 'optional' => true ),
			array( 'name' => 'title', 'type' => 'string' ),
			array( 'name' => 'excerpt', 'type' => 'string', 'optional' => true ),
			// Page text, including page builder content and custom fields.
			array( 'name' => 'content', 'type' => 'string', 'optional' => true ),
			array( 'name' => 'file_type', 'type' => 'string', 'facet' => true, 'optional' => true ),
			// One facet per taxonomy of non-product content: tax_typ_dokumentu, tax_category, …
			array( 'name' => 'tax_.*', 'type' => 'string[]', 'facet' => true, 'optional' => true ),
			array( 'name' => 'sku', 'type' => 'string', 'optional' => true ),
			array( 'name' => 'skus', 'type' => 'string[]', 'optional' => true ),
			array( 'name' => 'gtins', 'type' => 'string[]', 'optional' => true ),
			array( 'name' => 'short_description', 'type' => 'string', 'optional' => true ),
			array( 'name' => 'description', 'type' => 'string', 'optional' => true ),
			array( 'name' => 'brands', 'type' => 'string[]', 'facet' => true, 'optional' => true ),
			array( 'name' => 'tags', 'type' => 'string[]', 'facet' => true, 'optional' => true ),
			array( 'name' => 'categories', 'type' => 'string[]', 'facet' => true, 'optional' => true ),
			array( 'name' => 'category_ids', 'type' => 'int64[]', 'facet' => true, 'optional' => true ),
			array( 'name' => 'category_lvl0', 'type' => 'string[]', 'facet' => true, 'optional' => true ),
			array( 'name' => 'category_lvl1', 'type' => 'string[]', 'facet' => true, 'optional' => true ),
			array( 'name' => 'category_lvl2', 'type' => 'string[]', 'facet' => true, 'optional' => true ),
			// One facet per product attribute: attr_farba, attr_velkost, …
			array( 'name' => 'attr_.*', 'type' => 'string[]', 'facet' => true, 'optional' => true ),
			// All attribute values in one searchable list (shade names, sizes …).
			array( 'name' => 'attributes', 'type' => 'string[]', 'optional' => true ),
			array( 'name' => 'price', 'type' => 'float', 'facet' => true, 'optional' => true ),
			array( 'name' => 'price_max', 'type' => 'float', 'optional' => true ),
			array( 'name' => 'regular_price', 'type' => 'float', 'optional' => true ),
			array( 'name' => 'discount_percent', 'type' => 'int32', 'optional' => true ),
			array( 'name' => 'on_sale', 'type' => 'bool', 'facet' => true ),
			array( 'name' => 'in_stock', 'type' => 'bool', 'facet' => true ),
			array( 'name' => 'stock_status', 'type' => 'string', 'facet' => true ),
			array( 'name' => 'featured', 'type' => 'bool', 'facet' => true ),
			// visible | catalog | search — the frontend filters by context (search vs. listing).
			array( 'name' => 'visibility', 'type' => 'string', 'facet' => true ),
			array( 'name' => 'rating', 'type' => 'float', 'optional' => true ),
			array( 'name' => 'review_count', 'type' => 'int32', 'optional' => true ),
			// Log-scaled sales, so the public search key never exposes raw sales numbers.
			array( 'name' => 'popularity', 'type' => 'int32' ),
			array( 'name' => 'menu_order', 'type' => 'int32' ),
			// Manual ranking nudge set per product in the admin. Optional, so it can be
			// added to a collection that already holds documents (PATCH /collections).
			array( 'name' => 'boost', 'type' => 'int32', 'optional' => true ),
			array( 'name' => 'created_at', 'type' => 'int64' ),
			array( 'name' => 'modified_at', 'type' => 'int64', 'optional' => true ),
			// Set on every upsert; a full reindex deletes documents older than its start.
			array( 'name' => 'indexed_at', 'type' => 'int64' ),
		);

		return (array) apply_filters( 'nwr_search_schema_fields', $fields );
	}

	public static function collection( string $name ): array {
		return array(
			'name'                  => $name,
			'fields'                => self::fields(),
			'default_sorting_field' => 'popularity',
			'token_separators'      => array( '-', '/' ),
		);
	}

	/** Fields the plugin expects that an existing collection does not have yet. */
	public static function missing_fields( array $existing_fields ): array {
		$existing = array_column( $existing_fields, 'name' );
		return array_values(
			array_filter(
				self::fields(),
				static fn( array $field ): bool => ! in_array( $field['name'], $existing, true )
			)
		);
	}
}
