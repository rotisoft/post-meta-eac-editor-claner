<?php
/**
 * Plugin uninstall handler.
 *
 * Runs only when the user deletes the plugin from the WordPress admin.
 * Data cleanup is opt-in via Settings → “Delete plugin data when this plugin
 * is removed from the Plugins list.”
 *
 * @package PostMetaEAC_RotiStudio
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete all plugin data for the current site.
 *
 * Removes plugin options, the cached overview index (transients), leftover
 * per-meta-key operation lock records (rspmeac_op_*), and the custom
 * capability granted to administrators on activation.
 *
 * Does not delete rows from wp_postmeta.
 *
 * @return void
 */
function rspmeac_uninstall_site_data() {
	global $wpdb;

	// Delete plugin options.
	delete_option( 'rspmeac_process_speed' );
	delete_option( 'rspmeac_items_per_page' );
	delete_option( 'rspmeac_delete_data_on_uninstall' );
	delete_option( 'rspmeac_caps_version' );

	// Delete cached overview index.
	delete_transient( 'rspmeac_meta_overview' );
	delete_transient( 'rspmeac_meta_overview_lock' );

	// Delete leftover operation lock/checkpoint records. Their names contain
	// an md5 hash, so they must be collected with a prefix query first.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, prefix lookup has no core API.
	$rspmeac_lock_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'rspmeac_op_' ) . '%'
		)
	);

	if ( is_array( $rspmeac_lock_options ) ) {
		foreach ( $rspmeac_lock_options as $rspmeac_lock_option ) {
			delete_option( $rspmeac_lock_option );
		}
	}

	// Remove the capability granted on activation.
	$rspmeac_role = get_role( 'administrator' );
	if ( $rspmeac_role ) {
		$rspmeac_role->remove_cap( 'manage_post_meta_cleanup' );
	}
}

// Read the opt-in flag before any option is deleted.
$rspmeac_delete_data = (bool) get_option( 'rspmeac_delete_data_on_uninstall', false );

if ( is_multisite() ) {
	// Clean up every site where the option is enabled.
	$rspmeac_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $rspmeac_site_ids as $rspmeac_site_id ) {
		switch_to_blog( (int) $rspmeac_site_id );

		if ( get_option( 'rspmeac_delete_data_on_uninstall', false ) ) {
			rspmeac_uninstall_site_data();
		}

		restore_current_blog();
	}
} elseif ( $rspmeac_delete_data ) {
	rspmeac_uninstall_site_data();
}
