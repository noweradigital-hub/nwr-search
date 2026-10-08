<?php
namespace Nowera\Search\Integrations;

use Nowera\Search\Frontend\Shortcode;

defined( 'ABSPATH' ) || exit;

/** Bricks element: the search field with the instant results panel. */
class Bricks_Search_Element extends \Bricks\Element {

	public $category = 'nowera';
	public $name     = 'nwr-search';
	public $icon     = 'ti-search';

	public function get_label() {
		return 'Nowera Search';
	}

	public function get_keywords() {
		return array( 'search', 'vyhľadávanie', 'hladat', 'typesense' );
	}

	public function set_controls() {
		$this->controls['layout'] = array(
			'label'   => 'Rozloženie',
			'type'    => 'select',
			'options' => array(
				'inline'  => 'Vyhľadávacie pole',
				'bar'     => 'Lupa → pás na celú šírku',
				'overlay' => 'Lupa → celá obrazovka',
			),
			'default' => 'inline',
			'inline'  => true,
		);
		$this->controls['size'] = array(
			'label'   => 'Veľkosť',
			'type'    => 'select',
			'options' => array(
				'default' => 'Štandardná',
				'compact' => 'Kompaktná (header)',
			),
			'default' => 'default',
			'inline'  => true,
		);
		$this->controls['align'] = array(
			'label'       => 'Panel zarovnať',
			'type'        => 'select',
			'options'     => array(
				'left'   => 'Vľavo',
				'center' => 'Na stred',
				'right'  => 'Vpravo',
			),
			'default'     => 'left',
			'inline'      => true,
			'description' => 'Ku ktorej strane poľa sa pripne široký panel s výsledkami.',
		);
		$this->controls['label'] = array(
			'label'       => 'Text pri lupe',
			'type'        => 'text',
			'inline'      => true,
			'required'    => array( 'layout', '!=', 'inline' ),
			'description' => 'Nepovinné, napr. „Hľadať“.',
		);
		$this->controls['boxed'] = array(
			'label'       => 'Box namiesto celej šírky',
			'type'        => 'checkbox',
			'required'    => array( 'layout', '=', 'bar' ),
			'description' => 'Vyhľadávanie sa otvorí ako okno pod lupou (zarovnanie podľa „Panel zarovnať“), nie ako pás cez celú stránku. Na mobile ostáva celá obrazovka.',
		);
		$this->controls['width'] = array(
			'label'       => 'Šírka pásu / boxu',
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => '1200px (box: 760px)',
			'required'    => array( 'layout', '=', 'bar' ),
		);
		$this->controls['top'] = array(
			'label'       => 'Odsadenie pásu zhora',
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => '0px',
			'required'    => array( 'layout', '=', 'bar' ),
			'description' => 'Napr. výška fixného headera, aby ostal viditeľný.',
		);
		$this->controls['placeholder'] = array(
			'label'  => 'Placeholder',
			'type'   => 'text',
			'inline' => true,
		);
		$this->controls['limit'] = array(
			'label'       => 'Počet výsledkov na typ',
			'type'        => 'number',
			'min'         => 1,
			'max'         => 24,
			'inline'      => true,
			'description' => 'Prázdne = podľa nastavení pluginu.',
		);
	}

	public function render() {
		$settings = $this->settings;
		$atts     = array(
			'layout' => $settings['layout'] ?? 'inline',
			'size'   => $settings['size'] ?? 'default',
			'align'  => $settings['align'] ?? 'left',
			'limit'  => $settings['limit'] ?? '',
		);
		if ( ! empty( $settings['boxed'] ) ) {
			$atts['fullwidth'] = 'no';
		}
		foreach ( array( 'placeholder', 'label', 'width', 'top' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$atts[ $key ] = $settings[ $key ];
			}
		}

		echo '<div ' . $this->render_attributes( '_root' ) . '>' . ( new Shortcode() )->render( $atts ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the shortcode.
	}
}
