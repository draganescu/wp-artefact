<?php
/**
 * Publishing, validation and the unfiltered_html gate.
 *
 * @package WPArtifacts
 */

namespace WPArtifacts\Tests;

use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\Storage\ArtifactRepository;
use WPArtifacts\Storage\BundleStore;

/**
 * Criteria 1, 2, 3 and 5 from the build spec.
 */
final class PublishTest extends ArtifactTestCase {

	/**
	 * The stored bytes equal the sent bytes, scripts and all.
	 *
	 * @return void
	 */
	public function test_content_is_stored_verbatim(): void {
		$content = '<!doctype html><html><body><h1>Hi</h1><script>document.title=\'x\'</script></body></html>';

		$result = $this->publish(
			array(
				'title'   => 'Hello',
				'content' => $content,
				'status'  => 'publish',
			)
		);

		$post = get_post( (int) $result['id'] );

		$this->assertSame( $content, $post->post_content );
		$this->assertSame( hash( 'sha256', $content ), hash( 'sha256', $post->post_content ) );
		$this->assertSame( 'hello', $post->post_name );
		$this->assertStringContainsString( '/a/hello/', $result['url'] );
	}

	/**
	 * Quotes, slashes and unicode survive the round trip.
	 *
	 * @return void
	 */
	public function test_tricky_bytes_survive(): void {
		$content = "<!doctype html>\n<html><body>\n<p>He said \"it's \\\\ 100% done\" — ✅ 日本語</p>\n<pre>a\\nb</pre>\n</body></html>\n";

		$result = $this->publish(
			array(
				'title'   => 'Tricky',
				'content' => $content,
				'status'  => 'publish',
			)
		);

		$this->assertSame( $content, get_post( (int) $result['id'] )->post_content );
	}

	/**
	 * An editor without unfiltered_html cannot store script.
	 *
	 * @return void
	 */
	public function test_script_requires_unfiltered_html(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$user = new \WP_User( $editor );
		$user->remove_cap( 'unfiltered_html' );

		$result = ArtifactRepository::instance()->create(
			array(
				'title'   => 'Scripted',
				'content' => '<!doctype html><html><body><script>alert(1)</script></body></html>',
				'status'  => 'publish',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'artifact_requires_unfiltered_html', $result->get_error_code() );

		$clean = ArtifactRepository::instance()->create(
			array(
				'title'   => 'Clean',
				'content' => $this->document( '<p>no script here</p>' ),
				'status'  => 'publish',
			)
		);

		$this->assertIsArray( $clean );
		$this->assertSame( 'publish', $clean['status'] );
	}

	/**
	 * A .js asset counts as executable content too.
	 *
	 * @return void
	 */
	public function test_js_asset_requires_unfiltered_html(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		( new \WP_User( $editor ) )->remove_cap( 'unfiltered_html' );

		$result = ArtifactRepository::instance()->create(
			array(
				'title'   => 'Bundle',
				'content' => $this->document(),
				'status'  => 'publish',
				'files'   => array(
					array(
						'path'        => 'js/app.js',
						'data_base64' => base64_encode( 'console.log(1)' ),
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'artifact_requires_unfiltered_html', $result->get_error_code() );
		$this->assertStringContainsString( 'js/app.js', $result->get_error_message() );
	}

	/**
	 * Bundle files land on disk and in the manifest.
	 *
	 * @return void
	 */
	public function test_bundle_is_stored_and_manifested(): void {
		$css = 'body{color:red}';
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );

		$result = $this->publish(
			array(
				'title'   => 'Bundle',
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- artifact markup, not site markup.
				'content' => $this->document( '<link rel="stylesheet" href="css/a.css">' ),
				'status'  => 'publish',
				'files'   => array(
					array(
						'path'        => 'css/a.css',
						'data_base64' => base64_encode( $css ),
					),
					array(
						'path'        => 'img/x.png',
						'data_base64' => base64_encode( $png ),
					),
				),
			)
		);

		$repository = ArtifactRepository::instance();
		$manifest   = $repository->manifest( (int) $result['id'] );

		$this->assertTrue( $manifest->has( 'css/a.css' ) );
		$this->assertSame( 'text/css', $manifest->file( 'css/a.css' )['mime'] );
		$this->assertSame( hash( 'sha256', $css ), $manifest->file( 'css/a.css' )['sha256'] );
		$this->assertTrue( $manifest->has( 'index.html' ), 'The manifest lists its own entry document.' );

		$revision = $repository->assets_revision( (int) $result['id'] );
		$this->assertGreaterThan( 0, $revision );

		$path = BundleStore::instance()->file_path( (int) $result['id'], $revision, 'css/a.css' );
		$this->assertNotNull( $path );
		$this->assertSame( $css, file_get_contents( $path ) );
	}

	/**
	 * Path traversal is refused before anything is written.
	 *
	 * @return void
	 */
	public function test_path_traversal_is_rejected(): void {
		foreach ( array( '../wp-config.php', '/etc/passwd', 'a\\b.css', 'css/../../x.css', 'evil.php' ) as $path ) {
			$result = ArtifactRepository::instance()->create(
				array(
					'title'   => 'Bad path',
					'content' => $this->document(),
					'files'   => array(
						array(
							'path'        => $path,
							'data_base64' => base64_encode( 'x' ),
						),
					),
				)
			);

			$this->assertWPError( $result, "Path {$path} should be rejected." );
			$this->assertSame( 'artifact_invalid_path', $result->get_error_code() );
		}
	}

	/**
	 * MIME types outside the allow list are refused.
	 *
	 * @return void
	 */
	public function test_disallowed_mime_is_rejected(): void {
		$result = ArtifactRepository::instance()->create(
			array(
				'title'   => 'Weird',
				'content' => $this->document(),
				'files'   => array(
					array(
						'path'        => 'thing.bin',
						'mime'        => 'application/octet-stream',
						'data_base64' => base64_encode( 'x' ),
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'artifact_mime_not_allowed', $result->get_error_code() );
	}

	/**
	 * The entry document size limit is enforced.
	 *
	 * @return void
	 */
	public function test_entry_size_limit(): void {
		add_filter(
			'wp_artifacts_settings',
			static function ( array $settings ): array {
				$settings['max_entry_bytes'] = 128;

				return $settings;
			}
		);
		\WPArtifacts\Settings::flush();

		$result = ArtifactRepository::instance()->create(
			array(
				'title'   => 'Huge',
				'content' => str_repeat( 'a', 200 ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'artifact_too_large', $result->get_error_code() );
	}

	/**
	 * Private artifacts get a share token that only matches itself.
	 *
	 * @return void
	 */
	public function test_share_token_round_trip(): void {
		$result = $this->publish(
			array(
				'title'   => 'Unlisted',
				'content' => $this->document(),
				'status'  => 'private',
			)
		);

		$repository = ArtifactRepository::instance();
		$post_id    = (int) $result['id'];
		$token      = $repository->share_token( $post_id );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
		$this->assertStringContainsString( 'share=' . $token, $result['share_url'] );

		$rotated = $repository->share_token( $post_id, true );
		$this->assertNotSame( $token, $rotated );
		$this->assertSame( $rotated, get_post_meta( $post_id, ArtifactPostType::META_SHARE_TOKEN, true ) );
	}

	/**
	 * Publishing needs publish_artifacts.
	 *
	 * @return void
	 */
	public function test_publish_capability_is_enforced(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$result = ArtifactRepository::instance()->create(
			array(
				'title'   => 'Nope',
				'content' => $this->document(),
				'status'  => 'publish',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'artifact_forbidden', $result->get_error_code() );
	}
}
