<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * @package WPArtifacts
 */

$wp_artifacts_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wp_artifacts_tests_dir ) {
	$wp_artifacts_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $wp_artifacts_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI bootstrap, WordPress is not loaded yet.
	echo "Could not find the WordPress test suite in {$wp_artifacts_tests_dir}.\n";
	echo "Install it with bin/install-wp-tests.sh, or point WP_TESTS_DIR at an existing copy.\n";
	echo "The end-to-end suite needs none of this: npm run test:e2e:server, then npm run test:e2e.\n";
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $wp_artifacts_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/wp-artifacts.php';
	}
);

tests_add_filter(
	'init',
	static function (): void {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			update_option( 'permalink_structure', '/%postname%/' );
		}
		\WPArtifacts\Security\Capabilities::grant();
	},
	5
);

require $wp_artifacts_tests_dir . '/includes/bootstrap.php';
