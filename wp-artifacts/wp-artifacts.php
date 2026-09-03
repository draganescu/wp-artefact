<?php
/**
 * Plugin Name:       Artifacts
 * Plugin URI:        https://github.com/draganescu/wp-artifacts
 * Description:       Agent-authored HTML/CSS/JS artifacts as a first-class WordPress content type: stored like a post, served byte-identical, exposed as Abilities for MCP, REST and WP-CLI.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Andrei Draganescu
 * Author URI:        https://andreidraganescu.info
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-artifacts
 * Domain Path:       /languages
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.0';

define( 'WP_ARTIFACTS_FILE', __FILE__ );
define( 'WP_ARTIFACTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_ARTIFACTS_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_ARTIFACTS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4 style autoloader for the WPArtifacts namespace.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = WP_ARTIFACTS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Bootstraps the plugin once WordPress has loaded all plugins.
 *
 * @return void
 */
function bootstrap(): void {
	Plugin::instance()->boot();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', 5 );

/**
 * Activation routine: register rewrite rules, grant capabilities, prepare storage.
 *
 * @param bool|null $network_wide Whether the plugin is being activated network wide.
 *                                WP-CLI passes null, so this is not typed.
 * @return void
 */
function activate( $network_wide = false ): void {
	if ( is_multisite() && ! empty( $network_wide ) ) {
		$site_ids = get_sites(
			array(
				'fields'        => 'ids',
				'number'        => 0,
				'no_found_rows' => true,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			activate_single_site();
			restore_current_blog();
		}
		return;
	}

	activate_single_site();
}

/**
 * Per-site activation work.
 *
 * @return void
 */
function activate_single_site(): void {
	Settings::install_defaults();
	PostType\ArtifactPostType::register();
	Security\Capabilities::grant();
	Storage\BundleStore::instance()->protect_base_dir();
	flush_rewrite_rules( false );
	update_option( 'wp_artifacts_version', VERSION, false );
}

/**
 * Runs activation for sites created while the plugin is network active.
 *
 * @param \WP_Site|int $site New site.
 * @return void
 */
function activate_new_site( $site ): void {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active_for_network( WP_ARTIFACTS_BASENAME ) ) {
		return;
	}

	$site_id = $site instanceof \WP_Site ? (int) $site->blog_id : (int) $site;
	switch_to_blog( $site_id );
	activate_single_site();
	restore_current_blog();
}
add_action( 'wp_initialize_site', __NAMESPACE__ . '\\activate_new_site', 99 );

/**
 * Deactivation routine.
 *
 * @return void
 */
function deactivate(): void {
	flush_rewrite_rules( false );
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
