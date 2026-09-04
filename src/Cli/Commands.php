<?php
/**
 * `wp artifact` commands.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Cli;

use WPArtifacts\Abilities\Guide;
use WPArtifacts\Abilities\ListArtifacts;
use WPArtifacts\Abilities\Revisions;
use WPArtifacts\Abilities\Screenshot;
use WPArtifacts\Storage\ArtifactRepository;
use WPArtifacts\Storage\Manifest;
use WPArtifacts\Style\ThemeAnalyzer;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrappers over the same code the abilities call.
 */
final class Commands {

	/**
	 * Registers the command.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command( 'artifact', self::class );
	}

	/**
	 * Publishes an artifact.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the entry document, or to a directory to publish as a bundle.
	 *
	 * [--title=<title>]
	 * : Title. Defaults to the file name.
	 *
	 * [--status=<status>]
	 * : draft, pending, publish, private or future. Default draft.
	 *
	 * [--slug=<slug>]
	 * : URL slug.
	 *
	 * [--parent=<id>]
	 * : Post or page this artifact represents.
	 *
	 * [--deliver-for-parent]
	 * : Serve the artifact at the parent URL.
	 *
	 * [--indexable]
	 * : Allow search engines to index it.
	 *
	 * [--wrap]
	 * : Serve inside the site header and footer.
	 *
	 * [--porcelain]
	 * : Output just the new artifact ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp artifact publish ./dashboard.html --title="Q3 dashboard" --status=publish
	 *     wp artifact publish ./build --title="Landing page" --status=private
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function publish( array $args, array $assoc_args ): void {
		$path = (string) ( $args[0] ?? '' );

		$payload           = $this->read_source( $path );
		$payload['title']  = (string) ( $assoc_args['title'] ?? basename( $path ) );
		$payload['status'] = (string) ( $assoc_args['status'] ?? 'draft' );

		if ( isset( $assoc_args['slug'] ) ) {
			$payload['slug'] = (string) $assoc_args['slug'];
		}

		if ( isset( $assoc_args['parent'] ) ) {
			$payload['parent_id'] = (int) $assoc_args['parent'];
		}

		$payload['deliver_for_parent'] = isset( $assoc_args['deliver-for-parent'] );
		$payload['indexable']          = isset( $assoc_args['indexable'] );
		$payload['wrap']               = isset( $assoc_args['wrap'] );
		$payload['provenance']         = array( 'tool' => 'wp-cli' );

		$result = ArtifactRepository::instance()->create( $payload );
		$this->bail_on_error( $result );

		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $result['id'] );

			return;
		}

		WP_CLI::success(
			sprintf(
				'Artifact %d published at %s',
				(int) $result['id'],
				(string) $result['url']
			)
		);

		if ( ! empty( $result['warnings'] ) ) {
			foreach ( (array) $result['warnings'] as $warning ) {
				WP_CLI::warning( (string) $warning );
			}
		}
	}

	/**
	 * Updates an artifact.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Artifact ID.
	 *
	 * [<file>]
	 * : New entry document or bundle directory.
	 *
	 * [--status=<status>]
	 * : New status.
	 *
	 * [--title=<title>]
	 * : New title.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function update( array $args, array $assoc_args ): void {
		$id   = (int) ( $args[0] ?? 0 );
		$path = (string) ( $args[1] ?? '' );

		$payload = '' !== $path ? $this->read_source( $path ) : array();

		foreach ( array( 'status', 'title', 'slug' ) as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$payload[ $key ] = (string) $assoc_args[ $key ];
			}
		}

		$result = ArtifactRepository::instance()->update( $id, $payload );
		$this->bail_on_error( $result );

		WP_CLI::success( sprintf( 'Artifact %d updated, revision %d.', $id, (int) $result['revision_id'] ) );
	}

	/**
	 * Lists artifacts.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml or ids. Default table.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function list( array $args, array $assoc_args ): void {
		unset( $args );

		$result = ListArtifacts::execute(
			array(
				'status'   => (string) ( $assoc_args['status'] ?? 'any' ),
				'per_page' => 100,
			)
		);

		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $result['items'], 'id' ) ) );

			return;
		}

		WP_CLI\Utils\format_items(
			$format,
			$result['items'],
			array( 'id', 'title', 'status', 'bytes', 'file_count', 'url' )
		);
	}

	/**
	 * Shows one artifact.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Artifact ID or slug.
	 *
	 * [--content]
	 * : Print the entry document instead of the record.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function get( array $args, array $assoc_args ): void {
		$post = ArtifactRepository::instance()->find( (string) ( $args[0] ?? '' ) );
		$this->bail_on_error( $post );

		if ( isset( $assoc_args['content'] ) ) {
			WP_CLI::line( (string) $post->post_content );

			return;
		}

		WP_CLI::line( (string) wp_json_encode( ArtifactRepository::instance()->record( $post, false, true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Lists the revisions of an artifact.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Artifact ID.
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @return void
	 */
	public function revisions( array $args ): void {
		$result = Revisions::execute( array( 'id' => (int) ( $args[0] ?? 0 ) ) );
		$this->bail_on_error( $result );

		WP_CLI\Utils\format_items( 'table', $result['items'], array( 'revision_id', 'date', 'author', 'bytes', 'current' ) );
	}

