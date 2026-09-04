<?php
/**
 * Create, update, roll back and delete artifacts.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Storage;

use WPArtifacts\PostType\ArtifactPostType;
use WPArtifacts\Security\Capabilities;
use WPArtifacts\Settings;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The single place where artifact rows are written.
 *
 * Every write goes through here so that the stored bytes are exactly the bytes
 * that were sent, the revision carries the manifest, and the asset directory of
 * the new revision is written before the artifact is considered saved.
 */
final class ArtifactRepository {

	private const WRITABLE_STATUSES = array( 'draft', 'pending', 'publish', 'private', 'future' );

	/**
	 * Singleton instance.
	 *
	 * @var ArtifactRepository|null
	 */
	private static ?ArtifactRepository $instance = null;

	/**
	 * Content that must be stored verbatim during the current write, if any.
	 *
	 * @var string|null
	 */
	private ?string $verbatim = null;

	/**
	 * Singleton accessor.
	 *
	 * @return ArtifactRepository
	 */
	public static function instance(): ArtifactRepository {
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
		add_filter( 'wp_insert_post_data', array( $this, 'force_verbatim_content' ), PHP_INT_MAX, 2 );
		add_action( 'post_updated', array( $this, 'track_slug_change' ), 10, 3 );
		add_action( 'wp_delete_post_revision', array( $this, 'on_delete_revision' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ), 10, 2 );
	}

	/*
	---------------------------------------------------------------------
	 * Verbatim storage
	 */

	/**
	 * Declares the exact bytes that must land in `post_content`.
	 *
	 * @param string $content Entry document.
	 * @return void
	 */
	public function expect_verbatim( string $content ): void {
		$this->verbatim = $content;
	}

	/**
	 * Restores the exact bytes if a filter changed them, then clears the expectation.
	 *
	 * @param int $post_id Post ID that was written.
	 * @return void
	 */
	public function flush_verbatim( int $post_id ): void {
		if ( null === $this->verbatim ) {
			return;
		}

		$expected       = $this->verbatim;
		$this->verbatim = null;

		$this->repair_content( $post_id, $expected );
	}

	/**
	 * Keeps `post_content` untouched while this repository is writing.
	 *
	 * @param array<string,mixed> $data    Sanitized post data.
	 * @param array<string,mixed> $postarr Raw post array.
	 * @return array<string,mixed>
	 */
	public function force_verbatim_content( $data, $postarr ) {
		if ( null === $this->verbatim ) {
			return $data;
		}

		$post_type = isset( $data['post_type'] ) ? (string) $data['post_type'] : '';

		if ( ArtifactPostType::POST_TYPE === $post_type ) {
			$data['post_content'] = wp_slash( $this->verbatim );

			return $data;
		}

		if ( 'revision' === $post_type ) {
			$parent = isset( $postarr['post_parent'] ) ? (int) $postarr['post_parent'] : 0;
			if ( $parent > 0 && ArtifactPostType::POST_TYPE === get_post_type( $parent ) ) {
				$data['post_content'] = wp_slash( $this->verbatim );
			}
		}

		return $data;
	}

