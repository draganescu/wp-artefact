<?php
/**
 * The `wp-artifacts/delete` ability.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\Storage\ArtifactRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Trashes or permanently deletes an artifact.
 */
final class Delete implements Ability {

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Delete an artifact', 'wp-artifacts' ),
			'description'         => __( 'Move an artifact to the trash, or delete it for good with force=true. A deleted URL answers 410, or 301 when redirect_to is given.', 'wp-artifacts' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'Artifact ID.', 'wp-artifacts' ),
					),
					'force'       => array(
						'type'        => 'boolean',
						'description' => __( 'Skip the trash and delete permanently, including every stored asset. Default false.', 'wp-artifacts' ),
					),
					'redirect_to' => array(
						'type'        => 'string',
						'description' => __( 'URL the old artifact URL should redirect to.', 'wp-artifacts' ),
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'deleted' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'forced'  => array( 'type' => 'boolean' ),
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
					'title'       => __( 'Delete an artifact', 'wp-artifacts' ),
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		);
	}

	/**
	 * Permission callback.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return true|WP_Error
	 */
	public static function permission( $input = array() ) {
		$input = (array) $input;

		if ( empty( $input ) ) {
			return current_user_can( 'delete_artifacts' ) ? true : new WP_Error(
				'artifact_forbidden',
				__( 'The current user cannot delete artifacts.', 'wp-artifacts' ),
				array( 'status' => 403 )
			);
		}

		$post = Registrar::resolve_writable( $input, 'delete_post' );

		return is_wp_error( $post ) ? $post : true;
	}

	/**
	 * Deletes the artifact.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function execute( $input = array() ) {
		$input = (array) $input;

		$post = Registrar::resolve_writable( $input, 'delete_post' );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return ArtifactRepository::instance()->delete(
			(int) $post->ID,
			! empty( $input['force'] ),
			isset( $input['redirect_to'] ) ? (string) $input['redirect_to'] : ''
		);
	}
}
