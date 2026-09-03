<?php
/**
 * The `wp-artifacts/rollback` ability.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\Storage\ArtifactRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Restores a stored revision.
 */
final class Rollback implements Ability {

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Roll an artifact back', 'wp-artifacts' ),
			'description'         => __( 'Restore a previous revision of an artifact. The content and the file manifest both go back; the assets of that revision are still on disk.', 'wp-artifacts' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'id', 'revision_id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'Artifact ID.', 'wp-artifacts' ),
					),
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'Revision to restore, from wp-artifacts/revisions.', 'wp-artifacts' ),
					),
				),
			),
			'output_schema'       => Registrar::write_output_schema(),
			'execute_callback'    => array( self::class, 'execute' ),
			'permission_callback' => array( Registrar::class, 'can_write' ),
			'category'            => Registrar::CATEGORY,
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array( 'type' => 'tool' ),
				'annotations'  => array(
					'title'       => __( 'Roll an artifact back', 'wp-artifacts' ),
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		);
	}

	/**
	 * Restores the revision.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute( $input = array() ) {
		$input = (array) $input;

		$post = Registrar::resolve_writable( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return ArtifactRepository::instance()->rollback( (int) $post->ID, isset( $input['revision_id'] ) ? (int) $input['revision_id'] : 0 );
	}
}
