<?php
/**
 * Admin menu registration, asset loading, AJAX handling and page rendering.
 *
 * @package PostMetaEAC_RotiStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! is_admin() ) {
	return;
}

/**
 * Build reverse lookup maps for meta keys based on meta-sources.php.
 *
 * Return value:
 *  - 'exact'  : meta_key => plugin name
 *  - 'prefix' : prefix   => plugin name (sorted from longest to shortest)
 *
 * @return array{exact: array<string,string>, prefix: array<string,string>}
 */
function rspmeac_get_meta_source_map() {
	static $data = null;

	if ( null !== $data ) {
		return $data;
	}

	$sources_file = RSPMEAC_PATH . 'admin/meta-sources.php';
	$sources      = file_exists( $sources_file ) ? include $sources_file : array();

	$exact_map  = array();
	$prefix_map = array();

	$exact_sources = isset( $sources['exact'] ) ? $sources['exact'] : $sources;
	foreach ( $exact_sources as $plugin_name => $keys ) {
		foreach ( $keys as $key ) {
			$exact_map[ $key ] = $plugin_name;
		}
	}

	$prefix_sources = isset( $sources['prefix'] ) ? $sources['prefix'] : array();
	foreach ( $prefix_sources as $plugin_name => $prefixes ) {
		foreach ( $prefixes as $prefix ) {
			$prefix_map[ $prefix ] = $plugin_name;
		}
	}

	// Match longest prefix first.
	uksort(
		$prefix_map,
		function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);

	$data = array(
		'exact'  => $exact_map,
		'prefix' => $prefix_map,
	);

	return $data;
}

/**
 * Find the source plugin for a meta key: first exact match, then prefix-based match.
 *
 * @param string $key The meta key to search for.
 * @return string Plugin name, or empty string if unknown.
 */
function rspmeac_get_source_for_key( $key ) {
	$map = rspmeac_get_meta_source_map();

	if ( isset( $map['exact'][ $key ] ) ) {
		return $map['exact'][ $key ];
	}

	foreach ( $map['prefix'] as $prefix => $plugin_name ) {
		if ( 0 === strpos( $key, $prefix ) ) {
			return $plugin_name;
		}
	}

	return '';
}

/**
 * Register admin menu item under Tools menu, and hidden Help and Settings pages.
 *
 * @return void
 */
function rspmeac_register_admin_menu() {
	add_management_page(
		__( 'Post Meta EAC', 'rotistudio-post-meta-editor-cleaner' ),
		__( 'Post Meta EAC', 'rotistudio-post-meta-editor-cleaner' ),
		'manage_options',
		'rspmeac-main',
		'rspmeac_render_dashboard_page'
	);

	add_submenu_page(
		'',
		__( 'Settings', 'rotistudio-post-meta-editor-cleaner' ),
		__( 'Settings', 'rotistudio-post-meta-editor-cleaner' ),
		'manage_options',
		'rspmeac-settings',
		'rspmeac_render_settings_page'
	);

	add_submenu_page(
		'',
		__( 'Help', 'rotistudio-post-meta-editor-cleaner' ),
		__( 'Help', 'rotistudio-post-meta-editor-cleaner' ),
		'manage_options',
		'rspmeac-help',
		'rspmeac_render_help_page'
	);
}
add_action( 'admin_menu', 'rspmeac_register_admin_menu', 10 );

/**
 * Set admin page title for hidden submenu pages (parent '') so core admin-header does not pass null to strip_tags().
 *
 * @return void
 */
function rspmeac_set_hidden_admin_page_title() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Title only, page context.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$titles = array(
		'rspmeac-settings' => __( 'Settings', 'rotistudio-post-meta-editor-cleaner' ),
		'rspmeac-help'     => __( 'Help', 'rotistudio-post-meta-editor-cleaner' ),
	);
	if ( isset( $titles[ $page ] ) ) {
		$GLOBALS['title'] = $titles[ $page ];
	}
}
add_action( 'load-admin_page_rspmeac-settings', 'rspmeac_set_hidden_admin_page_title', 10 );
add_action( 'load-admin_page_rspmeac-help', 'rspmeac_set_hidden_admin_page_title', 10 );

/**
 * Load admin CSS and JS only on plugin pages.
 *
 * @param string $hook_suffix The current admin page hook suffix.
 * @return void
 */
