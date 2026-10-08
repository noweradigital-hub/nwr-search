<?php
namespace Nowera\Search;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		// Job handlers and change tracking run in every context: checkout, cron, REST, admin.
		( new Sync\Jobs() )->register();
		( new Sync\Change_Tracker() )->register();
		( new Frontend\Assets() )->register();
		( new Frontend\Shortcode() )->register();
		( new Frontend\Results_Shortcode() )->register();
		( new Frontend\Search_Takeover() )->register();
		( new Integrations\Bricks() )->register();

		add_action( 'update_option_' . Settings::OPTION, array( Content_Types::class, 'flush' ) );

		if ( is_admin() ) {
			( new Admin\Settings_Page() )->register();
			( new Admin\Post_Boost() )->register();
			if ( Content_Types::woocommerce() ) {
				( new Admin\Product_Boost() )->register();
			}
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'nwr-search', CLI\Command::class );
		}
	}
}
