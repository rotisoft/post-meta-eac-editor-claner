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
 * Build the canonical admin URL for a plugin page.
 *
 * The main page is registered under Tools, so its canonical URL uses
 * tools.php (this also keeps the Tools menu highlighted). The hidden
 * Settings and Help pages are only reachable via admin.php.
 *
 * @param string $slug Page slug.
 * @return string Admin URL for the page.
 */
function rspmeac_get_admin_page_url( $slug ) {
	$parent = ( 'rspmeac-main' === $slug ) ? 'tools.php' : 'admin.php';

	return admin_url( $parent . '?page=' . $slug );
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
					$url          = rspmeac_get_admin_page_url( $slug );
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
			// Skip markup translation and textdomain loading; header parsing only.
			$plugin_data = get_plugin_data( RSPMEAC_PATH . 'post-meta-eac-rotistudio.php', false, false );

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
 * Handle the "Refresh data" action on the dashboard before any output.
 *
 * Runs on the load-{page} hook so a redirect is still possible; this keeps
 * the refresh parameters out of the URL used by the pagination links.
 *
 * @return void
 */
function rspmeac_handle_overview_refresh() {
	if ( ! isset( $_GET['rspmeac_refresh'], $_GET['_wpnonce'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rspmeac_refresh' ) ) {
		return;
	}

	delete_transient( 'rspmeac_meta_overview' );

	wp_safe_redirect( remove_query_arg( array( 'rspmeac_refresh', '_wpnonce' ) ) );
	exit;
}
add_action( 'load-tools_page_rspmeac-main', 'rspmeac_handle_overview_refresh', 10 );

/**
 * Recursively apply search & replace on unserialized data.
 *
 * @param mixed  $value           Any value (string, array, etc.).
 * @param string $search          Text to search for.
 * @param string $replace         Replacement text.
 * @param bool   $replace_in_keys When true, string array keys are replaced too.
 * @return mixed The modified value.
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
 * Run search & replace on every meta row of a post for a given key.
 *
 * Rows are processed individually by meta_id so posts holding multiple rows
 * for the same key keep their distinct values instead of collapsing into the
 * first value (which is what update_post_meta() without $prev_value would do).
 *
 * @param int    $post_id         Post ID.
 * @param string $meta_key        Meta key to process.
 * @param string $search          Text to search for.
 * @param string $replace         Replacement text.
 * @param bool   $replace_in_keys Whether to also replace inside array keys.
 * @return void
 */
function rspmeac_search_replace_post_meta( $post_id, $meta_key, $search, $replace, $replace_in_keys ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- meta_id is required for per-row updates; indexed lookup, admin operation.
	$meta_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$post_id,
			$meta_key
		)
	);

	foreach ( $meta_rows as $meta_row ) {
		// Raw DB value: a single maybe_unserialize() is correct here. Reading
		// through get_post_meta() and unserializing again would corrupt
		// double-serialized data.
		$value = maybe_unserialize( $meta_row->meta_value );

		if ( is_array( $value ) ) {
			$updated = rspmeac_replace_in_serialized( $value, $search, $replace, $replace_in_keys );

			if ( $updated !== $value ) {
				update_metadata_by_mid( 'post', $meta_row->meta_id, $updated );
			}
		} elseif ( is_scalar( $value ) ) {
			$original = (string) $value;
			$updated  = str_replace( $search, $replace, $original );

			if ( $updated !== $original ) {
				update_metadata_by_mid( 'post', $meta_row->meta_id, $updated );
			}
		}
		// Objects are skipped on purpose: rewriting class instances via string
		// replacement could corrupt them.
	}
}

/**
 * AJAX handler: batched post meta delete / edit operations.
 *
 * @return void
 */
function rspmeac_ajax_process_meta() {
	// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin batch operation, meta_key required, wp_postmeta has native index.
	check_ajax_referer( 'rspmeac_meta_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'rotistudio-post-meta-editor-cleaner' ) ) );
	}

	// Meta keys and values must be preserved verbatim (HTML, percent-encoded
	// sequences, backslashes…), otherwise the sanitized string no longer
	// matches the data stored in the database. They are validated as UTF-8
	// only and used exclusively through $wpdb->prepare() and the meta API,
	// both of which are injection-safe. Output is escaped elsewhere.
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$meta_key      = isset( $_POST['meta_key'] ) && is_string( $_POST['meta_key'] ) ? wp_check_invalid_utf8( wp_unslash( $_POST['meta_key'] ) ) : '';
	$action_type   = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';
	$offset        = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
	$new_value     = isset( $_POST['new_value'] ) && is_string( $_POST['new_value'] ) ? wp_check_invalid_utf8( wp_unslash( $_POST['new_value'] ) ) : '';
	$search_value  = isset( $_POST['search_value'] ) && is_string( $_POST['search_value'] ) ? wp_check_invalid_utf8( wp_unslash( $_POST['search_value'] ) ) : '';
	$replace_value = isset( $_POST['replace_value'] ) && is_string( $_POST['replace_value'] ) ? wp_check_invalid_utf8( wp_unslash( $_POST['replace_value'] ) ) : '';
	// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	$allowed_actions = array( 'delete', 'delete_value', 'overwrite', 'search_replace_value', 'search_replace_value_and_key' );

	if ( '' === $meta_key || ! in_array( $action_type, $allowed_actions, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'rotistudio-post-meta-editor-cleaner' ) ) );
	}

	$is_search_replace = in_array( $action_type, array( 'search_replace_value', 'search_replace_value_and_key' ), true );
	if ( $is_search_replace && '' === $search_value ) {
		wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'rotistudio-post-meta-editor-cleaner' ) ) );
	}

	global $wpdb;

	$limit = absint( get_option( 'rspmeac_process_speed', 50 ) );
	if ( 0 === $limit ) {
		$limit = 50;
	}
	$limit = min( $limit, 500 );

	$sql = "SELECT DISTINCT pm.post_id
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE pm.meta_key = %s
		AND p.post_status NOT IN ('trash', 'auto-draft')";

	// Only select rows that still need clearing. Without this filter the
	// already processed posts (key re-added with an empty value) would keep
	// matching and the restart-from-zero batch loop could never finish.
	if ( 'delete_value' === $action_type ) {
		$sql .= " AND pm.meta_value != ''";
	}

	// Deterministic order so LIMIT/OFFSET pagination cannot skip or repeat posts.
	$sql .= ' ORDER BY pm.post_id ASC LIMIT %d OFFSET %d';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin operation; $sql contains static fragments only, values go through prepare(); wp_postmeta has a native meta_key index.
	$posts_with_meta = $wpdb->get_col( $wpdb->prepare( $sql, $meta_key, $limit, $offset ) );

	// The delete_post_meta() / update_post_meta() / add_post_meta() wrappers
	// silently redirect revision IDs to the parent post (wp_is_post_revision),
	// so meta rows stored on revisions (e.g. Elementor copies its meta onto
	// every revision) could never be deleted and the calls returned false.
	// The lower-level *_metadata() functions operate on the exact post ID.
	$processed_count = 0;
	foreach ( $posts_with_meta as $post_id ) {
		if ( 'delete' === $action_type ) {
			delete_metadata( 'post', $post_id, wp_slash( $meta_key ) );
		} elseif ( 'delete_value' === $action_type ) {
			delete_metadata( 'post', $post_id, wp_slash( $meta_key ) );
			add_metadata( 'post', $post_id, wp_slash( $meta_key ), '', true );
		} elseif ( 'overwrite' === $action_type ) {
			// The meta API expects slashed input; without wp_slash() any
			// legitimate backslash in the value would be stripped on save.
			update_metadata( 'post', $post_id, wp_slash( $meta_key ), wp_slash( $new_value ) );
		} elseif ( $is_search_replace ) {
			rspmeac_search_replace_post_meta( $post_id, $meta_key, $search_value, $replace_value, ( 'search_replace_value_and_key' === $action_type ) );
		}
		$processed_count++;
	}

	// The cached overview table is now stale.
	if ( $processed_count > 0 ) {
		delete_transient( 'rspmeac_meta_overview' );
	}

	$is_destructive = in_array( $action_type, array( 'delete', 'delete_value' ), true );
	$response_data  = array(
		'processed'   => $processed_count,
		'has_more'    => count( $posts_with_meta ) === $limit,
		// Destructive batches restart from the beginning because processed
		// rows drop out of the result set; other actions continue where the
		// previous batch stopped.
		'next_offset' => $is_destructive ? 0 : $offset + $processed_count,
		'meta_key'    => $meta_key,
		'action'      => $action_type,
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
			esc_url( rspmeac_get_admin_page_url( 'rspmeac-main' ) ),
			esc_html__( 'Post Meta list', 'rotistudio-post-meta-editor-cleaner' )
		),
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( rspmeac_get_admin_page_url( 'rspmeac-settings' ) ),
			esc_html__( 'Settings', 'rotistudio-post-meta-editor-cleaner' )
		),
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( rspmeac_get_admin_page_url( 'rspmeac-help' ) ),
			esc_html__( 'Help', 'rotistudio-post-meta-editor-cleaner' )
		),
	);

	return array_merge( $custom_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( RSPMEAC_PATH . 'post-meta-eac-rotistudio.php' ), 'rspmeac_plugin_action_links', 10 );
