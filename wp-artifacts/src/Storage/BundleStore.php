<?php
/**
 * On-disk storage for bundle assets.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Storage;

use WPArtifacts\PostType\ArtifactPostType;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes `{uploads}/artifacts/{post_id}/{revision_id}/…`.
 *
 * A revision directory is written once and never mutated afterwards.
 */
final class BundleStore {

	public const DIR_NAME = 'artifacts';

	/**
	 * Singleton instance.
	 *
	 * @var BundleStore|null
	 */
	private static ?BundleStore $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return BundleStore
	 */
	public static function instance(): BundleStore {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Absolute path of the storage root for the current site.
	 *
	 * @return string
	 */
	public function base_dir(): string {
		$uploads = wp_get_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;
	}

	/**
	 * Public URL of the storage root for the current site.
	 *
	 * @return string
	 */
	public function base_url(): string {
		$uploads = wp_get_upload_dir();

		return trailingslashit( $uploads['baseurl'] ) . self::DIR_NAME;
	}

	/**
	 * Directory holding every revision of one artifact.
	 *
	 * The name carries a random segment, so a server that has not been told to deny
	 * direct access to the uploads directory still cannot be walked from
	 * `artifacts/1/`, `artifacts/2/` and so on. A private artifact's bytes are only as
	 * private as the hardest-to-guess thing in front of them.
	 *
	 * @param int $post_id Artifact ID.
	 * @return string
	 */
	public function post_dir( int $post_id ): string {
		return $this->base_dir() . '/' . $post_id . '-' . $this->storage_key( $post_id );
	}

	/**
	 * The random segment of an artifact's storage path, created on first use.
	 *
	 * @param int $post_id Artifact ID.
	 * @return string
	 */
	public function storage_key( int $post_id ): string {
		$key = (string) get_post_meta( $post_id, ArtifactPostType::META_STORAGE_KEY, true );

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			$key = bin2hex( random_bytes( 16 ) );
			update_post_meta( $post_id, ArtifactPostType::META_STORAGE_KEY, $key );
		}

		return $key;
	}

	/**
	 * Directory holding the assets of one revision.
	 *
	 * @param int $post_id     Artifact ID.
	 * @param int $revision_id Revision ID.
	 * @return string
	 */
	public function revision_dir( int $post_id, int $revision_id ): string {
		return $this->post_dir( $post_id ) . '/' . $revision_id;
	}

	/**
	 * Resolves a manifest path to an absolute file path inside a revision directory.
	 *
	 * Returns null when the resolved path escapes the revision directory.
	 *
	 * @param int    $post_id     Artifact ID.
	 * @param int    $revision_id Revision ID.
	 * @param string $path        Relative path from the manifest.
	 * @return string|null
	 */
	public function file_path( int $post_id, int $revision_id, string $path ): ?string {
		$path = Manifest::normalize_path( $path );
		if ( is_wp_error( Manifest::validate_path( $path ) ) ) {
			return null;
		}

		$root = $this->revision_dir( $post_id, $revision_id );
		$full = $root . '/' . $path;

		$real_root = realpath( $root );
		$real_full = realpath( $full );

		if ( false === $real_root || false === $real_full ) {
			return null;
		}

		if ( 0 !== strpos( $real_full, trailingslashit( $real_root ) ) ) {
			return null;
		}

		return $real_full;
	}

