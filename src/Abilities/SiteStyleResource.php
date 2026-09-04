<?php
/**
 * The `wp://site/style` MCP resource.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * The same document as SiteStyle, exposed as a resource for clients that read
 * resources rather than calling tools.
 */
final class SiteStyleResource implements Ability {

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Site style', 'wp-artifacts' ),
			'description'         => __( 'Palette, fonts, spacing, logo and site chrome of this WordPress site, as JSON.', 'wp-artifacts' ),
			// No input schema: a resource is read, not called with arguments.
			'output_schema'       => SiteStyle::output_schema(),
			'execute_callback'    => array( SiteStyle::class, 'execute' ),
			'permission_callback' => array( Registrar::class, 'can_read' ),
			'category'            => Registrar::CATEGORY,
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array(
					'type'      => 'resource',
					'uri'       => 'wp://site/style',
					'mime_type' => 'application/json',
					'mimeType'  => 'application/json',
					'name'      => 'site-style',
				),
				'uri'          => 'wp://site/style',
				'annotations'  => array(
					'title'      => __( 'Site style', 'wp-artifacts' ),
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		);
	}
}
