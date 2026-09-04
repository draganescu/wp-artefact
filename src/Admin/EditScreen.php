<?php
/**
 * The artifact edit screen.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Admin;

use WPArtifacts\Abilities\UploadUrl;
use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\Settings;
use WPArtifacts\Storage\ArtifactRepository;
use WPArtifacts\Storage\BundleStore;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A classic edit screen: code on the left, live preview on the right, artifact
 * settings in the sidebar. No block editor, no rich text, nothing that would
 * rewrite the stored bytes.
 */
final class EditScreen {

	private const NONCE = 'wp_artifacts_edit';

	/**
	 * Singleton instance.
	 *
	 * @var EditScreen|null
	 */
	private static ?EditScreen $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return EditScreen
	 */
	public static function instance(): EditScreen {
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
		add_action( 'load-post.php', array( $this, 'drop_editor_support' ) );
		add_action( 'load-post-new.php', array( $this, 'drop_editor_support' ) );
		add_action( 'add_meta_boxes_' . ArtifactPostType::POST_TYPE, array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_' . ArtifactPostType::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_wp_artifacts_download', array( $this, 'download_bundle' ) );
		add_action( 'admin_post_wp_artifacts_regenerate_share', array( $this, 'regenerate_share' ) );
		add_filter( 'post_updated_messages', array( $this, 'updated_messages' ) );
		add_action( 'post_edit_form_tag', array( $this, 'form_enctype' ) );
		add_action( 'admin_notices', array( $this, 'save_notice' ) );
	}

