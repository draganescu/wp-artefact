<?php
/**
 * Registers every ability.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\Storage\ArtifactRepository;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's whole API surface.
 *
 * Abilities are registered and nothing else. The WordPress MCP Adapter, when it is
 * installed, exposes them on its own server: it reads `meta.public` to decide what is
 * exposed and `meta.mcp.type` to sort tools from resources and prompts. Standing up a
 * second MCP server here would only give site owners two endpoints to secure and keep
 * in step, so the adapter owns the transport and this plugin owns the abilities.
 */
final class Registrar {

	public const NAMESPACE_PREFIX = 'wp-artifacts';

	public const CATEGORY = 'wp-artifacts';

	/**
	 * Singleton instance.
	 *
	 * @var Registrar|null
	 */
	private static ?Registrar $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Registrar
	 */
	public static function instance(): Registrar {
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
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_all' ) );
		add_action( 'admin_notices', array( $this, 'missing_api_notice' ) );
	}

	/**
	 * The ability classes this plugin registers.
	 *
	 * @return array<string,class-string<Ability>>
	 */
	public static function abilities(): array {
		return array(
			'publish'             => Publish::class,
			'update'              => Update::class,
			'get'                 => Get::class,
			'list'                => ListArtifacts::class,
			'revisions'           => Revisions::class,
			'rollback'            => Rollback::class,
			'delete'              => Delete::class,
			'share'               => Share::class,
			'screenshot'          => Screenshot::class,
			'set-front-page'      => SetFrontPage::class,
			'upload-url'          => UploadUrl::class,
			'site-style'          => SiteStyle::class,
			'site-style-resource' => SiteStyleResource::class,
			'guide'               => Guide::class,
		);
	}

	/**
	 * Registers the ability category every artifact ability belongs to.
	 *
	 * Categories have their own init action; abilities registered against a category
	 * that does not exist yet are silently dropped.
	 *
	 * @return void
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Artifacts', 'wp-artifacts' ),
				'description' => __( 'Publish and manage self-contained documents served byte-identical.', 'wp-artifacts' ),
			)
		);
	}

	/**
	 * Registers every ability.
	 *
	 * @return void
	 */
	public function register_all(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( self::abilities() as $slug => $class_name ) {
			$args = $class_name::definition();

			if ( ! isset( $args['category'] ) ) {
				$args['category'] = self::CATEGORY;
			}

			$args['meta'] = array_merge(
				array(
					'public'       => true,
					'show_in_rest' => true,
					'annotations'  => array(),
				),
				isset( $args['meta'] ) ? (array) $args['meta'] : array()
			);

			/**
			 * Filters one ability definition before registration.
			 *
			 * @param array<string,mixed> $args Ability arguments.
			 * @param string              $slug Ability slug without the namespace.
			 */
			$args = (array) apply_filters( 'wp_artifacts_ability_args', $args, $slug );

			wp_register_ability( self::NAMESPACE_PREFIX . '/' . $slug, $args );
		}
	}

	/**
	 * Warns administrators when the Abilities API is missing.
	 *
	 * @return void
	 */
	public function missing_api_notice(): void {
		if ( function_exists( 'wp_register_ability' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_wp-artifacts' ), true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Artifacts: this site has no Abilities API, so the MCP and agent tools are unavailable. Artifacts themselves still work. The Abilities API ships with WordPress 6.9.', 'wp-artifacts' )
		);
	}

	/*
	---------------------------------------------------------------------
	 * Shared schema and permission helpers
	 */

