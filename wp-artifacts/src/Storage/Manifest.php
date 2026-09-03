<?php
/**
 * Bundle manifest value object.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Storage;

use WPArtifacts\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable description of the files that belong to one artifact revision.
 *
 * The manifest is the source of truth for what may be served: a request for a
 * path that is not listed here is a 404 even when the file exists on disk.
 */
final class Manifest {

	public const PATH_PATTERN = '#^[A-Za-z0-9._\-/]+$#';

	/**
	 * Entry document path.
	 *
	 * @var string
	 */
	private string $entry;

	/**
	 * Files keyed by relative path.
	 *
	 * @var array<string,array{path:string,mime:string,bytes:int,sha256:string}>
	 */
	private array $files;

	/**
	 * Constructor.
	 *
	 * @param string                                                               $entry Entry document path.
	 * @param array<string,array{path:string,mime:string,bytes:int,sha256:string}> $files Files keyed by path.
	 */
	private function __construct( string $entry, array $files ) {
		$this->entry = $entry;
		$this->files = $files;
	}

	/**
	 * An empty manifest for an artifact with no assets.
	 *
	 * @param string $entry Entry document path.
	 * @return Manifest
	 */
	public static function empty_manifest( string $entry = 'index.html' ): Manifest {
		return new self( $entry, array() );
	}

	/**
	 * Rehydrates a manifest from stored meta.
	 *
	 * @param mixed $data Stored value.
	 * @return Manifest
	 */
	public static function from_meta( $data ): Manifest {
		if ( ! is_array( $data ) ) {
			return self::empty_manifest();
		}

		$entry = isset( $data['entry'] ) && is_string( $data['entry'] ) ? $data['entry'] : 'index.html';
		$files = array();

		if ( isset( $data['files'] ) && is_array( $data['files'] ) ) {
			foreach ( $data['files'] as $file ) {
				if ( ! is_array( $file ) || empty( $file['path'] ) ) {
					continue;
				}
				$path           = (string) $file['path'];
				$files[ $path ] = array(
					'path'   => $path,
					'mime'   => isset( $file['mime'] ) ? (string) $file['mime'] : 'application/octet-stream',
					'bytes'  => isset( $file['bytes'] ) ? (int) $file['bytes'] : 0,
					'sha256' => isset( $file['sha256'] ) ? (string) $file['sha256'] : '',
				);
			}
		}

		return new self( $entry, $files );
	}

