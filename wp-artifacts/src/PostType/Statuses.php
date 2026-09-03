<?php
/**
 * Visibility rules and share tokens.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\PostType;

use WPArtifacts\Storage\ArtifactRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "may this request see this artifact?".
 *
 * Non-public artifacts answer 404 rather than 403 so their existence does not leak.
 */
final class Statuses {

	public const QUERY_ARG = 'share';

	/**
	 * The share token on the current request, if any.
	 *
	 * @return string
	 */
	public static function requested_token(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET[ self::QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_ARG ] ) ) : '';

		return preg_match( '/^[a-f0-9]{16,128}$/i', $token ) ? strtolower( $token ) : '';
	}

	/**
	 * Constant-time comparison of a request token with the stored one.
	 *
	 * @param int    $post_id Artifact ID.
	 * @param string $token   Token from the request.
	 * @return bool
	 */
	public static function token_matches( int $post_id, string $token ): bool {
		if ( '' === $token ) {
			return false;
		}

		$stored = (string) get_post_meta( $post_id, ArtifactPostType::META_SHARE_TOKEN, true );
		if ( '' === $stored ) {
			return false;
		}

		return hash_equals( $stored, $token );
	}

	/**
	 * Whether the current request may see an artifact at all.
	 *
	 * @param WP_Post $post Artifact.
	 * @return bool
	 */
	public static function can_view( WP_Post $post ): bool {
		if ( ArtifactPostType::POST_TYPE !== $post->post_type ) {
			return false;
		}

		$post_id = (int) $post->ID;

		switch ( $post->post_status ) {
			case 'publish':
				return true;

			case 'private':
				if ( current_user_can( 'read_private_artifacts' ) || current_user_can( 'edit_post', $post_id ) ) {
					return true;
				}

				return self::token_matches( $post_id, self::requested_token() );

			case 'draft':
			case 'pending':
			case 'future':
				if ( current_user_can( 'edit_post', $post_id ) ) {
					return true;
				}

				return self::token_matches( $post_id, self::requested_token() );

			case 'trash':
			case 'auto-draft':
			default:
				return false;
		}
	}

	/**
	 * Whether the artifact is password protected and the password has not been given.
	 *
	 * @param WP_Post $post Artifact.
	 * @return bool
	 */
	public static function needs_password( WP_Post $post ): bool {
		if ( '' === (string) $post->post_password ) {
			return false;
		}

		if ( current_user_can( 'edit_post', (int) $post->ID ) ) {
			return false;
		}

		if ( self::token_matches( (int) $post->ID, self::requested_token() ) ) {
			return false;
		}

		return post_password_required( $post );
	}

	/**
	 * Whether responses for this artifact may be cached by shared caches.
	 *
	 * @param WP_Post $post Artifact.
	 * @return bool
	 */
	public static function is_public( WP_Post $post ): bool {
		return 'publish' === $post->post_status && '' === (string) $post->post_password;
	}
}
