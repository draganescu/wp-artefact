<?php
/**
 * Revisions, rollback and asset retention.
 *
 * @package WPArtifacts
 */

namespace WPArtifacts\Tests;

use WPArtifacts\Storage\ArtifactRepository;
use WPArtifacts\Storage\BundleStore;

/**
 * Criterion 4 from the build spec.
 */
final class RevisionsTest extends ArtifactTestCase {

	/**
	 * Every update adds a revision and keeps the old assets in place.
	 *
	 * @return void
	 */
	public function test_update_creates_revision_and_keeps_old_assets(): void {
		$repository = ArtifactRepository::instance();

		$first = $this->publish(
			array(
				'title'   => 'Versioned',
				'content' => $this->document( '<p>one</p>' ),
				'status'  => 'publish',
				'files'   => array(
					array(
						'path'        => 'css/a.css',
						'data_base64' => base64_encode( 'body{color:red}' ),
					),
				),
			)
		);

		$post_id       = (int) $first['id'];
		$first_content = get_post( $post_id )->post_content;
		$first_rev     = (int) $first['revision_id'];
		$first_dir     = BundleStore::instance()->revision_dir( $post_id, $repository->assets_revision( $post_id ) );

		$this->assertDirectoryExists( $first_dir );

		$second = $repository->update(
			$post_id,
			array(
				'content' => $this->document( '<p>two</p>' ),
				'files'   => array(
					array(
						'path'        => 'css/a.css',
						'data_base64' => base64_encode( 'body{color:blue}' ),
					),
				),
			)
		);

		$this->assertIsArray( $second );
		$this->assertNotSame( $first_rev, (int) $second['revision_id'] );
		$this->assertCount( 2, wp_get_post_revisions( $post_id, array( 'numberposts' => -1 ) ) );
		$this->assertDirectoryExists( $first_dir, 'A previous revision keeps its own asset directory.' );

		$new_dir = BundleStore::instance()->revision_dir( $post_id, $repository->assets_revision( $post_id ) );
		$this->assertNotSame( $first_dir, $new_dir );
		$this->assertSame( 'body{color:blue}', file_get_contents( $new_dir . '/css/a.css' ) );

		$rolled = $repository->rollback( $post_id, $first_rev );
		$this->assertIsArray( $rolled );

		clean_post_cache( $post_id );
		$this->assertSame( $first_content, get_post( $post_id )->post_content );

		$restored_dir = BundleStore::instance()->revision_dir( $post_id, $repository->assets_revision( $post_id ) );
		$this->assertSame( 'body{color:red}', file_get_contents( $restored_dir . '/css/a.css' ) );
	}

	/**
	 * Updating without `files` keeps the current asset set.
	 *
	 * @return void
	 */
	public function test_update_without_files_keeps_assets(): void {
		$repository = ArtifactRepository::instance();

		$created = $this->publish(
			array(
				'title'   => 'Keep assets',
				'content' => $this->document(),
				'status'  => 'publish',
				'files'   => array(
					array(
						'path'        => 'app.css',
						'data_base64' => base64_encode( 'a{}' ),
					),
				),
			)
		);

		$post_id = (int) $created['id'];
		$before  = $repository->assets_revision( $post_id );

		$repository->update( $post_id, array( 'content' => $this->document( '<p>changed</p>' ) ) );

		$this->assertSame( $before, $repository->assets_revision( $post_id ) );
		$this->assertTrue( $repository->manifest( $post_id )->has( 'app.css' ) );
	}

	/**
	 * The manifest travels with the revision.
	 *
	 * @return void
	 */
	public function test_manifest_is_revisioned(): void {
		$repository = ArtifactRepository::instance();

		$created = $this->publish(
			array(
				'title'   => 'Manifested',
				'content' => $this->document(),
				'status'  => 'publish',
				'files'   => array(
					array(
						'path'        => 'one.css',
						'data_base64' => base64_encode( 'a{}' ),
					),
				),
			)
		);

		$post_id     = (int) $created['id'];
		$revision_id = (int) $created['revision_id'];

		$this->assertGreaterThan( 0, $revision_id );
		$this->assertTrue( $repository->manifest( $revision_id )->has( 'one.css' ) );

		$repository->update(
			$post_id,
			array(
				'files' => array(
					array(
						'path'        => 'two.css',
						'data_base64' => base64_encode( 'b{}' ),
					),
				),
			)
		);

		$this->assertFalse( $repository->manifest( $post_id )->has( 'one.css' ) );
		$this->assertTrue( $repository->manifest( $post_id )->has( 'two.css' ) );
		$this->assertTrue( $repository->manifest( $revision_id )->has( 'one.css' ), 'The old revision still knows its own files.' );
	}

	/**
	 * The storage root denies direct access and the path is not guessable.
	 *
	 * @return void
	 */
	public function test_storage_is_not_reachable_by_guessing(): void {
		$store = BundleStore::instance();
		$store->protect_base_dir();

		$htaccess = $store->base_dir() . '/.htaccess';

		$this->assertFileExists( $htaccess );

		$rules = (string) file_get_contents( $htaccess );

		$this->assertStringContainsString( 'Require all denied', $rules );
		$this->assertStringContainsString( 'Deny from all', $rules );
		$this->assertStringContainsString( 'Options -Indexes', $rules );
		$this->assertStringNotContainsString( 'FilesMatch', $rules, 'Everything is denied, not just scripts.' );

		$created = $this->publish(
			array(
				'title'   => 'Private bytes',
				'content' => $this->document(),
				'status'  => 'private',
				'files'   => array(
					array(
						'path'        => 'secret.css',
						'data_base64' => base64_encode( 'body{}' ),
					),
				),
			)
		);

		$post_id = (int) $created['id'];

		// The directory carries a random segment, so it cannot be walked from the
		// post ID alone even where the deny rules are missing.
		$this->assertStringNotContainsString(
			'/artifacts/' . $post_id . '/',
			$store->post_dir( $post_id ) . '/'
		);
		$this->assertMatchesRegularExpression(
			'#/artifacts/' . $post_id . '-[a-f0-9]{32}$#',
			$store->post_dir( $post_id )
		);

		// And it is stable, or the assets of every earlier revision would be lost.
		$this->assertSame( $store->post_dir( $post_id ), $store->post_dir( $post_id ) );
	}

	/**
	 * Deleting an artifact for good removes its stored bundles.
	 *
	 * @return void
	 */
	public function test_force_delete_removes_assets(): void {
		$repository = ArtifactRepository::instance();

		$created = $this->publish(
			array(
				'title'   => 'Doomed',
				'content' => $this->document(),
				'status'  => 'publish',
				'files'   => array(
					array(
						'path'        => 'a.css',
						'data_base64' => base64_encode( 'a{}' ),
					),
				),
			)
		);

		$post_id = (int) $created['id'];
		$dir     = BundleStore::instance()->post_dir( $post_id );

		$this->assertDirectoryExists( $dir );

		$repository->delete( $post_id, true );

		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertNull( get_post( $post_id ) );
	}
}
