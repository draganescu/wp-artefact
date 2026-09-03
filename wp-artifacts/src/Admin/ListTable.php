<?php
/**
 * The artifact list table.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Admin;

use WPArtifacts\Abilities\Screenshot;
use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\Storage\ArtifactRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Columns, row actions and bulk actions for `edit.php?post_type=artifact`.
 */
final class ListTable {

	/**
	 * Singleton instance.
	 *
	 * @var ListTable|null
	 */
	private static ?ListTable $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return ListTable
	 */
	public static function instance(): ListTable {
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
		$type = ArtifactPostType::POST_TYPE;

		add_filter( "manage_{$type}_posts_columns", array( $this, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$type}_sortable_columns", array( $this, 'sortable_columns' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( "bulk_actions-edit-{$type}", array( $this, 'bulk_actions' ) );
		add_filter( "handle_bulk_actions-edit-{$type}", array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		add_action( 'admin_head', array( $this, 'styles' ) );
	}

	/**
	 * Defines the columns.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function columns( $columns ) {
		$new = array();

		if ( isset( $columns['cb'] ) ) {
			$new['cb'] = $columns['cb'];
		}

		$new['wp_artifacts_thumb']  = __( 'Screenshot', 'wp-artifacts' );
		$new['title']               = __( 'Title', 'wp-artifacts' );
		$new['wp_artifacts_status'] = __( 'Status', 'wp-artifacts' );
		$new['wp_artifacts_size']   = __( 'Size', 'wp-artifacts' );
		$new['wp_artifacts_files']  = __( 'Files', 'wp-artifacts' );
		$new['wp_artifacts_tool']   = __( 'Made with', 'wp-artifacts' );
		$new['wp_artifacts_parent'] = __( 'Represents', 'wp-artifacts' );
		$new['author']              = __( 'Author', 'wp-artifacts' );
		$new['date']                = isset( $columns['date'] ) ? $columns['date'] : __( 'Date', 'wp-artifacts' );

		return $new;
	}

	/**
	 * Marks the size column sortable by modification date fallback.
	 *
	 * @param array<string,string> $columns Sortable columns.
	 * @return array<string,string>
	 */
	public function sortable_columns( $columns ) {
		$columns['wp_artifacts_status'] = 'post_status';

		return $columns;
	}

	/**
	 * Renders one cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Artifact ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$repository = ArtifactRepository::instance();

		switch ( $column ) {
			case 'wp_artifacts_thumb':
				$thumbnail = get_the_post_thumbnail( $post, array( 80, 60 ), array( 'class' => 'wp-artifacts-thumb' ) );
				if ( '' === $thumbnail ) {
					echo '<span class="wp-artifacts-thumb wp-artifacts-thumb--empty" aria-hidden="true"></span>';
					break;
				}
				echo wp_kses_post( $thumbnail );
				break;

			case 'wp_artifacts_status':
				$status = get_post_status_object( (string) $post->post_status );
				echo esc_html( $status instanceof \stdClass ? (string) $status->label : (string) $post->post_status );
				if ( '' !== (string) $post->post_password ) {
					echo ' <span class="dashicons dashicons-lock" title="' . esc_attr__( 'Password protected', 'wp-artifacts' ) . '"></span>';
				}
				break;

			case 'wp_artifacts_size':
				$manifest = $repository->manifest( (int) $post->ID );
				$bytes    = $manifest->count() > 0 ? $manifest->total_bytes() : strlen( (string) $post->post_content );
				echo esc_html( (string) size_format( $bytes, 1 ) );
				break;

			case 'wp_artifacts_files':
				$manifest = $repository->manifest( (int) $post->ID );
				echo esc_html( (string) max( 1, $manifest->count() ) );
				break;

			case 'wp_artifacts_tool':
				$stored     = get_post_meta( (int) $post->ID, ArtifactPostType::META_PROVENANCE, true );
				$provenance = is_array( $stored ) ? $stored : array();
				$parts      = array_filter(
					array(
						isset( $provenance['tool'] ) ? (string) $provenance['tool'] : '',
						isset( $provenance['model'] ) ? (string) $provenance['model'] : '',
					)
				);
				echo '' === implode( '', $parts ) ? '&#8212;' : esc_html( implode( ' · ', $parts ) );
				break;

			case 'wp_artifacts_parent':
				$parent_id = (int) $post->post_parent;
				if ( $parent_id <= 0 ) {
					echo '&#8212;';
					break;
				}

				$delivers = (int) get_post_meta( $parent_id, ArtifactPostType::META_DELIVER_FOR_PARENT, true ) === (int) $post->ID;

				printf(
					'<a href="%1$s">%2$s</a>%3$s',
					esc_url( (string) get_edit_post_link( $parent_id ) ),
					esc_html( (string) get_the_title( $parent_id ) ),
					$delivers ? ' <span class="wp-artifacts-badge">' . esc_html__( 'delivering', 'wp-artifacts' ) . '</span>' : ''
				);
				break;
		}
	}

	/**
	 * Adds view / copy row actions.
	 *
	 * @param array<string,string> $actions Existing actions.
	 * @param WP_Post              $post    Post.
	 * @return array<string,string>
	 */
	public function row_actions( $actions, $post ) {
		if ( ! $post instanceof WP_Post || ArtifactPostType::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$repository = ArtifactRepository::instance();

		$actions['wp_artifacts_view'] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
			esc_url( $repository->url( $post ) ),
			esc_html__( 'Open', 'wp-artifacts' )
		);

		if ( current_user_can( 'edit_post', (int) $post->ID ) ) {
			$actions['wp_artifacts_share'] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
				esc_url( $repository->share_url( $post ) ),
				esc_html__( 'Share link', 'wp-artifacts' )
			);
		}

		return $actions;
	}

	/**
	 * Adds the screenshot bulk action.
	 *
	 * @param array<string,string> $actions Bulk actions.
	 * @return array<string,string>
	 */
	public function bulk_actions( $actions ) {
		if ( Screenshot::provider_available() ) {
			$actions['wp_artifacts_screenshot'] = __( 'Regenerate screenshots', 'wp-artifacts' );
		}

		return $actions;
	}

	/**
	 * Runs the screenshot bulk action.
	 *
	 * @param string           $redirect_to Redirect URL.
	 * @param string           $action      Action name.
	 * @param array<int,mixed> $post_ids    Selected IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( 'wp_artifacts_screenshot' !== $action ) {
			return $redirect_to;
		}

		$done = 0;

		foreach ( (array) $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
				continue;
			}

			delete_post_thumbnail( $post );
			$result = Screenshot::execute( array( 'id' => (int) $post->ID ) );

			if ( is_array( $result ) && empty( $result['error'] ) ) {
				++$done;
			}
		}

		return add_query_arg( 'wp_artifacts_shot', $done, $redirect_to );
	}

	/**
	 * Reports the result of the screenshot bulk action.
	 *
	 * @return void
	 */
	public function bulk_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST['wp_artifacts_shot'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$count = (int) $_REQUEST['wp_artifacts_shot'];

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of screenshots. */
					_n( '%d screenshot regenerated.', '%d screenshots regenerated.', $count, 'wp-artifacts' ),
					$count
				)
			)
		);
	}

	/**
	 * A few rules so the thumbnail column does not jump around.
	 *
	 * @return void
	 */
	public function styles(): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || ArtifactPostType::POST_TYPE !== $screen->post_type ) {
			return;
		}

		echo '<style>
.column-wp_artifacts_thumb { width: 96px; }
.wp-artifacts-thumb { display: block; width: 80px; height: 60px; object-fit: cover; border-radius: 3px; border: 1px solid #dcdcde; }
.wp-artifacts-thumb--empty { background: repeating-linear-gradient(45deg, #f0f0f1, #f0f0f1 6px, #fff 6px, #fff 12px); }
.column-wp_artifacts_size, .column-wp_artifacts_files { width: 80px; }
.wp-artifacts-badge { display: inline-block; padding: 0 6px; border-radius: 9px; background: #d5e5f5; color: #1d3f5e; font-size: 11px; }
</style>';
	}
}
