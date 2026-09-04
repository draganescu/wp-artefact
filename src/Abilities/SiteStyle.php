<?php
/**
 * The `wp-artifacts/site-style` ability.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\Style\ThemeAnalyzer;

defined( 'ABSPATH' ) || exit;

/**
 * Tells an agent how the site looks.
 */
final class SiteStyle implements Ability {

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Describe the site style', 'wp-artifacts' ),
			'description'         => __( 'Return the palette, fonts, spacing scale, logo, rendered header and footer, and the CSS custom properties of this site, plus prose guidance. Call this before writing an artifact so it looks like it belongs here.', 'wp-artifacts' ),
			'input_schema'        => array(
				// Callers may omit the input entirely; every property is optional.
				'type'                 => array( 'object', 'null' ),
				'additionalProperties' => false,
				'properties'           => array(
					'refresh' => array(
						'type'        => 'boolean',
						'description' => __( 'Bypass the cache and re-read the theme. Default false.', 'wp-artifacts' ),
					),
				),
			),
			'output_schema'       => self::output_schema(),
			'execute_callback'    => array( self::class, 'execute' ),
			'permission_callback' => array( Registrar::class, 'can_read' ),
			'category'            => Registrar::CATEGORY,
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array( 'type' => 'tool' ),
				'annotations'  => array(
					'title'      => __( 'Describe the site style', 'wp-artifacts' ),
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		);
	}

	/**
	 * The shared output schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => array(
				'theme'         => array( 'type' => 'object' ),
				'colors'        => array( 'type' => 'object' ),
				'typography'    => array( 'type' => 'object' ),
				'spacing'       => array( 'type' => 'object' ),
				'shape'         => array( 'type' => 'object' ),
				'logo'          => array( 'type' => 'object' ),
				'site'          => array( 'type' => 'object' ),
				'chrome'        => array( 'type' => 'object' ),
				'css_variables' => array( 'type' => 'string' ),
				'guidance'      => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Builds the style document.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function execute( $input = array() ) {
		$input = (array) $input;

		return ThemeAnalyzer::instance()->style( ! empty( $input['refresh'] ) );
	}
}
