<?php
/**
 * Plugin uninstall handler.
 *
 * Runs only when the user deletes the plugin from the WordPress admin.
 *
 * @package PostMetaEAC_RotiStudio
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$rspmeac_delete_data = get_option( 'rspmeac_delete_data_on_uninstall', false );

if ( ! $rspmeac_delete_data ) {
	return;
}

// Delete plugin options.
delete_option( 'rspmeac_process_speed' );
delete_option( 'rspmeac_items_per_page' );
delete_option( 'rspmeac_delete_data_on_uninstall' );
