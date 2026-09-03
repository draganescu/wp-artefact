<?php
/**
 * Shared test scaffolding.
 *
 * @package WPArtifacts
 */

namespace WPArtifacts\Tests;

use WPArtifacts\Security\Capabilities;
use WPArtifacts\Settings;
use WPArtifacts\Storage\ArtifactRepository;
use WPArtifacts\Style\ThemeAnalyzer;
use WP_UnitTestCase;

/**
 * Base class: a fresh administrator and clean settings per test.
 */
abstract class ArtifactTestCase extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected int $admin_id = 0;

	/**
	 * Sets up the site the way the plugin expects it.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Capabilities::grant();
		Settings::flush();
		ThemeAnalyzer::instance()->flush();

		update_option( 'permalink_structure', '/%postname%/' );
		flush_rewrite_rules( false );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Publishes an artifact and fails the test if the repository refused.
	 *
	 * @param array<string,mixed> $args Publish arguments.
	 * @return array<string,mixed>
	 */
	protected function publish( array $args ): array {
		$result = ArtifactRepository::instance()->create( $args );

		if ( is_wp_error( $result ) ) {
			$this->fail( 'Publish failed: [' . $result->get_error_code() . '] ' . $result->get_error_message() );
		}

		return $result;
	}

	/**
	 * A minimal complete HTML document.
	 *
	 * @param string $body Body markup.
	 * @return string
	 */
	protected function document( string $body = '<h1>Hi</h1>' ): string {
		return '<!doctype html><html><head><meta charset="utf-8"><title>t</title></head><body>' . $body . '</body></html>';
	}
}
