<?php
/**
 * Site Health tests.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Admin;

use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\Serving\Responder;
use WPArtifacts\Settings;
use WPArtifacts\Storage\ArtifactRepository;
use WPArtifacts\Storage\BundleStore;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Self-tests that answer "is serving actually working on this install?".
 */
final class SiteHealth {

	private const PROBE_SLUG = '__probe__';

	/**
	 * Singleton instance.
	 *
	 * @var SiteHealth|null
	 */
	private static ?SiteHealth $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return SiteHealth
	 */
	public static function instance(): SiteHealth {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wires hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
	}

	/**
	 * Registers the tests.
	 *
	 * @param array<string,array<string,mixed>> $tests Existing tests.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_tests( $tests ) {
		$tests['direct']['wp_artifacts_rewrite'] = array(
			'label' => __( 'Artifact URLs resolve', 'wp-artifacts' ),
			'test'  => array( $this, 'test_rewrite' ),
		);

		$tests['direct']['wp_artifacts_storage'] = array(
			'label' => __( 'Artifact storage is writable and not listable', 'wp-artifacts' ),
			'test'  => array( $this, 'test_storage' ),
		);

		$tests['direct']['wp_artifacts_serving'] = array(
			'label' => __( 'Artifacts are served byte-identical', 'wp-artifacts' ),
			'test'  => array( $this, 'test_serving' ),
		);

		return $tests;
	}

	/*
	---------------------------------------------------------------------
	 * Tests
	 */

