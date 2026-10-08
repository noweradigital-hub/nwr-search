<?php
// Universal-mode fixture modelled on a building-materials site (Bricks + ACPT, no WooCommerce):
// CPTs with ACPT-shaped meta, a Bricks page, a PDF document, a noindex page, an excluded page.
require __DIR__ . '/_bootstrap.php';

// 1. Custom post types and taxonomies, persisted as an mu-plugin for the next requests.
$mu = WP_CONTENT_DIR . '/mu-plugins/nwr-test-types.php';
wp_mkdir_p( dirname( $mu ) );
file_put_contents(
	$mu,
	<<<'PHP'
<?php
function nwr_test_register_types() {
	if ( post_type_exists( 'dokumenty' ) ) {
		return;
	}
	$cpt = static function ( string $name, string $label, string $slug, array $supports ) {
		register_post_type( $name, array( 'label' => $label, 'labels' => array( 'name' => $label, 'singular_name' => $label ), 'public' => true, 'has_archive' => true, 'rewrite' => array( 'slug' => $slug ), 'supports' => $supports, 'show_in_rest' => true ) );
	};
	$cpt( 'product', 'Produkty', 'katalog', array( 'title', 'thumbnail', 'revisions' ) );
	$cpt( 'dokumenty', 'Centrum dokumentov', 'dokumenty', array( 'title', 'thumbnail' ) );
	$cpt( 'poradna', 'Odborná poradňa', 'odborna-poradna', array( 'title', 'editor', 'thumbnail', 'excerpt' ) );
	$cpt( 'montazne-firmy', 'Montážne firmy', 'montazne-firmy', array( 'title', 'thumbnail' ) );
	register_taxonomy( 'product_category', 'product', array( 'label' => 'Kategórie produktov', 'labels' => array( 'name' => 'Kategórie produktov', 'singular_name' => 'Kategória produktu' ), 'hierarchical' => true, 'public' => true ) );
	register_taxonomy( 'typ_dokumentu', 'dokumenty', array( 'label' => 'Typy dokumentov', 'labels' => array( 'name' => 'Typy dokumentov', 'singular_name' => 'Typ dokumentu' ), 'hierarchical' => true, 'public' => true ) );
	register_taxonomy( 'kategoria_poradne', 'poradna', array( 'label' => 'Kategórie poradne', 'labels' => array( 'name' => 'Kategórie poradne', 'singular_name' => 'Kategória poradne' ), 'hierarchical' => true, 'public' => true ) );
	register_taxonomy( 'firmy', 'montazne-firmy', array( 'label' => 'Kraje', 'labels' => array( 'name' => 'Kraje', 'singular_name' => 'Kraj' ), 'hierarchical' => true, 'public' => true ) );
}
add_action( 'init', 'nwr_test_register_types', 0 );
PHP
);
require_once $mu;
nwr_test_register_types(); // init has already run in this request.
\Nowera\Search\Content_Types::flush();

// 2. Settings: the nwr_uni test collection, documents link to their PDF.
$env = nwr_test_env_for( 'nwr_uni' );
update_option(
	'nwr_search_settings',
	array(
		'host'       => $env['NWR_SEARCH_HOST'],
		'collection' => $env['NWR_SEARCH_COLLECTION'],
		'site_key'   => $env['NWR_SEARCH_SITE_KEY'],
		'search_key' => $env['NWR_SEARCH_SEARCH_KEY'],
		'auto_sync'  => 1,
		'types'      => array(
			'product'        => array( 'enabled' => 1, 'label' => 'Produkty', 'order' => 1, 'limit' => 4, 'link' => 'detail', 'taxonomy' => 'product_category' ),
			'dokumenty'      => array( 'enabled' => 1, 'label' => 'Dokumenty', 'order' => 2, 'limit' => 4, 'link' => 'file', 'taxonomy' => 'typ_dokumentu' ),
			'poradna'        => array( 'enabled' => 1, 'label' => 'Odborná poradňa', 'order' => 3, 'limit' => 3, 'link' => 'detail', 'taxonomy' => 'kategoria_poradne' ),
			'page'           => array( 'enabled' => 1, 'label' => 'Stránky', 'order' => 4, 'limit' => 3, 'link' => 'detail', 'taxonomy' => '' ),
			'post'           => array( 'enabled' => 1, 'label' => 'Články', 'order' => 5, 'limit' => 3, 'link' => 'detail', 'taxonomy' => 'category' ),
			'montazne-firmy' => array( 'enabled' => 1, 'label' => 'Montážne firmy', 'order' => 6, 'limit' => 3, 'link' => 'detail', 'taxonomy' => 'firmy' ),
		),
	)
);
\Nowera\Search\Content_Types::flush();

