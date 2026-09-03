<?php
/**
 * The `artifact` post type.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\PostType;

use WPArtifacts\Security\Capabilities;
use WPArtifacts\Settings;
use WPArtifacts\Storage\ArtifactRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post type, its meta and the core integrations around it.
 */
final class ArtifactPostType {

	public const POST_TYPE = 'artifact';

	public const META_MANIFEST           = '_artifact_manifest';
	public const META_CONTENT_TYPE       = '_artifact_content_type';
	public const META_INDEXABLE          = '_artifact_indexable';
	public const META_CSP                = '_artifact_csp';
	public const META_PROVENANCE         = '_artifact_provenance';
	public const META_SHARE_TOKEN        = '_artifact_share_token';
	public const META_WRAP               = '_artifact_wrap';
	public const META_DELIVER_FOR_PARENT = '_artifact_deliver_for_parent';
	public const META_OLD_SLUGS          = '_artifact_old_slugs';
	public const META_REDIRECT_TO        = '_artifact_redirect_to';
	public const META_ASSETS_REV         = '_artifact_assets_rev';
	public const META_STORAGE_KEY        = '_artifact_storage_key';

	public const DEFAULT_CONTENT_TYPE = 'text/html; charset=utf-8';

	/**
	 * Singleton instance.
	 *
	 * @var ArtifactPostType|null
	 */
	private static ?ArtifactPostType $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return ArtifactPostType
	 */
	public static function instance(): ArtifactPostType {
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
		add_action( 'init', array( self::class, 'register' ), 0 );
		add_action( 'init', array( self::class, 'register_meta' ), 1 );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );

		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor' ), 10, 2 );
		add_filter( 'wp_sitemaps_post_types', array( $this, 'sitemap_post_types' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'sitemap_query_args' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'exclude_from_feeds' ) );

		// Verbatim REST round-trip for wp/v2/artifacts.
		add_filter( 'rest_pre_insert_' . self::POST_TYPE, array( $this, 'rest_pre_insert' ), 10, 2 );
		add_action( 'rest_after_insert_' . self::POST_TYPE, array( $this, 'rest_after_insert' ), 10, 3 );

