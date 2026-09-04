<?php
/**
 * Request interception for artifact entries and bundle assets.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Serving;

use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\PostType\Statuses;
use WPArtifacts\Settings;
use WPArtifacts\Storage\ArtifactRepository;
use WPArtifacts\Storage\BundleStore;
use WPArtifacts\Storage\Manifest;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Intercepts artifact requests before WordPress builds a page.
 */
final class Router {

	/**
	 * Singleton instance.
	 *
	 * @var Router|null
	 */
	private static ?Router $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Router
	 */
	public static function instance(): Router {
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
		add_action( 'parse_request', array( $this, 'on_parse_request' ), 1 );
		add_action( 'template_redirect', array( $this, 'on_template_redirect' ), 1 );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar' ) );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_cookieless_host' ) );
	}

	/*
	---------------------------------------------------------------------
	 * Request parsing
	 */

	/**
	 * The request path relative to the WordPress home path, decoded and unslashed.
	 *
	 * @return string
	 */
	public function request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = rtrim( $home, '/' );

		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return trim( rawurldecode( $path ), '/' );
	}

	/**
	 * Handles asset requests, front page artifacts and stale slugs.
	 *
	 * @param \WP $wp Current environment.
	 * @return void
	 */
	public function on_parse_request( $wp ): void {
		unset( $wp );

		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$path = $this->request_path();

		if ( '' === $path ) {
			$this->maybe_serve_front_page();

			return;
		}

		$prefix = Settings::prefix();
		if ( '' === $prefix ) {
			return;
		}

		if ( ! preg_match( '#^' . preg_quote( $prefix, '#' ) . '/([^/]+)(?:/(.*))?$#', $path, $matches ) ) {
			return;
		}

		$slug    = sanitize_title( (string) $matches[1] );
		$subpath = isset( $matches[2] ) ? (string) $matches[2] : '';

		$post = ArtifactRepository::instance()->find_by_slug( $slug );

		if ( ! $post instanceof WP_Post ) {
			$this->handle_unknown_slug( $slug, $subpath );

			return;
		}

		if ( $this->maybe_redirect_to_cookieless_host( $post, $subpath ) ) {
			return;
		}

		if ( '' === $subpath ) {
			// Published artifacts are served from template_redirect, so the main query
			// and canonical redirects still apply. Everything else never reaches the
			// main query at all — WordPress will not resolve a draft or a private post
			// by name for a visitor holding only a share token — so it is served here.
			if ( 'publish' !== $post->post_status ) {
				$this->serve_entry_directly( $post );
			}

			return;
		}

		$this->serve_asset( $post, $subpath );
	}

	/**
	 * Serves the entry document of a singular artifact request.
	 *
	 * @return void
	 */
	public function on_template_redirect(): void {
		if ( ! is_singular( ArtifactPostType::POST_TYPE ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( $this->maybe_redirect_to_cookieless_host( $post, '' ) ) {
			return;
		}

		if ( ! Statuses::can_view( $post ) ) {
			Responder::instance()->send_404( 'not_visible' );

			return;
		}

		if ( 'publish' === $post->post_status && ! is_preview() ) {
			// Interception happens at priority 1, so canonicalisation has to be asked for.
			redirect_canonical();
		}

		if ( Statuses::needs_password( $post ) ) {
			Responder::instance()->send_password_form( $post );

			return;
		}

		Responder::instance()->send_entry( $post );
	}

	/**
	 * Serves a non-public artifact that the main query would never resolve.
	 *
	 * Falls through silently when the request may not see it, so the response is an
	 * ordinary 404 and the artifact's existence does not leak.
	 *
	 * @param WP_Post $post Artifact.
	 * @return void
	 */
	private function serve_entry_directly( WP_Post $post ): void {
		if ( ! Statuses::can_view( $post ) ) {
			return;
		}

		if ( Statuses::needs_password( $post ) ) {
			Responder::instance()->send_password_form( $post );

			return;
		}

		Responder::instance()->send_entry( $post );
	}

	/*
	---------------------------------------------------------------------
	 * Assets
	 */

	/**
	 * Resolves and streams one bundle asset.
	 *
	 * @param WP_Post $post    Artifact.
	 * @param string  $subpath Everything after the slug.
	 * @return void
	 */
	private function serve_asset( WP_Post $post, string $subpath ): void {
		$revision_id = 0;

		if ( preg_match( '#^~r(\d+)/(.+)$#', $subpath, $pinned ) ) {
			$revision_id = (int) $pinned[1];
			$subpath     = (string) $pinned[2];
		}

		$subpath = Manifest::normalize_path( $subpath );
		$subpath = rtrim( $subpath, '/' );

		if ( '' === $subpath ) {
			Responder::instance()->send_404( 'empty_asset_path' );

			return;
		}

		if ( is_wp_error( Manifest::validate_path( $subpath ) ) ) {
			Responder::instance()->send_404( 'invalid_asset_path' );

			return;
		}

		if ( ! Statuses::can_view( $post ) || Statuses::needs_password( $post ) ) {
			Responder::instance()->send_404( 'not_visible' );

			return;
		}

		$repository = ArtifactRepository::instance();
		$immutable  = true;

		if ( $revision_id > 0 ) {
			$revision = wp_get_post_revision( $revision_id );
			if ( ! $revision instanceof WP_Post || (int) $revision->post_parent !== (int) $post->ID ) {
				Responder::instance()->send_404( 'unknown_revision' );

				return;
			}

			$manifest   = $repository->manifest( $revision_id );
			$assets_rev = (int) get_post_meta( $revision_id, ArtifactPostType::META_ASSETS_REV, true );
			$assets_rev = $assets_rev > 0 ? $assets_rev : $revision_id;
		} else {
			$manifest   = $repository->manifest( (int) $post->ID );
			$assets_rev = $repository->assets_revision( (int) $post->ID );
		}

		$file = $manifest->file( $subpath );
		if ( null === $file || $subpath === $manifest->entry() ) {
			// The manifest is the source of truth; the entry document is never an asset.
			Responder::instance()->send_404( 'not_in_manifest' );

			return;
		}

		if ( $assets_rev <= 0 ) {
			Responder::instance()->send_404( 'no_assets_stored' );

			return;
		}

		$file_path = BundleStore::instance()->file_path( (int) $post->ID, $assets_rev, $subpath );
		if ( null === $file_path || ! is_file( $file_path ) ) {
			Responder::instance()->send_404( 'asset_missing' );

			return;
		}

		Responder::instance()->send_asset( $post, $file, $file_path, $immutable );
	}

	/*
	---------------------------------------------------------------------
	 * Redirects
	 */

	/**
	 * Answers requests for a slug that no artifact currently uses.
	 *
	 * @param string $slug    Requested slug.
	 * @param string $subpath Everything after the slug.
	 * @return void
	 */
	private function handle_unknown_slug( string $slug, string $subpath ): void {
		$post = $this->find_by_old_slug( $slug );

		if ( $post instanceof WP_Post ) {
			$target = ArtifactRepository::instance()->url( $post );
			if ( '' !== $subpath ) {
				$target = trailingslashit( $target ) . ltrim( $subpath, '/' );
			}

			Responder::instance()->send_redirect( $target );

			return;
		}

		$gone = get_option( 'wp_artifacts_gone', array() );
		if ( is_array( $gone ) && array_key_exists( $slug, $gone ) ) {
			$redirect_to = (string) $gone[ $slug ];

			if ( '' !== $redirect_to ) {
				Responder::instance()->send_redirect( $redirect_to, false );

				return;
			}

			Responder::instance()->send_410();

			return;
		}

		Responder::instance()->send_404( 'unknown_artifact' );
	}

	/**
	 * Finds the artifact that used to live at a slug.
	 *
	 * @param string $slug Old slug.
	 * @return WP_Post|null
	 */
	private function find_by_old_slug( string $slug ): ?WP_Post {
		if ( '' === $slug ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'      => ArtifactPostType::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => ArtifactPostType::META_OLD_SLUGS,
						'value'   => '"' . $slug . '"',
						'compare' => 'LIKE',
					),
				),
			)
		);

		$posts = $query->get_posts();

		return empty( $posts ) ? null : $posts[0];
	}

	/**
	 * Sends artifact traffic to the configured cookieless host.
	 *
	 * @param WP_Post $post    Artifact.
	 * @param string  $subpath Everything after the slug.
	 * @return bool Whether the request was redirected.
	 */
	private function maybe_redirect_to_cookieless_host( WP_Post $post, string $subpath ): bool {
		$host = (string) Settings::get( 'cookieless_host', '' );
		if ( '' === $host ) {
			return false;
		}

		$current = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) ) : '';
		if ( '' === $current || strtolower( $host ) === $current ) {
			return false;
		}

		$target = ArtifactRepository::instance()->url( $post );
		if ( '' !== $subpath ) {
			$target = trailingslashit( $target ) . ltrim( $subpath, '/' );
		}

		$location = wp_parse_url( $target );
		if ( ! is_array( $location ) || empty( $location['host'] ) || strtolower( (string) $location['host'] ) === $current ) {
			return false;
		}

		Responder::instance()->send_redirect( $target, false );

		return true;
	}

	/*
	---------------------------------------------------------------------
	 * Front page
	 */

	/**
	 * Serves an artifact chosen as the static front page.
	 *
	 * @return void
	 */
	private function maybe_serve_front_page(): void {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return;
		}

		$front_id = (int) get_option( 'page_on_front' );
		if ( $front_id <= 0 || ArtifactPostType::POST_TYPE !== get_post_type( $front_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['artifact'] ) && '0' === (string) $_GET['artifact'] ) {
			return;
		}

		$post = get_post( $front_id );
		if ( ! $post instanceof WP_Post || ! Statuses::can_view( $post ) ) {
			return;
		}

		if ( Statuses::needs_password( $post ) ) {
			Responder::instance()->send_password_form( $post );

			return;
		}

		Responder::instance()->send_entry( $post );
	}

	/*
	---------------------------------------------------------------------
	 * Misc
	 */

	/**
	 * Lets redirects reach the configured cookieless host.
	 *
	 * @param array<int,string> $hosts Allowed hosts.
	 * @return array<int,string>
	 */
	public function allow_cookieless_host( $hosts ) {
		$host = (string) Settings::get( 'cookieless_host', '' );

		if ( '' !== $host ) {
			$hosts[] = $host;
		}

		return $hosts;
	}

	/**
	 * Never shows the admin bar over an artifact.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public function hide_admin_bar( $show ) {
		if ( is_admin() ) {
			return $show;
		}

		if ( is_singular( ArtifactPostType::POST_TYPE ) ) {
			return false;
		}

		return $show;
	}
}
