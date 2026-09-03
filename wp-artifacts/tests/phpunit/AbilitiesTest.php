<?php
/**
 * Ability definitions and the REST round trip.
 *
 * @package WPArtifacts
 */

namespace WPArtifacts\Tests;

use WPArtifacts\Abilities\Registrar;
use WPArtifacts\PostType\ArtifactPostType;

/**
 * Criteria 10 and 11 from the build spec, minus the adapter itself.
 */
final class AbilitiesTest extends ArtifactTestCase {

	/**
	 * Every ability declares what the Abilities API and MCP need.
	 *
	 * @return void
	 */
	public function test_every_ability_is_well_formed(): void {
		foreach ( Registrar::abilities() as $slug => $class_name ) {
			$definition = $class_name::definition();

			$this->assertArrayHasKey( 'label', $definition, "{$slug} has a label" );
			$this->assertArrayHasKey( 'description', $definition, "{$slug} has a description" );
			if ( isset( $definition['input_schema'] ) ) {
				$type = $definition['input_schema']['type'];
				$this->assertContains( 'object', (array) $type, "{$slug} takes an object" );
			}
			$this->assertArrayHasKey( 'output_schema', $definition, "{$slug} has an output schema" );
			$this->assertArrayHasKey( 'execute_callback', $definition, "{$slug} has an execute callback" );
			$this->assertArrayHasKey( 'permission_callback', $definition, "{$slug} has a permission callback" );

			$this->assertIsCallable( $definition['execute_callback'], "{$slug} execute callback is callable" );
			$this->assertIsCallable( $definition['permission_callback'], "{$slug} permission callback is callable" );

			$this->assertArrayHasKey( 'category', $definition, "{$slug} declares a category" );
			$this->assertSame( Registrar::CATEGORY, $definition['category'], "{$slug} is in the artifacts category" );

			$this->assertTrue( $definition['meta']['public'], "{$slug} is public" );
			$this->assertTrue( $definition['meta']['show_in_rest'], "{$slug} is callable over REST" );
			$this->assertNotEmpty( $definition['meta']['annotations'], "{$slug} carries annotations" );
		}
	}

	/**
	 * The annotations decide the HTTP method core accepts, so they have to be honest.
	 *
	 * Core maps read-only to GET, destructive-and-idempotent to DELETE, and everything
	 * else to POST.
	 *
	 * @return void
	 */
	public function test_annotations(): void {
		$read_only = array( 'get', 'list', 'revisions', 'site-style', 'site-style-resource', 'guide' );

		foreach ( Registrar::abilities() as $slug => $class_name ) {
			$annotations = $class_name::definition()['meta']['annotations'];

			if ( in_array( $slug, $read_only, true ) ) {
				$this->assertTrue( $annotations['readonly'], "{$slug} is read only" );
				continue;
			}

			$this->assertFalse( $annotations['readonly'], "{$slug} writes something" );
		}

		$delete = \WPArtifacts\Abilities\Delete::definition()['meta']['annotations'];
		$this->assertTrue( $delete['destructive'], 'delete is destructive' );
		$this->assertTrue( $delete['idempotent'], 'delete is idempotent, so core routes it to DELETE' );

		// Only `delete` should end up on the DELETE method.
		foreach ( Registrar::abilities() as $slug => $class_name ) {
			if ( 'delete' === $slug ) {
				continue;
			}
			$annotations = $class_name::definition()['meta']['annotations'];
			$this->assertFalse(
				! empty( $annotations['destructive'] ) && ! empty( $annotations['idempotent'] ),
				"{$slug} would be forced onto the DELETE method"
			);
		}
	}

