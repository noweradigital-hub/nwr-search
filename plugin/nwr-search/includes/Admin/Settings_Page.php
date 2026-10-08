<?php
namespace Nowera\Search\Admin;

use Nowera\Search\Content_Types;
use Nowera\Search\Frontend\Results_Shortcode;
use Nowera\Search\Settings;
use Nowera\Search\Status;
use Nowera\Search\Sync\Jobs;
use Nowera\Search\Typesense\Client;
use WP_Error;

use const Nowera\Search\PLUGIN_FILE;

defined( 'ABSPATH' ) || exit;

/** WooCommerce → Nowera Search (or Settings → Nowera Search without a shop): connection, content, index status. */
final class Settings_Page {

	const SLUG       = 'nwr-search';
	const CAPABILITY = 'manage_woocommerce';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_filter( 'option_page_capability_nwr_search', static fn(): string => self::capability() );
		add_action( 'admin_post_nwr_search_test', array( $this, 'test_connection' ) );
		add_action( 'admin_post_nwr_search_reindex', array( $this, 'reindex' ) );
		add_action( 'admin_post_nwr_search_create_page', array( $this, 'create_results_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_link' ) );
	}

	public function menu(): void {
		add_submenu_page( Content_Types::woocommerce() ? 'woocommerce' : 'options-general.php', 'Nowera Search', 'Nowera Search', self::capability(), self::SLUG, array( $this, 'render' ) );
	}

	/** WordPress's own colour picker for the colour fields. */
	public function assets( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".nwr-search-color").wpColorPicker();});' );
	}

	/** Shop managers run the search on shops; elsewhere it is an administrator setting. */
	private static function capability(): string {
		return Content_Types::woocommerce() ? self::CAPABILITY : 'manage_options';
	}

	private static function page_url(): string {
		return admin_url( ( Content_Types::woocommerce() ? 'admin.php' : 'options-general.php' ) . '?page=' . self::SLUG );
	}

	public function register_setting(): void {
		register_setting(
			'nwr_search',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	public function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">Nastavenia</a>' );
		return $links;
	}

	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$current  = get_option( Settings::OPTION, array() );
		$current  = is_array( $current ) ? $current : array();
		$site_key = trim( sanitize_text_field( (string) ( $input['site_key'] ?? '' ) ) );

		return array(
			'host'           => Client::normalize_url( sanitize_text_field( (string) ( $input['host'] ?? '' ) ) ),
			'collection'     => (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $input['collection'] ?? '' ) ) ),
			// An empty password field means "keep the saved key".
			'site_key'       => '' !== $site_key ? $site_key : (string) ( $current['site_key'] ?? '' ),
			'search_key'     => trim( sanitize_text_field( (string) ( $input['search_key'] ?? '' ) ) ),
			'auto_sync'      => empty( $input['auto_sync'] ) ? 0 : 1,
			'sections'       => $this->sanitize_sections( is_array( $input['sections'] ?? null ) ? $input['sections'] : array() ),
			'boost_buckets'  => Settings::boost_buckets( $input['boost_buckets'] ?? null ),
			'results_page'   => absint( $input['results_page'] ?? 0 ),
			'takeover'       => empty( $input['takeover'] ) ? 0 : 1,
			'category_links' => empty( $input['category_links'] ) ? 0 : 1,
			'filters'        => $this->sanitize_filters( is_array( $input['filters'] ?? null ) ? $input['filters'] : array() ),
			'attach'         => trim( sanitize_text_field( (string) ( $input['attach'] ?? '' ) ) ),
			'types'          => $this->sanitize_types( is_array( $input['types'] ?? null ) ? $input['types'] : array() ),
			'mimes'          => array_values( array_filter( array_map( 'sanitize_mime_type', preg_split( '/[\s,]+/', (string) ( $input['mimes'] ?? '' ) ) ) ) ),
			'exclude'        => array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) ( $input['exclude'] ?? '' ) ) ) ) ),
			// "2786b2" and "#2786b2" both work; anything else means "follow the theme".
			'accent'         => self::hex( $input['accent'] ?? '' ),
			'icon_color'     => self::hex( $input['icon_color'] ?? '' ),
		);
	}

	private static function hex( $value ): string {
		return (string) sanitize_hex_color( '#' . ltrim( trim( (string) $value ), '#' ) );
	}

	/** One row per content type; hidden "0" inputs make unchecked boxes explicit. */
	private function sanitize_types( array $input ): array {
		$types = array();
		foreach ( $input as $name => $row ) {
			$name = sanitize_key( (string) $name );
			if ( '' === $name || ! is_array( $row ) ) {
				continue;
			}
			$types[ $name ] = array(
				'enabled'  => empty( $row['enabled'] ) ? 0 : 1,
				'label'    => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
				'limit'    => absint( $row['limit'] ?? 0 ),
				'order'    => absint( $row['order'] ?? 0 ),
				'link'     => Content_Types::LINK_FILE === ( $row['link'] ?? '' ) ? Content_Types::LINK_FILE : Content_Types::LINK_DETAIL,
				'taxonomy' => sanitize_key( (string) ( $row['taxonomy'] ?? '' ) ),
			);
		}
		return $types;
	}

	/** Unchecked boxes are not submitted, so every filter is written explicitly. */
	private function sanitize_filters( array $input ): array {
		$filters = array( 'attributes' => is_array( $input['attributes'] ?? null ) ? $input['attributes'] : array() );
		foreach ( array_keys( Settings::FILTERS ) as $id ) {
			$filters[ $id ] = empty( $input[ $id ] ) ? 0 : 1;
		}
		return Settings::filters( $filters );
	}

	/** Unchecked boxes are not submitted, so every known switch is written explicitly. */
	private function sanitize_sections( array $input ): array {
		$sections = array();
		foreach ( array( 'browse', 'search' ) as $state ) {
			foreach ( array_keys( Settings::SECTIONS[ $state ] ) as $id ) {
				$row = is_array( $input[ $state ][ $id ] ?? null ) ? $input[ $state ][ $id ] : array();

				$sections[ $state ][ $id ] = array(
					'enabled' => empty( $row['enabled'] ) ? 0 : 1,
					'limit'   => (int) ( $row['limit'] ?? 0 ),
					'order'   => (int) ( $row['order'] ?? 0 ),
				);
			}
		}
		$categories             = is_array( $input['categories'] ?? null ) ? $input['categories'] : array();
		$sections['categories'] = array(
			'browse'       => empty( $categories['browse'] ) ? 0 : 1,
			'search'       => empty( $categories['search'] ) ? 0 : 1,
			'limit'        => (int) ( $categories['limit'] ?? 0 ),
			'mobile_limit' => (int) ( $categories['mobile_limit'] ?? 0 ),
		);
		return Settings::sections( $sections );
	}

	public function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$settings = Settings::all();
		$shop     = Content_Types::woocommerce();
		$status   = Status::all();
		$notice   = get_transient( $this->notice_key() );
		if ( $notice ) {
			delete_transient( $this->notice_key() );
		}
		?>
		<div class="wrap">
			<h1>Nowera Search</h1>
			<?php settings_errors(); ?>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'nwr_search' ); ?>
				<table class="form-table" role="presentation">
					<?php
					$this->field( 'host', 'Server', $settings['host'], 'Adresa Typesense servera, napr. <code>search.example.com</code>.', 'text', 'search.example.com' );
					$this->field( 'collection', 'Kolekcia', $settings['collection'], 'Názov indexu tohto webu. Musí sedieť s kolekciou, na ktorú je vystavený kľúč.', 'text', Settings::default_collection() );
					$this->field( 'site_key', 'Kľúč pre zápis', '', 'Kľúč obmedzený na kolekciu tohto webu. Nikdy sa neposiela do prehliadača. Prázdne pole ponechá uložený kľúč.', 'password', '' !== $settings['site_key'] ? '•••••••• uložený' : '' );
					$this->field( 'search_key', 'Kľúč pre vyhľadávanie', $settings['search_key'], 'Verejný kľúč len na vyhľadávanie — používa ho frontend.', 'text' );
					?>
					<tr>
						<th scope="row">Automatická synchronizácia</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[auto_sync]" value="1" <?php checked( ! empty( $settings['auto_sync'] ) ); ?>>
								Posielať zmeny obsahu, skladu a kategórií na pozadí
							</label>
						</td>
					</tr>
				</table>

				<h2>Vzhľad</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nwr-search-accent">Farba akcentu</label></th>
						<td>
							<input id="nwr-search-accent" class="nwr-search-color" type="text" name="<?php echo esc_attr( Settings::OPTION ); ?>[accent]"
								value="<?php echo esc_attr( Settings::accent() ); ?>" data-default-color="" maxlength="7" placeholder="#2786b2">
							<p class="description">Tlačidlá, zvýraznenie hľadaného výrazu, záložky a filtre. Prázdne = farba z témy (Bricks, Elementor, Woodmart).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nwr-search-icon-color">Farba ikonky</label></th>
						<td>
							<input id="nwr-search-icon-color" class="nwr-search-color" type="text" name="<?php echo esc_attr( Settings::OPTION ); ?>[icon_color]"
								value="<?php echo esc_attr( Settings::icon_color() ); ?>" data-default-color="" maxlength="7" placeholder="#ffffff">
							<p class="description">Lupa, ktorá otvára vyhľadávanie (<code>layout="bar"</code> a <code>layout="overlay"</code>), aj text vedľa nej. Prázdne = farba textu v hlavičke. Pre jeden konkrétny shortcode: <code>icon_color="#ffffff"</code>.</p>
						</td>
					</tr>
				</table>

				<h2>Obsah vo vyhľadávaní</h2>
				<p class="description">Čo sa indexuje a ako sa to zobrazí v paneli. Po zmene spustite plnú reindexáciu.</p>
				<?php $this->types_table( Content_Types::all(), $settings ); ?>

				<h2>Stránka s výsledkami</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nwr-search-results-page">Stránka</label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => Settings::OPTION . '[results_page]',
									'id'                => 'nwr-search-results-page',
									'selected'          => (int) $settings['results_page'],
									'show_option_none'  => $shop ? '— natívne vyhľadávanie WooCommerce —' : '— natívne vyhľadávanie WordPressu —',
									'option_none_value' => '0',
								)
							);
							?>
							<p class="description">
								Stránka s krátkym kódom <code>[nwr_search_results]</code> — výsledky s filtrami, zoradením a donekonečna sa načítavajúcim výpisom.
								Panel bude na túto stránku odkazovať cez „Zobraziť všetky výsledky“.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Prevziať vyhľadávanie</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[takeover]" value="1" <?php checked( ! empty( $settings['takeover'] ) ); ?>>
								Presmerovať vyhľadávanie z celého webu na túto stránku
							</label>
							<p class="description">Funguje aj pre vyhľadávacie polia témy — netreba meniť header. Hľadanie obmedzené na obsah, ktorý nie je v indexe (<code>post_type</code>), ostáva nedotknuté.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Kategórie v paneli</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[category_links]" value="1" <?php checked( ! empty( $settings['category_links'] ) ); ?>>
								Otvárať na stránke s výsledkami
							</label>
							<p class="description">Klik na kategóriu v paneli otvorí túto stránku s kategóriou vo filtri a s napísaným výrazom — namiesto archívu kategórie. Archívy ostávajú, vedú na ne odkazy v menu a na webe.</p>
						</td>
					</tr>
					<?php if ( $shop ) : ?>
						<tr>
							<th scope="row">Filtre produktov</th>
							<td><?php $this->filters_fields( Settings::filters() ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="nwr-search-attach">Napojiť na pole v téme</label></th>
						<td>
							<input id="nwr-search-attach" class="regular-text code" type="text" name="<?php echo esc_attr( Settings::OPTION ); ?>[attach]"
								value="<?php echo esc_attr( (string) $settings['attach'] ); ?>" placeholder=".wd-header-search-form" autocomplete="off">
							<p class="description">
								Nepovinné. CSS selektor existujúceho vyhľadávacieho formulára — panel sa otvorí priamo nad ním, bez krátkeho kódu v headeri.
								Ak má téma vlastné našeptávanie, môžu sa zobraziť dva panely.
							</p>
						</td>
					</tr>
				</table>

				<h2>Poradie výsledkov</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nwr-search-boost-buckets">Vplyv boostu</label></th>
						<td>
							<select id="nwr-search-boost-buckets" name="<?php echo esc_attr( Settings::OPTION ); ?>[boost_buckets]">
								<?php foreach ( $this->boost_labels() as $buckets => $label ) : ?>
									<option value="<?php echo esc_attr( (string) $buckets ); ?>" <?php selected( (int) $settings['boost_buckets'], $buckets ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								Boost (−100 až 100) sa nastavuje pri položke: produkt → Pokročilé, ostatný obsah → box <em>Nowera Search</em> v bočnom paneli, alebo stĺpec <em>Boost</em> v zozname.
								Čím silnejší vplyv, tým viac môže boost prehodiť poradie medzi podobne relevantnými výsledkami.
								Pri <em>vypnuté</em> rozhoduje iba zhoda s hľadaným výrazom.
							</p>
						</td>
					</tr>
				</table>

				<h2>Panel vyhľadávania</h2>
				<p class="description">Čo sa zobrazí po kliknutí do prázdneho poľa a počas písania. Nižšie poradie je vyššie v paneli.</p>
				<?php $this->sections_table( Settings::sections() ); ?>

				<?php submit_button( 'Uložiť nastavenia' ); ?>
			</form>

			<h2>Index</h2>
			<table class="widefat striped" style="max-width:760px">
				<tbody>
					<tr><th style="width:220px">Kolekcia</th><td><code><?php echo esc_html( $settings['collection'] ); ?></code></td></tr>
					<tr><th>Posledná synchronizácia</th><td><?php echo esc_html( $this->last_sync( $status ) ); ?></td></tr>
					<tr><th>Plná reindexácia</th><td><?php echo esc_html( $this->reindex_state( $status ) ); ?></td></tr>
					<tr>
						<th>Čakajúce úlohy</th>
						<td>
							<?php echo esc_html( (string) Jobs::pending_count() ); ?>
							<?php if ( Jobs::uses_action_scheduler() && $shop ) : ?>
								— <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=nwr_search' ) ); ?>">Action Scheduler</a>
							<?php elseif ( ! Jobs::uses_action_scheduler() ) : ?>
								<span class="description">(WP-Cron)</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr><th>Posledná chyba</th><td><?php echo esc_html( $this->last_error( $status ) ); ?></td></tr>
				</tbody>
			</table>

			<p style="display:flex;gap:8px;margin-top:16px">
				<?php $this->action_button( 'nwr_search_test', 'Otestovať spojenie', 'secondary' ); ?>
				<?php if ( (int) $settings['results_page'] <= 0 ) : ?>
					<?php $this->action_button( 'nwr_search_create_page', 'Vytvoriť stránku s výsledkami', 'secondary' ); ?>
				<?php endif; ?>
				<?php $this->action_button( 'nwr_search_reindex', 'Spustiť plnú reindexáciu', 'primary' ); ?>
			</p>
		</div>
		<?php
	}

	public function test_connection(): void {
		$this->guard( 'nwr_search_test' );

		if ( ! Settings::is_configured() ) {
			$this->redirect( 'error', 'Vyplňte server, kolekciu a kľúč pre zápis.' );
		}

		$settings = Settings::all();
		$client   = new Client( $settings['host'], $settings['site_key'], 10 );

		$health = $client->health();
		if ( is_wp_error( $health ) || empty( $health['ok'] ) ) {
			$this->redirect( 'error', 'Server neodpovedá: ' . ( is_wp_error( $health ) ? $health->get_error_message() : 'health check zlyhal' ) );
		}

		$type       = 'success';
		$messages   = array( 'Server beží.' );
		$collection = $client->get_collection( $settings['collection'] );
		if ( ! is_wp_error( $collection ) ) {
			$messages[] = sprintf( 'Kolekcia %s — počet dokumentov: %d.', $settings['collection'], (int) ( $collection['num_documents'] ?? 0 ) );
			Status::schema_fields( array_column( $collection['fields'] ?? array(), 'name' ) );
		} elseif ( 404 === $this->status_code( $collection ) ) {
			$messages[] = 'Kolekcia zatiaľ neexistuje, vytvorí sa pri prvej reindexácii.';
		} else {
			$type       = 'error';
			$messages[] = 'Kľúč pre zápis nemá prístup ku kolekcii: ' . $collection->get_error_message();
		}

		if ( '' === $settings['search_key'] ) {
			$type       = 'error' === $type ? $type : 'warning';
			$messages[] = 'Kľúč pre vyhľadávanie nie je vyplnený.';
		} else {
			$search = ( new Client( $settings['host'], $settings['search_key'], 10 ) )->search(
				$settings['collection'],
				array(
					'q'        => '*',
					'query_by' => 'title',
					'per_page' => 1,
				)
			);
			if ( ! is_wp_error( $search ) || 404 === $this->status_code( $search ) ) {
				$messages[] = 'Kľúč pre vyhľadávanie funguje.';
			} else {
				$type       = 'error';
				$messages[] = 'Kľúč pre vyhľadávanie nefunguje: ' . $search->get_error_message();
			}
		}

		$this->redirect( $type, implode( ' ', $messages ) );
	}

	/** One click instead of "create a page, paste the shortcode, pick it in the setting". */
	public function create_results_page(): void {
		$this->guard( 'nwr_search_create_page' );

		$current = (int) Settings::get( 'results_page' );
		if ( $current > 0 && 'publish' === get_post_status( $current ) ) {
			$this->redirect( 'warning', 'Stránka s výsledkami je už nastavená.' );
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Vyhľadávanie',
				'post_name'    => 'vyhladavanie',
				'post_content' => '<!-- wp:shortcode -->[' . Results_Shortcode::TAG . ']<!-- /wp:shortcode -->',
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			$this->redirect( 'error', 'Stránku sa nepodarilo vytvoriť: ' . $page_id->get_error_message() );
		}

		$saved                 = get_option( Settings::OPTION, array() );
		$saved                 = is_array( $saved ) ? $saved : array();
		$saved['results_page'] = (int) $page_id;
		update_option( Settings::OPTION, $saved );

		$this->redirect( 'success', 'Stránka s výsledkami je pripravená: ' . get_permalink( $page_id ) );
	}

	public function reindex(): void {
		$this->guard( 'nwr_search_reindex' );

		if ( ! Settings::is_configured() ) {
			$this->redirect( 'error', 'Najprv vyplňte server, kolekciu a kľúč pre zápis.' );
		}

		Jobs::start_reindex();
		$this->redirect( 'success', 'Plná reindexácia beží na pozadí. Priebeh uvidíte po obnovení stránky.' );
	}

	private function field( string $key, string $label, string $value, string $description, string $type, string $placeholder = '' ): void {
		$locked = Settings::is_locked( $key );
		?>
		<tr>
			<th scope="row"><label for="nwr-search-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input id="nwr-search-<?php echo esc_attr( $key ); ?>" class="regular-text code" type="<?php echo esc_attr( $type ); ?>"
					name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>"
					value="<?php echo esc_attr( $locked ? '' : $value ); ?>"
					placeholder="<?php echo esc_attr( $locked ? 'nastavené vo wp-config.php' : $placeholder ); ?>"
					autocomplete="off" <?php disabled( $locked ); ?>>
				<p class="description"><?php echo wp_kses( $description, array( 'code' => array() ) ); ?></p>
			</td>
		</tr>
		<?php
	}

	/** Which facets the results page offers; attributes come from WooCommerce. */
	private function filters_fields( array $filters ): void {
		$labels = array(
			'categories'   => 'Kategórie',
			'brands'       => 'Značka',
			'price'        => 'Cena',
			'availability' => 'Dostupnosť',
			'sale'         => 'Zľavy',
		);
		$name = static fn( string $key ): string => Settings::OPTION . '[filters][' . $key . ']';
		?>
		<fieldset>
			<?php foreach ( $labels as $id => $label ) : ?>
				<label style="margin-right:16px">
					<input type="checkbox" name="<?php echo esc_attr( $name( $id ) ); ?>" value="1" <?php checked( ! empty( $filters[ $id ] ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php
		$attributes = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
		if ( ! $attributes ) {
			return;
		}
		?>
		<fieldset style="margin-top:10px">
			<legend class="description" style="margin-bottom:4px">Vlastnosti produktov ako filtre:</legend>
			<?php foreach ( $attributes as $attribute ) : ?>
				<label style="margin-right:16px">
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[filters][attributes][]' ); ?>" value="<?php echo esc_attr( $attribute->attribute_name ); ?>"
						<?php checked( in_array( $attribute->attribute_name, $filters['attributes'], true ) ); ?>>
					<?php echo esc_html( $attribute->attribute_label ); ?>
				</label>
			<?php endforeach; ?>
			<p class="description">Filtrovať sa dá len podľa toho, čo je v indexe — po zapnutí novej vlastnosti nie je potrebná reindexácia, atribúty sa indexujú vždy.</p>
		</fieldset>
		<?php
	}

	/** Content types: what is indexed, how it is labelled and where results link to. */
	private function types_table( array $types, array $settings ): void {
		$name = static fn( string ...$path ): string => Settings::OPTION . '[types][' . implode( '][', $path ) . ']';
		?>
		<div style="overflow-x:auto;max-width:1100px">
			<table class="widefat striped" style="margin-top:8px">
				<thead>
					<tr>
						<th>Typ obsahu</th>
						<th style="width:64px">Hľadať</th>
						<th>Nadpis v paneli</th>
						<th style="width:80px">Počet</th>
						<th style="width:80px">Poradie</th>
						<th style="width:170px">Odkaz vedie na</th>
						<th style="width:220px">Kategórie z taxonómie</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $types as $id => $type ) : ?>
						<?php $is_file = Content_Types::ATTACHMENTS === $id; ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $is_file ? 'Súbory z knižnice médií' : $type['label'] ); ?></strong><br>
								<code><?php echo esc_html( $id ); ?></code>
							</td>
							<td>
								<input type="hidden" name="<?php echo esc_attr( $name( $id, 'enabled' ) ); ?>" value="0">
								<input type="checkbox" name="<?php echo esc_attr( $name( $id, 'enabled' ) ); ?>" value="1" <?php checked( $type['enabled'] ); ?>>
							</td>
							<td><input type="text" style="width:100%" name="<?php echo esc_attr( $name( $id, 'label' ) ); ?>" value="<?php echo esc_attr( $type['label'] ); ?>"></td>
							<td><input type="number" class="small-text" min="1" max="24" name="<?php echo esc_attr( $name( $id, 'limit' ) ); ?>" value="<?php echo esc_attr( (string) $type['limit'] ); ?>"></td>
							<td><input type="number" class="small-text" min="1" max="99" name="<?php echo esc_attr( $name( $id, 'order' ) ); ?>" value="<?php echo esc_attr( (string) $type['order'] ); ?>"></td>
							<td>
								<?php if ( $is_file ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $name( $id, 'link' ) ); ?>" value="<?php echo esc_attr( Content_Types::LINK_FILE ); ?>">
									<span class="description">súbor</span>
								<?php else : ?>
									<select name="<?php echo esc_attr( $name( $id, 'link' ) ); ?>">
										<option value="<?php echo esc_attr( Content_Types::LINK_DETAIL ); ?>" <?php selected( $type['link'], Content_Types::LINK_DETAIL ); ?>>detail položky</option>
										<option value="<?php echo esc_attr( Content_Types::LINK_FILE ); ?>" <?php selected( $type['link'], Content_Types::LINK_FILE ); ?>>priamo na súbor (PDF…)</option>
									</select>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $type['facets'] ) : ?>
									<select name="<?php echo esc_attr( $name( $id, 'taxonomy' ) ); ?>">
										<option value="">—</option>
										<?php foreach ( $type['facets'] as $taxonomy => $label ) : ?>
											<option value="<?php echo esc_attr( $taxonomy ); ?>" <?php selected( $type['taxonomy'], $taxonomy ); ?>><?php echo esc_html( $label . ' (' . $taxonomy . ')' ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<span class="description">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nwr-search-mimes">Typy súborov z knižnice</label></th>
				<td>
					<input id="nwr-search-mimes" class="regular-text code" type="text" name="<?php echo esc_attr( Settings::OPTION ); ?>[mimes]" value="<?php echo esc_attr( implode( ', ', Content_Types::mimes() ) ); ?>">
					<p class="description">MIME typy oddelené čiarkou, napr. <code>application/pdf</code>. Platí len keď sú zapnuté „Súbory z knižnice médií“. Súbory pripojené k položkám (napr. PDF pri dokumente) sa berú z polí ACPT/ACF automaticky.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nwr-search-exclude">Vylúčiť položky</label></th>
				<td>
					<input id="nwr-search-exclude" class="regular-text code" type="text" name="<?php echo esc_attr( Settings::OPTION ); ?>[exclude]" value="<?php echo esc_attr( implode( ', ', array_map( 'intval', (array) $settings['exclude'] ) ) ); ?>" placeholder="12, 345">
					<p class="description">ID stránok či príspevkov, ktoré sa nemajú dať nájsť. Položky s <em>noindex</em> v Slim SEO, Yoast, Rank Math či SEOPress sa vynechávajú automaticky.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/** @return array<int,string> buckets => label */
	private function boost_labels(): array {
		return array(
			0  => 'vypnuté — rozhoduje iba relevancia',
			20 => 'jemný',
			10 => 'stredný (odporúčané)',
			4  => 'silný',
		);
	}

	private function sections_table( array $sections ): void {
		$labels = array(
			'browse' => array(
				'title'   => 'Po kliknutí do prázdneho poľa',
				'recent'  => 'Naposledy prezerané',
				'related' => 'Odporúčané k naposledy prezeraným',
				'popular' => 'Obľúbené produkty',
			),
			'search' => array(
				'title'    => 'Počas písania',
				'products' => 'Nájdené produkty',
				'related'  => 'Odporúčané k najlepšiemu výsledku',
			),
		);
		$name = static fn( string ...$path ): string => Settings::OPTION . '[sections][' . implode( '][', $path ) . ']';
		?>
		<table class="widefat striped" style="max-width:760px;margin-top:8px">
			<thead>
				<tr><th>Sekcia</th><th style="width:90px">Zobraziť</th><th style="width:110px">Počet</th><th style="width:110px">Poradie</th></tr>
			</thead>
			<tbody>
				<?php foreach ( array( 'browse', 'search' ) as $state ) : ?>
					<tr><td colspan="4"><strong><?php echo esc_html( $labels[ $state ]['title'] ); ?></strong></td></tr>
					<?php foreach ( $sections[ $state ] as $id => $row ) : ?>
						<tr>
							<td style="padding-left:24px"><?php echo esc_html( $labels[ $state ][ $id ] ); ?></td>
							<td>
								<input type="checkbox" name="<?php echo esc_attr( $name( $state, $id, 'enabled' ) ); ?>" value="1" <?php checked( $row['enabled'] ); ?> <?php disabled( 'products' === $id ); ?>>
								<?php if ( 'products' === $id ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $name( $state, $id, 'enabled' ) ); ?>" value="1">
								<?php endif; ?>
							</td>
							<td><input type="number" class="small-text" min="1" max="24" name="<?php echo esc_attr( $name( $state, $id, 'limit' ) ); ?>" value="<?php echo esc_attr( (string) $row['limit'] ); ?>"></td>
							<td><input type="number" class="small-text" min="1" max="99" name="<?php echo esc_attr( $name( $state, $id, 'order' ) ); ?>" value="<?php echo esc_attr( (string) $row['order'] ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				<tr><td colspan="4"><strong>Kategórie</strong> <span class="description">(bočný stĺpec, na mobile riadok nad produktmi)</span></td></tr>
				<tr>
					<td style="padding-left:24px">
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'categories', 'browse' ) ); ?>" value="1" <?php checked( $sections['categories']['browse'] ); ?>> po kliknutí</label>
						&nbsp;
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'categories', 'search' ) ); ?>" value="1" <?php checked( $sections['categories']['search'] ); ?>> počas písania</label>
					</td>
					<td></td>
					<td><input type="number" class="small-text" min="1" max="30" name="<?php echo esc_attr( $name( 'categories', 'limit' ) ); ?>" value="<?php echo esc_attr( (string) $sections['categories']['limit'] ); ?>"> <span class="description">desktop</span></td>
					<td><input type="number" class="small-text" min="0" max="30" name="<?php echo esc_attr( $name( 'categories', 'mobile_limit' ) ); ?>" value="<?php echo esc_attr( (string) $sections['categories']['mobile_limit'] ); ?>"> <span class="description">mobil</span></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	private function action_button( string $action, string $label, string $style ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<?php submit_button( $label, $style, 'submit', false ); ?>
		</form>
		<?php
	}

	private function last_sync( array $status ): string {
		$sync = $status['last_sync'] ?? null;
		return $sync ? sprintf( '%s — odoslané: %d, odstránené: %d', $this->date( (int) $sync['time'] ), (int) $sync['indexed'], (int) $sync['removed'] ) : 'zatiaľ nie';
	}

	private function reindex_state( array $status ): string {
		$reindex = $status['reindex'] ?? null;
		if ( ! $reindex ) {
			return 'zatiaľ nespustená';
		}
		$labels = array(
			'running' => 'beží',
			'done'    => 'dokončená',
			'failed'  => 'zlyhala',
		);
		$state = $labels[ $reindex['state'] ] ?? $reindex['state'];
		$text  = sprintf( '%s — spustená %s, položky: %d', $state, $this->date( (int) $reindex['run'] ), (int) $reindex['indexed'] );
		if ( 'done' === $reindex['state'] ) {
			$text .= sprintf( ', odstránené staré dokumenty: %d', (int) ( $reindex['purged'] ?? 0 ) );
		}
		return $text;
	}

	private function last_error( array $status ): string {
		$error = $status['last_error'] ?? null;
		return $error ? $this->date( (int) $error['time'] ) . ' — ' . $error['message'] : 'žiadna';
	}

	private function date( int $timestamp ): string {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	private function status_code( WP_Error $error ): int {
		$data = $error->get_error_data();
		return is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( 'Nemáte oprávnenie.', 403 );
		}
		check_admin_referer( $action );
	}

	private function redirect( string $type, string $message ): never {
		set_transient( $this->notice_key(), compact( 'type', 'message' ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	private function notice_key(): string {
		return 'nwr_search_notice_' . get_current_user_id();
	}
}
