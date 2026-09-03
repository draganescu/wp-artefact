<?php
/**
 * Serving an artifact in place of the post or page it represents.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Serving;

use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\PostType\Statuses;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The "immersive version" of an ordinary post.
 *
 * The parent keeps its URL and its indexing rules; only the bytes change.
 */
final class ParentDelivery {

	public const QUERY_ARG = 'artifact';

	/**
	 * Singleton instance.
	 *
	 * @var ParentDelivery|null
	 */
	private static ?ParentDelivery $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return ParentDelivery
	 */
	public static function instance(): ParentDelivery {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wires hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybe_deliver' ), 1 );
	}

	/**
	 * Serves the artifact standing in for the queried post, when there is one.
	 *
	 * @return void
	 */
	public function maybe_deliver(): void {
		if ( ! is_singular( ArtifactPostType::parent_post_types() ) ) {
			return;
		}

		$parent = get_queried_object();
		if ( ! $parent instanceof WP_Post ) {
			return;
		}

		$mode = $this->requested_mode();
		if ( '0' === $mode ) {
			return;
		}

		$artifact = $this->artifact_for( $parent, '1' === $mode );
		if ( ! $artifact instanceof WP_Post ) {
			return;
		}

		if ( ! Statuses::can_view( $artifact ) || Statuses::needs_password( $artifact ) ) {
			return;
		}

		// The parent owns the canonical URL and the indexing rules, so no X-Robots-Tag.
		Responder::instance()->send_entry( $artifact, array( 'robots' => false ) );
	}

	/**
	 * The `artifact` query argument, normalised.
	 *
	 * @return string `1`, `0` or an empty string.
	 */
	private function requested_mode(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_ARG ] ) );

		if ( in_array( $value, array( '0', 'false', 'no' ), true ) ) {
			return '0';
		}

		if ( in_array( $value, array( '1', 'true', 'yes' ), true ) ) {
			return '1';
		}

		return '';
	}

	/**
	 * Resolves the artifact that should stand in for a post.
	 *
	 * @param WP_Post $post   Post or page being requested.
	 * @param bool    $forced Whether `?artifact=1` was used.
	 * @return WP_Post|null
	 */
	public function artifact_for( WP_Post $post, bool $forced = false ): ?WP_Post {
		$artifact_id = (int) get_post_meta( (int) $post->ID, ArtifactPostType::META_DELIVER_FOR_PARENT, true );

		if ( $artifact_id <= 0 && $forced ) {
			$artifact_id = $this->newest_child_artifact( (int) $post->ID );
		}

		if ( $artifact_id <= 0 ) {
			return null;
		}

		$artifact = get_post( $artifact_id );
		if ( ! $artifact instanceof WP_Post || ArtifactPostType::POST_TYPE !== $artifact->post_type ) {
			return null;
		}

		if ( 'publish' !== $artifact->post_status && ! $forced ) {
			return null;
		}

		return $artifact;
	}

	/**
	 * The most recent artifact whose parent is the given post.
	 *
	 * @param int $parent_id Parent post ID.
	 * @return int
	 */
	private function newest_child_artifact( int $parent_id ): int {
		$children = get_posts(
			array(
				'post_type'        => ArtifactPostType::POST_TYPE,
				'post_parent'      => $parent_id,
				'post_status'      => array( 'publish', 'private', 'draft', 'pending' ),
				'numberposts'      => 1,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);

		return empty( $children ) ? 0 : (int) $children[0]->ID;
	}
}