	/**
	 * The MCP groups cover every registered ability exactly once.
	 *
	 * @return void
	 */
	public function test_mcp_groups_are_complete(): void {
		$groups = Registrar::mcp_groups();
		$listed = array_merge( $groups['tools'], $groups['resources'], $groups['prompts'] );

		$expected = array_map(
			static function ( string $slug ): string {
				return 'wp-artifacts/' . $slug;
			},
			array_keys( Registrar::abilities() )
		);

		sort( $listed );
		sort( $expected );

		$this->assertSame( $expected, $listed );
		$this->assertContains( 'wp-artifacts/site-style-resource', $groups['resources'] );
		$this->assertContains( 'wp-artifacts/guide', $groups['prompts'] );
	}

	/**
	 * Abilities that take no arguments accept being called with nothing.
	 *
	 * @return void
	 */
	public function test_input_free_abilities_accept_null(): void {
		// A declared schema has to allow null; no schema at all already means "no input".
		foreach ( array( 'site-style', 'list' ) as $slug ) {
			$class_name = Registrar::abilities()[ $slug ];
			$type       = (array) $class_name::definition()['input_schema']['type'];

			$this->assertContains( 'null', $type, "{$slug} can be called with no input" );
		}

		foreach ( array( 'guide', 'site-style-resource' ) as $slug ) {
			$class_name = Registrar::abilities()[ $slug ];

			$this->assertArrayNotHasKey(
				'input_schema',
				$class_name::definition(),
				"{$slug} declares no input schema, so the API accepts a null input"
			);
		}
	}

	/**
	 * The site style resource advertises its URI.
	 *
	 * @return void
	 */
	public function test_resource_uri(): void {
		$definition = \WPArtifacts\Abilities\SiteStyleResource::definition();

		$this->assertSame( 'wp://site/style', $definition['meta']['uri'] );
		$this->assertSame( 'resource', $definition['meta']['mcp']['type'] );
	}

	/**
	 * A publish → get → update → rollback → delete cycle works end to end.
	 *
	 * @return void
	 */
	public function test_full_cycle(): void {
		$published = \WPArtifacts\Abilities\Publish::execute(
			array(
				'title'   => 'Cycle',
				'content' => $this->document( '<p>one</p>' ),
				'status'  => 'publish',
			)
		);

		$this->assertIsArray( $published );
		$id = (int) $published['id'];

		$fetched = \WPArtifacts\Abilities\Get::execute( array( 'id' => $id ) );
		$this->assertSame( $this->document( '<p>one</p>' ), $fetched['content'] );

		$updated = \WPArtifacts\Abilities\Update::execute(
			array(
				'id'      => $id,
				'content' => $this->document( '<p>two</p>' ),
			)
		);
		$this->assertIsArray( $updated );

		$revisions = \WPArtifacts\Abilities\Revisions::execute( array( 'id' => $id ) );
		$this->assertGreaterThanOrEqual( 2, count( $revisions['items'] ) );

		$first_revision = (int) end( $revisions['items'] )['revision_id'];

		$rolled = \WPArtifacts\Abilities\Rollback::execute(
			array(
				'id'          => $id,
				'revision_id' => $first_revision,
			)
		);
		$this->assertIsArray( $rolled );

		clean_post_cache( $id );
		$this->assertSame( $this->document( '<p>one</p>' ), get_post( $id )->post_content );

		$deleted = \WPArtifacts\Abilities\Delete::execute(
			array(
				'id'    => $id,
				'force' => true,
			)
		);
		$this->assertTrue( $deleted['deleted'] );
		$this->assertNull( get_post( $id ) );
	}

	/**
	 * `wp/v2/artifacts` round-trips raw content unchanged.
	 *
	 * @return void
	 */
	public function test_rest_raw_round_trip(): void {
		$content = '<!doctype html><html><body><h1>REST</h1><script>var a=1;</script></body></html>';

		$request = new \WP_REST_Request( 'POST', '/wp/v2/artifacts' );
		$request->set_body_params(
			array(
				'title'   => 'Rested',
				'status'  => 'publish',
				'content' => $content,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$id = (int) $response->get_data()['id'];
		$this->assertSame( $content, get_post( $id )->post_content );
		$this->assertSame( ArtifactPostType::POST_TYPE, get_post_type( $id ) );
	}
}