	/**
	 * Builds a manifest from decoded upload payloads and validates the whole set.
	 *
	 * @param string                                                $entry         Entry document path.
	 * @param array<int,array{path:string,mime:string,data:string}> $payloads      Files with raw bytes.
	 * @param string                                                $entry_content The entry document itself.
	 * @param string                                                $entry_mime    MIME type of the entry document.
	 * @return Manifest|WP_Error
	 */
	public static function build( string $entry, array $payloads, string $entry_content, string $entry_mime = 'text/html' ) {
		$entry = self::normalize_path( $entry );

		$entry_check = self::validate_path( $entry );
		if ( is_wp_error( $entry_check ) ) {
			return $entry_check;
		}

		$max_files  = (int) Settings::get( 'max_files', 200 );
		$max_asset  = (int) Settings::get( 'max_asset_bytes', 10485760 );
		$max_bundle = (int) Settings::get( 'max_bundle_bytes', 52428800 );

		if ( count( $payloads ) > $max_files ) {
			return new WP_Error(
				'artifact_too_large',
				sprintf(
					/* translators: 1: number of files sent, 2: maximum number of files. */
					__( 'The bundle has %1$d files; this site allows at most %2$d. Remove files or inline them into the entry document.', 'wp-artifacts' ),
					count( $payloads ),
					$max_files
				),
				array( 'status' => 400 )
			);
		}

		$files = array();
		$total = strlen( $entry_content );

		foreach ( $payloads as $payload ) {
			$path = self::normalize_path( isset( $payload['path'] ) ? (string) $payload['path'] : '' );

			$path_check = self::validate_path( $path );
			if ( is_wp_error( $path_check ) ) {
				return $path_check;
			}

			if ( isset( $files[ $path ] ) ) {
				return new WP_Error(
					'artifact_invalid_path',
					sprintf(
						/* translators: %s: duplicated relative path. */
						__( 'File "%s" appears twice in the bundle. Each path must be unique.', 'wp-artifacts' ),
						$path
					),
					array( 'status' => 400 )
				);
			}

			$data  = isset( $payload['data'] ) ? (string) $payload['data'] : '';
			$bytes = strlen( $data );

			if ( $bytes > $max_asset ) {
				return new WP_Error(
					'artifact_too_large',
					sprintf(
						/* translators: 1: relative path, 2: file size, 3: allowed size. */
						__( 'File "%1$s" is %2$s; the per-file limit is %3$s.', 'wp-artifacts' ),
						$path,
						size_format( $bytes ),
						size_format( $max_asset )
					),
					array( 'status' => 400 )
				);
			}

			$mime       = isset( $payload['mime'] ) && '' !== $payload['mime'] ? (string) $payload['mime'] : self::guess_mime( $path );
			$mime_check = self::validate_mime( $path, $mime );
			if ( is_wp_error( $mime_check ) ) {
				return $mime_check;
			}

			$total += $bytes;

			$files[ $path ] = array(
				'path'   => $path,
				'mime'   => $mime,
				'bytes'  => $bytes,
				'sha256' => hash( 'sha256', $data ),
			);
		}

		if ( $total > $max_bundle ) {
			return new WP_Error(
				'artifact_too_large',
				sprintf(
					/* translators: 1: bundle size, 2: allowed size. */
					__( 'The bundle is %1$s; the limit is %2$s.', 'wp-artifacts' ),
					size_format( $total ),
					size_format( $max_bundle )
				),
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $files ) ) {
			// The manifest must always list its own entry document. The entry lives in
			// post_content rather than on disk, so its record is derived here.
			$files[ $entry ] = array(
				'path'   => $entry,
				'mime'   => strtolower( trim( explode( ';', $entry_mime )[0] ) ),
				'bytes'  => strlen( $entry_content ),
				'sha256' => hash( 'sha256', $entry_content ),
			);
		}

		return new self( $entry, $files );
	}

