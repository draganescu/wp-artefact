<?php
/**
 * Service wiring.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts;

defined( 'ABSPATH' ) || exit;

/**
 * Boots every service the plugin provides.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Wires all hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );

		PostType\ArtifactPostType::instance()->hooks();
		Security\Capabilities::hooks();
		Storage\ArtifactRepository::instance()->hooks();
		Serving\Router::instance()->hooks();
		Serving\ParentDelivery::instance()->hooks();
		Style\ThemeAnalyzer::instance()->hooks();
		Abilities\Registrar::instance()->hooks();
		Abilities\UploadUrl::instance()->hooks();

		if ( is_admin() ) {
			Admin\ListTable::instance()->hooks();
			Admin\EditScreen::instance()->hooks();
			Admin\SettingsPage::instance()->hooks();
		}

		Admin\SiteHealth::instance()->hooks();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli\Commands::register();
		}
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wp-artifacts', false, dirname( WP_ARTIFACTS_BASENAME ) . '/languages' );
	}
}
