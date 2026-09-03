<?php
/**
 * The `wp-artifacts/list` ability.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\Storage\ArtifactRepository;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Lists artifacts.
 */
final class ListArtifacts implements Ability {

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'List artifacts', 'wp-artifacts' ),
			'description'         => __( 'List artifacts on this site, newest first, with their URLs, sizes and provenance.', 'wp-artifacts' ),
			'input_schema'        => array(
				// Callers may omit the input entirely; every property is optional.
				'type'                 => array( 'object', 'null' ),
				'additionalProperties' => false,
				'properties'           => array(
					'status'    => array(
						'type'        => 'string',
						'enum'        => array( 'any', 'draft', 'pending', 'publish', 'private', 'future', 'trash' ),
						'description' => __( 'Filter by status. Default "any" for editors, "publish" otherwise.', 'wp-artifacts' ),
					),
					'parent_id' => array(
						'type'        => 'integer',
						'description' => __( 'Only artifacts attached to this post or page.', 'wp-artifacts' ),
					),
					'search'    => array(
						'type'        => 'string',
						'description' => __( 'Free text search over title and content.', 'wp-artifacts' ),
					),
					'page'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Page number, 1 based.', 'wp-artifacts' ),
					),
					'per_page'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => __( 'Results per page, up to 100. Default 20.', 'wp-artifacts' ),
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'items'       => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
					'page'        => array( 'type' => 'integer' ),
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
					'title'      => __( 'List artifacts', 'wp-artifacts' ),
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		);
	}

	/**
	 * Runs the query.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function execute( $input = array() ) {
		$input = (array) $input;

		$can_read_private = current_user_can( 'read_private_artifacts' ) || current_user_can( 'edit_artifacts' );

		$status = isset( $input['status'] ) && '' !== $input['status'] ? (string) $input['status'] : ( $can_read_private ? 'any' : 'publish' );
		if ( ! $can_read_private ) {
			$status = 'publish';
		}

		$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = array(
			'post_type'      => ArtifactPostType::POST_TYPE,
			'post_status'    => 'any' === $status ? array( 'publish', 'private', 'draft', 'pending', 'future' ) : $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( isset( $input['parent_id'] ) && (int) $input['parent_id'] > 0 ) {
			$args['post_parent'] = (int) $input['parent_id'];
		}

		if ( isset( $input['search'] ) && '' !== $input['search'] ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}

		$query      = new WP_Query( $args );
		$repository = ArtifactRepository::instance();
		$items      = array();

		foreach ( $query->posts as $post ) {
			$record = $repository->record( $post, false, current_user_can( 'edit_post', (int) $post->ID ) );

			$items[] = array(
				'id'            => $record['id'],
				'title'         => $record['title'],
				'slug'          => $record['slug'],
				'url'           => $record['url'],
				'status'        => $record['status'],
				'modified'      => $record['modified'],
				'bytes'         => $record['bytes'],
				'file_count'    => $record['file_count'],
				'thumbnail_url' => $record['thumbnail_url'],
				'provenance'    => $record['provenance'],
				'parent_id'     => $record['parent_id'],
				'share_url'     => $record['share_url'] ?? '',
			);
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		);
	}
}