$acpt = static function ( int $post_id, string $key, string $type, $value, array $extra = array() ): void {
	update_post_meta( $post_id, $key, $value );
	update_post_meta( $post_id, $key . '_type', $type );
	update_post_meta( $post_id, $key . '_id', wp_generate_uuid4() );
	foreach ( $extra as $suffix => $extra_value ) {
		update_post_meta( $post_id, $key . '_' . $suffix, $extra_value );
	}
};
$cell = static fn( string $name, string $type, $value, array $extra = array() ): array => $extra + array( 'original_name' => $name, 'type' => $type, 'value' => $value );
$new_post = static fn( array $args ): int => (int) wp_insert_post( $args + array( 'post_status' => 'publish' ), true );
$term = static function ( string $name, string $taxonomy, int $parent = 0 ): int {
	$found = term_exists( $name, $taxonomy, $parent );
	$found = $found ?: wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );
	return (int) $found['term_id'];
};

// 3. A PDF in the media library.
$upload = wp_upload_dir();
$file   = trailingslashit( $upload['path'] ) . 'DOP-DEMOFOL-100.pdf';
file_put_contents( $file, "%PDF-1.4\n" . str_repeat( "Demofol test document\n", 4600 ) . "%%EOF\n" );
$pdf = (int) wp_insert_attachment( array( 'post_title' => 'DOP-DEMOFOL-100', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $file );
update_post_meta( $pdf, '_wp_attachment_metadata', array( 'filesize' => filesize( $file ) ) );
$pdf_url = wp_get_attachment_url( $pdf );

// 4. Product (ACPT fields, including repeaters and a File field).
$roofs   = $term( 'Izolácie striech', 'product_category' );
$ponds   = $term( 'Izolácie vodných plôch a jazierok', 'product_category' );
$product = $new_post( array( 'post_type' => 'product', 'post_title' => 'DEMOPOND 200' ) );
wp_set_object_terms( $product, array( $ponds ), 'product_category' );
$acpt( $product, 'zakladne_short_description', 'Text', 'Hydroizolačná fólia na báze mäkčeného PVC vhodná na izoláciu záhradných jazierok, rybníkov a väčších vodných plôch.' );
$acpt( $product, 'zakladne_description', 'Editor', '<p>DEMOPOND 200 je homogénna hydroizolačná fólia z mäkčeného PVC určená na izoláciu záhradných jazierok a rybníkov.</p>' );
$acpt(
	$product,
	'vlastnosti_key_features',
	'Repeater',
	array( 'feature' => array( $cell( 'feature', 'Text', 'Odolnosť voči UV žiareniu' ), $cell( 'feature', 'Text', 'Bezpečná pre ryby a vodné rastliny' ) ) )
);
$acpt(
	$product,
	'vlastnosti_technical_parameters',
	'Repeater',
	array(
		'parameter_name'  => array( $cell( 'parameter_name', 'Text', 'Hrúbka' ) ),
		'parameter_value' => array( $cell( 'parameter_value', 'Text', '1,0 / 1,5 / 2,0 mm' ) ),
	)
);
$acpt( $product, 'varianty_color_variants', 'Repeater', array( 'color_hex' => array( $cell( 'color_hex', 'Color', '#43433b' ) ), 'color_name' => array( $cell( 'color_name', 'Text', 'Sivoolivová' ) ) ) );
$acpt( $product, 'varianty_has_color_variants', 'Toggle', '1' );
$acpt( $product, 'dokumenty_technical_sheet', 'File', $pdf_url, array( 'attachment_id' => (string) $pdf, 'label' => 'TL-Demopond-200.pdf' ) );
$acpt(
	$product,
	'dokumenty_documents',
	'Repeater',
	array(
		'document_label' => array( $cell( 'document_label', 'Text', 'Vyhlásenie o parametroch' ) ),
		'document_file'  => array( $cell( 'document_file', 'File', $pdf_url, array( 'attachment_id' => (string) $pdf, 'label' => 'Prohlaseni-o-vlastnostech.pdf' ) ) ),
	)
);
update_post_meta( $product, '_nwr_search_boost', 5 );

// 5. Document entry pointing at the PDF.
$declarations = $term( 'Vyhlásenia o parametroch', 'typ_dokumentu' );
$document     = $new_post( array( 'post_type' => 'dokumenty', 'post_title' => 'Vyhlásenie o parametroch DEMOFOL 100' ) );
wp_set_object_terms( $document, array( $declarations ), 'typ_dokumentu' );
$acpt( $document, 'subor_box_popis', 'Textarea', 'Oficiálne vyhlásenie o parametroch a deklarovaných technických vlastnostiach fólie DEMOFOL 100.' );
$acpt( $document, 'subor_box_subor', 'File', $pdf_url, array( 'attachment_id' => (string) $pdf, 'label' => 'DOP-DEMOFOL-100.pdf' ) );

// 6. Advice article with block content.
$legal   = $term( 'Legislatíva a normy', 'kategoria_poradne' );
$article = $new_post(
	array(
		'post_type'    => 'poradna',
		'post_title'   => 'Stavebný zákon a požiadavky na ploché strechy: Čo by mal vedieť každý investor?',
		'post_content' => "<!-- wp:paragraph -->\n<p>Plochá strecha je jednou z najviac namáhaných častí každej budovy.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Hydroizolácia ako neoddeliteľná súčasť strechy</h2>\n<!-- /wp:heading -->\n\n<!-- wp:list -->\n<ul class=\"wp-block-list\"><li>zabrániť prenikaniu vody,</li><li>chrániť tepelnú izoláciu,</li></ul>\n<!-- /wp:list -->",
	)
);
wp_set_object_terms( $article, array( $legal ), 'kategoria_poradne' );
$acpt( $article, 'obsah_perex', 'Textarea', 'Pri návrhu plochej strechy nestačí myslieť iba na hydroizoláciu alebo tepelnú izoláciu.' );

// 7. Bricks page: text lives only in _bricks_page_content_2.
$home = $new_post( array( 'post_type' => 'page', 'post_title' => 'Domov', 'post_content' => '' ) );
update_post_meta( $home, '_bricks_editor_mode', 'bricks' );
update_post_meta(
	$home,
	'_bricks_page_content_2',
	array(
		array( 'id' => 'lvifhm', 'name' => 'section', 'parent' => 0, 'settings' => array( '_cssCustom' => '%root% { padding: 80px 0 }' ) ),
		array( 'id' => 'luxabw', 'name' => 'text-basic', 'parent' => 'lvifhm', 'settings' => array( 'text' => 'Váš partner pre izolácie' ) ),
		array( 'id' => 'ywnpni', 'name' => 'heading', 'parent' => 'lvifhm', 'settings' => array( 'text' => 'Hydroizolačné systémy DEMOFOL', 'tag' => 'h1' ) ),
		array( 'id' => 'ammiwe', 'name' => 'text-basic', 'parent' => 'lvifhm', 'settings' => array( 'text' => 'Izolácie striech, vodných plôch a spodných stavieb pre profesionálov.' ) ),
		array( 'id' => 'rdutdv', 'name' => 'button', 'parent' => 'lvifhm', 'settings' => array( 'text' => 'Produktový katalóg', 'link' => array( 'type' => 'internal', 'postId' => (string) $home ), 'iconTypography' => array( 'color' => array( 'raw' => 'var(--white)' ) ) ) ),
		array( 'id' => 'afncni', 'name' => 'svg', 'parent' => 'lvifhm', 'settings' => array( 'file' => array( 'url' => 'https://example.com/dot.svg' ) ) ),
		array( 'id' => 'quvoed', 'name' => 'text-basic', 'parent' => 'lvifhm', 'settings' => array( 'text' => '{term_name}' ) ),
		array( 'id' => 'dcrqzm', 'name' => 'text-basic', 'parent' => 'lvifhm', 'settings' => array( 'text' => 'Dostupné na stiahnutie' ) ),
		array( 'id' => 'acc001', 'name' => 'accordion', 'parent' => 'lvifhm', 'settings' => array( 'items' => array( array( 'title' => 'Ako dlho vydrží fólia?', 'content' => '<p>Pri správnej montáži <strong>desiatky rokov</strong>.</p>' ) ) ) ),
		array( 'id' => 'code01', 'name' => 'code', 'parent' => 'lvifhm', 'settings' => array( 'code' => '<script>console.log("secret code text")</script>' ) ),
		array( 'id' => 'dupl01', 'name' => 'button', 'parent' => 'lvifhm', 'settings' => array( 'text' => 'Produktový katalóg' ) ),
	)
);

// 8. Blog post with a perex field.
$news = $new_post( array( 'post_type' => 'post', 'post_title' => 'Nové produktové rady DEMOFOL 2026', 'post_content' => '<p>Predstavujeme rozšírenú ponuku strešných membrán.</p>' ) );
$acpt( $news, 'obsah-clanku_perex-kratky-popis', 'Textarea', 'Predstavujeme rozšírenú ponuku strešných membrán s vylepšenými vlastnosťami a dlhšou zárukou.' );

// 9. Installer company: URL fields and a contact repeater.
$region  = $term( 'Košický kraj', 'firmy' );
$company = $new_post( array( 'post_type' => 'montazne-firmy', 'post_title' => 'Demo Montáže s.r.o.' ) );
wp_set_object_terms( $company, array( $region ), 'firmy' );
$acpt( $company, 'firmy_adresa', 'Url', 'https://maps.app.goo.gl/x', array( 'label' => 'Ulica 1, 000 00 Mesto' ) );
$acpt( $company, 'firmy_web', 'Url', 'https://www.example.com', array( 'label' => 'www.example.com' ) );
$acpt( $company, 'firmy_kontaktna-osoba', 'Repeater', array( 'meno' => array( $cell( 'meno', 'Text', 'Ján Demo' ) ), 'telefon' => array( $cell( 'telefon', 'Text', '0900 000 000' ) ) ) );

// 10. Pages that must stay out: Slim SEO noindex, and one excluded in the settings.
$noindex = $new_post( array( 'post_type' => 'page', 'post_title' => 'Ďakujeme za objednávku', 'post_content' => '<p>Tajná stránka hydroizolácia.</p>' ) );
update_post_meta( $noindex, 'slim_seo', array( 'noindex' => 1 ) );
$excluded = $new_post( array( 'post_type' => 'page', 'post_title' => 'Interná stránka', 'post_content' => '<p>Interná hydroizolácia.</p>' ) );
$settings = get_option( 'nwr_search_settings' );
$settings['exclude'] = array( $excluded );
update_option( 'nwr_search_settings', $settings );

// 11. A search page and a test page for the frontend.
$results = $new_post( array( 'post_type' => 'page', 'post_title' => 'Vyhľadávanie', 'post_content' => '<!-- wp:shortcode -->[nwr_search_results]<!-- /wp:shortcode -->' ) );
$test    = $new_post( array( 'post_type' => 'page', 'post_title' => 'Test hľadania', 'post_content' => '<!-- wp:shortcode -->[nwr_search]<!-- /wp:shortcode --><div style="height:900px"></div>' ) );
$settings = get_option( 'nwr_search_settings' );
$settings['results_page'] = $results;
$settings['exclude'][]    = $results;
$settings['exclude'][]    = $test;
update_option( 'nwr_search_settings', $settings );

$ids = compact( 'pdf', 'product', 'document', 'article', 'home', 'news', 'company', 'noindex', 'excluded', 'results', 'test' );
update_option( 'nwr_uni_ids', $ids );

nwr_test_output(
	array(
		'ids'     => $ids,
		'types'   => array_keys( \Nowera\Search\Content_Types::enabled() ),
		'queue'   => \Nowera\Search\Sync\Jobs::uses_action_scheduler() ? 'action-scheduler' : 'wp-cron',
		'pending' => \Nowera\Search\Sync\Jobs::pending_count(),
	)
);
