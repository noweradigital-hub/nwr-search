<?php
namespace Nowera\Search\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Native Bricks elements for the search field and the results page. They
 * render the same markup as the shortcodes, so nothing else differs.
 *
 * Element files are named so the class autoloader never touches them:
 * they extend \Bricks\Element, which only exists once Bricks has loaded.
 */
final class Bricks {

	public function register(): void {
		add_action( 'init', array( $this, 'elements' ), 11 );
		add_filter( 'bricks/builder/i18n', array( $this, 'category' ) );
	}

	public function elements(): void {
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return;
		}
		\Bricks\Elements::register_element( __DIR__ . '/bricks/element-search.php', 'nwr-search', __NAMESPACE__ . '\Bricks_Search_Element' );
		\Bricks\Elements::register_element( __DIR__ . '/bricks/element-results.php', 'nwr-search-results', __NAMESPACE__ . '\Bricks_Results_Element' );
	}

	public function category( array $i18n ): array {
		$i18n['nowera'] = 'Nowera';
		return $i18n;
	}
}