	/**
	 * The optional fields shared by publish and update.
	 *
	 * @return array<string,mixed>
	 */
	public static function shared_input_properties(): array {
		return array(
			'title'              => array(
				'type'        => 'string',
				'description' => __( 'Human readable title. Shown in the admin and in the archive, never injected into the artifact.', 'wp-artifacts' ),
			),
			'content'            => array(
				'type'        => 'string',
				'description' => __( 'The entry document, stored and served byte for byte. Usually a complete HTML document.', 'wp-artifacts' ),
			),
			'content_type'       => array(
				'type'        => 'string',
				'description' => __( 'MIME type of the entry document. Defaults to text/html; charset=utf-8.', 'wp-artifacts' ),
			),
			'entry'              => array(
				'type'        => 'string',
				'description' => __( 'Relative path the entry document is known by inside the bundle. Defaults to index.html.', 'wp-artifacts' ),
			),
			'files'              => array(
				'type'        => 'array',
				'description' => __( 'Bundle assets, addressed by relative path from the entry document. Sending this replaces the whole asset set.', 'wp-artifacts' ),
				'items'       => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'path', 'data_base64' ),
					'properties'           => array(
						'path'        => array(
							'type'        => 'string',
							'description' => __( 'Relative path such as css/app.css. No leading slash, no "..".', 'wp-artifacts' ),
						),
						'mime'        => array(
							'type'        => 'string',
							'description' => __( 'MIME type. Guessed from the extension when omitted.', 'wp-artifacts' ),
						),
						'data_base64' => array(
							'type'        => 'string',
							'description' => __( 'File contents, base64 encoded.', 'wp-artifacts' ),
						),
					),
				),
			),
			'status'             => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'publish', 'private', 'future' ),
				'description' => __( 'Use "private" plus the returned share_url to show work in progress without publishing it.', 'wp-artifacts' ),
			),
			'slug'               => array(
				'type'        => 'string',
				'description' => __( 'URL slug. WordPress makes it unique if it is taken.', 'wp-artifacts' ),
			),
			'excerpt'            => array(
				'type'        => 'string',
				'description' => __( 'Short description used in the archive.', 'wp-artifacts' ),
			),
			'parent_id'          => array(
				'type'        => 'integer',
				'description' => __( 'ID of a post or page this artifact represents.', 'wp-artifacts' ),
			),
			'deliver_for_parent' => array(
				'type'        => 'boolean',
				'description' => __( 'Serve this artifact at the parent post URL.', 'wp-artifacts' ),
			),
			'indexable'          => array(
				'type'        => 'boolean',
				'description' => __( 'Allow search engines to index the artifact. Default false.', 'wp-artifacts' ),
			),
			'csp'                => array(
				'type'        => 'string',
				'description' => __( 'Content-Security-Policy: "inherit", "strict", "off", or a literal header value.', 'wp-artifacts' ),
			),
			'wrap'               => array(
				'type'        => 'boolean',
				'description' => __( 'Serve the artifact inside the site header and footer. Best effort.', 'wp-artifacts' ),
			),
			'provenance'         => array(
				'type'                 => 'object',
				'description'          => __( 'Free-form record of what made this artifact.', 'wp-artifacts' ),
				'additionalProperties' => true,
				'properties'           => array(
					'tool'         => array( 'type' => 'string' ),
					'model'        => array( 'type' => 'string' ),
					'agent'        => array( 'type' => 'string' ),
					'source_url'   => array( 'type' => 'string' ),
					'generated_at' => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * The output schema shared by publish, update and rollback.
	 *
	 * @return array<string,mixed>
	 */
	public static function write_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => array(
				'id'          => array( 'type' => 'integer' ),
				'url'         => array( 'type' => 'string' ),
				'share_url'   => array( 'type' => 'string' ),
				'status'      => array( 'type' => 'string' ),
				'revision_id' => array( 'type' => 'integer' ),
				'bytes'       => array( 'type' => 'integer' ),
				'warnings'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * Permission check for the write abilities.
	 *
	 * @return true|WP_Error
	 */
	public static function can_write() {
		if ( current_user_can( 'publish_artifacts' ) ) {
			return true;
		}

		return new WP_Error(
			'artifact_forbidden',
			__( 'The current user cannot publish artifacts. The publish_artifacts capability is required.', 'wp-artifacts' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Permission check for the read abilities.
	 *
	 * @return true|WP_Error
	 */
	public static function can_read() {
		if ( current_user_can( 'read' ) ) {
			return true;
		}

		return new WP_Error(
			'artifact_forbidden',
			__( 'The current user cannot read this site.', 'wp-artifacts' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Loads an artifact for a read ability, enforcing visibility.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	public static function resolve_readable( array $input ) {
		$identifier = null;

		if ( isset( $input['id'] ) && '' !== $input['id'] ) {
			$identifier = (int) $input['id'];
		} elseif ( isset( $input['slug'] ) && '' !== $input['slug'] ) {
			$identifier = (string) $input['slug'];
		}

		if ( null === $identifier ) {
			return new WP_Error(
				'artifact_not_found',
				__( 'Pass either "id" or "slug".', 'wp-artifacts' ),
				array( 'status' => 400 )
			);
		}

		$post = ArtifactRepository::instance()->find( $identifier );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( 'publish' === $post->post_status ) {
			return $post;
		}

		if ( current_user_can( 'read_private_artifacts' ) || current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $post;
		}

		return new WP_Error(
			'artifact_forbidden',
			sprintf(
				/* translators: %d: artifact ID. */
				__( 'Artifact %d is not public and the current user cannot read it. The read_private_artifacts capability is required.', 'wp-artifacts' ),
				(int) $post->ID
			),
			array( 'status' => 403 )
		);
	}

	/**
	 * Loads an artifact for a write ability.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @param string              $cap   Meta capability to check.
	 * @return WP_Post|WP_Error
	 */
	public static function resolve_writable( array $input, string $cap = 'edit_post' ) {
		$post = ArtifactRepository::instance()->require_artifact( isset( $input['id'] ) ? (int) $input['id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! current_user_can( $cap, (int) $post->ID ) ) {
			return new WP_Error(
				'artifact_forbidden',
				sprintf(
					/* translators: %d: artifact ID. */
					__( 'The current user cannot modify artifact %d.', 'wp-artifacts' ),
					(int) $post->ID
				),
				array( 'status' => 403 )
			);
		}

		return $post;
	}

	/**
	 * The post type name, exposed for the ability schemas.
	 *
	 * @return string
	 */
	public static function post_type(): string {
		return ArtifactPostType::POST_TYPE;
	}
}
