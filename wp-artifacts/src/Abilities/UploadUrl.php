<?php
/**
 * The `wp-artifacts/upload-url` ability and the endpoint it hands out.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\Settings;
use WPArtifacts\Storage\ArtifactRepository;
use WPArtifacts\Storage\Manifest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * A one-time signed endpoint that accepts a zip bundle.
 *
 * JSON payloads get expensive past a few megabytes; agents that build real
 * bundles upload a zip here instead.
 */
final class UploadUrl implements Ability {

	public const REST_NAMESPACE   = 'wp-artifacts/v1';
	public const TRANSIENT_PREFIX = 'wp_artifacts_upload_';

	private const DEFAULT_TTL = 900;
	private const MAX_TTL     = 86400;

	/**
	 * Singleton instance.
	 *
	 * @var UploadUrl|null
	 */
	private static ?UploadUrl $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return UploadUrl
	 */
	public static function instance(): UploadUrl {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wires the REST route.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Get a bundle upload URL', 'wp-artifacts' ),
			'description'         => __( 'Mint a one-time URL that accepts a zip archive, for bundles too large to send as base64 JSON. POST the zip as the raw request body. Omit "id" to create a new artifact, pass it to replace an existing one.', 'wp-artifacts' ),
			'input_schema'        => array(
				// Callers may omit the input entirely; every property is optional.
				'type'                 => array( 'object', 'null' ),
				'additionalProperties' => false,
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'Artifact to replace. Omit to create a new one.', 'wp-artifacts' ),
					),
					'expires_in' => array(
						'type'        => 'integer',
						'minimum'     => 60,
						'maximum'     => self::MAX_TTL,
						'description' => __( 'Seconds the URL stays valid. Default 900.', 'wp-artifacts' ),
					),
					'title'      => array(
						'type'        => 'string',
						'description' => __( 'Title for the artifact the upload creates.', 'wp-artifacts' ),
					),
					'status'     => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'pending', 'publish', 'private' ),
						'description' => __( 'Status for the artifact the upload creates. Default draft.', 'wp-artifacts' ),
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'url'        => array( 'type' => 'string' ),
					'method'     => array( 'type' => 'string' ),
					'expires_at' => array( 'type' => 'string' ),
					'max_bytes'  => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => array( self::class, 'execute' ),
			'permission_callback' => array( Registrar::class, 'can_write' ),
			'category'            => Registrar::CATEGORY,
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array( 'type' => 'tool' ),
				'annotations'  => array(
					'title'       => __( 'Get a bundle upload URL', 'wp-artifacts' ),
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		);
	}

	/**
	 * Mints the ticket.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function execute( $input = array() ) {
		$input = (array) $input;

		$artifact_id = isset( $input['id'] ) ? (int) $input['id'] : 0;

		if ( $artifact_id > 0 ) {
			$post = Registrar::resolve_writable( array( 'id' => $artifact_id ) );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
		}

		$ttl = isset( $input['expires_in'] ) ? (int) $input['expires_in'] : self::DEFAULT_TTL;
		$ttl = max( 60, min( self::MAX_TTL, $ttl ) );

		$token = bin2hex( random_bytes( 32 ) );

		set_transient(
			self::TRANSIENT_PREFIX . hash( 'sha256', $token ),
			array(
				'artifact_id' => $artifact_id,
				'user_id'     => get_current_user_id(),
				'title'       => isset( $input['title'] ) ? (string) $input['title'] : '',
				'status'      => isset( $input['status'] ) ? (string) $input['status'] : 'draft',
			),
			$ttl
		);

		return array(
			'url'        => rest_url( self::REST_NAMESPACE . '/upload/' . $token ),
			'method'     => 'POST',
			'expires_at' => gmdate( 'c', time() + $ttl ),
			'max_bytes'  => (int) Settings::get( 'max_bundle_bytes', 52428800 ),
			'hint'       => __( 'POST the zip archive as the raw request body with Content-Type: application/zip. The ticket works once.', 'wp-artifacts' ),
		);
	}

	/**
	 * Registers the upload route.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/upload/(?P<token>[a-f0-9]{64})',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_upload' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Consumes a ticket and stores the uploaded bundle.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_upload( WP_REST_Request $request ) {
		$token  = (string) $request->get_param( 'token' );
		$key    = self::TRANSIENT_PREFIX . hash( 'sha256', $token );
		$ticket = get_transient( $key );

		if ( ! is_array( $ticket ) ) {
			return new WP_Error(
				'artifact_upload_expired',
				__( 'That upload URL has expired or was already used. Ask for a new one with wp-artifacts/upload-url.', 'wp-artifacts' ),
				array( 'status' => 404 )
			);
		}

		delete_transient( $key );

		$user_id = (int) $ticket['user_id'];
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'artifact_forbidden',
				__( 'That upload ticket has no owner.', 'wp-artifacts' ),
				array( 'status' => 403 )
			);
		}

		$previous_user = get_current_user_id();
		wp_set_current_user( $user_id );

		$result = $this->store_upload( $request, $ticket );

		wp_set_current_user( $previous_user );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Unpacks the zip and writes the artifact.
	 *
	 * @param WP_REST_Request     $request Incoming request.
	 * @param array<string,mixed> $ticket  Ticket payload.
	 * @return array<string,mixed>|WP_Error
	 */
	private function store_upload( WP_REST_Request $request, array $ticket ) {
		$body = (string) $request->get_body();

		if ( '' === $body ) {
			$files = $request->get_file_params();
			if ( ! empty( $files['file']['tmp_name'] ) && is_uploaded_file( (string) $files['file']['tmp_name'] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$body = (string) file_get_contents( (string) $files['file']['tmp_name'] );
			}
		}

		if ( '' === $body ) {
			return new WP_Error(
				'artifact_upload_empty',
				__( 'The request body was empty. POST the zip archive as the raw body.', 'wp-artifacts' ),
				array( 'status' => 400 )
			);
		}

		$max = (int) Settings::get( 'max_bundle_bytes', 52428800 );
		if ( strlen( $body ) > $max ) {
			return new WP_Error(
				'artifact_too_large',
				sprintf(
					/* translators: 1: upload size, 2: allowed size. */
					__( 'The upload is %1$s; the limit is %2$s.', 'wp-artifacts' ),
					size_format( strlen( $body ) ),
					size_format( $max )
				),
				array( 'status' => 413 )
			);
		}

		$payloads = $this->extract_zip( $body );
		if ( is_wp_error( $payloads ) ) {
			return $payloads;
		}

		$entry = $this->pick_entry( $payloads );
		if ( '' === $entry ) {
			return new WP_Error(
				'artifact_invalid_payload',
				__( 'The archive has no HTML entry document. Include an index.html at the archive root.', 'wp-artifacts' ),
				array( 'status' => 400 )
			);
		}

		$content = '';
		foreach ( $payloads as $index => $payload ) {
			if ( $payload['path'] === $entry ) {
				$content = $payload['data'];
				unset( $payloads[ $index ] );
			}
		}

		$args = array(
			'entry'   => $entry,
			'content' => $content,
			'files'   => array_map(
				static function ( array $payload ): array {
					return array(
						'path'        => $payload['path'],
						'mime'        => $payload['mime'],
						'data_base64' => base64_encode( $payload['data'] ),
					);
				},
				array_values( $payloads )
			),
		);

		$repository  = ArtifactRepository::instance();
		$artifact_id = (int) $ticket['artifact_id'];

		if ( $artifact_id > 0 ) {
			return $repository->update( $artifact_id, $args );
		}

		$args['title']  = '' !== (string) $ticket['title'] ? (string) $ticket['title'] : __( 'Uploaded artifact', 'wp-artifacts' );
		$args['status'] = (string) $ticket['status'];

		return $repository->create( $args );
	}

	/**
	 * Unzips an archive into path/data payloads.
	 *
	 * @param string $zip_bytes Raw zip archive.
	 * @return array<int,array{path:string,mime:string,data:string}>|WP_Error
	 */
	private function extract_zip( string $zip_bytes ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new WP_Error(
				'artifact_zip_unavailable',
				__( 'This server has no ZipArchive support, so zip uploads are unavailable. Send files as base64 with wp-artifacts/publish instead.', 'wp-artifacts' ),
				array( 'status' => 501 )
			);
		}

		$temp = wp_tempnam( 'wp-artifacts-bundle' );
		if ( ! is_string( $temp ) || '' === $temp ) {
			return new WP_Error(
				'artifact_storage_unavailable',
				__( 'Could not create a temporary file for the upload.', 'wp-artifacts' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $temp, $zip_bytes );

		$zip    = new \ZipArchive();
		$opened = $zip->open( $temp );

		if ( true !== $opened ) {
			wp_delete_file( $temp );

			return new WP_Error(
				'artifact_invalid_payload',
				__( 'The upload is not a readable zip archive.', 'wp-artifacts' ),
				array( 'status' => 400 )
			);
		}

		$max_files  = (int) Settings::get( 'max_files', 200 );
		$payloads   = array();
		$wrapper    = $this->wrapper_dir( $zip );
		$file_count = self::entry_count( $zip );

		for ( $index = 0; $index < $file_count; $index++ ) {
			$name = (string) $zip->getNameIndex( $index );

			if ( '' === $name || str_ends_with( $name, '/' ) ) {
				continue;
			}

			if ( '' !== $wrapper && str_starts_with( $name, $wrapper ) ) {
				$name = substr( $name, strlen( $wrapper ) );
			}

			if ( str_starts_with( basename( $name ), '.' ) || str_contains( $name, '__MACOSX/' ) ) {
				continue;
			}

			$path  = Manifest::normalize_path( $name );
			$check = Manifest::validate_path( $path );
			if ( is_wp_error( $check ) ) {
				$zip->close();
				wp_delete_file( $temp );

				return $check;
			}

			if ( count( $payloads ) >= $max_files ) {
				$zip->close();
				wp_delete_file( $temp );

				return new WP_Error(
					'artifact_too_large',
					sprintf(
						/* translators: %d: maximum number of files. */
						__( 'The archive has more than %d files.', 'wp-artifacts' ),
						$max_files
					),
					array( 'status' => 400 )
				);
			}

			$data = $zip->getFromIndex( $index );
			if ( false === $data ) {
				continue;
			}

			$payloads[] = array(
				'path' => $path,
				'mime' => Manifest::guess_mime( $path ),
				'data' => (string) $data,
			);
		}

		$zip->close();
		wp_delete_file( $temp );

		return $payloads;
	}

	/**
	 * The single wrapping directory an archive has, if it has one.
	 *
	 * Archives made with `zip -r site.zip site/` nest everything one level deep;
	 * that level is not part of the artifact's paths.
	 *
	 * @param \ZipArchive $zip Open archive.
	 * @return string Wrapper prefix including its trailing slash, or an empty string.
	 */
	private function wrapper_dir( \ZipArchive $zip ): string {
		$roots      = array();
		$file_count = self::entry_count( $zip );

		for ( $index = 0; $index < $file_count; $index++ ) {
			$entry = (string) $zip->getNameIndex( $index );
			if ( '' === $entry || str_contains( $entry, '__MACOSX' ) ) {
				continue;
			}

			$segments = explode( '/', trim( $entry, '/' ) );
			if ( count( $segments ) < 2 ) {
				// A file at the archive root means there is no single wrapper.
				return '';
			}

			$roots[ $segments[0] ] = true;
		}

		if ( 1 !== count( $roots ) ) {
			return '';
		}

		$root = (string) array_key_first( $roots );

		return false === strpos( $root, '.' ) ? $root . '/' : '';
	}

	/**
	 * The number of entries in an archive.
	 *
	 * @param \ZipArchive $zip Open archive.
	 * @return int
	 */
	public static function entry_count( \ZipArchive $zip ): int {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive names its own property.
		return (int) $zip->numFiles;
	}

	/**
	 * Picks the entry document out of an extracted archive.
	 *
	 * @param array<int,array{path:string,mime:string,data:string}> $payloads Extracted files.
	 * @return string
	 */
	private function pick_entry( array $payloads ): string {
		$html = array();

		foreach ( $payloads as $payload ) {
			if ( in_array( strtolower( (string) pathinfo( $payload['path'], PATHINFO_EXTENSION ) ), array( 'html', 'htm' ), true ) ) {
				$html[] = $payload['path'];
			}
		}

		if ( empty( $html ) ) {
			return '';
		}

		foreach ( array( 'index.html', 'index.htm' ) as $preferred ) {
			if ( in_array( $preferred, $html, true ) ) {
				return $preferred;
			}
		}

		usort(
			$html,
			static function ( string $a, string $b ): int {
				return substr_count( $a, '/' ) <=> substr_count( $b, '/' );
			}
		);

		return $html[0];
	}
}
