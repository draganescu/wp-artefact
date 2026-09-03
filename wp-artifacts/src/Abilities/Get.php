<?php
/**
 * The `wp-artifacts/get` ability.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\Storage\ArtifactRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Reads one artifact.
 */
final class Get implements Ability {

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Get an artifact', 'wp-artifacts' ),
			'description'         => __( 'Read one artifact by ID or slug, optionally including its entry document and its file manifest.', 'wp-artifacts' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'id'              => array(
						'type'        => 'integer',
						'description' => __( 'Artifact ID. Either this or "slug" is required.', 'wp-artifacts' ),
					),
					'slug'            => array(
						'type'        => 'string',
						'description' => __( 'Artifact slug. Either this or "id" is required.', 'wp-artifacts' ),
					),
					'include_content' => array(
						'type'        => 'boolean',
						'description' => __( 'Include the entry document and its sha256. Default true.', 'wp-artifacts' ),
					),
					'include_files'   => array(
						'type'        => 'boolean',
						'description' => __( 'Include the file manifest. Default true.', 'wp-artifacts' ),
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
			'execute_callback'    => array( self::class, 'execute' ),
			'permission_callback' => array( Registrar::class, 'can_read' ),
			'category'            => Registrar::CATEGORY,
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array( 'type' => 'tool' ),
				'annotations'  => array(
					'title'      => __( 'Get an artifact', 'wp-artifacts' ),
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		);
	}

	/**
	 * Reads the artifact.
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

		$include_content = ! array_key_exists( 'include_content', $input ) || (bool) $input['include_content'];
		$include_files   = ! array_key_exists( 'include_files', $input ) || (bool) $input['include_files'];

		$repository = ArtifactRepository::instance();
		$record     = $repository->record( $post, $include_content, current_user_can( 'edit_post', (int) $post->ID ) );

		if ( ! $include_files ) {
			unset( $record['files'] );
		}

		return $record;
	}
}