		// Let an artifact be chosen as the static front page.
		add_filter( 'wp_dropdown_pages', array( $this, 'front_page_dropdown' ), 10, 3 );
	}

	/**
	 * Registers the post type.
	 *
	 * @return void
	 */
	public static function register(): void {
		$labels = array(
			'name'                   => _x( 'Artifacts', 'post type general name', 'wp-artifacts' ),
			'singular_name'          => _x( 'Artifact', 'post type singular name', 'wp-artifacts' ),
			'add_new'                => __( 'Add Artifact', 'wp-artifacts' ),
			'add_new_item'           => __( 'Add New Artifact', 'wp-artifacts' ),
			'edit_item'              => __( 'Edit Artifact', 'wp-artifacts' ),
			'new_item'               => __( 'New Artifact', 'wp-artifacts' ),
			'view_item'              => __( 'View Artifact', 'wp-artifacts' ),
			'view_items'             => __( 'View Artifacts', 'wp-artifacts' ),
			'search_items'           => __( 'Search Artifacts', 'wp-artifacts' ),
			'not_found'              => __( 'No artifacts found.', 'wp-artifacts' ),
			'not_found_in_trash'     => __( 'No artifacts found in Trash.', 'wp-artifacts' ),
			'all_items'              => __( 'All Artifacts', 'wp-artifacts' ),
			'archives'               => __( 'Artifact Archives', 'wp-artifacts' ),
			'attributes'             => __( 'Artifact Attributes', 'wp-artifacts' ),
			'insert_into_item'       => __( 'Insert into artifact', 'wp-artifacts' ),
			'uploaded_to_this_item'  => __( 'Uploaded to this artifact', 'wp-artifacts' ),
			'featured_image'         => __( 'Screenshot', 'wp-artifacts' ),
			'set_featured_image'     => __( 'Set screenshot', 'wp-artifacts' ),
			'remove_featured_image'  => __( 'Remove screenshot', 'wp-artifacts' ),
			'use_featured_image'     => __( 'Use as screenshot', 'wp-artifacts' ),
			'menu_name'              => _x( 'Artifacts', 'admin menu', 'wp-artifacts' ),
			'name_admin_bar'         => _x( 'Artifact', 'add new on admin bar', 'wp-artifacts' ),
			'item_published'         => __( 'Artifact published.', 'wp-artifacts' ),
			'item_updated'           => __( 'Artifact updated.', 'wp-artifacts' ),
			'item_reverted_to_draft' => __( 'Artifact reverted to draft.', 'wp-artifacts' ),
		);

		$args = array(
			'labels'              => $labels,
			'description'         => __( 'Self-contained documents served byte-identical.', 'wp-artifacts' ),
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'show_in_rest'        => true,
			'rest_base'           => 'artifacts',
			'has_archive'         => Settings::archive_slug(),
			'rewrite'             => array(
				'slug'       => Settings::prefix(),
				'with_front' => false,
				'feeds'      => false,
				'pages'      => false,
			),
			'hierarchical'        => false,
			'exclude_from_search' => false,
			'supports'            => array( 'title', 'editor', 'author', 'thumbnail', 'revisions', 'custom-fields', 'excerpt' ),
			'capability_type'     => array( 'artifact', 'artifacts' ),
			'capabilities'        => Capabilities::post_type_caps(),
			'map_meta_cap'        => true,
			'menu_icon'           => 'dashicons-layout',
			'menu_position'       => 21,
			'can_export'          => true,
			'delete_with_user'    => false,
			'template'            => array(),
			'template_lock'       => 'all',
		);

		/**
		 * Filters the `artifact` post type registration arguments.
		 *
		 * @param array<string,mixed> $args Arguments passed to register_post_type().
		 */
		$args = (array) apply_filters( 'wp_artifacts_post_type_args', $args );

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Registers all post meta.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		$can_edit = static function ( $allowed, $meta_key, $post_id ): bool {
			unset( $allowed, $meta_key );

			return current_user_can( 'edit_post', (int) $post_id );
		};

		register_post_meta(
			self::POST_TYPE,
			self::META_MANIFEST,
			array(
				'type'              => 'object',
				'description'       => __( 'Bundle manifest: entry document plus asset list.', 'wp-artifacts' ),
				'single'            => true,
				'default'           => array(),
				'revisions_enabled' => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => array(
							'entry'       => array( 'type' => 'string' ),
							'total_bytes' => array( 'type' => 'integer' ),
							'files'       => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'additionalProperties' => true,
									'properties'           => array(
										'path'   => array( 'type' => 'string' ),
										'mime'   => array( 'type' => 'string' ),
										'bytes'  => array( 'type' => 'integer' ),
										'sha256' => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
				),
				'auth_callback'     => $can_edit,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_CONTENT_TYPE,
			array(
				'type'              => 'string',
				'description'       => __( 'MIME type of the entry document.', 'wp-artifacts' ),
				'single'            => true,
				'default'           => self::DEFAULT_CONTENT_TYPE,
				'revisions_enabled' => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_content_type' ),
				'auth_callback'     => $can_edit,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_INDEXABLE,
			array(
				'type'          => 'boolean',
				'description'   => __( 'Whether search engines may index this artifact.', 'wp-artifacts' ),
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => true,
				'auth_callback' => $can_edit,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_CSP,
			array(
				'type'              => 'string',
				'description'       => __( 'Content-Security-Policy mode: inherit, strict, off, or a literal header value.', 'wp-artifacts' ),
				'single'            => true,
				'default'           => 'inherit',
				'show_in_rest'      => true,
				'sanitize_callback' => array( Settings::class, 'sanitize_header_value' ),
				'auth_callback'     => $can_edit,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_PROVENANCE,
			array(
				'type'              => 'object',
				'description'       => __( 'How and by what this artifact was generated.', 'wp-artifacts' ),
				'single'            => true,
				'default'           => array(),
				'revisions_enabled' => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'string' ),
						'properties'           => array(
							'tool'         => array( 'type' => 'string' ),
							'model'        => array( 'type' => 'string' ),
							'agent'        => array( 'type' => 'string' ),
							'source_url'   => array( 'type' => 'string' ),
							'generated_at' => array( 'type' => 'string' ),
						),
					),
				),
				'auth_callback'     => $can_edit,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_SHARE_TOKEN,
			array(
				'type'          => 'string',
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_WRAP,
			array(
				'type'              => 'boolean',
				'description'       => __( 'Serve the artifact inside the site header and footer.', 'wp-artifacts' ),
				'single'            => true,
				'default'           => false,
				'revisions_enabled' => true,
				'show_in_rest'      => true,
				'auth_callback'     => $can_edit,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_ASSETS_REV,
			array(
				'type'          => 'integer',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_STORAGE_KEY,
			array(
				'type'          => 'string',
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_REDIRECT_TO,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $can_edit,
			)
		);

		foreach ( self::parent_post_types() as $parent_type ) {
			register_post_meta(
				$parent_type,
				self::META_DELIVER_FOR_PARENT,
				array(
					'type'          => 'integer',
					'description'   => __( 'Artifact ID served in place of this post.', 'wp-artifacts' ),
					'single'        => true,
					'default'       => 0,
					'show_in_rest'  => true,
					'auth_callback' => $can_edit,
				)
			);
		}
	}

	/**
	 * Post types that may be represented by an artifact.
	 *
	 * @return array<int,string>
	 */
	public static function parent_post_types(): array {
		/**
		 * Filters the post types an artifact may stand in for.
		 *
		 * @param array<int,string> $types Post type names.
		 */
		return (array) apply_filters( 'wp_artifacts_parent_post_types', array( 'post', 'page' ) );
	}

	/**
	 * Keeps a content type header value sane.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_content_type( $value ): string {
		$value = Settings::sanitize_header_value( (string) $value );

		return '' === $value ? self::DEFAULT_CONTENT_TYPE : $value;
	}

	/**
	 * Flushes rewrite rules when the prefix or archive slug changed.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrites(): void {
		$signature = Settings::prefix() . '|' . Settings::archive_slug();
		if ( get_option( 'wp_artifacts_rewrite_signature' ) === $signature ) {
			return;
		}

		update_option( 'wp_artifacts_rewrite_signature', $signature, false );
		flush_rewrite_rules( false );
	}

	/**
	 * Artifacts never use the block editor.
	 *
	 * @param bool   $use_block_editor Whether to use the block editor.
	 * @param string $post_type        Post type name.
	 * @return bool
	 */
	public function disable_block_editor( $use_block_editor, $post_type ) {
		if ( self::POST_TYPE === $post_type ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Adds `artifact` to the sitemap provider list.
	 *
	 * @param array<string,\WP_Post_Type> $post_types Sitemap post types.
	 * @return array<string,\WP_Post_Type>
	 */
	public function sitemap_post_types( $post_types ) {
		$object = get_post_type_object( self::POST_TYPE );
		if ( $object instanceof \WP_Post_Type ) {
			$post_types[ self::POST_TYPE ] = $object;
		}

		return $post_types;
	}

	/**
	 * Restricts sitemap entries to artifacts flagged indexable.
	 *
	 * @param array<string,mixed> $args      Query args.
	 * @param string              $post_type Post type name.
	 * @return array<string,mixed>
	 */
	public function sitemap_query_args( $args, $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return $args;
		}

		$args['meta_query'] = array(
			array(
				'key'     => self::META_INDEXABLE,
				'value'   => '1',
				'compare' => '=',
			),
		);

		return $args;
	}

	/**
	 * Keeps artifacts out of feeds unless the setting says otherwise.
	 *
	 * @param \WP_Query $query Current query.
	 * @return void
	 */
	public function exclude_from_feeds( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_feed() || Settings::get( 'include_in_feeds' ) ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) ) {
			return;
		}

		$post_type = (array) $post_type;
		if ( ! in_array( self::POST_TYPE, $post_type, true ) ) {
			return;
		}

		$post_type = array_values( array_diff( $post_type, array( self::POST_TYPE ) ) );
		$query->set( 'post_type', empty( $post_type ) ? array( 'post' ) : $post_type );
	}

	/**
	 * Keeps `content.raw` byte-identical when writing through wp/v2/artifacts.
	 *
	 * @param \stdClass        $prepared Post object about to be inserted.
	 * @param \WP_REST_Request $request  Incoming request.
	 * @return \stdClass|\WP_Error
	 */
	public function rest_pre_insert( $prepared, $request ) {
		$content = $request['content'] ?? null;

		if ( is_array( $content ) && array_key_exists( 'raw', $content ) ) {
			$raw = (string) $content['raw'];
		} elseif ( is_string( $content ) ) {
			$raw = $content;
		} else {
			return $prepared;
		}

		$check = ArtifactRepository::instance()->check_executable_content( $raw, array() );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$prepared->post_content = wp_slash( $raw );
		ArtifactRepository::instance()->expect_verbatim( $raw );

		return $prepared;
	}

	/**
	 * Repairs the stored bytes after a REST write, if a filter mangled them.
	 *
	 * @param \WP_Post         $post     Inserted post.
	 * @param \WP_REST_Request $request  Request.
	 * @param bool             $creating Whether the post was created.
	 * @return void
	 */
	public function rest_after_insert( $post, $request, $creating ): void {
		unset( $request, $creating );

		ArtifactRepository::instance()->flush_verbatim( (int) $post->ID );
	}

	/**
	 * Appends published artifacts to the Settings → Reading front page dropdown.
	 *
	 * @param string              $output HTML output.
	 * @param array<string,mixed> $args   Dropdown args.
	 * @param array<int,\WP_Post> $pages  Pages shown.
	 * @return string
	 */
	public function front_page_dropdown( $output, $args, $pages ) {
		unset( $pages );

		$name = isset( $args['name'] ) ? (string) $args['name'] : '';
		if ( ! in_array( $name, array( 'page_on_front', 'page_for_posts' ), true ) || 'page_on_front' !== $name ) {
			return $output;
		}

		/**
		 * Filters whether artifacts may be chosen as the static front page.
		 *
		 * @param bool $enabled Enabled by default.
		 */
		if ( ! apply_filters( 'wp_artifacts_allow_front_page', true ) ) {
			return $output;
		}

		$artifacts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => 100,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		if ( empty( $artifacts ) ) {
			return $output;
		}

		$selected = (int) get_option( 'page_on_front' );
		$options  = '';
		foreach ( $artifacts as $artifact ) {
			$options .= sprintf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $artifact->ID,
				selected( $selected, (int) $artifact->ID, false ),
				esc_html( $artifact->post_title )
			);
		}

		$group = '<optgroup label="' . esc_attr__( 'Artifacts', 'wp-artifacts' ) . '">' . $options . '</optgroup>';

		$position = strrpos( $output, '</select>' );
		if ( false === $position ) {
			return $output;
		}

		return substr( $output, 0, $position ) . $group . substr( $output, $position );
	}
}