	/**
	 * Writes the raw bytes straight to the database when they differ from the expectation.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $expected Expected content.
	 * @return void
	 */
	private function repair_content( int $post_id, string $expected ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || $post->post_content === $expected ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->posts, array( 'post_content' => $expected ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
	}

	/*
	---------------------------------------------------------------------
	 * Writes
	 */

	/**
	 * Creates a new artifact.
	 *
	 * @param array<string,mixed> $args Publish arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create( array $args ) {
		return $this->save( null, $args );
	}

	/**
	 * Updates an existing artifact, always creating a revision.
	 *
	 * @param int                 $post_id Artifact ID.
	 * @param array<string,mixed> $args    Update arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update( int $post_id, array $args ) {
		$post = $this->require_artifact( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->save( $post, $args );
	}

	/**
	 * Shared create/update implementation.
	 *
	 * @param WP_Post|null        $existing Existing artifact, or null when creating.
	 * @param array<string,mixed> $args     Arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	private function save( ?WP_Post $existing, array $args ) {
		$warnings = array();
		$is_new   = ! $existing instanceof WP_Post;

		$content_type = isset( $args['content_type'] ) && '' !== $args['content_type']
			? ArtifactPostType::sanitize_content_type( (string) $args['content_type'] )
			: ( $is_new
				? ArtifactPostType::DEFAULT_CONTENT_TYPE
				: (string) get_post_meta( (int) $existing->ID, ArtifactPostType::META_CONTENT_TYPE, true ) );

		if ( '' === $content_type ) {
			$content_type = ArtifactPostType::DEFAULT_CONTENT_TYPE;
		}

		$entry = isset( $args['entry'] ) && '' !== $args['entry']
			? Manifest::normalize_path( (string) $args['entry'] )
			: ( $is_new ? 'index.html' : $this->manifest( (int) $existing->ID )->entry() );

		$has_files = array_key_exists( 'files', $args ) && is_array( $args['files'] );
		$payloads  = array();

		if ( $has_files ) {
			$payloads = $this->decode_files( (array) $args['files'] );
			if ( is_wp_error( $payloads ) ) {
				return $payloads;
			}
		}

		// A file shipped under the entry path *is* the entry document.
		$content_given = array_key_exists( 'content', $args );
		$content       = $content_given ? (string) $args['content'] : ( $is_new ? '' : (string) $existing->post_content );

		foreach ( $payloads as $index => $payload ) {
			if ( $payload['path'] === $entry ) {
				if ( ! $content_given ) {
					$content       = $payload['data'];
					$content_given = true;
				}
				unset( $payloads[ $index ] );
			}
		}
		$payloads = array_values( $payloads );

		$encoding_check = $this->check_encoding( $content, $content_type );
		if ( is_wp_error( $encoding_check ) ) {
			return $encoding_check;
		}

		$max_entry = (int) Settings::get( 'max_entry_bytes', 2097152 );
		if ( strlen( $content ) > $max_entry ) {
			return new WP_Error(
				'artifact_too_large',
				sprintf(
					/* translators: 1: document size, 2: allowed size. */
					__( 'The entry document is %1$s; the limit is %2$s. Move large data into bundle files.', 'wp-artifacts' ),
					size_format( strlen( $content ) ),
					size_format( $max_entry )
				),
				array( 'status' => 400 )
			);
		}

		if ( $has_files ) {
			$manifest = Manifest::build( $entry, $payloads, $content, $content_type );
			if ( is_wp_error( $manifest ) ) {
				return $manifest;
			}
		} else {
			$manifest = $is_new
				? Manifest::empty_manifest( $entry )
				: $this->manifest( (int) $existing->ID );
		}

		$gate = $this->check_executable_content( $content, $payloads );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$status = $this->resolve_status( $args, $existing );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$postarr = array(
			'post_type'    => ArtifactPostType::POST_TYPE,
			'post_status'  => $status,
			'post_content' => wp_slash( $content ),
		);

		if ( ! $is_new ) {
			$postarr['ID'] = (int) $existing->ID;
		}

		if ( array_key_exists( 'title', $args ) ) {
			$postarr['post_title'] = sanitize_text_field( (string) $args['title'] );
		} elseif ( $is_new ) {
			$postarr['post_title'] = __( 'Untitled artifact', 'wp-artifacts' );
		}

		if ( array_key_exists( 'excerpt', $args ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( (string) $args['excerpt'] );
		}

		if ( array_key_exists( 'slug', $args ) && '' !== $args['slug'] ) {
			$postarr['post_name'] = sanitize_title( (string) $args['slug'] );
		}

		if ( array_key_exists( 'parent_id', $args ) ) {
			$parent_id = (int) $args['parent_id'];
			if ( $parent_id > 0 ) {
				$parent = get_post( $parent_id );

				// Attaching to a post decides whose URL can serve these bytes, so it
				// takes the same permission as editing that post.
				if ( $parent instanceof WP_Post && ! current_user_can( 'edit_post', $parent_id ) ) {
					return new WP_Error(
						'artifact_forbidden',
						sprintf(
							/* translators: %d: post ID. */
							__( 'You cannot attach an artifact to post %d, because you cannot edit it.', 'wp-artifacts' ),
							$parent_id
						),
						array( 'status' => 403 )
					);
				}

				if ( ! $parent instanceof WP_Post || ! in_array( $parent->post_type, ArtifactPostType::parent_post_types(), true ) ) {
					return new WP_Error(
						'artifact_not_found',
						sprintf(
							/* translators: %d: post ID. */
							__( 'Post %d cannot be used as an artifact parent; it must be an existing post or page.', 'wp-artifacts' ),
							$parent_id
						),
						array( 'status' => 400 )
					);
				}
			}
			$postarr['post_parent'] = max( 0, $parent_id );
		}

		if ( array_key_exists( 'author_id', $args ) && (int) $args['author_id'] > 0 ) {
			$postarr['post_author'] = (int) $args['author_id'];
		} elseif ( $is_new && get_current_user_id() > 0 ) {
			$postarr['post_author'] = get_current_user_id();
		}

		if ( array_key_exists( 'date', $args ) && '' !== $args['date'] ) {
			$postarr['post_date'] = (string) $args['date'];
		}

		// Take ownership of revision creation so the manifest is stored first.
		$had_revisions = post_type_supports( ArtifactPostType::POST_TYPE, 'revisions' );
		if ( $had_revisions ) {
			remove_post_type_support( ArtifactPostType::POST_TYPE, 'revisions' );
		}

		$this->expect_verbatim( $content );
		$post_id = $is_new ? wp_insert_post( $postarr, true ) : wp_update_post( $postarr, true );

		if ( $had_revisions ) {
			add_post_type_support( ArtifactPostType::POST_TYPE, 'revisions' );
		}

		if ( is_wp_error( $post_id ) ) {
			$this->verbatim = null;

			return $post_id;
		}

		$post_id = (int) $post_id;
		$this->flush_verbatim( $post_id );

		$this->apply_meta( $post_id, $args, $manifest, $content_type, $is_new );

		$revision_id = $this->force_revision( $post_id, $content );

		$assets_rev = $this->assets_revision( $post_id );

		if ( $has_files ) {
			$target_rev = $revision_id > 0 ? $revision_id : $post_id;
			$written    = BundleStore::instance()->write_revision( $post_id, $target_rev, $payloads );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			$assets_rev = $target_rev;
		} elseif ( 0 === $assets_rev ) {
			$assets_rev = 0;
		}

		update_post_meta( $post_id, ArtifactPostType::META_ASSETS_REV, $assets_rev );
		if ( $revision_id > 0 ) {
			update_post_meta( $revision_id, ArtifactPostType::META_ASSETS_REV, $assets_rev );
		}

		if ( 0 === $revision_id ) {
			$warnings[] = __( 'WordPress did not create a revision for this write; revisions may be disabled by WP_POST_REVISIONS.', 'wp-artifacts' );
		}

		$this->apply_parent_delivery( $post_id, $args, $warnings );

		$stored = get_post( $post_id );
		if ( $stored instanceof WP_Post && $stored->post_content !== $content ) {
			$this->repair_content( $post_id, $content );
		}

		$record                = $this->record( get_post( $post_id ), false, true );
		$record['revision_id'] = $revision_id;
		$record['warnings']    = $warnings;

		return $record;
	}

	/**
	 * Writes every meta field a save may touch.
	 *
	 * @param int                 $post_id      Artifact ID.
	 * @param array<string,mixed> $args         Arguments.
	 * @param Manifest            $manifest     Manifest to store.
	 * @param string              $content_type Entry document MIME type.
	 * @param bool                $is_new       Whether the artifact was just created.
	 * @return void
	 */
	private function apply_meta( int $post_id, array $args, Manifest $manifest, string $content_type, bool $is_new ): void {
		update_post_meta( $post_id, ArtifactPostType::META_MANIFEST, $manifest->to_array() );
		update_post_meta( $post_id, ArtifactPostType::META_CONTENT_TYPE, $content_type );

		if ( array_key_exists( 'indexable', $args ) ) {
			update_post_meta( $post_id, ArtifactPostType::META_INDEXABLE, (bool) $args['indexable'] );
		} elseif ( $is_new ) {
			update_post_meta( $post_id, ArtifactPostType::META_INDEXABLE, false );
		}

		if ( array_key_exists( 'csp', $args ) && '' !== $args['csp'] ) {
			update_post_meta( $post_id, ArtifactPostType::META_CSP, Settings::sanitize_header_value( (string) $args['csp'] ) );
		} elseif ( $is_new ) {
			update_post_meta( $post_id, ArtifactPostType::META_CSP, 'inherit' );
		}

		if ( array_key_exists( 'wrap', $args ) ) {
			update_post_meta( $post_id, ArtifactPostType::META_WRAP, (bool) $args['wrap'] );
		} elseif ( $is_new ) {
			update_post_meta( $post_id, ArtifactPostType::META_WRAP, false );
		}

		if ( array_key_exists( 'provenance', $args ) && is_array( $args['provenance'] ) ) {
			update_post_meta( $post_id, ArtifactPostType::META_PROVENANCE, $this->sanitize_provenance( (array) $args['provenance'] ) );
		}

		if ( array_key_exists( 'redirect_to', $args ) && '' !== $args['redirect_to'] ) {
			update_post_meta( $post_id, ArtifactPostType::META_REDIRECT_TO, esc_url_raw( (string) $args['redirect_to'] ) );
		}

		$this->share_token( $post_id );
	}

	/**
	 * Applies the deliver-for-parent flag to the artifact's parent post.
	 *
	 * @param int                 $post_id  Artifact ID.
	 * @param array<string,mixed> $args     Arguments.
	 * @param array<int,string>   $warnings Warning list, by reference.
	 * @return void
	 */
	private function apply_parent_delivery( int $post_id, array $args, array &$warnings ): void {
		if ( ! array_key_exists( 'deliver_for_parent', $args ) ) {
			return;
		}

		$post   = get_post( $post_id );
		$parent = $post instanceof WP_Post ? (int) $post->post_parent : 0;

		if ( $parent <= 0 ) {
			$warnings[] = __( 'deliver_for_parent was ignored because the artifact has no parent post.', 'wp-artifacts' );

			return;
		}

		if ( ! current_user_can( 'edit_post', $parent ) ) {
			$warnings[] = __( 'deliver_for_parent was ignored because you cannot edit the parent post.', 'wp-artifacts' );

			return;
		}

		if ( $args['deliver_for_parent'] ) {
			update_post_meta( $parent, ArtifactPostType::META_DELIVER_FOR_PARENT, $post_id );
		} else {
			delete_post_meta( $parent, ArtifactPostType::META_DELIVER_FOR_PARENT );
		}
	}

	/**
	 * Forces a revision even when only meta or assets changed.
	 *
	 * @param int    $post_id Artifact ID.
	 * @param string $content Entry document, used to repair the revision row.
	 * @return int Revision ID, or 0 when revisions are unavailable.
	 */
	private function force_revision( int $post_id, string $content ): int {
		if ( ! post_type_supports( ArtifactPostType::POST_TYPE, 'revisions' ) ) {
			return 0;
		}

		add_filter( 'wp_save_post_revision_check_for_changes', '__return_false', PHP_INT_MAX );
		$this->expect_verbatim( $content );

		$revision_id = wp_save_post_revision( $post_id );

		$this->verbatim = null;
		remove_filter( 'wp_save_post_revision_check_for_changes', '__return_false', PHP_INT_MAX );

		if ( is_wp_error( $revision_id ) || empty( $revision_id ) ) {
			$latest = $this->latest_revision_id( $post_id );

			return $latest;
		}

		$revision_id = (int) $revision_id;
		$this->repair_content( $revision_id, $content );

		return $revision_id;
	}

	/**
	 * The newest revision ID of an artifact.
	 *
	 * @param int $post_id Artifact ID.
	 * @return int
	 */
	public function latest_revision_id( int $post_id ): int {
		$revisions = wp_get_post_revisions( $post_id, array( 'numberposts' => 1 ) );
		if ( empty( $revisions ) ) {
			return 0;
		}

		$revision = array_shift( $revisions );

		return $revision instanceof WP_Post ? (int) $revision->ID : 0;
	}

	/*
	---------------------------------------------------------------------
	 * Rollback, delete, share
	 */

	/**
	 * Restores a revision.
	 *
	 * @param int $post_id     Artifact ID.
	 * @param int $revision_id Revision ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function rollback( int $post_id, int $revision_id ) {
		$post = $this->require_artifact( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$revision = wp_get_post_revision( $revision_id );
		if ( ! $revision instanceof WP_Post || (int) $revision->post_parent !== $post_id ) {
			return new WP_Error(
				'artifact_not_found',
				sprintf(
					/* translators: 1: revision ID, 2: artifact ID. */
					__( 'Revision %1$d does not belong to artifact %2$d.', 'wp-artifacts' ),
					$revision_id,
					$post_id
				),
				array( 'status' => 404 )
			);
		}

		$gate = $this->check_executable_content( (string) $revision->post_content, array() );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$this->expect_verbatim( (string) $revision->post_content );
		$restored       = wp_restore_post_revision( $revision_id );
		$this->verbatim = null;

		if ( null === $restored || is_wp_error( $restored ) ) {
			return new WP_Error(
				'artifact_rollback_failed',
				__( 'WordPress refused to restore that revision.', 'wp-artifacts' ),
				array( 'status' => 500 )
			);
		}

		$this->repair_content( $post_id, (string) $revision->post_content );

		$assets_rev = (int) get_post_meta( $revision_id, ArtifactPostType::META_ASSETS_REV, true );
		if ( 0 === $assets_rev && is_dir( BundleStore::instance()->revision_dir( $post_id, $revision_id ) ) ) {
			$assets_rev = $revision_id;
		}

		update_post_meta( $post_id, ArtifactPostType::META_ASSETS_REV, $assets_rev );

		$new_revision = $this->latest_revision_id( $post_id );
		if ( $new_revision > 0 ) {
			update_post_meta( $new_revision, ArtifactPostType::META_ASSETS_REV, $assets_rev );
			$this->repair_content( $new_revision, (string) $revision->post_content );
		}

		$record                = $this->record( get_post( $post_id ), false, true );
		$record['revision_id'] = $new_revision > 0 ? $new_revision : $revision_id;
		$record['warnings']    = array();

		return $record;
	}

	/**
	 * Trashes or permanently deletes an artifact.
	 *
	 * @param int    $post_id     Artifact ID.
	 * @param bool   $force       Whether to bypass the trash.
	 * @param string $redirect_to Optional URL old links should redirect to.
	 * @return array<string,mixed>|WP_Error
	 */
	public function delete( int $post_id, bool $force = false, string $redirect_to = '' ) {
		$post = $this->require_artifact( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( '' !== $redirect_to ) {
			update_post_meta( $post_id, ArtifactPostType::META_REDIRECT_TO, esc_url_raw( $redirect_to ) );
			$this->remember_gone( $post, esc_url_raw( $redirect_to ) );
		} elseif ( $force ) {
			$this->remember_gone( $post, (string) get_post_meta( $post_id, ArtifactPostType::META_REDIRECT_TO, true ) );
		}

		$result = $force ? wp_delete_post( $post_id, true ) : wp_trash_post( $post_id );

		if ( ! $result ) {
			return new WP_Error(
				'artifact_delete_failed',
				__( 'WordPress refused to delete that artifact.', 'wp-artifacts' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'deleted' => true,
			'id'      => $post_id,
			'forced'  => $force,
		);
	}

	/**
	 * Records a deleted slug so its URL can answer with 301 or 410.
	 *
	 * @param WP_Post $post        Artifact being removed.
	 * @param string  $redirect_to Optional redirect target.
	 * @return void
	 */
	private function remember_gone( WP_Post $post, string $redirect_to ): void {
		$gone = get_option( 'wp_artifacts_gone', array() );
		$gone = is_array( $gone ) ? $gone : array();

		$slugs = array_merge( array( $post->post_name ), $this->old_slugs( (int) $post->ID ) );
		foreach ( $slugs as $slug ) {
			if ( '' === $slug ) {
				continue;
			}
			$gone[ $slug ] = $redirect_to;
		}

		if ( count( $gone ) > 500 ) {
			$gone = array_slice( $gone, -500, null, true );
		}

		update_option( 'wp_artifacts_gone', $gone, false );
	}

	/**
	 * Returns (creating if needed) the share token for an artifact.
	 *
	 * @param int  $post_id    Artifact ID.
	 * @param bool $regenerate Whether to mint a new token.
	 * @return string
	 */
	public function share_token( int $post_id, bool $regenerate = false ): string {
		$token = (string) get_post_meta( $post_id, ArtifactPostType::META_SHARE_TOKEN, true );

		if ( $regenerate || 32 > strlen( $token ) ) {
			$token = bin2hex( random_bytes( 32 ) );
			update_post_meta( $post_id, ArtifactPostType::META_SHARE_TOKEN, $token );
		}

		return $token;
	}

	/*
	---------------------------------------------------------------------
	 * Reads
	 */

	/**
	 * Loads an artifact by ID or slug.
	 *
	 * @param int|string $identifier Artifact ID or slug.
	 * @return WP_Post|WP_Error
	 */
	public function find( $identifier ) {
		if ( is_numeric( $identifier ) ) {
			return $this->require_artifact( (int) $identifier );
		}

		$post = $this->find_by_slug( (string) $identifier );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'artifact_not_found',
				sprintf(
					/* translators: %s: artifact slug. */
					__( 'No artifact with slug "%s" exists.', 'wp-artifacts' ),
					$identifier
				),
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * Resolves a slug to an artifact regardless of status.
	 *
	 * @param string $slug Post slug.
	 * @return WP_Post|null
	 */
	public function find_by_slug( string $slug ): ?WP_Post {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$found = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => ArtifactPostType::POST_TYPE,
				'post_status'      => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'numberposts'      => 1,
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);

		return empty( $found ) ? null : $found[0];
	}

	/**
	 * Loads an artifact, erroring when it is missing or of the wrong type.
	 *
	 * @param int $post_id Artifact ID.
	 * @return WP_Post|WP_Error
	 */
	public function require_artifact( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ArtifactPostType::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'artifact_not_found',
				sprintf(
					/* translators: %d: artifact ID. */
					__( 'No artifact with ID %d exists.', 'wp-artifacts' ),
					$post_id
				),
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * The manifest of an artifact or revision.
	 *
	 * @param int $post_id Artifact or revision ID.
	 * @return Manifest
	 */
	public function manifest( int $post_id ): Manifest {
		return Manifest::from_meta( get_post_meta( $post_id, ArtifactPostType::META_MANIFEST, true ) );
	}

	/**
	 * The revision whose directory holds the current assets.
	 *
	 * @param int $post_id Artifact ID.
	 * @return int
	 */
	public function assets_revision( int $post_id ): int {
		return (int) get_post_meta( $post_id, ArtifactPostType::META_ASSETS_REV, true );
	}

	/**
	 * Old slugs recorded for an artifact.
	 *
	 * @param int $post_id Artifact ID.
	 * @return array<int,string>
	 */
	public function old_slugs( int $post_id ): array {
		$slugs = get_post_meta( $post_id, ArtifactPostType::META_OLD_SLUGS, true );

		return is_array( $slugs ) ? array_values( array_filter( array_map( 'strval', $slugs ) ) ) : array();
	}

	/**
	 * The canonical URL of an artifact.
	 *
	 * @param WP_Post|int $post Artifact.
	 * @return string
	 */
	public function url( $post ): string {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$url = (string) get_permalink( $post );

		return self::apply_cookieless_host( $url );
	}

	/**
	 * Rewrites a URL onto the configured cookieless host, when there is one.
	 *
	 * @param string $url URL on the primary host.
	 * @return string
	 */
	public static function apply_cookieless_host( string $url ): string {
		$host = (string) Settings::get( 'cookieless_host', '' );
		if ( '' === $host || '' === $url ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		return str_replace( '://' . $parts['host'], '://' . $host, $url );
	}

	/**
	 * The share URL of an artifact.
	 *
	 * @param WP_Post|int $post Artifact.
	 * @return string
	 */
	public function share_url( $post ): string {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		return add_query_arg( 'share', $this->share_token( (int) $post->ID ), $this->url( $post ) );
	}

	/**
	 * The public record of an artifact used by the abilities and the admin.
	 *
	 * @param WP_Post|null $post            Artifact.
	 * @param bool         $include_content Whether to include the entry document.
	 * @param bool         $include_share   Whether to include the share URL.
	 * @return array<string,mixed>
	 */
	public function record( ?WP_Post $post, bool $include_content = false, bool $include_share = false ): array {
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$manifest   = $this->manifest( (int) $post->ID );
		$thumb_id   = (int) get_post_thumbnail_id( $post );
		$parent_id  = (int) $post->post_parent;
		$stored     = get_post_meta( (int) $post->ID, ArtifactPostType::META_PROVENANCE, true );
		$provenance = is_array( $stored ) ? $stored : array();

		$record = array(
			'id'                  => (int) $post->ID,
			'title'               => (string) $post->post_title,
			'slug'                => (string) $post->post_name,
			'status'              => (string) $post->post_status,
			'url'                 => $this->url( $post ),
			'edit_url'            => (string) get_edit_post_link( $post->ID, 'raw' ),
			'excerpt'             => (string) $post->post_excerpt,
			'author'              => (int) $post->post_author,
			'author_name'         => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'created'             => (string) get_post_time( 'c', true, $post ),
			'modified'            => (string) get_post_modified_time( 'c', true, $post ),
			'content_type'        => (string) get_post_meta( (int) $post->ID, ArtifactPostType::META_CONTENT_TYPE, true ),
			'entry'               => $manifest->entry(),
			'files'               => array_values( $manifest->files() ),
			'file_count'          => $manifest->count(),
			'bytes'               => $manifest->count() > 0 ? $manifest->total_bytes() : strlen( (string) $post->post_content ),
			'indexable'           => (bool) get_post_meta( (int) $post->ID, ArtifactPostType::META_INDEXABLE, true ),
			'csp'                 => (string) get_post_meta( (int) $post->ID, ArtifactPostType::META_CSP, true ),
			'wrap'                => (bool) get_post_meta( (int) $post->ID, ArtifactPostType::META_WRAP, true ),
			'parent_id'           => $parent_id,
			'parent_url'          => $parent_id > 0 ? (string) get_permalink( $parent_id ) : '',
			'delivers_for_parent' => $parent_id > 0 && (int) get_post_meta( $parent_id, ArtifactPostType::META_DELIVER_FOR_PARENT, true ) === (int) $post->ID,
			'is_front_page'       => 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post->ID,
			'thumbnail_url'       => $thumb_id > 0 ? (string) wp_get_attachment_image_url( $thumb_id, 'medium' ) : '',
			'provenance'          => $provenance,
			'revision_id'         => $this->latest_revision_id( (int) $post->ID ),
		);

		if ( $include_share ) {
			$record['share_url'] = $this->share_url( $post );
		}

		if ( $include_content ) {
			$record['content'] = (string) $post->post_content;
			$record['sha256']  = hash( 'sha256', (string) $post->post_content );
		}

		return $record;
	}

	/*
	---------------------------------------------------------------------
	 * Validation
	 */

	/**
	 * Decodes the base64 payloads of a `files` argument.
	 *
	 * @param array<int,mixed> $files Raw file descriptors.
	 * @return array<int,array{path:string,mime:string,data:string}>|WP_Error
	 */
	public function decode_files( array $files ) {
		$decoded = array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || empty( $file['path'] ) ) {
				return new WP_Error(
					'artifact_invalid_path',
					__( 'Every entry in "files" needs a "path" and "data_base64".', 'wp-artifacts' ),
					array( 'status' => 400 )
				);
			}

			$path = Manifest::normalize_path( (string) $file['path'] );

			$check = Manifest::validate_path( $path );
			if ( is_wp_error( $check ) ) {
				return $check;
			}

			if ( array_key_exists( 'data_base64', $file ) ) {
				$raw = base64_decode( (string) $file['data_base64'], true );
				if ( false === $raw ) {
					return new WP_Error(
						'artifact_invalid_payload',
						sprintf(
							/* translators: %s: relative path. */
							__( 'File "%s" has a data_base64 value that is not valid base64.', 'wp-artifacts' ),
							$path
						),
						array( 'status' => 400 )
					);
				}
			} elseif ( array_key_exists( 'data', $file ) ) {
				$raw = (string) $file['data'];
			} else {
				return new WP_Error(
					'artifact_invalid_payload',
					sprintf(
						/* translators: %s: relative path. */
						__( 'File "%s" has no data_base64 value.', 'wp-artifacts' ),
						$path
					),
					array( 'status' => 400 )
				);
			}

			$mime = Manifest::guess_mime( $path );

			// A declared type is checked against the extension rather than trusted:
			// claiming text/plain for a file the server might execute is exactly the
			// trick this refuses.
			if ( isset( $file['mime'] ) && '' !== $file['mime'] ) {
				$declared = strtolower( trim( explode( ';', (string) $file['mime'] )[0] ) );

				if ( $declared !== $mime && ! in_array( $declared, self::mime_aliases( $mime ), true ) ) {
					return new WP_Error(
						'artifact_mime_not_allowed',
						sprintf(
							/* translators: 1: relative path, 2: declared MIME type, 3: MIME type for the extension. */
							__( 'File "%1$s" declares MIME type "%2$s" but its extension means "%3$s". Send a matching type, or leave "mime" out and it is derived from the extension.', 'wp-artifacts' ),
							$path,
							$declared,
							$mime
						),
						array( 'status' => 400 )
					);
				}
			}

			$decoded[] = array(
				'path' => $path,
				'mime' => $mime,
				'data' => $raw,
			);
		}

		return $decoded;
	}

	/**
	 * Type names that mean the same thing as the extension's canonical type.
	 *
	 * @param string $mime Canonical MIME type for the extension.
	 * @return array<int,string>
	 */
	private static function mime_aliases( string $mime ): array {
		$aliases = array(
			'text/javascript' => array( 'application/javascript', 'application/x-javascript', 'text/ecmascript' ),
			'image/x-icon'    => array( 'image/vnd.microsoft.icon' ),
			'font/ttf'        => array( 'application/x-font-ttf', 'font/truetype' ),
			'font/otf'        => array( 'application/x-font-otf', 'font/opentype' ),
			'text/plain'      => array( 'text/markdown', 'text/csv' ),
		);

		return $aliases[ $mime ] ?? array();
	}

	/**
	 * Enforces the `unfiltered_html` gate.
	 *
	 * @param string                                                $content  Entry document.
	 * @param array<int,array{path:string,mime:string,data:string}> $payloads Bundle files.
	 * @return true|WP_Error
	 */
	public function check_executable_content( string $content, array $payloads ) {
		if ( Capabilities::can_publish_executable() ) {
			return true;
		}

		$reason = Capabilities::detect_script( $content );
		if ( null !== $reason ) {
			return $this->unfiltered_html_error(
				sprintf(
					/* translators: %s: what was detected, e.g. "a <script> tag". */
					__( 'The entry document contains %s.', 'wp-artifacts' ),
					$reason
				)
			);
		}

		foreach ( $payloads as $payload ) {
			if ( Capabilities::asset_is_executable( $payload['path'], $payload['mime'] ) ) {
				return $this->unfiltered_html_error(
					sprintf(
						/* translators: %s: relative path. */
						__( 'File "%s" can execute script.', 'wp-artifacts' ),
						$payload['path']
					)
				);
			}

			if ( str_starts_with( $payload['mime'], 'text/' ) ) {
				$asset_reason = Capabilities::detect_script( $payload['data'] );
				if ( null !== $asset_reason ) {
					return $this->unfiltered_html_error(
						sprintf(
							/* translators: 1: relative path, 2: what was detected. */
							__( 'File "%1$s" contains %2$s.', 'wp-artifacts' ),
							$payload['path'],
							$asset_reason
						)
					);
				}
			}
		}

		if ( ! Capabilities::allow_nonadmin_publish() ) {
			return $this->unfiltered_html_error( __( 'This site only allows administrators to publish artifacts.', 'wp-artifacts' ) );
		}

		return true;
	}

	/**
	 * Builds the standard unfiltered_html rejection.
	 *
	 * @param string $detail What was detected.
	 * @return WP_Error
	 */
	private function unfiltered_html_error( string $detail ): WP_Error {
		return new WP_Error(
			'artifact_requires_unfiltered_html',
			$detail . ' ' . __( 'The current user lacks the unfiltered_html capability. Ask a site administrator to grant it, or publish a script-free version.', 'wp-artifacts' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Rejects text entry documents that are not valid UTF-8.
	 *
	 * @param string $content      Entry document.
	 * @param string $content_type Declared MIME type.
	 * @return true|WP_Error
	 */
	private function check_encoding( string $content, string $content_type ) {
		$type = strtolower( trim( explode( ';', $content_type )[0] ) );

		$is_text = str_starts_with( $type, 'text/' )
			|| in_array( $type, array( 'application/json', 'image/svg+xml', 'application/xhtml+xml', 'application/manifest+json' ), true );

		if ( ! $is_text ) {
			return true;
		}

		if ( '' !== $content && function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
			return new WP_Error(
				'artifact_invalid_content',
				sprintf(
					/* translators: %s: MIME type. */
					__( 'The entry document is declared as %s but is not valid UTF-8. Re-encode it as UTF-8.', 'wp-artifacts' ),
					$content_type
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Resolves and authorizes the requested post status.
	 *
	 * @param array<string,mixed> $args     Arguments.
	 * @param WP_Post|null        $existing Existing artifact.
	 * @return string|WP_Error
	 */
	private function resolve_status( array $args, ?WP_Post $existing ) {
		$status = isset( $args['status'] ) && '' !== $args['status']
			? (string) $args['status']
			: ( $existing instanceof WP_Post ? (string) $existing->post_status : 'draft' );

		if ( ! in_array( $status, self::WRITABLE_STATUSES, true ) ) {
			return new WP_Error(
				'artifact_invalid_status',
				sprintf(
					/* translators: 1: requested status, 2: list of allowed statuses. */
					__( 'Status "%1$s" is not supported. Use one of: %2$s.', 'wp-artifacts' ),
					$status,
					implode( ', ', self::WRITABLE_STATUSES )
				),
				array( 'status' => 400 )
			);
		}

		if ( in_array( $status, array( 'publish', 'private', 'future' ), true ) && ! current_user_can( 'publish_artifacts' ) ) {
			return new WP_Error(
				'artifact_forbidden',
				__( 'You cannot publish artifacts. Save as "draft" instead, or ask for the publish_artifacts capability.', 'wp-artifacts' ),
				array( 'status' => 403 )
			);
		}

		return $status;
	}

	/**
	 * Cleans a provenance payload.
	 *
	 * @param array<string,mixed> $provenance Raw values.
	 * @return array<string,string>
	 */
	private function sanitize_provenance( array $provenance ): array {
		$clean = array();

		foreach ( $provenance as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$clean[ $key ] = 'source_url' === $key
				? esc_url_raw( (string) $value )
				: sanitize_text_field( (string) $value );
		}

		return $clean;
	}

	/*
	---------------------------------------------------------------------
	 * Hook callbacks
	 */

	/**
	 * Records the previous slug so old URLs can 301.
	 *
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post_after  Post after the update.
	 * @param WP_Post $post_before Post before the update.
	 * @return void
	 */
	public function track_slug_change( $post_id, $post_after, $post_before ): void {
		if ( ! $post_after instanceof WP_Post || ArtifactPostType::POST_TYPE !== $post_after->post_type ) {
			return;
		}

		$old = (string) $post_before->post_name;
		$new = (string) $post_after->post_name;

		if ( '' === $old || $old === $new ) {
			return;
		}

		$slugs = $this->old_slugs( (int) $post_id );
		if ( ! in_array( $old, $slugs, true ) ) {
			$slugs[] = $old;
		}

		$slugs = array_values( array_diff( $slugs, array( $new ) ) );

		update_post_meta( (int) $post_id, ArtifactPostType::META_OLD_SLUGS, $slugs );
	}

	/**
	 * Removes a revision's asset directory when nothing else references it.
	 *
	 * @param int          $revision_id Revision ID.
	 * @param WP_Post|null $revision    Revision object.
	 * @return void
	 */
	public function on_delete_revision( $revision_id, $revision = null ): void {
		$revision_id = (int) $revision_id;
		$revision    = $revision instanceof WP_Post ? $revision : get_post( $revision_id );

		if ( ! $revision instanceof WP_Post ) {
			return;
		}

		$parent_id = (int) $revision->post_parent;
		if ( ArtifactPostType::POST_TYPE !== get_post_type( $parent_id ) ) {
			return;
		}

		$owner = (int) get_post_meta( $revision_id, ArtifactPostType::META_ASSETS_REV, true );
		$owner = $owner > 0 ? $owner : $revision_id;

		if ( $this->assets_revision( $parent_id ) === $owner ) {
			return;
		}

		foreach ( wp_get_post_revisions( $parent_id, array( 'numberposts' => -1 ) ) as $sibling ) {
			if ( (int) $sibling->ID === $revision_id ) {
				continue;
			}
			$sibling_owner = (int) get_post_meta( (int) $sibling->ID, ArtifactPostType::META_ASSETS_REV, true );
			if ( $sibling_owner === $owner ) {
				return;
			}
		}

		BundleStore::instance()->delete_revision( $parent_id, $owner );
	}

	/**
	 * Removes every asset of an artifact that is being deleted for good.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 * @return void
	 */
	public function on_delete_post( $post_id, $post = null ): void {
		$post = $post instanceof WP_Post ? $post : get_post( (int) $post_id );

		if ( ! $post instanceof WP_Post || ArtifactPostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		BundleStore::instance()->delete_post( (int) $post_id );

		if ( $post->post_parent > 0 ) {
			$flag = (int) get_post_meta( (int) $post->post_parent, ArtifactPostType::META_DELIVER_FOR_PARENT, true );
			if ( $flag === (int) $post_id ) {
				delete_post_meta( (int) $post->post_parent, ArtifactPostType::META_DELIVER_FOR_PARENT );
			}
		}
	}
}
