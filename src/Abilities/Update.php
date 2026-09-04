<?php
/**
 * The `wp-artifacts/update` ability.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\Storage\ArtifactRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Updates an artifact, always creating a revision.
 */
final class Update implements Ability {

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		$properties = array_merge(
			array(
				'id' => array(
					'type'        => 'integer',
					'description' => __( 'Artifact ID.', 'wp-artifacts' ),
				),
			),
			Registrar::shared_input_properties()
		);

		return array(
			'label'               => __( 'Update an artifact', 'wp-artifacts' ),
			'description'         => __( 'Replace the content and/or the assets of an existing artifact. Every call creates a revision you can roll back to. Sending "files" replaces the whole asset set; omitting it keeps the current assets.', 'wp-artifacts' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'id' ),
				'properties'           => $properties,
			),
			'output_schema'       => Registrar::write_output_schema(),
			'execute_callback'    => array( self::class, 'execute' ),
			'permission_callback' => array( self::class, 'permission' ),
			'category'            => Registrar::CATEGORY,
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array( 'type' => 'tool' ),
				'annotations'  => array(
					'title'       => __( 'Update an artifact', 'wp-artifacts' ),
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		);
	}

	/**
	 * Permission callback.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function permission( $input = array() ) {
		$input = (array) $input;

		$can_write = Registrar::can_write();
		if ( is_wp_error( $can_write ) ) {
			return $can_write;
		}

		if ( empty( $input ) ) {
			return true;
		}

		$post = Registrar::resolve_writable( $input );

		return is_wp_error( $post ) ? $post : true;
	}

	/**
	 * Updates the artifact.
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

		unset( $input['id'] );

		return ArtifactRepository::instance()->update( (int) $post->ID, $input );
	}
}