	/**
	 * Restores a revision.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Artifact ID.
	 *
	 * <revision>
	 * : Revision ID.
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @return void
	 */
	public function rollback( array $args ): void {
		$result = ArtifactRepository::instance()->rollback( (int) ( $args[0] ?? 0 ), (int) ( $args[1] ?? 0 ) );
		$this->bail_on_error( $result );

		WP_CLI::success( sprintf( 'Rolled back to revision %d.', (int) ( $args[1] ?? 0 ) ) );
	}

	/**
	 * Deletes an artifact.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Artifact ID.
	 *
	 * [--force]
	 * : Skip the trash.
	 *
	 * [--redirect-to=<url>]
	 * : Where the old URL should point.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function delete( array $args, array $assoc_args ): void {
		$result = ArtifactRepository::instance()->delete(
			(int) ( $args[0] ?? 0 ),
			isset( $assoc_args['force'] ),
			(string) ( $assoc_args['redirect-to'] ?? '' )
		);
		$this->bail_on_error( $result );

		WP_CLI::success( sprintf( 'Artifact %d deleted.', (int) ( $args[0] ?? 0 ) ) );
	}

	/**
	 * Prints the share URL of an artifact.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Artifact ID.
	 *
	 * [--regenerate]
	 * : Mint a new token first.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function share( array $args, array $assoc_args ): void {
		$repository = ArtifactRepository::instance();
		$post       = $repository->require_artifact( (int) ( $args[0] ?? 0 ) );
		$this->bail_on_error( $post );

		if ( isset( $assoc_args['regenerate'] ) ) {
			$repository->share_token( (int) $post->ID, true );
		}

		WP_CLI::line( $repository->share_url( $post ) );
	}

	/**
	 * Renders an artifact to a PNG.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Artifact ID.
	 *
	 * [--viewport=<viewport>]
	 * : mobile or desktop. Default desktop.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function screenshot( array $args, array $assoc_args ): void {
		$result = Screenshot::execute(
			array(
				'id'       => (int) ( $args[0] ?? 0 ),
				'viewport' => (string) ( $assoc_args['viewport'] ?? 'desktop' ),
			)
		);
		$this->bail_on_error( $result );

		if ( ! empty( $result['error'] ) ) {
			WP_CLI::error( 'No screenshot provider is configured. Set one under Settings > Artifacts.' );
		}

		WP_CLI::success( sprintf( 'Screenshot stored as attachment %d.', (int) ( $result['attachment_id'] ?? 0 ) ) );
	}

	/**
	 * Prints the site style document agents use.
	 *
	 * [--refresh]
	 * : Bypass the cache.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function style( array $args, array $assoc_args ): void {
		unset( $args );

		WP_CLI::line(
			(string) wp_json_encode(
				ThemeAnalyzer::instance()->style( isset( $assoc_args['refresh'] ) ),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			)
		);
	}

	/**
	 * Prints the agent guide.
	 *
	 * @return void
	 */
	public function guide(): void {
		WP_CLI::line( Guide::markdown() );
	}

	/*
	---------------------------------------------------------------------
	 * Helpers
	 */

	/**
	 * Reads a file or a directory into publish arguments.
	 *
	 * @param string $path File or directory path.
	 * @return array<string,mixed>
	 */
	private function read_source( string $path ): array {
		if ( '' === $path || ! file_exists( $path ) ) {
			WP_CLI::error( sprintf( 'No such file or directory: %s', $path ) );
		}

		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return array( 'content' => (string) file_get_contents( $path ) );
		}

		$root  = rtrim( $path, '/' );
		$entry = '';
		$files = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( ! $item instanceof \SplFileInfo || ! $item->isFile() ) {
				continue;
			}

			$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $root ) + 1 ) );

			if ( str_starts_with( basename( $relative ), '.' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$data = (string) file_get_contents( $item->getPathname() );

			if ( 'index.html' === $relative ) {
				$entry   = $relative;
				$files[] = array(
					'path' => $relative,
					'data' => $data,
				);
				continue;
			}

			$files[] = array(
				'path' => $relative,
				'mime' => Manifest::guess_mime( $relative ),
				'data' => $data,
			);
		}

		if ( '' === $entry ) {
			WP_CLI::error( sprintf( 'No index.html found in %s.', $root ) );
		}

		return array(
			'entry' => $entry,
			'files' => array_map(
				static function ( array $file ): array {
					return array(
						'path'        => $file['path'],
						'mime'        => $file['mime'] ?? Manifest::guess_mime( $file['path'] ),
						'data_base64' => base64_encode( (string) $file['data'] ),
					);
				},
				$files
			),
		);
	}

	/**
	 * Stops the command when a repository call failed.
	 *
	 * @param mixed $result Result to inspect.
	 * @return void
	 */
	private function bail_on_error( $result ): void {
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( sprintf( '[%s] %s', $result->get_error_code(), $result->get_error_message() ) );
		}
	}
}
