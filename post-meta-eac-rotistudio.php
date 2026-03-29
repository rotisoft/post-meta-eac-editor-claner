<?php
/**
 * Plugin Name: Post Meta Editor and Cleaner by RotiStudio
 * Plugin URI: https://rotistudio.com/plugins/post-meta-eac-editor-cleaner/
 * Description: Post Meta bulk editor to delete unused data, overwrite values, run search and replace, and clean your database directly from the admin panel.
 * Version: 1.0.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: RotiStudio - Tamas Rottenbacher
 * Author URI: https://rotistudio.com
 * License: GPLv2 or later
 * Text Domain: rotistudio-post-meta-editor-cleaner
 * Domain Path: /languages
 *
 * @package PostMetaEAC_RotiStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RSPMEAC_VERSION', '1.0.0' );
define( 'RSPMEAC_PATH', plugin_dir_path( __FILE__ ) );
define( 'RSPMEAC_URL', plugin_dir_url( __FILE__ ) );

if ( is_admin() ) {
	require_once RSPMEAC_PATH . 'admin/admin-core.php';
}
