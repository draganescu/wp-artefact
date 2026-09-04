<?php
/**
 * The `wp-artifacts/set-front-page` ability.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Puts an artifact at the site root.
 */
final class SetFrontPage implements Ability {

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Set an artifact as the front page', 'wp-artifacts' ),
			'description'         => __( 'Serve an artifact at the site root. This changes a site-wide setting, so it needs the manage_options capability. Pass restore=true to put the previous front page back.', 'wp-artifacts' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'Artifact ID.', 'wp-artifacts' ),
					),
					'restore' => array(
						'type'        => 'boolean',
						'description' => __( 'Undo: restore the front page setting this ability replaced.', 'wp-artifacts' ),
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'ok'                => array( 'type' => 'boolean' ),
					'url'               => array( 'type' => 'string' ),
					'previous_front_id' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => array( self::class, 'execute' ),
			'permission_callback' => array( self::class, 'permission' ),
			'category'            => Registrar::CATEGORY,
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array( 'type' => 'tool' ),
				// Not destructive: the previous setting is recorded, and `restore` puts it back.
				'annotations'  => array(
					'title'       => __( 'Set an artifact as the front page', 'wp-artifacts' ),
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		);
	}

	/**
	 * Permission callback.
	 *
	 * Changing the front page is a site-wide setting, so it needs more than
	 * publish_artifacts.
	 *
	 * @return true|WP_Error
	 */
	public static function permission() {
		if ( current_user_can( 'manage_options' ) && current_user_can( 'publish_artifacts' ) ) {
			return true;
		}

		return new WP_Error(
			'artifact_forbidden',
			__( 'Setting the front page changes a site-wide setting and needs the manage_options capability.', 'wp-artifacts' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Applies the setting.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function execute( $input = array() ) {
		$input = (array) $input;

		$post = Registrar::resolve_writable( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! empty( $input['restore'] ) ) {
			$previous = get_option( 'wp_artifacts_previous_front', null );
			if ( is_array( $previous ) ) {
				update_option( 'show_on_front', (string) $previous['show_on_front'] );
				update_option( 'page_on_front', (int) $previous['page_on_front'] );
				delete_option( 'wp_artifacts_previous_front' );
			}

			return array(
				'ok'  => true,
				'url' => (string) home_url( '/' ),
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error(
				'artifact_forbidden',
				__( 'Only a published artifact can be the front page. Publish it first.', 'wp-artifacts' ),
				array( 'status' => 400 )
			);
		}

		$previous_front = (int) get_option( 'page_on_front' );

		if ( ! get_option( 'wp_artifacts_previous_front' ) ) {
			update_option(
				'wp_artifacts_previous_front',
				array(
					'show_on_front' => (string) get_option( 'show_on_front' ),
					'page_on_front' => $previous_front,
				),
				false
			);
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', (int) $post->ID );

		return array(
			'ok'                => true,
			'url'               => (string) home_url( '/' ),
			'previous_front_id' => $previous_front,
		);
	}
}