	/**
	 * Confirms that the router, not the theme, answers artifact URLs.
	 *
	 * @return array<string,mixed>
	 */
	public function test_rewrite(): array {
		$result = $this->result( 'wp_artifacts_rewrite', __( 'Artifact URLs resolve', 'wp-artifacts' ) );

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'Artifacts need pretty permalinks', 'wp-artifacts' );
			$result['description'] = '<p>' . esc_html__( 'Permalinks are set to "Plain". Entry documents still answer at their query-string URL, but pretty artifact URLs and every bundle asset path need rewrite rules. Choose any other permalink structure under Settings → Permalinks.', 'wp-artifacts' ) . '</p>';
			$result['actions']     = sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'options-permalink.php' ) ),
				esc_html__( 'Open the permalink settings', 'wp-artifacts' )
			);

			return $result;
		}

		$url      = home_url( '/' . Settings::prefix() . '/' . self::PROBE_SLUG . '/' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'The artifact URL probe could not run', 'wp-artifacts' );
			$result['description'] = '<p>' . esc_html(
				sprintf(
					/* translators: %s: error message. */
					__( 'A loopback request to the probe URL failed: %s. This usually means loopback requests are blocked, not that artifacts are broken.', 'wp-artifacts' ),
					$response->get_error_message()
				)
			) . '</p>';

			return $result;
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$marker = (string) wp_remote_retrieve_header( $response, strtolower( Responder::MARKER_HEADER ) );

		if ( 404 === $code && '1' === $marker ) {
			$result['description'] = '<p>' . esc_html(
				sprintf(
					/* translators: %s: probe URL. */
					__( '%s answered with the artifact router, so interception is working.', 'wp-artifacts' ),
					$url
				)
			) . '</p>';

			return $result;
		}

		$result['status']      = 'critical';
		$result['label']       = __( 'Artifact URLs are not being intercepted', 'wp-artifacts' );
		$result['description'] = '<p>' . esc_html(
			sprintf(
				/* translators: 1: probe URL, 2: HTTP status code. */
				__( '%1$s returned %2$d without the X-Artifacts header, which means the theme answered instead of this plugin. Re-save the permalink settings to rebuild the rewrite rules.', 'wp-artifacts' ),
				$url,
				$code
			)
		) . '</p>';
		$result['actions'] = sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'options-permalink.php' ) ),
			esc_html__( 'Open the permalink settings', 'wp-artifacts' )
		);

		return $result;
	}

	/**
	 * Confirms the asset directory is writable and cannot be browsed.
	 *
	 * @return array<string,mixed>
	 */
	public function test_storage(): array {
		$result = $this->result( 'wp_artifacts_storage', __( 'Artifact storage is writable and not listable', 'wp-artifacts' ) );

		$store = BundleStore::instance();
		$base  = $store->base_dir();

		$store->protect_base_dir();

		if ( ! is_dir( $base ) || ! wp_is_writable( $base ) ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'The artifact storage directory is not writable', 'wp-artifacts' );
			$result['description'] = '<p>' . esc_html(
				sprintf(
					/* translators: %s: directory path. */
					__( '%s does not exist or cannot be written to. Bundle assets cannot be stored until that is fixed.', 'wp-artifacts' ),
					$base
				)
			) . '</p>';

			return $result;
		}

		$response = wp_remote_get(
			trailingslashit( $store->base_url() ),
			array(
				'timeout'   => 10,
				'sslverify' => false,
			)
		);

		$body = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );

		if ( '' !== $body && false !== stripos( $body, 'index of' ) ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'The artifact storage directory is browsable', 'wp-artifacts' );
			$result['description'] = '<p>' . esc_html__( 'The web server returns a directory listing for the artifact upload directory. Turn off directory indexes for the uploads directory.', 'wp-artifacts' ) . '</p>';

			return $result;
		}

		$result['description'] = '<p>' . esc_html(
			sprintf(
				/* translators: %s: directory path. */
				__( 'Assets are stored in %s, which is writable and does not list its contents.', 'wp-artifacts' ),
				$base
			)
		) . '</p>';

		return $result;
	}

	/**
	 * Fetches a published artifact and compares the bytes.
	 *
	 * @return array<string,mixed>
	 */
	public function test_serving(): array {
		$result = $this->result( 'wp_artifacts_serving', __( 'Artifacts are served byte-identical', 'wp-artifacts' ) );

		$posts = get_posts(
			array(
				'post_type'        => ArtifactPostType::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);

		if ( empty( $posts ) ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'No published artifact to test with', 'wp-artifacts' );
			$result['description'] = '<p>' . esc_html__( 'Publish an artifact and run this test again to confirm the bytes travel unchanged.', 'wp-artifacts' ) . '</p>';

			return $result;
		}

		$post = $posts[0];
		if ( ! $post instanceof WP_Post ) {
			return $result;
		}

		$url      = ArtifactRepository::instance()->url( $post );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'The serving test could not run', 'wp-artifacts' );
			$result['description'] = '<p>' . esc_html(
				sprintf(
					/* translators: %s: error message. */
					__( 'A loopback request failed: %s.', 'wp-artifacts' ),
					$response->get_error_message()
				)
			) . '</p>';

			return $result;
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$cookies = wp_remote_retrieve_header( $response, 'set-cookie' );
		$issues  = array();

		if ( hash( 'sha256', $body ) !== hash( 'sha256', (string) $post->post_content ) && ! get_post_meta( (int) $post->ID, ArtifactPostType::META_WRAP, true ) ) {
			$issues[] = __( 'the served bytes do not match the stored bytes', 'wp-artifacts' );
		}

		if ( ! empty( $cookies ) ) {
			$issues[] = __( 'the response sets a cookie', 'wp-artifacts' );
		}

		if ( empty( $issues ) ) {
			$result['description'] = '<p>' . esc_html(
				sprintf(
					/* translators: %s: artifact URL. */
					__( '%s came back byte for byte, with no cookies.', 'wp-artifacts' ),
					$url
				)
			) . '</p>';

			return $result;
		}

		$result['status']      = 'critical';
		$result['label']       = __( 'Something is rewriting artifact responses', 'wp-artifacts' );
		$result['description'] = '<p>' . esc_html(
			sprintf(
				/* translators: 1: artifact URL, 2: list of problems. */
				__( 'Fetching %1$s showed that %2$s. A caching layer, a security plugin or a server module is modifying the response.', 'wp-artifacts' ),
				$url,
				implode( __( ', and ', 'wp-artifacts' ), $issues )
			)
		) . '</p>';

		return $result;
	}

	/**
	 * A passing result to start from.
	 *
	 * @param string $test  Test id.
	 * @param string $label Result label.
	 * @return array<string,mixed>
	 */
	private function result( string $test, string $label ): array {
		return array(
			'label'       => $label,
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Artifacts', 'wp-artifacts' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => '',
			'test'        => $test,
		);
	}
}
