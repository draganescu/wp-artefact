<?php
/**
 * The `wp-artifacts/revisions` ability.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\Storage\Manifest;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the revisions of an artifact.
 */
final class Revisions implements Ability {

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'List artifact revisions', 'wp-artifacts' ),
			'description'         => __( 'List the stored revisions of an artifact, newest first. Pass a revision_id to wp-artifacts/rollback to restore one.', 'wp-artifacts' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'Artifact ID.', 'wp-artifacts' ),
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'items' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'properties'           => array(
								'revision_id' => array( 'type' => 'integer' ),
								'date'        => array( 'type' => 'string' ),
								'author'      => array( 'type' => 'string' ),
								'bytes'       => array( 'type' => 'integer' ),
								'current'     => array( 'type' => 'boolean' ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( self::class, 'execute' ),
			'permission_callback' => array( Registrar::class, 'can_read' ),
			'category'            => Registrar::CATEGORY,
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array( 'type' => 'tool' ),
				'annotations'  => array(
					'title'      => __( 'List artifact revisions', 'wp-artifacts' ),
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		);
	}

	/**
	 * Lists revisions.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute( $input = array() ) {
		$input = (array) $input;

		$post = Registrar::resolve_readable( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$items = array();

		foreach ( wp_get_post_revisions( (int) $post->ID, array( 'numberposts' => -1 ) ) as $revision ) {
			$manifest = Manifest::from_meta( get_post_meta( (int) $revision->ID, ArtifactPostType::META_MANIFEST, true ) );

			$items[] = array(
				'revision_id' => (int) $revision->ID,
				'date'        => (string) get_post_time( 'c', true, $revision ),
				'author'      => (string) get_the_author_meta( 'display_name', (int) $revision->post_author ),
				'bytes'       => $manifest->count() > 0 ? $manifest->total_bytes() : strlen( (string) $revision->post_content ),
				'sha256'      => hash( 'sha256', (string) $revision->post_content ),
				'current'     => (string) $revision->post_content === (string) $post->post_content,
			);
		}

		return array(
			'id'    => (int) $post->ID,
			'items' => $items,
		);
	}
}
