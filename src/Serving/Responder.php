<?php
/**
 * Sends artifact bytes with the right headers and nothing else.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Serving;

use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\PostType\Statuses;
use WPArtifacts\Settings;
use WPArtifacts\Storage\ArtifactRepository;
use WPArtifacts\Style\ThemeAnalyzer;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that writes an artifact response body.
 */
final class Responder {

	public const MARKER_HEADER = 'X-Artifacts';

	/**
	 * Singleton instance.
	 *
	 * @var Responder|null
	 */
	private static ?Responder $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Responder
	 */
	public static function instance(): Responder {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Sends the entry document of an artifact and ends the request.
	 *
	 * @param WP_Post             $post    Artifact.
	 * @param array<string,mixed> $options `robots` (bool) sends X-Robots-Tag, `exit` (bool) ends the request.
	 * @return void
	 */
	public function send_entry( WP_Post $post, array $options = array() ): void {
		$options = array_merge(
			array(
				'robots' => true,
				'exit'   => true,
			),
			$options
		);

		$content      = (string) $post->post_content;
		$content_type = (string) get_post_meta( (int) $post->ID, ArtifactPostType::META_CONTENT_TYPE, true );
		$content_type = '' !== $content_type ? $content_type : ArtifactPostType::DEFAULT_CONTENT_TYPE;

		if ( get_post_meta( (int) $post->ID, ArtifactPostType::META_WRAP, true ) ) {
			$content      = $this->wrap( $post, $content );
			$content_type = ArtifactPostType::DEFAULT_CONTENT_TYPE;
		}

		/**
		 * Filters the bytes sent for an artifact entry document.
		 *
		 * Anything hooked here breaks the byte-identical guarantee; nothing in this
		 * plugin uses it.
		 *
		 * @param string  $content Entry document.
		 * @param WP_Post $post    Artifact.
		 */
		$content = (string) apply_filters( 'wp_artifacts_entry_body', $content, $post );

		$is_public   = Statuses::is_public( $post );
		$revision_id = ArtifactRepository::instance()->assets_revision( (int) $post->ID );
		if ( 0 === $revision_id ) {
			$revision_id = ArtifactRepository::instance()->latest_revision_id( (int) $post->ID );
		}
		$etag = '"rev-' . ( $revision_id > 0 ? $revision_id : (int) $post->ID ) . '-' . substr( hash( 'sha256', $content ), 0, 12 ) . '"';

		$this->begin( $content_type );
		$this->send_common_headers( $post, (bool) $options['robots'] );

		if ( $is_public ) {
			header( 'Cache-Control: public, max-age=0, must-revalidate' );
			header( 'ETag: ' . $etag );
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) get_post_modified_time( 'U', true, $post ) ) . ' GMT' );
		} else {
			nocache_headers();
		}

		if ( $is_public && $this->etag_matches( $etag ) ) {
			status_header( 304 );
			$this->finish( '', (bool) $options['exit'], false );

			return;
		}

		status_header( 200 );
		$this->finish( $content, (bool) $options['exit'] );
	}

	/**
	 * Streams a bundle asset and ends the request.
	 *
	 * @param WP_Post                                                $post      Artifact the asset belongs to.
	 * @param array{path:string,mime:string,bytes:int,sha256:string} $file      Manifest record.
	 * @param string                                                 $file_path Absolute path on disk.
	 * @param bool                                                   $immutable Whether the URL is revision pinned.
	 * @return void
	 */
	public function send_asset( WP_Post $post, array $file, string $file_path, bool $immutable = true ): void {
		$is_public = Statuses::is_public( $post );
		$mime      = '' !== $file['mime'] ? $file['mime'] : 'application/octet-stream';
		$modified  = (int) @filemtime( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$size      = (int) @filesize( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$etag      = '"' . ( '' !== $file['sha256'] ? substr( $file['sha256'], 0, 32 ) : md5( $file_path . (string) $modified ) ) . '"';

		$this->begin( $mime );
		$this->send_common_headers( $post, true );

		if ( $is_public ) {
			$cache = $immutable
				? 'public, max-age=31536000, immutable'
				: 'public, max-age=0, must-revalidate';

			/**
			 * Filters the Cache-Control header sent for bundle assets.
			 *
			 * @param string $cache     Header value.
			 * @param bool   $immutable Whether the URL was revision pinned.
			 * @param array  $file      Manifest record.
			 */
			header( 'Cache-Control: ' . apply_filters( 'wp_artifacts_asset_cache_control', $cache, $immutable, $file ) );
			header( 'ETag: ' . $etag );
			if ( $modified > 0 ) {
				header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
			}
		} else {
			nocache_headers();
		}

		if ( $is_public && $this->etag_matches( $etag ) ) {
			status_header( 304 );
			$this->finish( '', true, false );

			return;
		}

		status_header( 200 );

		if ( $size > 0 ) {
			header( 'Content-Length: ' . $size );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file_path );
		exit;
	}

	/**
	 * Sends a self-contained password form for a protected artifact.
	 *
	 * @param WP_Post $post Artifact.
	 * @return void
	 */
	public function send_password_form( WP_Post $post ): void {
		$action = esc_url( site_url( 'wp-login.php?action=postpass', 'login_post' ) );
		$title  = esc_html( get_the_title( $post ) );
		$label  = esc_html__( 'This artifact is password protected.', 'wp-artifacts' );
		$field  = esc_attr__( 'Password', 'wp-artifacts' );
		$submit = esc_attr__( 'Enter', 'wp-artifacts' );

		$body = <<<HTML
<!doctype html>
<html lang="{$this->language_attribute()}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$title}</title>
<style>
:root { color-scheme: light dark; }
body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 16px/1.5 system-ui, sans-serif; background: Canvas; color: CanvasText; }
form { display: grid; gap: .75rem; width: min(22rem, 90vw); }
h1 { font-size: 1.125rem; margin: 0 0 .5rem; font-weight: 600; }
input, button { font: inherit; padding: .6rem .7rem; border-radius: .4rem; border: 1px solid rgba(127,127,127,.5); background: Canvas; color: CanvasText; }
button { cursor: pointer; font-weight: 600; }
</style>
</head>
<body>
<form action="{$action}" method="post">
<h1>{$label}</h1>
<label for="wp-artifacts-pwd">{$field}</label>
<input id="wp-artifacts-pwd" name="post_password" type="password" autocomplete="current-password" required>
<input type="hidden" name="redirect_to" value="{$this->current_url()}">
<button type="submit">{$submit}</button>
</form>
</body>
</html>
HTML;

		$this->begin( ArtifactPostType::DEFAULT_CONTENT_TYPE );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		nocache_headers();
		status_header( 401 );

		$this->finish( $body, true );
	}

	/**
	 * Sends a 404 that is recognisably ours.
	 *
	 * @param string $reason Short machine-readable reason, sent as a header.
	 * @return void
	 */
	public function send_404( string $reason = 'not_found' ): void {
		$this->begin( 'text/html; charset=utf-8' );
		header( self::MARKER_HEADER . '-Reason: ' . $reason );
		header( 'X-Robots-Tag: noindex, nofollow' );
		nocache_headers();
		status_header( 404 );

		$this->finish( "<!doctype html>\n<title>404</title>\n<p>Not found.</p>\n", true );
	}

	/**
	 * Sends a 410 for a deleted artifact.
	 *
	 * @return void
	 */
	public function send_410(): void {
		$this->begin( 'text/html; charset=utf-8' );
		header( self::MARKER_HEADER . '-Reason: gone' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		nocache_headers();
		status_header( 410 );

		$this->finish( "<!doctype html>\n<title>410</title>\n<p>This artifact is gone.</p>\n", true );
	}

	/**
	 * Sends a permanent redirect.
	 *
	 * @param string $location Target URL.
	 * @param bool   $safe     Whether to restrict the target to allowed hosts. Owner-configured
	 *                         targets (a cookieless host, a redirect_to on a deleted artifact)
	 *                         are deliberately allowed to leave the site.
	 * @return void
	 */
	public function send_redirect( string $location, bool $safe = true ): void {
		$this->begin( 'text/html; charset=utf-8' );
		nocache_headers();

		if ( $safe ) {
			wp_safe_redirect( $location, 301, 'Artifacts' );
		} else {
			wp_redirect( $location, 301, 'Artifacts' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		}

		exit;
	}

	/*
	---------------------------------------------------------------------
	 * Internals
	 */

	/**
	 * Clears anything WordPress already buffered and sets the content type.
	 *
	 * @param string $content_type MIME type to send.
	 * @return void
	 */
	private function begin( string $content_type ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( ! headers_sent() ) {
			header_remove( 'X-Pingback' );
			header_remove( 'Link' );
			header( 'Content-Type: ' . Settings::sanitize_header_value( $content_type ) );
			header( self::MARKER_HEADER . ': 1' );
		}
	}

	/**
	 * Sends the headers every artifact response shares.
	 *
	 * @param WP_Post $post   Artifact.
	 * @param bool    $robots Whether to send X-Robots-Tag.
	 * @return void
	 */
	private function send_common_headers( WP_Post $post, bool $robots ): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );

		if ( $robots && ! get_post_meta( (int) $post->ID, ArtifactPostType::META_INDEXABLE, true ) ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		$csp = $this->resolve_csp( $post );
		if ( '' !== $csp ) {
			header( 'Content-Security-Policy: ' . $csp );
		}
	}

	/**
	 * Resolves the Content-Security-Policy header value for an artifact.
	 *
	 * @param WP_Post $post Artifact.
	 * @return string Empty string when no header should be sent.
	 */
	public function resolve_csp( WP_Post $post ): string {
		$mode = (string) get_post_meta( (int) $post->ID, ArtifactPostType::META_CSP, true );
		$mode = '' !== $mode ? $mode : 'inherit';

		if ( 'inherit' === $mode ) {
			$site_mode = (string) Settings::get( 'csp_mode', 'strict' );
			if ( 'off' === $site_mode ) {
				$value = '';
			} elseif ( 'custom' === $site_mode ) {
				$value = (string) Settings::get( 'csp_custom', '' );
			} else {
				$value = self::strict_csp();
			}
		} elseif ( 'off' === $mode ) {
			$value = '';
		} elseif ( 'strict' === $mode ) {
			$value = self::strict_csp();
		} else {
			$value = $mode;
		}

		/**
		 * Filters the Content-Security-Policy sent with an artifact.
		 *
		 * @param string  $value Header value; empty sends no header.
		 * @param WP_Post $post  Artifact.
		 */
		return Settings::sanitize_header_value( (string) apply_filters( 'wp_artifacts_csp', $value, $post ) );
	}

	/**
	 * The built-in strict policy.
	 *
	 * @return string
	 */
	public static function strict_csp(): string {
		return "default-src 'self' 'unsafe-inline' data: blob:; frame-ancestors 'self'";
	}

	/**
	 * Whether the request carries a matching If-None-Match header.
	 *
	 * @param string $etag Entity tag including quotes.
	 * @return bool
	 */
	private function etag_matches( string $etag ): bool {
		if ( empty( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
			return false;
		}

		$header = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) );

		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = trim( $candidate );
			$candidate = preg_replace( '/^W\//', '', $candidate ) ?? $candidate;

			if ( '*' === $candidate || $candidate === $etag ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Writes the body and ends the request.
	 *
	 * @param string $body           Response body.
	 * @param bool   $should_exit    Whether to end the request.
	 * @param bool   $content_length Whether to send Content-Length.
	 * @return void
	 */
	private function finish( string $body, bool $should_exit, bool $content_length = true ): void {
		if ( $content_length && ! headers_sent() ) {
			header( 'Content-Length: ' . strlen( $body ) );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $body;

		if ( $should_exit ) {
			exit;
		}
	}

	/**
	 * Wraps an artifact in the site chrome. Best effort by design.
	 *
	 * @param WP_Post $post    Artifact.
	 * @param string  $content Entry document.
	 * @return string
	 */
	private function wrap( WP_Post $post, string $content ): string {
		$chrome = ThemeAnalyzer::instance()->chrome();

		$head = '';
		if ( preg_match( '#<head\b[^>]*>(.*?)</head>#is', $content, $matches ) ) {
			$head = (string) $matches[1];
		}

		$body = $content;
		if ( preg_match( '#<body\b[^>]*>(.*?)</body>#is', $content, $matches ) ) {
			$body = (string) $matches[1];
		} elseif ( '' !== $head ) {
			$body = (string) preg_replace( '#<head\b[^>]*>.*?</head>#is', '', $content );
			$body = (string) preg_replace( '#</?(?:html|body)[^>]*>#i', '', $body );
			$body = (string) preg_replace( '#<!doctype[^>]*>#i', '', $body );
		}

		$title = get_the_title( $post );

		$document  = "<!doctype html>\n";
		$document .= '<html lang="' . esc_attr( $this->language_attribute() ) . '" dir="' . esc_attr( is_rtl() ? 'rtl' : 'ltr' ) . '">' . "\n";
		$document .= "<head>\n";
		$document .= '<meta charset="utf-8">' . "\n";
		$document .= '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		$document .= '<title>' . esc_html( $title ) . "</title>\n";

		if ( '' !== $chrome['css'] ) {
			$document .= "<style>\n" . $chrome['css'] . "\n</style>\n";
		}

		$document .= $head;
		$document .= "\n</head>\n<body class=\"wp-artifacts-wrapped\">\n";
		$document .= $chrome['header_html'];
		$document .= '<main class="artifact-wrap">' . "\n" . $body . "\n" . '</main>' . "\n";
		$document .= $chrome['footer_html'];
		$document .= "\n</body>\n</html>\n";

		return $document;
	}

	/**
	 * The site language as an HTML lang attribute value.
	 *
	 * @return string
	 */
	private function language_attribute(): string {
		$language = get_bloginfo( 'language' );

		return '' !== $language ? $language : 'en';
	}

	/**
	 * The current request URL, escaped for an attribute.
	 *
	 * @return string
	 */
	private function current_url(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';

		return esc_url( home_url( $path ) );
	}
}
