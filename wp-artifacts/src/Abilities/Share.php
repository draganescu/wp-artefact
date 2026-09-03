<?php
/**
 * The `wp-artifacts/share` ability.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\Storage\ArtifactRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Returns (and optionally rotates) the share link of an artifact.
 */
final class Share implements Ability {

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Get an artifact share link', 'wp-artifacts' ),
			'description'         => __( 'Get the unlisted preview URL of an artifact, which shows drafts and private artifacts to anyone holding the link. Pass regenerate=true to invalidate the old link.', 'wp-artifacts' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'Artifact ID.', 'wp-artifacts' ),
					),
					'regenerate' => array(
						'type'        => 'boolean',
						'description' => __( 'Mint a new token, invalidating every previously shared link. Default false.', 'wp-artifacts' ),
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'share_url' => array( 'type' => 'string' ),
					'id'        => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => array( self::class, 'execute' ),
			'permission_callback' => array( self::class, 'permission' ),
			'category'            => Registrar::CATEGORY,
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array( 'type' => 'tool' ),
				'annotations'  => array(
					'title'       => __( 'Get an artifact share link', 'wp-artifacts' ),
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

		if ( empty( $input ) ) {
			return Registrar::can_read();
		}

		$post = Registrar::resolve_writable( $input );

		return is_wp_error( $post ) ? $post : true;
	}

	/**
	 * Returns the share URL.
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

		$repository = ArtifactRepository::instance();

		if ( ! empty( $input['regenerate'] ) ) {
			$repository->share_token( (int) $post->ID, true );
		}

		return array(
			'id'        => (int) $post->ID,
			'share_url' => $repository->share_url( $post ),
		);
	}
}