function rspmeac_enqueue_admin_assets( $hook_suffix ) {
	$plugin_pages = array(
		'tools_page_rspmeac-main',
		'admin_page_rspmeac-settings',
		'admin_page_rspmeac-help',
	);

	if ( ! in_array( $hook_suffix, $plugin_pages, true ) ) {
		return;
	}

	$css_file = RSPMEAC_PATH . 'admin/css/admin-style.css';
	$js_file  = RSPMEAC_PATH . 'admin/js/admin-script.js';

	wp_enqueue_style(
		'rspmeac-admin-style',
		RSPMEAC_URL . 'admin/css/admin-style.css',
		array(),
		file_exists( $css_file ) ? filemtime( $css_file ) : RSPMEAC_VERSION
	);

	wp_enqueue_script(
		'rspmeac-admin-script',
		RSPMEAC_URL . 'admin/js/admin-script.js',
		array( 'jquery' ),
		file_exists( $js_file ) ? filemtime( $js_file ) : RSPMEAC_VERSION,
		true
	);

	wp_localize_script(
		'rspmeac-admin-script',
		'rspmeacData',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'rspmeac_meta_nonce' ),
			'i18n'    => array(
				'processing'         => __( 'Processing…', 'rotistudio-post-meta-editor-cleaner' ),
				'done'               => __( 'Done!', 'rotistudio-post-meta-editor-cleaner' ),
				'error'              => __( 'An error occurred.', 'rotistudio-post-meta-editor-cleaner' ),
				'confirmDelete'      => __( 'Are you sure you want to delete this meta key and all its values for all posts?', 'rotistudio-post-meta-editor-cleaner' ),
				'confirmDeleteValue' => __( 'Are you sure you want to clear the values of this meta key for all posts? The key itself will remain.', 'rotistudio-post-meta-editor-cleaner' ),
				/* translators: %d: number of selected items. */
			'confirmBulk'        => __( 'Are you sure you want to perform this action on %d selected items?', 'rotistudio-post-meta-editor-cleaner' ),
				'selectAction'       => __( 'Please select an action.', 'rotistudio-post-meta-editor-cleaner' ),
				'selectItems'        => __( 'Please select at least one item.', 'rotistudio-post-meta-editor-cleaner' ),
				'confirmOverwrite'   => __( 'Are you sure you want to overwrite and replace the full post meta field content?', 'rotistudio-post-meta-editor-cleaner' ),
				'confirmSearchReplaceValue'       => __( 'Are you sure you want to perform search & replace on all values (values only) for this meta key?', 'rotistudio-post-meta-editor-cleaner' ),
				'confirmSearchReplaceValueAndKey' => __( 'Are you sure you want to perform search & replace on all values and keys (name and value) for this meta key?', 'rotistudio-post-meta-editor-cleaner' ),
				'overwriteLabel'     => __( 'New value:', 'rotistudio-post-meta-editor-cleaner' ),
				'searchLabel'        => __( 'Search:', 'rotistudio-post-meta-editor-cleaner' ),
				'replaceLabel'       => __( 'Replace with:', 'rotistudio-post-meta-editor-cleaner' ),
				'applyButton'        => __( 'Apply', 'rotistudio-post-meta-editor-cleaner' ),
				'cancelButton'       => __( 'Cancel', 'rotistudio-post-meta-editor-cleaner' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'rspmeac_enqueue_admin_assets', 10 );



/**
 * Define admin header navigation links.
 *
 * @return array Slug => label pairs.
 */
function rspmeac_get_admin_nav_links() {
	return array(
		'rspmeac-main'     => __( 'Post Meta table', 'rotistudio-post-meta-editor-cleaner' ),
		'rspmeac-settings' => __( 'Settings', 'rotistudio-post-meta-editor-cleaner' ),
		'rspmeac-help'     => __( 'Help', 'rotistudio-post-meta-editor-cleaner' ),
	);
}

/**
 * Determine the current admin page slug.
 *
 * @return string The current page slug.
 */
function rspmeac_get_current_page_slug() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page identification, no state change.
	return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
}

/**
 * Render admin page wrapper with unified header.
 *
 * @param string $page_file The page file name to load (e.g. 'page-dashboard.php').
 * @return void
 */
function rspmeac_render_admin_wrapper( $page_file ) {
	$nav_links                  = rspmeac_get_admin_nav_links();
	$current_slug               = rspmeac_get_current_page_slug();
	$rspmeac_admin_screen_title = get_admin_page_title();
	?>
	<h1 style="display: none !important;"><?php esc_html_e( 'Post Meta EAC', 'rotistudio-post-meta-editor-cleaner' ); ?></h1>
	<div class="wrap rspmeac-admin-wrap">
		<h2 class="rspmeac-hidden-title"><?php echo esc_html( is_string( $rspmeac_admin_screen_title ) ? $rspmeac_admin_screen_title : '' ); ?></h2>

		<div class="rspmeac-admin-header">
			<h1 class="rspmeac-admin-title"><?php esc_html_e( 'Post Meta Editor and Cleaner by RotiStudio', 'rotistudio-post-meta-editor-cleaner' ); ?></h1>

			<nav class="rspmeac-admin-nav">
				<?php
				foreach ( $nav_links as $slug => $label ) {
					$url          = admin_url( 'admin.php?page=' . $slug );
					$active_class = ( $current_slug === $slug ) ? ' rspmeac-admin-nav-active' : '';

					printf(
						'<a href="%s" class="rspmeac-admin-nav-link%s">%s</a>',
						esc_url( $url ),
						esc_attr( $active_class ),
						esc_html( $label )
					);
				}
				?>
			</nav>
		</div>

		<div class="rspmeac-admin-content">
			<?php
			$file_path = RSPMEAC_PATH . 'admin/' . $page_file;

			if ( file_exists( $file_path ) ) {
				require $file_path;
			}
			?>
		</div>

		<div class="rspmeac-admin-footer">
			<?php
			$plugin_data = get_plugin_data( RSPMEAC_PATH . 'post-meta-eac-rotistudio.php' );

			printf(
				'%s - %s - by RotiStudio.com - <a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_html( $plugin_data['Name'] ),
				esc_html( $plugin_data['Version'] ),
				esc_url( $plugin_data['PluginURI'] ),
				esc_html( $plugin_data['PluginURI'] )
			);
			?>
		</div>
	</div>
	<?php
}


/**
 * Bulk action notice megjelenítése a dashboard oldalon.
 *
 * @return void
 */
function rspmeac_admin_notices() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Notice megjelenítés, nincs állapotváltozás.
	if ( ! isset( $_GET['page'] ) || 'rspmeac-main' !== $_GET['page'] ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Notice megjelenítés.
	if ( isset( $_GET['bulk_error'] ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid bulk action or no items selected.', 'rotistudio-post-meta-editor-cleaner' ) . '</p></div>';
	}
}
add_action( 'admin_notices', 'rspmeac_admin_notices', 10 );

/**
 * Rekurzívan alkalmazza a keresés-csere műveletet serialized adatokon.
 *
 * @param mixed  $value           Bármilyen érték (string, tömb, stb.).
 * @param string $search          Keresendő szöveg.
 * @param string $replace         Csere szöveg.
 * @param bool   $replace_in_keys Ha true, tömb kulcsokban is cserél.
 * @return mixed A módosított érték.
 */
function rspmeac_replace_in_serialized( $value, $search, $replace, $replace_in_keys = false ) {
	if ( is_string( $value ) ) {
		return str_replace( $search, $replace, $value );
	}
	if ( is_array( $value ) ) {
		$result = array();
		foreach ( $value as $k => $v ) {
			$new_key   = ( $replace_in_keys && is_string( $k ) ) ? str_replace( $search, $replace, $k ) : $k;
			$result[ $new_key ] = rspmeac_replace_in_serialized( $v, $search, $replace, $replace_in_keys );
		}
		return $result;
	}
	return $value;
}

/**
 * AJAX handler: kötegelt meta adat törlés / érték törlés.
 *
 * @return void
 */
function rspmeac_ajax_process_meta() {
	// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin batch operation, meta_key required, wp_postmeta has native index.
	check_ajax_referer( 'rspmeac_meta_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'rotistudio-post-meta-editor-cleaner' ) ) );
	}

	$meta_key      = isset( $_POST['meta_key'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_key'] ) ) : '';
	$action_type   = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';
	$offset        = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
	$new_value     = isset( $_POST['new_value'] ) ? sanitize_textarea_field( wp_unslash( $_POST['new_value'] ) ) : '';
	$search_value  = isset( $_POST['search_value'] ) ? sanitize_textarea_field( wp_unslash( $_POST['search_value'] ) ) : '';
	$replace_value = isset( $_POST['replace_value'] ) ? sanitize_textarea_field( wp_unslash( $_POST['replace_value'] ) ) : '';

	$allowed_actions = array( 'delete', 'delete_value', 'overwrite', 'search_replace_value', 'search_replace_value_and_key' );

	if ( empty( $meta_key ) || ! in_array( $action_type, $allowed_actions, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'rotistudio-post-meta-editor-cleaner' ) ) );
	}

	global $wpdb;

	$limit = absint( get_option( 'rspmeac_process_speed', 50 ) );
	if ( 0 === $limit ) {
		$limit = 50;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin operation, wp_postmeta has a native meta_key index.
	$posts_with_meta = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT pm.post_id
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
			AND p.post_status NOT IN ('trash', 'auto-draft')
			LIMIT %d OFFSET %d",
			$meta_key,
			$limit,
			$offset
		)
	);

	$processed_count = 0;
	foreach ( $posts_with_meta as $post_id ) {
		if ( 'delete' === $action_type ) {
			delete_post_meta( $post_id, $meta_key );
		} elseif ( 'delete_value' === $action_type ) {
			delete_post_meta( $post_id, $meta_key );
			add_post_meta( $post_id, $meta_key, '', true );
		} elseif ( 'overwrite' === $action_type ) {
			update_post_meta( $post_id, $meta_key, $new_value );
		} elseif ( 'search_replace_value' === $action_type || 'search_replace_value_and_key' === $action_type ) {
			$current          = get_post_meta( $post_id, $meta_key, true );
			$unserialized     = maybe_unserialize( $current );
			$replace_in_keys  = ( 'search_replace_value_and_key' === $action_type );
			if ( is_array( $unserialized ) ) {
				$updated = rspmeac_replace_in_serialized( $unserialized, $search_value, $replace_value, $replace_in_keys );
				update_post_meta( $post_id, $meta_key, $updated );
			} elseif ( is_scalar( $unserialized ) ) {
				$updated = str_replace( $search_value, $replace_value, (string) $unserialized );
				update_post_meta( $post_id, $meta_key, $updated );
			}
		}
		$processed_count++;
	}

	$response_data = array(
		'processed' => $processed_count,
		'has_more'  => count( $posts_with_meta ) === $limit,
		'meta_key'  => $meta_key,
		'action'    => $action_type,
	);

	if ( 'overwrite' === $action_type ) {
		$response_data['new_value'] = $new_value;
	}

	wp_send_json_success( $response_data );
}
add_action( 'wp_ajax_rspmeac_process_meta', 'rspmeac_ajax_process_meta', 10 );

/**
 * Render dashboard (main) page.
 *
 * @return void
 */
function rspmeac_render_dashboard_page() {
	rspmeac_render_admin_wrapper( 'page-dashboard.php' );
}

/**
 * Render settings page.
 *
 * @return void
 */
function rspmeac_render_settings_page() {
	rspmeac_render_admin_wrapper( 'page-settings.php' );
}

/**
 * Render help page.
 *
 * @return void
 */
function rspmeac_render_help_page() {
	rspmeac_render_admin_wrapper( 'page-help.php' );
}

/**
 * Add a Settings link to the plugin's action links on the Plugins list page.
 *
 * @param array $links Existing action links.
 * @return array Modified action links.
 */
function rspmeac_plugin_action_links( $links ) {
	$custom_links = array(
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=rspmeac-main' ) ),
			esc_html__( 'Post Meta list', 'rotistudio-post-meta-editor-cleaner' )
		),
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=rspmeac-settings' ) ),
			esc_html__( 'Settings', 'rotistudio-post-meta-editor-cleaner' )
		),
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=rspmeac-help' ) ),
			esc_html__( 'Help', 'rotistudio-post-meta-editor-cleaner' )
		),
	);

	return array_merge( $custom_links, $links );
}
add_filter( 'plugin_action_links_post-meta-eac-rotistudio/post-meta-eac-rotistudio.php', 'rspmeac_plugin_action_links', 10 );
