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
	 * Tags that can execute, navigate or load code no matter what they contain.
	 *
	 * @var array<int,string>
	 */
	private const EXECUTABLE_TAGS = array(
		'SCRIPT',
		'IFRAME',
		'FRAME',
		'FRAMESET',
		'OBJECT',
		'EMBED',
		'APPLET',
		'BASE',
		'PORTAL',
	);

	/**
	 * Attributes whose value is a URL the browser will follow.
	 *
	 * @var array<int,string>
	 */
	private const URL_ATTRIBUTES = array(
		'href',
		'src',
		'srcdoc',
		'action',
		'formaction',
		'data',
		'poster',
		'background',
		'xlink:href',
		'ping',
	);

	/**
	 * URL schemes an artifact may point at.
	 *
	 * @var array<int,string>
	 */
	private const SAFE_SCHEMES = array( 'http', 'https', 'mailto', 'tel', 'ftp', 'sms' );

	/**
	 * Conservative detection of executable content.
	 *
	 * False positives are acceptable, false negatives are not — this is the gate that
	 * decides whether storing a document needs `unfiltered_html`.
	 *
	 * It reads the document with the same HTML5 tokenizer the browser uses, via core's
	 * WP_HTML_Tag_Processor, rather than pattern-matching the raw bytes. A regex over
	 * bytes cannot see what a parser sees: `<svg/onload=…>` and `<img src="x"onerror=…>`
	 * both start an attribute without any preceding whitespace, and an entity-encoded
	 * `&#106;avascript:` is a scheme only after the parser decodes it.
	 *
	 * @param string $content Entry document.
	 * @return string|null The reason executable content was detected, or null.
	 */
	public static function detect_script( string $content ): ?string {
		if ( '' === $content ) {
			return null;
		}

		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			// Fail closed: without a parser there is no way to be sure.
			return __( 'content that cannot be inspected on this version of WordPress', 'wp-artifacts' );
		}

		$processor = new \WP_HTML_Tag_Processor( $content );

		while ( $processor->next_tag() ) {
			$tag = (string) $processor->get_tag();

			if ( in_array( $tag, self::EXECUTABLE_TAGS, true ) ) {
				return sprintf(
					/* translators: %s: an HTML tag name, lowercase. */
					__( 'a <%s> tag', 'wp-artifacts' ),
					strtolower( $tag )
				);
			}

			$handlers = $processor->get_attribute_names_with_prefix( 'on' );
			if ( ! empty( $handlers ) ) {
				return sprintf(
					/* translators: %s: an HTML attribute name. */
					__( 'the inline event handler "%s"', 'wp-artifacts' ),
					(string) $handlers[0]
				);
			}

			if ( 'META' === $tag ) {
				$equiv = $processor->get_attribute( 'http-equiv' );
				if ( is_string( $equiv ) && 'refresh' === strtolower( trim( $equiv ) ) ) {
					return __( 'a <meta http-equiv="refresh"> redirect', 'wp-artifacts' );
				}
			}

			foreach ( self::URL_ATTRIBUTES as $attribute ) {
				$value = $processor->get_attribute( $attribute );

				if ( ! is_string( $value ) ) {
					continue;
				}

				if ( 'srcdoc' === $attribute ) {
					return __( 'an inline srcdoc document', 'wp-artifacts' );
				}

				if ( self::is_dangerous_url( $value ) ) {
					return sprintf(
						/* translators: %s: an HTML attribute name. */
						__( 'a script-bearing URL in "%s"', 'wp-artifacts' ),
						$attribute
					);
				}
			}
		}

		return null;
	}

	/**
	 * Whether a URL a browser would follow can carry code.
	 *
	 * @param string $url Attribute value, still HTML-encoded.
	 * @return bool
	 */
	private static function is_dangerous_url( string $url ): bool {
		// The parser hands back the decoded value, but decode again so a
		// double-encoded scheme cannot slip past, and drop the characters browsers
		// ignore while looking for the colon.
		$candidate = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$candidate = html_entity_decode( $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$candidate = preg_replace( '/[\x00-\x20\x7f]+/', '', $candidate ) ?? '';
		$candidate = ltrim( $candidate );

		if ( ! preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $candidate, $matches ) ) {
			// Relative, protocol-relative or fragment: no scheme, nothing to execute.
			return false;
		}

		$scheme = strtolower( $matches[1] );

		if ( in_array( $scheme, self::SAFE_SCHEMES, true ) ) {
			return false;
		}

		if ( 'data' === $scheme ) {
			// Inline images are how a self-contained artifact ships its assets, but SVG
			// is a document that can carry script.
			return ! preg_match( '#^data:image/(?!svg)#i', $candidate );
		}

		return true;
	}

	/**
	 * Whether a bundle asset counts as executable.
	 *
	 * @param string $path Relative asset path.
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public static function asset_is_executable( string $path, string $mime ): bool {
		$extension  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$executable = array(
			// Runs in the visitor's browser.
			'js',
			'mjs',
			'cjs',
			'jsx',
			'ts',
			'wasm',
			'html',
			'htm',
			'svg',
			'xhtml',
			// Runs on the server if it ever reaches an interpreter. The extension allow
			// list in Manifest already refuses these; this is the second lock.
			'php',
			'php3',
			'php4',
			'php5',
			'php7',
			'php8',
			'phps',
			'phtml',
			'phar',
			'cgi',
			'pl',
			'py',
			'rb',
			'sh',
			'htaccess',
		);

		if ( in_array( $extension, $executable, true ) ) {
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
