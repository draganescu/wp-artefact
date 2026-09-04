<?php
/**
 * Slug changes, parent delivery, visibility and the front page.
 *
 * @package WPArtifacts
 */

namespace WPArtifacts\Tests;

use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\PostType\Statuses;
use WPArtifacts\Serving\ParentDelivery;
use WPArtifacts\Storage\ArtifactRepository;

/**
 * Criteria 5, 6, 7 and 8 from the build spec, at the unit level. The HTTP
 * behaviour itself is covered by the Playwright suite.
 */
final class RoutingTest extends ArtifactTestCase {

	/**
	 * Renaming an artifact records the old slug.
	 *
	 * @return void
	 */
	public function test_slug_change_is_recorded(): void {
		$repository = ArtifactRepository::instance();

		$created = $this->publish(
			array(
				'title'   => 'Original',
				'content' => $this->document(),
				'status'  => 'publish',
				'slug'    => 'original',
			)
		);

		$post_id = (int) $created['id'];
		$repository->update( $post_id, array( 'slug' => 'renamed' ) );

		$this->assertSame( 'renamed', get_post( $post_id )->post_name );
		$this->assertContains( 'original', $repository->old_slugs( $post_id ) );
	}

	/**
	 * Anonymous visitors cannot see a private artifact, but a share token can.
	 *
	 * @return void
	 */
	public function test_private_visibility(): void {
		$created = $this->publish(
			array(
				'title'   => 'Unlisted',
				'content' => $this->document(),
				'status'  => 'private',
			)
		);

		$post = get_post( (int) $created['id'] );

		wp_set_current_user( 0 );
		$this->assertFalse( Statuses::can_view( $post ) );

		$_GET['share'] = ArtifactRepository::instance()->share_token( (int) $post->ID );
		$this->assertTrue( Statuses::can_view( $post ) );

		ArtifactRepository::instance()->share_token( (int) $post->ID, true );
		$this->assertFalse( Statuses::can_view( $post ), 'Rotating the token invalidates the old link.' );

		unset( $_GET['share'] );
	}

	/**
	 * A draft is visible to its editor and to a share link, and to nobody else.
	 *
	 * @return void
	 */
	public function test_draft_visibility(): void {
		$created = $this->publish(
			array(
				'title'   => 'Draft',
				'content' => $this->document(),
				'status'  => 'draft',
			)
		);

		$post = get_post( (int) $created['id'] );

		$this->assertTrue( Statuses::can_view( $post ) );

		wp_set_current_user( 0 );
		$this->assertFalse( Statuses::can_view( $post ) );

		$_GET['share'] = ArtifactRepository::instance()->share_token( (int) $post->ID );
		$this->assertTrue( Statuses::can_view( $post ) );
		unset( $_GET['share'] );
	}

	/**
	 * Parent delivery resolves the artifact from the parent page.
	 *
	 * @return void
	 */
	public function test_parent_delivery_resolution(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About',
				'post_status' => 'publish',
			)
		);

		$created = $this->publish(
			array(
				'title'              => 'About, immersive',
				'content'            => $this->document( '<p>immersive</p>' ),
				'status'             => 'publish',
				'parent_id'          => $page_id,
				'deliver_for_parent' => true,
			)
		);

		$this->assertSame( (int) $created['id'], (int) get_post_meta( $page_id, ArtifactPostType::META_DELIVER_FOR_PARENT, true ) );

		$resolved = ParentDelivery::instance()->artifact_for( get_post( $page_id ) );
		$this->assertInstanceOf( \WP_Post::class, $resolved );
		$this->assertSame( (int) $created['id'], (int) $resolved->ID );

		ArtifactRepository::instance()->update( (int) $created['id'], array( 'deliver_for_parent' => false ) );
		$this->assertNull( ParentDelivery::instance()->artifact_for( get_post( $page_id ) ) );

		$forced = ParentDelivery::instance()->artifact_for( get_post( $page_id ), true );
		$this->assertInstanceOf( \WP_Post::class, $forced, '?artifact_preview=1 previews the artifact even with the flag off.' );
	}

	/**
	 * Deleting the artifact clears the parent's delivery flag.
	 *
	 * @return void
	 */
	public function test_parent_flag_is_cleaned_up(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$created = $this->publish(
			array(
				'title'              => 'Temp',
				'content'            => $this->document(),
				'status'             => 'publish',
				'parent_id'          => $page_id,
				'deliver_for_parent' => true,
			)
		);

		ArtifactRepository::instance()->delete( (int) $created['id'], true );

		$this->assertSame( '', (string) get_post_meta( $page_id, ArtifactPostType::META_DELIVER_FOR_PARENT, true ) );
	}

	/**
	 * An artifact can be made the front page.
	 *
	 * @return void
	 */
	public function test_set_front_page(): void {
		$created = $this->publish(
			array(
				'title'   => 'Home',
				'content' => $this->document( '<p>home</p>' ),
				'status'  => 'publish',
			)
		);

		$result = \WPArtifacts\Abilities\SetFrontPage::execute( array( 'id' => (int) $created['id'] ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$this->assertSame( (int) $created['id'], (int) get_option( 'page_on_front' ) );

		\WPArtifacts\Abilities\SetFrontPage::execute(
			array(
				'id'      => (int) $created['id'],
				'restore' => true,
			)
		);

		$this->assertNotSame( (int) $created['id'], (int) get_option( 'page_on_front' ) );
	}

	/**
	 * Deleted artifacts leave a redirect or a 410 behind.
	 *
	 * @return void
	 */
	public function test_delete_records_redirect(): void {
		$created = $this->publish(
			array(
				'title'   => 'Gone soon',
				'content' => $this->document(),
				'status'  => 'publish',
				'slug'    => 'gone-soon',
			)
		);

		ArtifactRepository::instance()->delete( (int) $created['id'], true, 'https://example.com/new-home/' );

		$gone = get_option( 'wp_artifacts_gone', array() );

		$this->assertArrayHasKey( 'gone-soon', $gone );
		$this->assertSame( 'https://example.com/new-home/', $gone['gone-soon'] );
	}
}
