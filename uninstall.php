<?php
/**
 * Uninstall routine.
 *
 * Options, capabilities and transients always go. Artifacts and their stored
 * bundles only go when the site owner asked for that under Settings → Artifacts.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes every trace of the plugin from the current site.
 *
 * @return void
 */
function wp_artifacts_uninstall_site(): void {
	$settings = get_option( 'wp_artifacts_settings', array() );
	$settings = is_array( $settings ) ? $settings : array();
	$purge    = ! empty( $settings['delete_data_on_uninstall'] );

	if ( $purge ) {
		$artifacts = get_posts(
			array(
				'post_type'        => 'artifact',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		foreach ( $artifacts as $artifact_id ) {
			wp_delete_post( (int) $artifact_id, true );
		}

		$uploads = wp_get_upload_dir();
		wp_artifacts_uninstall_rmdir( trailingslashit( $uploads['basedir'] ) . 'artifacts' );
	}

	if ( $purge ) {
		delete_post_meta_by_key( '_artifact_deliver_for_parent' );
	}

	$capabilities = array(
		'edit_artifact',
		'read_artifact',
		'delete_artifact',
		'edit_artifacts',
		'edit_others_artifacts',
		'delete_artifacts',
		'publish_artifacts',
		'read_private_artifacts',
		'delete_private_artifacts',
		'delete_published_artifacts',
		'delete_others_artifacts',
		'edit_private_artifacts',
		'edit_published_artifacts',
	);

	$roles = wp_roles();
	foreach ( array_keys( $roles->roles ) as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role instanceof WP_Role ) {
			continue;
		}
		foreach ( $capabilities as $capability ) {
			$role->remove_cap( $capability );
		}
	}

	foreach ( array(
		'wp_artifacts_settings',
		'wp_artifacts_version',
		'wp_artifacts_rewrite_signature',
		'wp_artifacts_gone',
		'wp_artifacts_previous_front',
	) as $option ) {
		delete_option( $option );
	}

	delete_transient( 'wp_artifacts_site_style' );
}

/**
 * Recursively removes a directory.
 *
 * @param string $dir Absolute directory path.
 * @return void
 */
function wp_artifacts_uninstall_rmdir( string $dir ): void {
	$real = realpath( $dir );
	if ( false === $real || ! is_dir( $real ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( ! $item instanceof SplFileInfo ) {
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

if ( is_multisite() ) {
	$wp_artifacts_sites = get_sites(
		array(
			'fields'        => 'ids',
			'number'        => 0,
			'no_found_rows' => true,
		)
	);

	foreach ( $wp_artifacts_sites as $wp_artifacts_site_id ) {
		switch_to_blog( (int) $wp_artifacts_site_id );
		wp_artifacts_uninstall_site();
		restore_current_blog();
	}

	unset( $wp_artifacts_sites, $wp_artifacts_site_id );
} else {
	wp_artifacts_uninstall_site();
}
