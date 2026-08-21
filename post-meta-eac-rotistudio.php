<?php
/**
 * Plugin Name: Post Meta Editor and Cleaner by RotiStudio
 * Plugin URI: https://rotistudio.com/plugins/post-meta-eac-editor-cleaner/
 * Description: Post Meta bulk editor to delete unused data, overwrite values, run search and replace, and clean your database directly from the admin panel.
 * Version: 1.4.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: RotiStudio - Tamas Rottenbacher
 * Author URI: https://rotistudio.com
 * License: GPLv2 or later
 * Text Domain: post-meta-eac-rotistudio
 * Domain Path: /languages
 *
 * @package PostMetaEAC_RotiStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RSPMEAC_VERSION', '1.4.0' );
define( 'RSPMEAC_PATH', plugin_dir_path( __FILE__ ) );
define( 'RSPMEAC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Grant the plugin capability to administrators.
 *
 * Shared by activation and upgrade so capability sync stays in one place.
 *
 * @return void
 */
function rspmeac_add_capabilities() {
	$role = get_role( 'administrator' );
	if ( $role ) {
		$role->add_cap( 'manage_post_meta_cleanup' );
	}
}

/**
 * Run capability grant on plugin activation.
 *
 * @return void
 */
function rspmeac_activate() {
	rspmeac_add_capabilities();
}
register_activation_hook( __FILE__, 'rspmeac_activate' );

/**
 * Ensure administrators have the plugin capability after upgrades.
 *
 * @return void
 */
function rspmeac_ensure_capability() {
	if ( get_option( 'rspmeac_caps_version' ) === RSPMEAC_VERSION ) {
		return;
	}

	rspmeac_add_capabilities();
	update_option( 'rspmeac_caps_version', RSPMEAC_VERSION, false );
}
add_action( 'admin_init', 'rspmeac_ensure_capability', 5 );

/**
 * Load plugin translations (supplements or overrides global language packs).
 *
 * WordPress JIT loading prefers wp-content/languages/plugins/ and would skip
 * the plugin MO when a global language pack exists. Explicitly merge the
 * plugin MO so new strings ship with each plugin release.
 *
 * @return void
 */
function rspmeac_load_textdomain() {
	$domain = 'post-meta-eac-rotistudio';
	$rel    = dirname( plugin_basename( __FILE__ ) ) . '/languages';

	load_plugin_textdomain( $domain, false, $rel ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Registers the custom languages path; plugin MO is merged explicitly below for release updates.

	$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	$mofile = RSPMEAC_PATH . 'languages/' . $domain . '-' . $locale . '.mo';

	if ( is_readable( $mofile ) ) {
		load_textdomain( $domain, $mofile, $locale );
	}
}
add_action( 'plugins_loaded', 'rspmeac_load_textdomain', 1 );

if ( is_admin() ) {
	require_once RSPMEAC_PATH . 'admin/admin-core.php';
}
