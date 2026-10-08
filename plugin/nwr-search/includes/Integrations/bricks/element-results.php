<?php
namespace Nowera\Search\Integrations;

use Nowera\Search\Frontend\Results_Shortcode;

defined( 'ABSPATH' ) || exit;

/** Bricks element: the full results page (tabs, filters, sorting, endless list). */
class Bricks_Results_Element extends \Bricks\Element {

	public $category = 'nowera';
	public $name     = 'nwr-search-results';
	public $icon     = 'ti-layout-grid2';

	public function get_label() {
		return 'Nowera Search – výsledky';
	}

	public function get_keywords() {
		return array( 'search', 'results', 'výsledky', 'vyhľadávanie', 'filtre' );
	}

	public function set_controls() {
		$this->controls['field'] = array(
			'label'   => 'Zobraziť vyhľadávacie pole',
			'type'    => 'checkbox',
			'default' => true,
		);
		$this->controls['per_page'] = array(
			'label'   => 'Počet na načítanie',
			'type'    => 'number',
			'min'     => 6,
			'max'     => 48,
			'default' => 24,
			'inline'  => true,
		);
	}

	public function render() {
		$settings = $this->settings;
		$html     = ( new Results_Shortcode() )->render(
			array(
				'field'    => empty( $settings['field'] ) ? 'no' : 'yes',
				'per_page' => (string) ( $settings['per_page'] ?? 24 ),
			)
		);

		echo '<div ' . $this->render_attributes( '_root' ) . '>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the shortcode.
	}
}
