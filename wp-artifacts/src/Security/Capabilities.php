<?php
/**
 * Capability mapping and the executable-content gate.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Security;

use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Grants, removes and inspects artifact capabilities.
 */
final class Capabilities {

	/**
	 * Explicit capability map for register_post_type().
	 *
	 * @return array<string,string>
	 */
	public static function post_type_caps(): array {
		return array(
			'edit_post'              => 'edit_artifact',
			'read_post'              => 'read_artifact',
			'delete_post'            => 'delete_artifact',
			'edit_posts'             => 'edit_artifacts',
			'edit_others_posts'      => 'edit_others_artifacts',
			'delete_posts'           => 'delete_artifacts',
			'publish_posts'          => 'publish_artifacts',
			'read_private_posts'     => 'read_private_artifacts',
			'delete_private_posts'   => 'delete_private_artifacts',
			'delete_published_posts' => 'delete_published_artifacts',
			'delete_others_posts'    => 'delete_others_artifacts',
			'edit_private_posts'     => 'edit_private_artifacts',
			'edit_published_posts'   => 'edit_published_artifacts',
			'create_posts'           => 'edit_artifacts',
		);
	}

	/**
	 * Every primitive capability this plugin owns.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array_values( array_unique( array_values( self::post_type_caps() ) ) );
	}

	/**
	 * Capabilities granted to authors (own artifacts only).
	 *
	 * @return array<int,string>
	 */
	public static function author_caps(): array {
		return array(
			'edit_artifacts',
			'delete_artifacts',
			'publish_artifacts',
			'edit_published_artifacts',
			'delete_published_artifacts',
		);
	}

	/**
	 * Wires hooks.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 4 );
		add_action( 'admin_notices', array( self::class, 'unfiltered_html_notice' ) );
	}

	/**
	 * Adds the artifact capabilities to the default roles.
	 *
	 * @return void
	 */
	public static function grant(): void {
		$all = self::all();

		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			foreach ( $all as $cap ) {
				$role->add_cap( $cap );
			}
		}

		$author = get_role( 'author' );
		if ( $author instanceof \WP_Role ) {
			foreach ( self::author_caps() as $cap ) {
				$author->add_cap( $cap );
			}
		}

		$contributor = get_role( 'contributor' );
		if ( $contributor instanceof \WP_Role ) {
			$contributor->add_cap( 'edit_artifacts' );
			$contributor->add_cap( 'delete_artifacts' );
		}
	}

	/**
	 * Removes every artifact capability from every role.
	 *
	 * @return void
	 */
	public static function remove(): void {
		$roles = wp_roles();
		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Maps the singular meta caps that core cannot derive on its own.
	 *
	 * Core handles `edit_artifact` / `delete_artifact` / `read_artifact` through the
	 * capability map above; this only adds the share-token read exception and keeps
	 * `read_artifact` meaningful for private artifacts.
	 *
	 * @param array<int,string> $caps    Primitive caps required.
	 * @param string            $cap     Meta capability being checked.
	 * @param int               $user_id User ID.
	 * @param array<int,mixed>  $args    Extra arguments; args[0] is the post ID.
	 * @return array<int,string>
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( 'read_artifact' !== $cap || empty( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );
		if ( ! $post instanceof \WP_Post || ArtifactPostType::POST_TYPE !== $post->post_type ) {
			return $caps;
		}

		if ( 'publish' === $post->post_status ) {
			return array( 'read' );
		}

		if ( (int) $post->post_author === (int) $user_id && 0 !== (int) $user_id ) {
			return array( 'read' );
		}

		if ( 'private' === $post->post_status ) {
			return array( 'read_private_artifacts' );
		}

		return array( 'edit_artifacts' );
	}

	/**
	 * Whether the current user may store executable content.
	 *
	 * @return bool
	 */
	public static function can_publish_executable(): bool {
		if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
			return false;
		}

		return current_user_can( 'unfiltered_html' );
	}

	/**
	 * Conservative detection of executable content.
	 *
	 * False positives are acceptable, false negatives are not.
	 *
	 * @param string $content Entry document.
	 * @return string|null The reason executable content was detected, or null.
	 */
	public static function detect_script( string $content ): ?string {
		if ( '' === $content ) {
			return null;
		}

		$haystack = strtolower( $content );

		if ( false !== strpos( $haystack, '<script' ) ) {
			return __( 'a <script> tag', 'wp-artifacts' );
		}

		if ( preg_match( '/\son[a-z]+\s*=/i', $content ) ) {
			return __( 'an inline event handler attribute', 'wp-artifacts' );
		}

		if ( preg_match( '/(?:href|src|action|formaction|data|xlink:href)\s*=\s*["\']?\s*javascript:/i', $content ) ) {
			return __( 'a javascript: URL', 'wp-artifacts' );
		}

		if ( false !== strpos( $haystack, '<iframe' ) && preg_match( '/srcdoc\s*=/i', $content ) ) {
			return __( 'an iframe srcdoc document', 'wp-artifacts' );
		}

		if ( preg_match( '/<(?:embed|object)\b/i', $content ) ) {
			return __( 'an <embed> or <object> tag', 'wp-artifacts' );
		}

		return null;
	}

	/**
	 * Whether a bundle asset counts as executable.
	 *
	 * @param string $path Relative asset path.
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public static function asset_is_executable( string $path, string $mime ): bool {
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, array( 'js', 'mjs', 'cjs', 'jsx', 'ts', 'wasm', 'html', 'htm', 'svg' ), true ) ) {
			return true;
		}

		$mime = strtolower( $mime );

		return (
			false !== strpos( $mime, 'javascript' ) ||
			false !== strpos( $mime, 'ecmascript' ) ||
			false !== strpos( $mime, 'wasm' ) ||
			false !== strpos( $mime, 'html' ) ||
			false !== strpos( $mime, 'svg' )
		);
	}

	/**
	 * Shows an admin notice when the current user cannot publish executable artifacts.
	 *
	 * @return void
	 */
	public static function unfiltered_html_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen ) {
			return;
		}

		$relevant = ( ArtifactPostType::POST_TYPE === $screen->post_type ) || ( 'settings_page_wp-artifacts' === $screen->id );
		if ( ! $relevant || self::can_publish_executable() ) {
			return;
		}

		if ( ! current_user_can( 'edit_artifacts' ) ) {
			return;
		}

		$message = __( 'You do not have the <code>unfiltered_html</code> capability, so you can only publish artifacts that contain no scripts. On multisite only super admins have it by default, and the <code>DISALLOW_UNFILTERED_HTML</code> constant removes it for everyone.', 'wp-artifacts' );

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			wp_kses( $message, array( 'code' => array() ) )
		);
	}

	/**
	 * Whether users without `unfiltered_html` may publish script-free artifacts.
	 *
	 * @return bool
	 */
	public static function allow_nonadmin_publish(): bool {
		return (bool) Settings::get( 'allow_nonadmin_publish', true );
	}
}