	/**
	 * Takes the content editor off the artifact edit screen.
	 *
	 * `edit-form-advanced.php` renders the editor from post type support rather than
	 * from a meta box, so removing the support is the only way to keep TinyMCE away
	 * from bytes that must survive untouched. REST still reports `content`, because
	 * this runs on the two admin screens and nowhere else.
	 *
	 * @return void
	 */
	public function drop_editor_support(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen instanceof \WP_Screen && ArtifactPostType::POST_TYPE === $screen->post_type ) {
			remove_post_type_support( ArtifactPostType::POST_TYPE, 'editor' );
		}
	}

	/**
	 * Swaps the default editor for the artifact boxes.
	 *
	 * @param WP_Post $post Artifact.
	 * @return void
	 */
	public function register_meta_boxes( $post ): void {
		remove_meta_box( 'postdivrich', ArtifactPostType::POST_TYPE, 'normal' );
		remove_meta_box( 'pageparentdiv', ArtifactPostType::POST_TYPE, 'side' );

		add_meta_box(
			'wp_artifacts_code',
			__( 'Entry document', 'wp-artifacts' ),
			array( $this, 'render_code_box' ),
			ArtifactPostType::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'wp_artifacts_preview',
			__( 'Preview', 'wp-artifacts' ),
			array( $this, 'render_preview_box' ),
			ArtifactPostType::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'wp_artifacts_delivery',
			__( 'Artifact settings', 'wp-artifacts' ),
			array( $this, 'render_settings_box' ),
			ArtifactPostType::POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'wp_artifacts_provenance',
			__( 'Provenance', 'wp-artifacts' ),
			array( $this, 'render_provenance_box' ),
			ArtifactPostType::POST_TYPE,
			'side',
			'low'
		);

		/**
		 * Filters whether the "Convert to blocks" box is offered.
		 *
		 * Conversion itself lands in a later version; the filter exists so the box can
		 * be switched on by whatever implements it.
		 *
		 * @param bool $enabled Disabled by default.
		 */
		if ( apply_filters( 'wp_artifacts_enable_convert', false ) ) {
			add_meta_box(
				'wp_artifacts_convert',
				__( 'Convert to blocks', 'wp-artifacts' ),
				array( $this, 'render_convert_box' ),
				ArtifactPostType::POST_TYPE,
				'side',
				'low'
			);
		}

		unset( $post );
	}

	/**
	 * Loads the edit screen assets.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function enqueue( $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || ArtifactPostType::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'wp-artifacts-edit',
			WP_ARTIFACTS_URL . 'assets/admin/edit.css',
			array(),
			\WPArtifacts\VERSION
		);

		wp_enqueue_script(
			'wp-artifacts-edit',
			WP_ARTIFACTS_URL . 'assets/admin/edit.js',
			array(),
			\WPArtifacts\VERSION,
			true
		);

		wp_localize_script(
			'wp-artifacts-edit',
			'wpArtifactsEdit',
			array(
				'copied' => __( 'Copied', 'wp-artifacts' ),
				'copy'   => __( 'Copy', 'wp-artifacts' ),
			)
		);
	}

	/**
	 * Lets the edit form carry a replacement bundle.
	 *
	 * @param WP_Post|null $post Post being edited.
	 * @return void
	 */
	public function form_enctype( $post = null ): void {
		if ( $post instanceof WP_Post && ArtifactPostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		echo ' enctype="multipart/form-data"';
	}

	/**
	 * Shows whatever the last save could not do.
	 *
	 * @return void
	 */
	public function save_notice(): void {
		$key     = 'wp_artifacts_notice_' . get_current_user_id();
		$message = get_transient( $key );

		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/*
	---------------------------------------------------------------------
	 * Boxes
	 */

	/**
	 * Read-only code view plus the bundle controls.
	 *
	 * @param WP_Post $post Artifact.
	 * @return void
	 */
	public function render_code_box( $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );

		$repository   = ArtifactRepository::instance();
		$manifest     = $repository->manifest( (int) $post->ID );
		$content      = (string) $post->post_content;
		$content_type = (string) get_post_meta( (int) $post->ID, ArtifactPostType::META_CONTENT_TYPE, true );
		$content_type = '' !== $content_type ? $content_type : ArtifactPostType::DEFAULT_CONTENT_TYPE;

		echo '<p class="description">';
		printf(
			/* translators: 1: content type, 2: size, 3: sha256 prefix. */
			esc_html__( '%1$s · %2$s · sha256 %3$s… — stored and served byte for byte.', 'wp-artifacts' ),
			esc_html( $content_type ),
			esc_html( (string) size_format( strlen( $content ), 1 ) ),
			esc_html( substr( hash( 'sha256', $content ), 0, 12 ) )
		);
		echo '</p>';

		printf(
			'<textarea class="wp-artifacts-code" readonly spellcheck="false" rows="18">%s</textarea>',
			esc_textarea( $content )
		);

		if ( $manifest->count() > 0 ) {
			echo '<h4>' . esc_html__( 'Bundle files', 'wp-artifacts' ) . '</h4>';
			echo '<table class="widefat striped wp-artifacts-files"><thead><tr>';
			echo '<th>' . esc_html__( 'Path', 'wp-artifacts' ) . '</th>';
			echo '<th>' . esc_html__( 'Type', 'wp-artifacts' ) . '</th>';
			echo '<th>' . esc_html__( 'Size', 'wp-artifacts' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $manifest->files() as $file ) {
				printf(
					'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td></tr>',
					esc_html( $file['path'] ),
					esc_html( $file['mime'] ),
					esc_html( (string) size_format( (int) $file['bytes'], 1 ) )
				);
			}

			echo '</tbody></table>';
		}

		echo '<p class="wp-artifacts-bundle-actions">';

		printf(
			'<a class="button" href="%1$s">%2$s</a> ',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=wp_artifacts_download&post=' . (int) $post->ID ),
					'wp_artifacts_download_' . (int) $post->ID
				)
			),
			esc_html__( 'Download bundle (.zip)', 'wp-artifacts' )
		);

		echo '<label class="wp-artifacts-replace">';
		echo '<span>' . esc_html__( 'Replace bundle', 'wp-artifacts' ) . '</span> ';
		echo '<input type="file" name="wp_artifacts_bundle" accept=".zip,.html,.htm,text/html,application/zip">';
		echo '</label>';
		echo '</p>';

		echo '<p class="description">' . esc_html__( 'Uploading a .zip replaces the entry document and every asset. Uploading a single .html replaces just the entry document. Either way a revision is created first.', 'wp-artifacts' ) . '</p>';
	}

	/**
	 * Live preview.
	 *
	 * @param WP_Post $post Artifact.
	 * @return void
	 */
	public function render_preview_box( $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Save the artifact to preview it.', 'wp-artifacts' ) . '</p>';

			return;
		}

		$url = ArtifactRepository::instance()->share_url( $post );

		echo '<div class="wp-artifacts-preview" data-src="' . esc_url( $url ) . '">';
		echo '<div class="wp-artifacts-preview__bar">';
		echo '<button type="button" class="button button-small is-active" data-viewport="desktop">' . esc_html__( 'Desktop', 'wp-artifacts' ) . '</button> ';
		echo '<button type="button" class="button button-small" data-viewport="mobile">' . esc_html__( 'Mobile', 'wp-artifacts' ) . '</button> ';
		echo '<button type="button" class="button button-small" data-reload="1">' . esc_html__( 'Reload', 'wp-artifacts' ) . '</button> ';
		printf(
			'<a class="button button-small" href="%1$s" target="_blank" rel="noopener">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Open in a new tab', 'wp-artifacts' )
		);
		echo '</div>';
		// No allow-same-origin: an artifact's script must not be able to act as the
		// editor who is previewing it. Scripts still run, in an opaque origin.
		printf(
			'<iframe class="wp-artifacts-preview__frame" src="%s" title="%s" sandbox="allow-scripts allow-forms allow-popups"></iframe>',
			esc_url( $url ),
			esc_attr__( 'Artifact preview', 'wp-artifacts' )
		);
		echo '</div>';
	}

	/**
	 * Delivery and header settings.
	 *
	 * @param WP_Post $post Artifact.
	 * @return void
	 */
	public function render_settings_box( $post ): void {
		$post_id    = (int) $post->ID;
		$repository = ArtifactRepository::instance();
		$parent_id  = (int) $post->post_parent;
		$delivers   = $parent_id > 0 && (int) get_post_meta( $parent_id, ArtifactPostType::META_DELIVER_FOR_PARENT, true ) === $post_id;
		$csp        = (string) get_post_meta( $post_id, ArtifactPostType::META_CSP, true );
		$csp        = '' !== $csp ? $csp : 'inherit';
		$known_csp  = in_array( $csp, array( 'inherit', 'strict', 'off' ), true );

		echo '<p><label for="wp_artifacts_parent"><strong>' . esc_html__( 'Represents', 'wp-artifacts' ) . '</strong></label><br>';
		echo '<select name="wp_artifacts_parent" id="wp_artifacts_parent" class="widefat">';
		printf(
			'<option value="0"%1$s>%2$s</option>',
			selected( $parent_id, 0, false ),
			esc_html__( '— nothing —', 'wp-artifacts' )
		);

		foreach ( $this->parent_candidates() as $candidate ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $candidate->ID,
				selected( $parent_id, (int) $candidate->ID, false ),
				esc_html( get_the_title( $candidate ) )
			);
		}

		echo '</select>';
		echo '</p>';

		printf(
			'<p><label><input type="checkbox" name="wp_artifacts_deliver_for_parent" value="1" %1$s> %2$s</label></p>',
			checked( $delivers, true, false ),
			esc_html__( 'Serve this artifact at the parent URL', 'wp-artifacts' )
		);

		printf(
			'<p><label><input type="checkbox" name="wp_artifacts_indexable" value="1" %1$s> %2$s</label></p>',
			checked( (bool) get_post_meta( $post_id, ArtifactPostType::META_INDEXABLE, true ), true, false ),
			esc_html__( 'Allow search engines to index it', 'wp-artifacts' )
		);

		printf(
			'<p><label><input type="checkbox" name="wp_artifacts_wrap" value="1" %1$s> %2$s</label></p>',
			checked( (bool) get_post_meta( $post_id, ArtifactPostType::META_WRAP, true ), true, false ),
			esc_html__( 'Wrap in the site header and footer', 'wp-artifacts' )
		);

		echo '<p><label for="wp_artifacts_csp"><strong>' . esc_html__( 'Content Security Policy', 'wp-artifacts' ) . '</strong></label><br>';
		echo '<select name="wp_artifacts_csp_mode" id="wp_artifacts_csp" class="widefat">';
		foreach ( array(
			'inherit' => __( 'Site default', 'wp-artifacts' ),
			'strict'  => __( 'Strict', 'wp-artifacts' ),
			'off'     => __( 'None', 'wp-artifacts' ),
			'custom'  => __( 'Custom header…', 'wp-artifacts' ),
		) as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $known_csp ? $csp : 'custom', $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		printf(
			'<input type="text" class="widefat" name="wp_artifacts_csp_custom" value="%s" placeholder="default-src \'self\'">',
			esc_attr( $known_csp ? '' : $csp )
		);
		echo '</p>';

		echo '<p><strong>' . esc_html__( 'Share link', 'wp-artifacts' ) . '</strong><br>';
		printf(
			'<input type="text" class="widefat wp-artifacts-copyable" readonly value="%s" onfocus="this.select()">',
			esc_attr( $repository->share_url( $post ) )
		);
		printf(
			'<a class="button button-small" href="%1$s">%2$s</a>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=wp_artifacts_regenerate_share&post=' . $post_id ),
					'wp_artifacts_share_' . $post_id
				)
			),
			esc_html__( 'Regenerate', 'wp-artifacts' )
		);
		echo '</p>';

		echo '<p class="description">' . esc_html__( 'Anyone with the share link can view this artifact even while it is a draft or private.', 'wp-artifacts' ) . '</p>';
	}

	/**
	 * The posts and pages an artifact may be attached to.
	 *
	 * The picker shows the most recent hundred. On a larger site, set the parent
	 * through wp-artifacts/update or wp/v2/artifacts instead.
	 *
	 * @return array<int,WP_Post>
	 */
	private function parent_candidates(): array {
		$candidates = get_posts(
			array(
				'post_type'        => ArtifactPostType::parent_post_types(),
				'post_status'      => array( 'publish', 'private', 'draft', 'pending' ),
				'numberposts'      => 100,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		// WP_Query with an explicit status list and no `perm` returns everyone's
		// unpublished posts, so the titles are filtered down to what this user may
		// actually attach an artifact to.
		return array_values(
			array_filter(
				$candidates,
				static function ( WP_Post $candidate ): bool {
					return current_user_can( 'edit_post', $candidate->ID );
				}
			)
		);
	}

	/**
	 * Read-only provenance.
	 *
	 * @param WP_Post $post Artifact.
	 * @return void
	 */
	public function render_provenance_box( $post ): void {
		$stored     = get_post_meta( (int) $post->ID, ArtifactPostType::META_PROVENANCE, true );
		$provenance = is_array( $stored ) ? $stored : array();

		if ( empty( $provenance ) ) {
			echo '<p class="description">' . esc_html__( 'Nothing recorded. Agents publishing through the Artifacts abilities can send tool, model, agent, source_url and generated_at.', 'wp-artifacts' ) . '</p>';

			return;
		}

		echo '<table class="wp-artifacts-provenance"><tbody>';
		foreach ( $provenance as $key => $value ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html( (string) $key ),
				esc_html( (string) $value )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Placeholder for the v2 converter.
	 *
	 * @param WP_Post $post Artifact.
	 * @return void
	 */
	public function render_convert_box( $post ): void {
		unset( $post );

		echo '<p class="description">' . esc_html__( 'Converting an artifact into a block page is not part of this version. The artifact is never modified by conversion.', 'wp-artifacts' ) . '</p>';
	}

	/*
	---------------------------------------------------------------------
	 * Saving
	 */

	/**
	 * Persists the metabox fields and any replacement bundle.
	 *
	 * @param int     $post_id Artifact ID.
	 * @param WP_Post $post    Artifact.
	 * @return void
	 */
	public function save( $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE . '_nonce' ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}

		$post_id = (int) $post_id;

		update_post_meta( $post_id, ArtifactPostType::META_INDEXABLE, isset( $_POST['wp_artifacts_indexable'] ) );
		update_post_meta( $post_id, ArtifactPostType::META_WRAP, isset( $_POST['wp_artifacts_wrap'] ) );

		$mode = isset( $_POST['wp_artifacts_csp_mode'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wp_artifacts_csp_mode'] ) ) : 'inherit';
		if ( 'custom' === $mode ) {
			$custom = isset( $_POST['wp_artifacts_csp_custom'] ) ? Settings::sanitize_header_value( wp_unslash( (string) $_POST['wp_artifacts_csp_custom'] ) ) : '';
			update_post_meta( $post_id, ArtifactPostType::META_CSP, '' !== $custom ? $custom : 'inherit' );
		} else {
			update_post_meta( $post_id, ArtifactPostType::META_CSP, in_array( $mode, array( 'inherit', 'strict', 'off' ), true ) ? $mode : 'inherit' );
		}

		$this->save_parent( $post_id, $post );
		$this->maybe_replace_bundle( $post_id );
	}

	/**
	 * Stores the parent post and the deliver-for-parent flag.
	 *
	 * @param int     $post_id Artifact ID.
	 * @param WP_Post $post    Artifact.
	 * @return void
	 */
	private function save_parent( int $post_id, WP_Post $post ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- save() verified the nonce before calling this.
		if ( ! isset( $_POST['wp_artifacts_parent'] ) ) {
			return;
		}

		$new_parent = (int) $_POST['wp_artifacts_parent'];
		$old_parent = (int) $post->post_parent;

		// Attaching to a post decides whose URL can serve these bytes. Check before
		// writing anything, not after: the old order authorized only the meta write.
		if ( $new_parent > 0 && ! current_user_can( 'edit_post', $new_parent ) ) {
			return;
		}

		if ( $new_parent > 0 && ! in_array( get_post_type( $new_parent ), ArtifactPostType::parent_post_types(), true ) ) {
			return;
		}

		if ( $new_parent !== $old_parent ) {
			remove_action( 'save_post_' . ArtifactPostType::POST_TYPE, array( $this, 'save' ), 10 );
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_parent' => $new_parent,
				)
			);
			add_action( 'save_post_' . ArtifactPostType::POST_TYPE, array( $this, 'save' ), 10, 2 );

			if ( $old_parent > 0 && (int) get_post_meta( $old_parent, ArtifactPostType::META_DELIVER_FOR_PARENT, true ) === $post_id ) {
				delete_post_meta( $old_parent, ArtifactPostType::META_DELIVER_FOR_PARENT );
			}
		}

		if ( $new_parent <= 0 ) {
			return;
		}

		if ( isset( $_POST['wp_artifacts_deliver_for_parent'] ) ) {
			update_post_meta( $new_parent, ArtifactPostType::META_DELIVER_FOR_PARENT, $post_id );
		} elseif ( (int) get_post_meta( $new_parent, ArtifactPostType::META_DELIVER_FOR_PARENT, true ) === $post_id ) {
			delete_post_meta( $new_parent, ArtifactPostType::META_DELIVER_FOR_PARENT );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Applies an uploaded replacement bundle.
	 *
	 * @param int $post_id Artifact ID.
	 * @return void
	 */
	private function maybe_replace_bundle( int $post_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- save() verified the nonce before calling this.
		if ( empty( $_FILES['wp_artifacts_bundle']['tmp_name'] ) || ! is_uploaded_file( (string) $_FILES['wp_artifacts_bundle']['tmp_name'] ) ) {
			return;
		}

		$name = isset( $_FILES['wp_artifacts_bundle']['name'] ) ? sanitize_file_name( (string) $_FILES['wp_artifacts_bundle']['name'] ) : '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bytes = (string) file_get_contents( (string) $_FILES['wp_artifacts_bundle']['tmp_name'] );

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $bytes ) {
			return;
		}

		$repository = ArtifactRepository::instance();

		if ( str_ends_with( strtolower( $name ), '.zip' ) ) {
			$result = $repository->update( $post_id, $this->args_from_zip( $bytes ) );
		} else {
			$result = $repository->update( $post_id, array( 'content' => $bytes ) );
		}

		if ( is_wp_error( $result ) ) {
			set_transient( 'wp_artifacts_notice_' . get_current_user_id(), $result->get_error_message(), 60 );
		}
	}

	/**
	 * Turns an uploaded zip into update arguments by reusing the upload endpoint logic.
	 *
	 * @param string $bytes Raw zip archive.
	 * @return array<string,mixed>
	 */
	private function args_from_zip( string $bytes ): array {
		$temp = wp_tempnam( 'wp-artifacts-replace' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $temp, $bytes );

		$args = array();

		if ( class_exists( '\ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true === $zip->open( $temp ) ) {
				$files      = array();
				$entry      = '';
				$file_count = UploadUrl::entry_count( $zip );

				for ( $index = 0; $index < $file_count; $index++ ) {
					$path = (string) $zip->getNameIndex( $index );
					if ( '' === $path || str_ends_with( $path, '/' ) || str_contains( $path, '__MACOSX' ) ) {
						continue;
					}

					$data = (string) $zip->getFromIndex( $index );

					if ( '' === $entry && in_array( strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'html', 'htm' ), true ) ) {
						$entry           = $path;
						$args['entry']   = 'index.html';
						$args['content'] = $data;
						continue;
					}

					$files[] = array(
						'path'        => $path,
						'data_base64' => base64_encode( $data ),
					);
				}

				$args['files'] = $files;
				$zip->close();
			}
		}

		wp_delete_file( $temp );

		return $args;
	}

	/*
	---------------------------------------------------------------------
	 * admin-post handlers
	 */

	/**
	 * Streams the current bundle as a zip.
	 *
	 * @return void
	 */
	public function download_bundle(): void {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		check_admin_referer( 'wp_artifacts_download_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You cannot download this artifact.', 'wp-artifacts' ), '', array( 'response' => 403 ) );
		}

		$repository = ArtifactRepository::instance();
		$post       = $repository->require_artifact( $post_id );

		if ( is_wp_error( $post ) ) {
			wp_die( esc_html( $post->get_error_message() ), '', array( 'response' => 404 ) );
		}

		if ( ! class_exists( '\ZipArchive' ) ) {
			wp_die( esc_html__( 'This server has no ZipArchive support.', 'wp-artifacts' ), '', array( 'response' => 501 ) );
		}

		$manifest = $repository->manifest( $post_id );
		$rev      = $repository->assets_revision( $post_id );
		$temp     = wp_tempnam( 'wp-artifacts-export' );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $temp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not create the archive.', 'wp-artifacts' ), '', array( 'response' => 500 ) );
		}

		$zip->addFromString( $manifest->entry(), (string) $post->post_content );

		if ( $rev > 0 ) {
			foreach ( $manifest->files() as $file ) {
				if ( $file['path'] === $manifest->entry() ) {
					continue;
				}

				$path = BundleStore::instance()->file_path( $post_id, $rev, $file['path'] );
				if ( null !== $path && is_file( $path ) ) {
					$zip->addFile( $path, $file['path'] );
				}
			}
		}

		$zip->close();

		$filename = ( $post->post_name ? $post->post_name : 'artifact-' . $post_id ) . '.zip';

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $temp ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $temp );
		wp_delete_file( $temp );
		exit;
	}

	/**
	 * Mints a new share token.
	 *
	 * @return void
	 */
	public function regenerate_share(): void {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		check_admin_referer( 'wp_artifacts_share_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You cannot change this artifact.', 'wp-artifacts' ), '', array( 'response' => 403 ) );
		}

		ArtifactRepository::instance()->share_token( $post_id, true );

		wp_safe_redirect( (string) get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}

	/**
	 * Points the "post updated" messages at the artifact URL.
	 *
	 * @param array<string,array<int,string>> $messages Existing messages.
	 * @return array<string,array<int,string>>
	 */
	public function updated_messages( $messages ) {
		$post = get_post();
		if ( ! $post instanceof WP_Post || ArtifactPostType::POST_TYPE !== $post->post_type ) {
			return $messages;
		}

		$link = sprintf(
			' <a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
			esc_url( ArtifactRepository::instance()->url( $post ) ),
			esc_html__( 'Open the artifact', 'wp-artifacts' )
		);

		$messages[ ArtifactPostType::POST_TYPE ] = array(
			0  => '',
			1  => __( 'Artifact updated.', 'wp-artifacts' ) . $link,
			4  => __( 'Artifact updated.', 'wp-artifacts' ),
			6  => __( 'Artifact published.', 'wp-artifacts' ) . $link,
			7  => __( 'Artifact saved.', 'wp-artifacts' ),
			8  => __( 'Artifact submitted.', 'wp-artifacts' ),
			10 => __( 'Artifact draft updated.', 'wp-artifacts' ),
		);

		return $messages;
	}
}
