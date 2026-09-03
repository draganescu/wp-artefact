<?php
/**
 * The `wp-artifacts/screenshot` ability.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\Settings;
use WPArtifacts\Storage\ArtifactRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Renders an artifact to an image using whichever provider the site configured.
 */
final class Screenshot implements Ability {

	private const VIEWPORTS = array(
		'mobile'  => array( 390, 844 ),
		'desktop' => array( 1440, 900 ),
	);

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Screenshot an artifact', 'wp-artifacts' ),
			'description'         => __( 'Render an artifact to a PNG using the screenshot provider configured on this site, and use it as the artifact thumbnail when there is none. Returns screenshot_provider_unavailable when no provider is configured.', 'wp-artifacts' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => __( 'Artifact ID.', 'wp-artifacts' ),
					),
					'viewport' => array(
						'type'        => 'string',
						'enum'        => array( 'mobile', 'desktop' ),
						'description' => __( 'Viewport to render at. Default desktop.', 'wp-artifacts' ),
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'image_base64'  => array( 'type' => 'string' ),
					'mime'          => array( 'type' => 'string' ),
					'attachment_id' => array( 'type' => 'integer' ),
					'thumbnail_url' => array( 'type' => 'string' ),
					'error'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( self::class, 'execute' ),
			'permission_callback' => array( Registrar::class, 'can_read' ),
			'category'            => Registrar::CATEGORY,
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array( 'type' => 'tool' ),
				// Not read-only: the render is stored in the media library and becomes the
				// artifact thumbnail when there is not one yet.
				'annotations'  => array(
					'title'       => __( 'Screenshot an artifact', 'wp-artifacts' ),
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		);
	}

	/**
	 * Renders the screenshot.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute( $input = array() ) {
		$input = (array) $input;

		$post = Registrar::resolve_readable( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$viewport = isset( $input['viewport'] ) && isset( self::VIEWPORTS[ (string) $input['viewport'] ] )
			? (string) $input['viewport']
			: 'desktop';

		$image = self::capture( $post, $viewport );

		if ( '' === $image ) {
			return array(
				'error' => 'screenshot_provider_unavailable',
				'hint'  => __( 'Set a screenshot provider under Settings → Artifacts, or set the featured image by hand.', 'wp-artifacts' ),
			);
		}

		$result = array(
			'image_base64' => base64_encode( $image ),
			'mime'         => 'image/png',
		);

		$attachment_id = self::attach( $post, $image, $viewport );
		if ( $attachment_id > 0 ) {
			$result['attachment_id'] = $attachment_id;
			$result['thumbnail_url'] = (string) wp_get_attachment_image_url( $attachment_id, 'medium' );
		}

		return $result;
	}

	/**
	 * Whether a provider is configured at all.
	 *
	 * @return bool
	 */
	public static function provider_available(): bool {
		$provider = (string) Settings::get( 'screenshot_provider', 'none' );

		if ( 'external' === $provider ) {
			return '' !== (string) Settings::get( 'screenshot_url_template', '' );
		}

		if ( 'headless' === $provider ) {
			$binary = (string) Settings::get( 'screenshot_binary', '' );

			return '' !== $binary && is_executable( $binary ) && self::can_exec();
		}

		return false;
	}

	/**
	 * Captures the artifact, returning raw PNG bytes or an empty string.
	 *
	 * @param WP_Post $post     Artifact.
	 * @param string  $viewport Viewport name.
	 * @return string
	 */
	public static function capture( WP_Post $post, string $viewport = 'desktop' ): string {
		$provider = (string) Settings::get( 'screenshot_provider', 'none' );
		$url      = 'publish' === $post->post_status
			? ArtifactRepository::instance()->url( $post )
			: ArtifactRepository::instance()->share_url( $post );

		if ( '' === $url ) {
			return '';
		}

		if ( 'external' === $provider ) {
			return self::capture_external( $url );
		}

		if ( 'headless' === $provider ) {
			return self::capture_headless( $url, $viewport );
		}

		return '';
	}

	/**
	 * Fetches a screenshot from an external rendering service.
	 *
	 * @param string $url Artifact URL.
	 * @return string
	 */
	private static function capture_external( string $url ): string {
		$template = (string) Settings::get( 'screenshot_url_template', '' );
		if ( '' === $template ) {
			return '';
		}

		$endpoint = str_replace(
			array( '{url}', '{url_encoded}' ),
			array( $url, rawurlencode( $url ) ),
			$template
		);

		$response = wp_remote_get( $endpoint, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Renders with a local Chromium binary.
	 *
	 * @param string $url      Artifact URL.
	 * @param string $viewport Viewport name.
	 * @return string
	 */
	private static function capture_headless( string $url, string $viewport ): string {
		$binary = (string) Settings::get( 'screenshot_binary', '' );
		if ( '' === $binary || ! is_executable( $binary ) || ! self::can_exec() ) {
			return '';
		}

		list( $width, $height ) = self::VIEWPORTS[ $viewport ] ?? self::VIEWPORTS['desktop'];

		$target = wp_tempnam( 'wp-artifacts-shot' );
		if ( ! is_string( $target ) || '' === $target ) {
			return '';
		}

		$target .= '.png';

		$command = sprintf(
			'%s --headless=new --disable-gpu --no-sandbox --hide-scrollbars --window-size=%d,%d --screenshot=%s %s 2>&1',
			escapeshellarg( $binary ),
			(int) $width,
			(int) $height,
			escapeshellarg( $target ),
			escapeshellarg( $url )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		@shell_exec( $command ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_file( $target ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bytes = (string) file_get_contents( $target );
		wp_delete_file( $target );

		return $bytes;
	}

	/**
	 * Stores the screenshot in the media library and sets it as the thumbnail.
	 *
	 * @param WP_Post $post     Artifact.
	 * @param string  $image    Raw PNG bytes.
	 * @param string  $viewport Viewport name.
	 * @return int Attachment ID, or 0.
	 */
	private static function attach( WP_Post $post, string $image, string $viewport ): int {
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return 0;
		}

		$filename = sanitize_file_name( ( $post->post_name ? $post->post_name : 'artifact-' . $post->ID ) . '-' . $viewport . '.png' );
		$upload   = wp_upload_bits( $filename, null, $image );

		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return 0;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => sprintf(
					/* translators: %s: artifact title. */
					__( 'Screenshot of %s', 'wp-artifacts' ),
					get_the_title( $post )
				),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			(string) $upload['file'],
			(int) $post->ID
		);

		if ( is_wp_error( $attachment_id ) || 0 === (int) $attachment_id ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( (int) $attachment_id, wp_generate_attachment_metadata( (int) $attachment_id, (string) $upload['file'] ) );

		if ( ! has_post_thumbnail( $post ) ) {
			set_post_thumbnail( $post, (int) $attachment_id );
		}

		return (int) $attachment_id;
	}

	/**
	 * Whether shell_exec is usable in this environment.
	 *
	 * @return bool
	 */
	private static function can_exec(): bool {
		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'shell_exec', $disabled, true );
	}
}
