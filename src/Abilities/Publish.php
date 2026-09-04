<?php
/**
 * The `wp-artifacts/publish` ability.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\Storage\ArtifactRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Creates an artifact.
 */
final class Publish implements Ability {

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Publish an artifact', 'wp-artifacts' ),
			'description'         => __( 'Store a self-contained document (usually a complete HTML page) as a new artifact and get back its URL. The bytes you send are the bytes visitors receive: no theme, no scripts injected, nothing filtered. Call wp-artifacts/site-style first if the artifact should match the site design.', 'wp-artifacts' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'title', 'content' ),
				'properties'           => Registrar::shared_input_properties(),
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
					'title'       => __( 'Publish an artifact', 'wp-artifacts' ),
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		);
	}

	/**
	 * Creates the artifact.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute( $input = array() ) {
		$input = (array) $input;

		return ArtifactRepository::instance()->create( $input );
	}
}
