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

		// Reading everyone's unpublished artifacts needs read_private_artifacts.
		// edit_artifacts, which contributors hold, earns you only your own.
		$can_read_all  = current_user_can( 'read_private_artifacts' );
		$can_read_mine = current_user_can( 'edit_artifacts' );

		$mine       = array();
		$non_public = array( 'private', 'draft', 'pending', 'future' );
		$requested  = isset( $input['status'] ) && '' !== $input['status'] ? (string) $input['status'] : 'any';

		$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = array(
			'post_type'      => ArtifactPostType::POST_TYPE,
			'post_status'    => 'any' === $requested ? array_merge( array( 'publish' ), $non_public ) : $requested,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( ! $can_read_all ) {
			if ( ! $can_read_mine ) {
				// No standing to see anything unpublished.
				$args['post_status'] = 'publish';
			} elseif ( 'any' === $requested ) {
				// Everyone's published work, plus this user's own unpublished work.
				// Two separate constraints, so it is stated rather than inferred from
				// WP_Query's status handling.
				$args['post_status'] = 'publish';
				$args['author']      = 0;

				$mine = get_posts(
					array(
						'post_type'        => ArtifactPostType::POST_TYPE,
						'post_status'      => $non_public,
						'author'           => get_current_user_id(),
						'numberposts'      => $per_page,
						'orderby'          => 'modified',
						'order'            => 'DESC',
						'suppress_filters' => false,
					)
				);
			} elseif ( in_array( $requested, $non_public, true ) ) {
				// A specific unpublished status: only the caller's own rows qualify.
				$args['author'] = get_current_user_id();
			}
		}

		if ( isset( $args['author'] ) && 0 === $args['author'] ) {
			unset( $args['author'] );
		}

		if ( isset( $input['parent_id'] ) && (int) $input['parent_id'] > 0 ) {
			$args['post_parent'] = (int) $input['parent_id'];
		}

		if ( isset( $input['search'] ) && '' !== $input['search'] ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}

		$query      = new WP_Query( $args );
		$repository = ArtifactRepository::instance();
		$items      = array();

		$posts = array();
		foreach ( array_merge( $mine, $query->posts ) as $found ) {
			if ( $found instanceof \WP_Post ) {
				$posts[] = $found;
			}
		}

		if ( ! empty( $mine ) ) {
			usort(
				$posts,
				static function ( \WP_Post $a, \WP_Post $b ): int {
					return strcmp( (string) $b->post_modified_gmt, (string) $a->post_modified_gmt );
				}
			);
			$posts = array_slice( $posts, 0, $per_page );
		}

		foreach ( $posts as $post ) {
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
			'total'       => (int) $query->found_posts + count( $mine ),
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		);
	}
}