	/**
	 * Normalizes a relative path for comparison.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	public static function normalize_path( string $path ): string {
		$path = trim( $path );
		$path = ltrim( $path, '/' );

		return $path;
	}

	/**
	 * Validates a single relative path.
	 *
	 * @param string $path Relative path.
	 * @return true|WP_Error
	 */
	public static function validate_path( string $path ) {
		$error = static function ( string $reason ) use ( $path ) {
			return new WP_Error(
				'artifact_invalid_path',
				sprintf(
					/* translators: 1: relative path, 2: reason it was rejected. */
					__( 'File path "%1$s" was rejected: %2$s Use forward-slash relative paths such as "css/app.css".', 'wp-artifacts' ),
					$path,
					$reason
				),
				array( 'status' => 400 )
			);
		};

		if ( '' === $path ) {
			return $error( __( 'it is empty.', 'wp-artifacts' ) );
		}

		if ( strlen( $path ) > 255 ) {
			return $error( __( 'it is longer than 255 characters.', 'wp-artifacts' ) );
		}

		if ( false !== strpos( $path, '\\' ) ) {
			return $error( __( 'it contains a backslash.', 'wp-artifacts' ) );
		}

		if ( false !== strpos( $path, "\0" ) ) {
			return $error( __( 'it contains a null byte.', 'wp-artifacts' ) );
		}

		if ( ! preg_match( self::PATH_PATTERN, $path ) ) {
			return $error( __( 'it contains characters outside A-Z a-z 0-9 . _ - /.', 'wp-artifacts' ) );
		}

		if ( '/' === $path[0] ) {
			return $error( __( 'it is absolute.', 'wp-artifacts' ) );
		}

		if ( preg_match( '#^[A-Za-z]:#', $path ) ) {
			return $error( __( 'it looks like a drive-letter path.', 'wp-artifacts' ) );
		}

		$segments = explode( '/', $path );
		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				return $error( __( 'it contains an empty path segment.', 'wp-artifacts' ) );
			}
			if ( '.' === $segment || '..' === $segment ) {
				return $error( __( 'it contains a relative path segment.', 'wp-artifacts' ) );
			}
		}

		if ( str_ends_with( $path, '.php' ) || false !== strpos( strtolower( $path ), '.php.' ) ) {
			return $error( __( 'PHP files are never stored in a bundle.', 'wp-artifacts' ) );
		}

		return true;
	}

	/**
	 * Validates a MIME type against the allow list.
	 *
	 * @param string $path Relative path, used for the error message.
	 * @param string $mime MIME type.
	 * @return true|WP_Error
	 */
	public static function validate_mime( string $path, string $mime ) {
		$mime  = strtolower( trim( explode( ';', $mime )[0] ) );
		$allow = array_map( 'strtolower', Settings::allowed_mimes() );

		foreach ( $allow as $candidate ) {
			if ( $candidate === $mime ) {
				return true;
			}
			if ( str_ends_with( $candidate, '/*' ) && str_starts_with( $mime, substr( $candidate, 0, -1 ) ) ) {
				return true;
			}
		}

		return new WP_Error(
			'artifact_mime_not_allowed',
			sprintf(
				/* translators: 1: relative path, 2: MIME type, 3: allowed MIME types. */
				__( 'File "%1$s" has MIME type "%2$s", which this site does not accept. Allowed: %3$s.', 'wp-artifacts' ),
				$path,
				$mime,
				implode( ', ', $allow )
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Guesses a MIME type from the file extension.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	public static function guess_mime( string $path ): string {
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		$map = array(
			'css'         => 'text/css',
			'js'          => 'text/javascript',
			'mjs'         => 'text/javascript',
			'json'        => 'application/json',
			'html'        => 'text/html',
			'htm'         => 'text/html',
			'txt'         => 'text/plain',
			'md'          => 'text/plain',
			'csv'         => 'text/plain',
			'svg'         => 'image/svg+xml',
			'png'         => 'image/png',
			'jpg'         => 'image/jpeg',
			'jpeg'        => 'image/jpeg',
			'gif'         => 'image/gif',
			'webp'        => 'image/webp',
			'avif'        => 'image/avif',
			'ico'         => 'image/x-icon',
			'woff'        => 'font/woff',
			'woff2'       => 'font/woff2',
			'ttf'         => 'font/ttf',
			'otf'         => 'font/otf',
			'wasm'        => 'application/wasm',
			'mp4'         => 'video/mp4',
			'mp3'         => 'audio/mpeg',
			'webmanifest' => 'application/manifest+json',
		);

		return $map[ $extension ] ?? 'application/octet-stream';
	}

	/**
	 * Entry document path.
	 *
	 * @return string
	 */
	public function entry(): string {
		return $this->entry;
	}

	/**
	 * Whether the manifest lists a path.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	public function has( string $path ): bool {
		return isset( $this->files[ self::normalize_path( $path ) ] );
	}

	/**
	 * One file record.
	 *
	 * @param string $path Relative path.
	 * @return array{path:string,mime:string,bytes:int,sha256:string}|null
	 */
	public function file( string $path ): ?array {
		return $this->files[ self::normalize_path( $path ) ] ?? null;
	}

	/**
	 * All file records.
	 *
	 * @return array<string,array{path:string,mime:string,bytes:int,sha256:string}>
	 */
	public function files(): array {
		return $this->files;
	}

	/**
	 * Number of files listed.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->files );
	}

	/**
	 * Total bytes of every listed file.
	 *
	 * @return int
	 */
	public function total_bytes(): int {
		$total = 0;
		foreach ( $this->files as $file ) {
			$total += (int) $file['bytes'];
		}

		return $total;
	}

	/**
	 * Serializable representation stored in post meta.
	 *
	 * @return array{entry:string,files:array<int,array{path:string,mime:string,bytes:int,sha256:string}>,total_bytes:int}
	 */
	public function to_array(): array {
		return array(
			'entry'       => $this->entry,
			'files'       => array_values( $this->files ),
			'total_bytes' => $this->total_bytes(),
		);
	}
}