	/**
	 * Writes every file of a revision.
	 *
	 * @param int                                                   $post_id     Artifact ID.
	 * @param int                                                   $revision_id Revision ID.
	 * @param array<int,array{path:string,mime:string,data:string}> $payloads    Files with raw bytes.
	 * @return true|WP_Error
	 */
	public function write_revision( int $post_id, int $revision_id, array $payloads ) {
		$dir = $this->revision_dir( $post_id, $revision_id );

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'artifact_storage_unavailable',
				sprintf(
					/* translators: %s: directory path. */
					__( 'Could not create the asset directory "%s". Check that the uploads directory is writable.', 'wp-artifacts' ),
					$dir
				),
				array( 'status' => 500 )
			);
		}

		$this->protect_base_dir();

		foreach ( $payloads as $payload ) {
			$path = Manifest::normalize_path( (string) $payload['path'] );
			if ( is_wp_error( Manifest::validate_path( $path ) ) ) {
				return Manifest::validate_path( $path );
			}

			$target    = $dir . '/' . $path;
			$directory = dirname( $target );

			if ( ! wp_mkdir_p( $directory ) ) {
				return new WP_Error(
					'artifact_storage_unavailable',
					sprintf(
						/* translators: %s: directory path. */
						__( 'Could not create the asset directory "%s".', 'wp-artifacts' ),
						$directory
					),
					array( 'status' => 500 )
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written = file_put_contents( $target, (string) $payload['data'], LOCK_EX );
			if ( false === $written ) {
				return new WP_Error(
					'artifact_storage_unavailable',
					sprintf(
						/* translators: %s: relative path. */
						__( 'Could not write asset "%s" to disk.', 'wp-artifacts' ),
						$path
					),
					array( 'status' => 500 )
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod
			@chmod( $target, (int) ( defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return true;
	}

	/**
	 * Copies one revision directory to another.
	 *
	 * @param int $post_id Artifact ID.
	 * @param int $from    Source revision ID.
	 * @param int $to      Target revision ID.
	 * @return bool
	 */
	public function copy_revision( int $post_id, int $from, int $to ): bool {
		if ( $from === $to ) {
			return true;
		}

		$source = $this->revision_dir( $post_id, $from );
		if ( ! is_dir( $source ) ) {
			return false;
		}

		$target = $this->revision_dir( $post_id, $to );
		if ( ! wp_mkdir_p( $target ) ) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$relative    = substr( $item->getPathname(), strlen( $source ) + 1 );
			$destination = $target . '/' . $relative;

			if ( $item->isDir() ) {
				wp_mkdir_p( $destination );
				continue;
			}

			wp_mkdir_p( dirname( $destination ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			copy( $item->getPathname(), $destination );
		}

		return true;
	}

	/**
	 * Deletes one revision directory.
	 *
	 * @param int $post_id     Artifact ID.
	 * @param int $revision_id Revision ID.
	 * @return void
	 */
	public function delete_revision( int $post_id, int $revision_id ): void {
		$this->rmdir_recursive( $this->revision_dir( $post_id, $revision_id ) );
	}

	/**
	 * Deletes every revision directory of an artifact.
	 *
	 * @param int $post_id Artifact ID.
	 * @return void
	 */
	public function delete_post( int $post_id ): void {
		$this->rmdir_recursive( $this->post_dir( $post_id ) );
	}

	/**
	 * Deletes the whole storage root.
	 *
	 * @return void
	 */
	public function delete_all(): void {
		$this->rmdir_recursive( $this->base_dir() );
	}

	/**
	 * Revision IDs that currently have a directory on disk.
	 *
	 * @param int $post_id Artifact ID.
	 * @return array<int,int>
	 */
	public function stored_revisions( int $post_id ): array {
		$dir = $this->post_dir( $post_id );
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$found = array();
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( ! is_string( $entry ) || ! ctype_digit( $entry ) ) {
				continue;
			}
			$found[] = (int) $entry;
		}

		sort( $found );

		return $found;
	}

	/**
	 * Writes the deny files that keep the storage root from being browsed.
	 *
	 * @return void
	 */
	public function protect_base_dir(): void {
		$base = $this->base_dir();
		if ( ! wp_mkdir_p( $base ) ) {
			return;
		}

		$index = $base . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		// The router is the only thing meant to read these files: it checks the
		// artifact's status and its manifest before serving a byte. Nothing should
		// reach them directly, so deny the lot.
		$rules = "Options -Indexes\n"
			. "<IfModule mod_authz_core.c>\n"
			. "\tRequire all denied\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\tOrder deny,allow\n"
			. "\tDeny from all\n"
			. "</IfModule>\n";

		$htaccess = $base . '/.htaccess';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$current = file_exists( $htaccess ) ? (string) file_get_contents( $htaccess ) : '';

		// Rewritten rather than only created, so a site carrying an older and weaker
		// version of these rules picks up the current ones.
		if ( $current !== $rules ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, $rules );
		}
	}

	/**
	 * Recursively removes a directory that lives inside the storage root.
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private function rmdir_recursive( string $dir ): void {
		$real = realpath( $dir );
		$root = realpath( $this->base_dir() );

		if ( false === $real || ! is_dir( $real ) ) {
			return;
		}

		if ( false !== $root && $real !== $root && 0 !== strpos( $real, trailingslashit( $root ) ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( ! $item instanceof \SplFileInfo ) {
				continue;
			}

			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				continue;
			}
			wp_delete_file( $item->getPathname() );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
