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
 * Maximum nesting depth processed by the serialization-aware replace helper.
 */
define( 'RSPMEAC_MAX_REPLACE_DEPTH', 32 );

/**
 * Maximum nodes visited during one replace walk.
 */
define( 'RSPMEAC_MAX_REPLACE_NODES', 10000 );

/**
 * Maximum bytes processed during one replace walk.
 */
define( 'RSPMEAC_MAX_REPLACE_BYTES', 10485760 );

/**
 * Capability required to use the plugin.
 *
 * The plugin exposes and edits post meta of every post type, which can
 * include private or personal data. On sites with custom roles this may be
 * broader than what 'manage_options' should imply, so the capability can be
 * tightened via the filter.
 *
 * @return string Capability name.
 */
function rspmeac_get_capability() {
	/**
	 * Filters the capability required to view and use the plugin pages.
	 *
	 * @param string $capability Capability name. Default 'manage_post_meta_cleanup'.
	 */
	return apply_filters( 'rspmeac_capability', 'manage_post_meta_cleanup' );
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
 * Results are memoized for the current request so each meta key is resolved
 * at most once (sort, filters and row rendering share the same map).
 *
 * @param string $key The meta key to search for.
 * @return string Plugin name, or empty string if unknown.
 */
function rspmeac_get_source_for_key( $key ) {
	static $rspmeac_resolved = array();

	if ( array_key_exists( $key, $rspmeac_resolved ) ) {
		return $rspmeac_resolved[ $key ];
	}

	$map = rspmeac_get_meta_source_map();

	if ( isset( $map['exact'][ $key ] ) ) {
		$rspmeac_resolved[ $key ] = $map['exact'][ $key ];
		return $rspmeac_resolved[ $key ];
	}

	foreach ( $map['prefix'] as $prefix => $plugin_name ) {
		if ( 0 === strpos( $key, $prefix ) ) {
			$rspmeac_resolved[ $key ] = $plugin_name;
			return $rspmeac_resolved[ $key ];
		}
	}

	$rspmeac_resolved[ $key ] = '';
	return '';
}

/**
 * Resolve and cache sources for a list of meta keys in one pass.
 *
 * @param array $meta_keys Meta keys.
 * @return array<string,string> meta_key => plugin name (empty string if unknown).
 */
function rspmeac_resolve_sources_for_keys( $meta_keys ) {
	$rspmeac_sources = array();

	foreach ( $meta_keys as $rspmeac_key ) {
		$rspmeac_sources[ $rspmeac_key ] = rspmeac_get_source_for_key( $rspmeac_key );
	}

	return $rspmeac_sources;
}

/**
 * Read and sanitize the overview table orderby / order query args.
 *
 * Default: meta key ascending (matches the SQL overview ORDER BY).
 *
 * @return array{0: string, 1: string} orderby and order (asc|desc).
 */
function rspmeac_get_table_order() {
	$rspmeac_allowed_orderby = array( 'meta_key', 'source', 'count', 'size' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only table sort, no state change.
	$rspmeac_orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'meta_key';
	if ( ! in_array( $rspmeac_orderby, $rspmeac_allowed_orderby, true ) ) {
		$rspmeac_orderby = 'meta_key';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only table sort, no state change.
	$rspmeac_order = isset( $_GET['order'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'asc';
	if ( ! in_array( $rspmeac_order, array( 'asc', 'desc' ), true ) ) {
		$rspmeac_order = 'asc';
	}

	return array( $rspmeac_orderby, $rspmeac_order );
}

/**
 * Sort meta keys for the overview table before pagination.
 *
 * Tie-break is always meta key ascending so equal sources/counts/sizes stay stable.
 *
 * @param array  $meta_keys   List of meta keys.
 * @param array  $post_counts Post counts keyed by meta key => post_type => count.
 * @param string $orderby     Column: meta_key, source, count, or size.
 * @param string $order       Direction: asc or desc.
 * @param array  $sizes       Byte sizes keyed by meta key.
 * @param array  $sources     Optional meta_key => plugin map (avoids per-compare lookups).
 * @return array Sorted list of meta keys.
 */
function rspmeac_sort_meta_keys( $meta_keys, $post_counts, $orderby, $order, $sizes = array(), $sources = array() ) {
	$rspmeac_keys = array_values( $meta_keys );
	$rspmeac_dir  = ( 'desc' === $order ) ? -1 : 1;

	usort(
		$rspmeac_keys,
		static function ( $rspmeac_a, $rspmeac_b ) use ( $orderby, $rspmeac_dir, $post_counts, $sizes, $sources ) {
			$rspmeac_cmp = 0;

			if ( 'source' === $orderby ) {
				$rspmeac_source_a = array_key_exists( $rspmeac_a, $sources )
					? $sources[ $rspmeac_a ]
					: rspmeac_get_source_for_key( $rspmeac_a );
				$rspmeac_source_b = array_key_exists( $rspmeac_b, $sources )
					? $sources[ $rspmeac_b ]
					: rspmeac_get_source_for_key( $rspmeac_b );
				$rspmeac_cmp      = strcasecmp( $rspmeac_source_a, $rspmeac_source_b );
			} elseif ( 'count' === $orderby ) {
				$rspmeac_count_a = isset( $post_counts[ $rspmeac_a ] ) ? array_sum( $post_counts[ $rspmeac_a ] ) : 0;
				$rspmeac_count_b = isset( $post_counts[ $rspmeac_b ] ) ? array_sum( $post_counts[ $rspmeac_b ] ) : 0;
				$rspmeac_cmp     = $rspmeac_count_a <=> $rspmeac_count_b;
			} elseif ( 'size' === $orderby ) {
				$rspmeac_size_a = isset( $sizes[ $rspmeac_a ] ) ? (int) $sizes[ $rspmeac_a ] : 0;
				$rspmeac_size_b = isset( $sizes[ $rspmeac_b ] ) ? (int) $sizes[ $rspmeac_b ] : 0;
				$rspmeac_cmp    = $rspmeac_size_a <=> $rspmeac_size_b;
			} else {
				$rspmeac_cmp = strcasecmp( $rspmeac_a, $rspmeac_b );
			}

			if ( 0 === $rspmeac_cmp ) {
				return strcasecmp( $rspmeac_a, $rspmeac_b );
			}

			return $rspmeac_cmp * $rspmeac_dir;
		}
	);

	return $rspmeac_keys;
}

/**
 * Build the URL for a sortable column header click.
 *
 * First click on Count / Size prefers DESC (highest first); Meta Key and Source prefer ASC.
 * Clicking the active column toggles direction. Resets to page 1.
 *
 * @param string $column          Column slug: meta_key, source, count, or size.
 * @param string $current_orderby Active orderby.
 * @param string $current_order   Active order.
 * @return string URL.
 */
function rspmeac_get_sortable_header_url( $column, $current_orderby, $current_order ) {
	$rspmeac_defaults = array(
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sort column slug, not a database query argument.
		'meta_key' => 'asc',
		'source'   => 'asc',
		'count'    => 'desc',
		'size'     => 'desc',
	);

	if ( $column === $current_orderby ) {
		$rspmeac_next_order = ( 'asc' === $current_order ) ? 'desc' : 'asc';
	} else {
		$rspmeac_next_order = isset( $rspmeac_defaults[ $column ] ) ? $rspmeac_defaults[ $column ] : 'asc';
	}

	return remove_query_arg(
		'paged',
		add_query_arg(
			array(
				'orderby' => $column,
				'order'   => $rspmeac_next_order,
			)
		)
	);
}

/**
 * Format a byte size as megabytes for the overview table.
 *
 * The "MB" unit is never translated.
 *
 * @param int $bytes Size in bytes.
 * @return string Size with unit, e.g. "1.25 MB".
 */
function rspmeac_format_size_mb( $bytes ) {
	$rspmeac_bytes = max( 0, (int) $bytes );
	$rspmeac_mb    = $rspmeac_bytes / ( 1024 * 1024 );

	return number_format_i18n( $rspmeac_mb, 2 ) . ' MB';
}

/**
 * Render a sortable overview table column header.
 *
 * @param string $column          Column slug.
 * @param string $label           Visible column label (already translated).
 * @param string $current_orderby Active orderby.
 * @param string $current_order   Active order.
 * @return void
 */
function rspmeac_render_sortable_column_header( $column, $label, $current_orderby, $current_order ) {
	rspmeac_render_table_column_header(
		array(
			'column'          => $column,
			'label'           => $label,
			'current_orderby' => $current_orderby,
			'current_order'   => $current_order,
			'sortable'        => true,
		)
	);
}

/**
 * Sentinel value for filtering rows with an empty Source / Post Type value.
 *
 * @return string Sentinel slug.
 */
function rspmeac_get_empty_filter_sentinel() {
	return 'rspmeac_empty';
}

/**
 * Read and sanitize overview table filter query args.
 *
 * Text searches require at least 2 characters; shorter values are ignored.
 *
 * @return array{
 *     meta_key_search: string,
 *     post_type_search: string,
 *     source: string,
 *     post_type: string
 * }
 */
function rspmeac_get_table_filters() {
	$rspmeac_filters = array(
		'meta_key_search'  => '',
		'post_type_search' => '',
		'source'           => '',
		'post_type'        => '',
	);

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET table filters, no state change.
	if ( isset( $_GET['filter_meta_key'] ) ) {
		$rspmeac_value = sanitize_text_field( wp_unslash( $_GET['filter_meta_key'] ) );
		if ( strlen( $rspmeac_value ) >= 2 ) {
			$rspmeac_filters['meta_key_search'] = $rspmeac_value;
		}
	}

	if ( isset( $_GET['filter_post_type_s'] ) ) {
		$rspmeac_value = sanitize_text_field( wp_unslash( $_GET['filter_post_type_s'] ) );
		if ( strlen( $rspmeac_value ) >= 2 ) {
			$rspmeac_filters['post_type_search'] = $rspmeac_value;
		}
	}

	if ( isset( $_GET['filter_source'] ) ) {
		$rspmeac_value = sanitize_text_field( wp_unslash( $_GET['filter_source'] ) );
		if ( '' !== $rspmeac_value ) {
			$rspmeac_filters['source'] = $rspmeac_value;
		}
	}

	if ( isset( $_GET['filter_post_type'] ) ) {
		$rspmeac_value = sanitize_text_field( wp_unslash( $_GET['filter_post_type'] ) );
		if ( '' !== $rspmeac_value ) {
			$rspmeac_filters['post_type'] = $rspmeac_value;
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	return $rspmeac_filters;
}

/**
 * Case-insensitive substring match helper (mbstring-safe when available).
 *
 * @param string $haystack Haystack.
 * @param string $needle   Needle.
 * @return bool Whether needle is found in haystack.
 */
function rspmeac_stripos_contains( $haystack, $needle ) {
	if ( '' === $needle ) {
		return true;
	}

	if ( function_exists( 'mb_stripos' ) ) {
		return false !== mb_stripos( $haystack, $needle );
	}

	return false !== stripos( $haystack, $needle );
}

/**
 * Filter meta keys by the active overview table filters.
 *
 * @param array $meta_keys  List of meta keys.
 * @param array $post_types Post types keyed by meta key => list of types.
 * @param array $filters    Filters from rspmeac_get_table_filters().
 * @param array $sources    Optional meta_key => plugin map.
 * @return array Filtered meta keys.
 */
function rspmeac_filter_meta_keys( $meta_keys, $post_types, $filters, $sources = array() ) {
	$rspmeac_empty = rspmeac_get_empty_filter_sentinel();

	$rspmeac_filtered = array();
	foreach ( $meta_keys as $rspmeac_key ) {
		if ( '' !== $filters['meta_key_search'] && ! rspmeac_stripos_contains( $rspmeac_key, $filters['meta_key_search'] ) ) {
			continue;
		}

		$rspmeac_source = array_key_exists( $rspmeac_key, $sources )
			? $sources[ $rspmeac_key ]
			: rspmeac_get_source_for_key( $rspmeac_key );
		if ( '' !== $filters['source'] ) {
			if ( $rspmeac_empty === $filters['source'] ) {
				if ( '' !== $rspmeac_source ) {
					continue;
				}
			} elseif ( $rspmeac_source !== $filters['source'] ) {
				continue;
			}
		}

		$rspmeac_types = isset( $post_types[ $rspmeac_key ] ) ? $post_types[ $rspmeac_key ] : array();
		if ( '' !== $filters['post_type'] ) {
			if ( $rspmeac_empty === $filters['post_type'] ) {
				if ( ! empty( $rspmeac_types ) ) {
					continue;
				}
			} elseif ( ! in_array( $filters['post_type'], $rspmeac_types, true ) ) {
				continue;
			}
		}

		if ( '' !== $filters['post_type_search'] ) {
			$rspmeac_type_hit = false;
			foreach ( $rspmeac_types as $rspmeac_type ) {
				if ( rspmeac_stripos_contains( $rspmeac_type, $filters['post_type_search'] ) ) {
					$rspmeac_type_hit = true;
					break;
				}
			}
			if ( ! $rspmeac_type_hit ) {
				continue;
			}
		}

		$rspmeac_filtered[] = $rspmeac_key;
	}

	return $rspmeac_filtered;
}

/**
 * Collect unique Source values for the filter select (ASC, max 99).
 *
 * @param array $meta_keys Meta keys to scan.
 * @param array $sources   Optional meta_key => plugin map.
 * @return array{values: string[], has_empty: bool}
 */
function rspmeac_get_filter_source_options( $meta_keys, $sources = array() ) {
	$rspmeac_values    = array();
	$rspmeac_has_empty = false;

	foreach ( $meta_keys as $rspmeac_key ) {
		$rspmeac_source = array_key_exists( $rspmeac_key, $sources )
			? $sources[ $rspmeac_key ]
			: rspmeac_get_source_for_key( $rspmeac_key );
		if ( '' === $rspmeac_source ) {
			$rspmeac_has_empty = true;
			continue;
		}
		$rspmeac_values[ $rspmeac_source ] = $rspmeac_source;
	}

	$rspmeac_values = array_values( $rspmeac_values );
	natcasesort( $rspmeac_values );
	$rspmeac_values = array_values( $rspmeac_values );

	if ( count( $rspmeac_values ) > 99 ) {
		$rspmeac_values = array_slice( $rspmeac_values, 0, 99 );
	}

	return array(
		'values'    => $rspmeac_values,
		'has_empty' => $rspmeac_has_empty,
	);
}

/**
 * Collect unique Post Type values for the filter select (ASC, max 99).
 *
 * @param array $post_types Post types keyed by meta key => list of types.
 * @return array{values: string[], has_empty: bool}
 */
function rspmeac_get_filter_post_type_options( $post_types ) {
	$rspmeac_values    = array();
	$rspmeac_has_empty = false;

	foreach ( $post_types as $rspmeac_types ) {
		if ( empty( $rspmeac_types ) ) {
			$rspmeac_has_empty = true;
			continue;
		}
		foreach ( $rspmeac_types as $rspmeac_type ) {
			if ( '' === $rspmeac_type ) {
				$rspmeac_has_empty = true;
				continue;
			}
			$rspmeac_values[ $rspmeac_type ] = $rspmeac_type;
		}
	}

	$rspmeac_values = array_values( $rspmeac_values );
	natcasesort( $rspmeac_values );
	$rspmeac_values = array_values( $rspmeac_values );

	if ( count( $rspmeac_values ) > 99 ) {
		$rspmeac_values = array_slice( $rspmeac_values, 0, 99 );
	}

	return array(
		'values'    => $rspmeac_values,
		'has_empty' => $rspmeac_has_empty,
	);
}

/**
 * Sanitize the advanced search query string.
 *
 * Strips tags and null bytes to block HTML/JS injection, but keeps quotes,
 * braces and colons so serialized PHP fragments remain searchable.
 *
 * @param string $raw Raw input.
 * @return string Sanitized query (max 99 characters).
 */
function rspmeac_sanitize_search_query( $raw ) {
	$rspmeac_value = wp_unslash( $raw );
	$rspmeac_value = wp_check_invalid_utf8( $rspmeac_value );
	$rspmeac_value = str_replace( "\0", '', $rspmeac_value );
	$rspmeac_value = wp_strip_all_tags( $rspmeac_value );
	$rspmeac_value = str_replace( array( '<', '>' ), '', $rspmeac_value );

	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $rspmeac_value, 0, 99 );
	}

	return substr( $rspmeac_value, 0, 99 );
}

/**
 * Meta key options for the advanced search select (ASC, max 99).
 *
 * @param array $meta_keys Overview meta keys.
 * @return string[]
 */
function rspmeac_get_search_meta_key_options( $meta_keys ) {
	$rspmeac_keys = array_values( $meta_keys );
	natcasesort( $rspmeac_keys );
	$rspmeac_keys = array_values( $rspmeac_keys );

	if ( count( $rspmeac_keys ) > 99 ) {
		$rspmeac_keys = array_slice( $rspmeac_keys, 0, 99 );
	}

	return $rspmeac_keys;
}

/**
 * Read advanced search query args from the request.
 *
 * @return array{
 *     active: bool,
 *     query: string,
 *     in: string,
 *     meta_key: string,
 *     source: string
 * }
 */
function rspmeac_get_advanced_search() {
	$rspmeac_allowed_in = array( 'all', 'meta_key', 'post_type', 'field_content' );
	$rspmeac_search     = array(
		'active'   => false,
		'query'    => '',
		'in'       => 'all',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Search form field name, not a database query argument.
		'meta_key' => '',
		'source'   => '',
	);

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only advanced search GET args, no state change.
	if ( isset( $_GET['rspmeac_s'] ) ) {
		// Custom sanitize allows serialized fragments; unslash happens inside the helper.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via rspmeac_sanitize_search_query().
		$rspmeac_search['query'] = rspmeac_sanitize_search_query( $_GET['rspmeac_s'] );
	}

	if ( isset( $_GET['rspmeac_s_in'] ) ) {
		$rspmeac_in = sanitize_key( wp_unslash( $_GET['rspmeac_s_in'] ) );
		if ( in_array( $rspmeac_in, $rspmeac_allowed_in, true ) ) {
			$rspmeac_search['in'] = $rspmeac_in;
		}
	}

	if ( isset( $_GET['rspmeac_s_key'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Meta key kept verbatim after UTF-8 / tag strip for exact matching.
		$rspmeac_key = wp_unslash( $_GET['rspmeac_s_key'] );
		$rspmeac_key = wp_check_invalid_utf8( $rspmeac_key );
		$rspmeac_key = str_replace( "\0", '', $rspmeac_key );
		$rspmeac_key = wp_strip_all_tags( $rspmeac_key );
		$rspmeac_key = str_replace( array( '<', '>' ), '', $rspmeac_key );
		if ( '' !== $rspmeac_key && 'all' !== strtolower( $rspmeac_key ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Assigning search filter value, not a database query argument.
			$rspmeac_search['meta_key'] = $rspmeac_key;
		}
	}

	if ( isset( $_GET['rspmeac_s_source'] ) ) {
		$rspmeac_source = sanitize_text_field( wp_unslash( $_GET['rspmeac_s_source'] ) );
		if ( '' !== $rspmeac_source && 'all' !== strtolower( $rspmeac_source ) ) {
			$rspmeac_search['source'] = $rspmeac_source;
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$rspmeac_search['active'] = (
		'' !== $rspmeac_search['query']
		|| '' !== $rspmeac_search['meta_key']
		|| '' !== $rspmeac_search['source']
	);

	return $rspmeac_search;
}

/**
 * Find meta keys whose stored values contain the search needle.
 *
 * Uses a prepared LIKE query; the needle is never executed as code.
 *
 * @param string $search        Search needle.
 * @param string $meta_key_only Optional exact meta key restriction.
 * @return string[] Matching meta keys.
 */
function rspmeac_search_meta_keys_by_field_content( $search, $meta_key_only = '' ) {
	global $wpdb;

	if ( '' === $search ) {
		return array();
	}

	$rspmeac_like = '%' . $wpdb->esc_like( $search ) . '%';

	if ( '' !== $meta_key_only ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin search; overview already cached separately.
		$rspmeac_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_value LIKE %s
				AND pm.meta_key = %s
				AND p.post_status NOT IN ('trash', 'auto-draft')",
				$rspmeac_like,
				$meta_key_only
			)
		);
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin search across meta values; scoped to non-trash posts.
		$rspmeac_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_value LIKE %s
				AND p.post_status NOT IN ('trash', 'auto-draft')",
				$rspmeac_like
			)
		);
	}

	return is_array( $rspmeac_keys ) ? $rspmeac_keys : array();
}

/**
 * Apply the advanced search form criteria to the meta key list.
 *
 * @param array $meta_keys  Meta keys.
 * @param array $post_types Post types keyed by meta key.
 * @param array $search     From rspmeac_get_advanced_search().
 * @param array $sources    Optional meta_key => plugin map.
 * @return array Filtered meta keys.
 */
function rspmeac_apply_advanced_search( $meta_keys, $post_types, $search, $sources = array() ) {
	if ( empty( $search['active'] ) ) {
		return $meta_keys;
	}

	$rspmeac_empty = rspmeac_get_empty_filter_sentinel();
	$rspmeac_keys  = array_values( $meta_keys );

	if ( '' !== $search['meta_key'] ) {
		$rspmeac_keys = in_array( $search['meta_key'], $rspmeac_keys, true )
			? array( $search['meta_key'] )
			: array();
	}

	if ( '' !== $search['source'] ) {
		$rspmeac_source_filtered = array();
		foreach ( $rspmeac_keys as $rspmeac_key ) {
			$rspmeac_source = array_key_exists( $rspmeac_key, $sources )
				? $sources[ $rspmeac_key ]
				: rspmeac_get_source_for_key( $rspmeac_key );
			if ( $rspmeac_empty === $search['source'] ) {
				if ( '' === $rspmeac_source ) {
					$rspmeac_source_filtered[] = $rspmeac_key;
				}
			} elseif ( $rspmeac_source === $search['source'] ) {
				$rspmeac_source_filtered[] = $rspmeac_key;
			}
		}
		$rspmeac_keys = $rspmeac_source_filtered;
	}

	if ( '' === $search['query'] ) {
		return $rspmeac_keys;
	}

	$rspmeac_in         = $search['in'];
	$rspmeac_value_keys = array();

	if ( 'field_content' === $rspmeac_in || 'all' === $rspmeac_in ) {
		$rspmeac_value_keys = array_fill_keys(
			rspmeac_search_meta_keys_by_field_content( $search['query'], $search['meta_key'] ),
			true
		);
	}

	$rspmeac_matched = array();
	foreach ( $rspmeac_keys as $rspmeac_key ) {
		$rspmeac_hit = false;

		if ( 'meta_key' === $rspmeac_in || 'all' === $rspmeac_in ) {
			if ( rspmeac_stripos_contains( $rspmeac_key, $search['query'] ) ) {
				$rspmeac_hit = true;
			}
		}

		if ( ! $rspmeac_hit && ( 'post_type' === $rspmeac_in || 'all' === $rspmeac_in ) ) {
			$rspmeac_types = isset( $post_types[ $rspmeac_key ] ) ? $post_types[ $rspmeac_key ] : array();
			foreach ( $rspmeac_types as $rspmeac_type ) {
				if ( rspmeac_stripos_contains( $rspmeac_type, $search['query'] ) ) {
					$rspmeac_hit = true;
					break;
				}
			}
		}

		if ( ! $rspmeac_hit && ( 'field_content' === $rspmeac_in || 'all' === $rspmeac_in ) ) {
			if ( isset( $rspmeac_value_keys[ $rspmeac_key ] ) ) {
				$rspmeac_hit = true;
			}
		}

		if ( $rspmeac_hit ) {
			$rspmeac_matched[] = $rspmeac_key;
		}
	}

	return $rspmeac_matched;
}

/**
 * Render the advanced search form markup (used inside the shared modal).
 *
 * @param array $search            Current search state.
 * @param array $meta_key_options  Meta key select options.
 * @param array $source_options    Source select options from rspmeac_get_filter_source_options().
 * @return void
 */
function rspmeac_render_advanced_search_form( $search, $meta_key_options, $source_options ) {
	$rspmeac_empty       = rspmeac_get_empty_filter_sentinel();
	$rspmeac_empty_label = __( 'No value', 'post-meta-eac-rotistudio' );
	$rspmeac_action      = admin_url( 'tools.php' );
	?>
	<form method="get" action="<?php echo esc_url( $rspmeac_action ); ?>" class="rspmeac-advanced-search-form" id="rspmeac-advanced-search-form">
		<input type="hidden" name="page" value="rspmeac-main" />
		<?php
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Preserve read-only sort / filter state in GET form.
		if ( ! empty( $_GET['orderby'] ) ) :
			?>
			<input type="hidden" name="orderby" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['orderby'] ) ) ); ?>" />
		<?php endif; ?>
		<?php if ( ! empty( $_GET['order'] ) ) : ?>
			<input type="hidden" name="order" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['order'] ) ) ); ?>" />
		<?php endif; ?>
		<?php
		$rspmeac_preserve_filters = array( 'filter_meta_key', 'filter_source', 'filter_post_type', 'filter_post_type_s' );
		foreach ( $rspmeac_preserve_filters as $rspmeac_preserve_key ) {
			if ( empty( $_GET[ $rspmeac_preserve_key ] ) ) {
				continue;
			}
			$rspmeac_preserve_value = sanitize_text_field( wp_unslash( $_GET[ $rspmeac_preserve_key ] ) );
			if ( '' === $rspmeac_preserve_value ) {
				continue;
			}
			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />' . "\n",
				esc_attr( $rspmeac_preserve_key ),
				esc_attr( $rspmeac_preserve_value )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>

		<div class="rspmeac-advanced-search-form__row">
			<p class="rspmeac-advanced-search-form__field">
				<label for="rspmeac_s_in"><?php esc_html_e( 'What do you want to search in?', 'post-meta-eac-rotistudio' ); ?></label>
				<select name="rspmeac_s_in" id="rspmeac_s_in">
					<option value="all" <?php selected( $search['in'], 'all' ); ?>><?php esc_html_e( 'All', 'post-meta-eac-rotistudio' ); ?></option>
					<option value="meta_key" <?php selected( $search['in'], 'meta_key' ); ?>><?php esc_html_e( 'Meta Key', 'post-meta-eac-rotistudio' ); ?></option>
					<option value="post_type" <?php selected( $search['in'], 'post_type' ); ?>><?php esc_html_e( 'Post Type', 'post-meta-eac-rotistudio' ); ?></option>
					<option value="field_content" <?php selected( $search['in'], 'field_content' ); ?>><?php esc_html_e( 'Field content', 'post-meta-eac-rotistudio' ); ?></option>
				</select>
			</p>

			<p class="rspmeac-advanced-search-form__field">
				<label for="rspmeac_s_key"><?php esc_html_e( 'Meta Key', 'post-meta-eac-rotistudio' ); ?></label>
				<select name="rspmeac_s_key" id="rspmeac_s_key">
					<option value=""><?php esc_html_e( 'All', 'post-meta-eac-rotistudio' ); ?></option>
					<?php if ( '' !== $search['meta_key'] && ! in_array( $search['meta_key'], $meta_key_options, true ) ) : ?>
						<option value="<?php echo esc_attr( $search['meta_key'] ); ?>" selected="selected"><?php echo esc_html( $search['meta_key'] ); ?></option>
					<?php endif; ?>
					<?php foreach ( $meta_key_options as $rspmeac_key_option ) : ?>
						<option value="<?php echo esc_attr( $rspmeac_key_option ); ?>" <?php selected( $search['meta_key'], $rspmeac_key_option ); ?>>
							<?php echo esc_html( $rspmeac_key_option ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="rspmeac-advanced-search-form__field">
				<label for="rspmeac_s_source"><?php esc_html_e( 'Source', 'post-meta-eac-rotistudio' ); ?></label>
				<select name="rspmeac_s_source" id="rspmeac_s_source">
					<option value=""><?php esc_html_e( 'All', 'post-meta-eac-rotistudio' ); ?></option>
					<?php if ( ! empty( $source_options['has_empty'] ) ) : ?>
						<option value="<?php echo esc_attr( $rspmeac_empty ); ?>" <?php selected( $search['source'], $rspmeac_empty ); ?>>
							<?php echo esc_html( $rspmeac_empty_label ); ?>
						</option>
					<?php endif; ?>
					<?php foreach ( $source_options['values'] as $rspmeac_source_option ) : ?>
						<option value="<?php echo esc_attr( $rspmeac_source_option ); ?>" <?php selected( $search['source'], $rspmeac_source_option ); ?>>
							<?php echo esc_html( $rspmeac_source_option ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="rspmeac-advanced-search-form__field rspmeac-advanced-search-form__field--query">
				<label for="rspmeac_s"><?php esc_html_e( 'Search term', 'post-meta-eac-rotistudio' ); ?></label>
				<input
					type="search"
					name="rspmeac_s"
					id="rspmeac_s"
					value="<?php echo esc_attr( $search['query'] ); ?>"
					maxlength="99"
					autocomplete="off"
				/>
			</p>

			<p class="rspmeac-advanced-search-form__field rspmeac-advanced-search-form__field--submit">
				<label class="screen-reader-text" for="rspmeac_s_submit"><?php esc_html_e( 'Search', 'post-meta-eac-rotistudio' ); ?></label>
				<button type="submit" class="button button-primary" id="rspmeac_s_submit">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<?php esc_html_e( 'Search', 'post-meta-eac-rotistudio' ); ?>
				</button>
			</p>
		</div>
	</form>
	<?php
}

/**
 * Render an overview table column header with optional sort and filters.
 *
 * @param array $args {
 *     Header arguments.
 *
 *     @type string $column           Column slug.
 *     @type string $label            Translated label.
 *     @type string $current_orderby  Active orderby.
 *     @type string $current_order    Active order.
 *     @type bool   $sortable         Whether sort icon is shown.
 *     @type bool   $filter_search    Whether a text search filter is shown.
 *     @type string $filter_search_name GET param name for text search.
 *     @type string $filter_search_value Current text search value.
 *     @type bool   $filter_select    Whether a select filter is shown.
 *     @type string $filter_select_name GET param name for select filter.
 *     @type string $filter_select_value Current select value.
 *     @type array  $filter_select_options From rspmeac_get_filter_*_options().
 * }
 * @return void
 */
function rspmeac_render_table_column_header( $args ) {
	$rspmeac_defaults = array(
		'column'                => '',
		'label'                 => '',
		'current_orderby'       => 'meta_key',
		'current_order'         => 'asc',
		'sortable'              => false,
		'filter_search'         => false,
		'filter_search_name'    => '',
		'filter_search_value'   => '',
		'filter_select'         => false,
		'filter_select_name'    => '',
		'filter_select_value'   => '',
		'filter_select_options' => array(
			'values'    => array(),
			'has_empty' => false,
		),
	);
	$args             = wp_parse_args( $args, $rspmeac_defaults );

	$rspmeac_classes = array( 'manage-column', 'rspmeac-table-column' );
	if ( $args['sortable'] ) {
		$rspmeac_classes[] = 'rspmeac-sortable-column';
	}
	if ( $args['filter_search'] || $args['filter_select'] ) {
		$rspmeac_classes[] = 'rspmeac-filterable-column';
	}

	$rspmeac_aria_sort = 'none';
	if ( $args['sortable'] && $args['column'] === $args['current_orderby'] ) {
		$rspmeac_classes[] = 'is-sorted';
		$rspmeac_classes[] = 'is-sorted-' . $args['current_order'];
		$rspmeac_aria_sort = ( 'asc' === $args['current_order'] ) ? 'ascending' : 'descending';
	}

	$rspmeac_empty_label = __( 'No value', 'post-meta-eac-rotistudio' );
	$rspmeac_empty       = rspmeac_get_empty_filter_sentinel();
	$rspmeac_search_open = ( $args['filter_search'] && '' !== $args['filter_search_value'] );
	$rspmeac_select_open = ( $args['filter_select'] && '' !== $args['filter_select_value'] );
	?>
	<th
		scope="col"
		class="<?php echo esc_attr( implode( ' ', $rspmeac_classes ) ); ?>"
		<?php echo $args['sortable'] ? 'aria-sort="' . esc_attr( $rspmeac_aria_sort ) . '"' : ''; ?>
	>
		<div class="rspmeac-table-column__top">
			<span class="rspmeac-sortable-column__label"><?php echo esc_html( $args['label'] ); ?></span>
			<?php if ( $args['filter_search'] ) : ?>
				<button
					type="button"
					class="rspmeac-column-filter-toggle<?php echo $rspmeac_search_open ? ' is-active' : ''; ?>"
					data-rspmeac-filter-panel="search"
					aria-expanded="<?php echo $rspmeac_search_open ? 'true' : 'false'; ?>"
				>
					<span class="screen-reader-text">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: column name */
								__( 'Search %s', 'post-meta-eac-rotistudio' ),
								$args['label']
							)
						);
						?>
					</span>
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
				</button>
			<?php endif; ?>
			<?php if ( $args['filter_select'] ) : ?>
				<button
					type="button"
					class="rspmeac-column-filter-toggle<?php echo $rspmeac_select_open ? ' is-active' : ''; ?>"
					data-rspmeac-filter-panel="select"
					aria-expanded="<?php echo $rspmeac_select_open ? 'true' : 'false'; ?>"
				>
					<span class="screen-reader-text">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: column name */
								__( 'Filter %s', 'post-meta-eac-rotistudio' ),
								$args['label']
							)
						);
						?>
					</span>
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
				</button>
			<?php endif; ?>
			<?php if ( $args['sortable'] ) : ?>
				<?php
				$rspmeac_sort_label = sprintf(
					/* translators: %s: column name */
					__( 'Sort by %s', 'post-meta-eac-rotistudio' ),
					$args['label']
				);
				?>
				<a
					class="rspmeac-sortable-column__link"
					href="<?php echo esc_url( rspmeac_get_sortable_header_url( $args['column'], $args['current_orderby'], $args['current_order'] ) ); ?>"
				>
					<span class="screen-reader-text"><?php echo esc_html( $rspmeac_sort_label ); ?></span>
					<span class="dashicons dashicons-sort" aria-hidden="true"></span>
				</a>
			<?php endif; ?>
		</div>

		<?php if ( $args['filter_search'] ) : ?>
			<div
				class="rspmeac-column-filter rspmeac-column-filter--search"
				data-rspmeac-filter-panel="search"
				<?php echo $rspmeac_search_open ? '' : 'hidden'; ?>
			>
				<label class="screen-reader-text" for="rspmeac-filter-<?php echo esc_attr( $args['filter_search_name'] ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: column name */
							__( 'Search %s', 'post-meta-eac-rotistudio' ),
							$args['label']
						)
					);
					?>
				</label>
				<div class="rspmeac-column-filter__search-wrap">
					<input
						type="search"
						id="rspmeac-filter-<?php echo esc_attr( $args['filter_search_name'] ); ?>"
						class="rspmeac-column-filter-input"
						name="<?php echo esc_attr( $args['filter_search_name'] ); ?>"
						value="<?php echo esc_attr( $args['filter_search_value'] ); ?>"
						minlength="2"
						autocomplete="off"
					/>
					<button type="button" class="rspmeac-column-filter-search-submit" aria-label="<?php esc_attr_e( 'Search', 'post-meta-eac-rotistudio' ); ?>">
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
					</button>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $args['filter_select'] ) : ?>
			<div
				class="rspmeac-column-filter rspmeac-column-filter--select"
				data-rspmeac-filter-panel="select"
				<?php echo $rspmeac_select_open ? '' : 'hidden'; ?>
			>
				<label class="screen-reader-text" for="rspmeac-filter-<?php echo esc_attr( $args['filter_select_name'] ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: column name */
							__( 'Filter %s', 'post-meta-eac-rotistudio' ),
							$args['label']
						)
					);
					?>
				</label>
				<select
					id="rspmeac-filter-<?php echo esc_attr( $args['filter_select_name'] ); ?>"
					class="rspmeac-column-filter-select"
					name="<?php echo esc_attr( $args['filter_select_name'] ); ?>"
				>
					<option value=""><?php esc_html_e( 'All', 'post-meta-eac-rotistudio' ); ?></option>
					<?php if ( ! empty( $args['filter_select_options']['has_empty'] ) ) : ?>
						<option value="<?php echo esc_attr( $rspmeac_empty ); ?>" <?php selected( $args['filter_select_value'], $rspmeac_empty ); ?>>
							<?php echo esc_html( $rspmeac_empty_label ); ?>
						</option>
					<?php endif; ?>
					<?php foreach ( $args['filter_select_options']['values'] as $rspmeac_option ) : ?>
						<option value="<?php echo esc_attr( $rspmeac_option ); ?>" <?php selected( $args['filter_select_value'], $rspmeac_option ); ?>>
							<?php echo esc_html( $rspmeac_option ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>
	</th>
	<?php
}

/**
 * Register admin menu item under Tools menu, and hidden Help and Settings pages.
 *
 * @return void
 */
function rspmeac_register_admin_menu() {
	add_management_page(
		__( 'Post Meta EAC', 'post-meta-eac-rotistudio' ),
		__( 'Post Meta EAC', 'post-meta-eac-rotistudio' ),
		rspmeac_get_capability(),
		'rspmeac-main',
		'rspmeac_render_dashboard_page'
	);

	add_submenu_page(
		'',
		__( 'Settings', 'post-meta-eac-rotistudio' ),
		__( 'Settings', 'post-meta-eac-rotistudio' ),
		rspmeac_get_capability(),
		'rspmeac-settings',
		'rspmeac_render_settings_page'
	);

	add_submenu_page(
		'',
		__( 'Help', 'post-meta-eac-rotistudio' ),
		__( 'Help', 'post-meta-eac-rotistudio' ),
		rspmeac_get_capability(),
		'rspmeac-help',
		'rspmeac_render_help_page'
	);
}
add_action( 'admin_menu', 'rspmeac_register_admin_menu', 10 );

/**
 * Sanitize the process speed option.
 *
 * @param mixed $value Submitted value.
 * @return int
 */
function rspmeac_sanitize_process_speed( $value ) {
	$value   = absint( $value );
	$allowed = array( 1, 5, 10, 20, 50, 100, 500 );

	if ( ! in_array( $value, $allowed, true ) ) {
		return 50;
	}

	return $value;
}

/**
 * Sanitize the items-per-page option.
 *
 * @param mixed $value Submitted value.
 * @return int
 */
function rspmeac_sanitize_items_per_page( $value ) {
	$value = absint( $value );

	if ( $value < 10 || $value > 500 ) {
		return 40;
	}

	return $value;
}

/**
 * Sanitize the uninstall checkbox option.
 *
 * @param mixed $value Submitted value.
 * @return int 1 or 0.
 */
function rspmeac_sanitize_delete_on_uninstall( $value ) {
	return ! empty( $value ) ? 1 : 0;
}

/**
 * Keep admin-only plugin options off the front-end autoload list.
 *
 * @param string $option Option name.
 * @return void
 */
function rspmeac_disable_option_autoload( $option ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keeps admin-only options off the front-end autoload list; compatible with WordPress 5.9+.
	$wpdb->update(
		$wpdb->options,
		array( 'autoload' => 'no' ),
		array( 'option_name' => $option )
	);
	wp_cache_delete( $option, 'options' );
}

/**
 * Register plugin settings via the WordPress Settings API.
 *
 * @return void
 */
function rspmeac_register_settings() {
	register_setting(
		'rspmeac_settings_group',
		'rspmeac_process_speed',
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'rspmeac_sanitize_process_speed',
			'default'           => 50,
			'show_in_rest'      => false,
		)
	);

	register_setting(
		'rspmeac_settings_group',
		'rspmeac_items_per_page',
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'rspmeac_sanitize_items_per_page',
			'default'           => 40,
			'show_in_rest'      => false,
		)
	);

	register_setting(
		'rspmeac_settings_group',
		'rspmeac_delete_data_on_uninstall',
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'rspmeac_sanitize_delete_on_uninstall',
			'default'           => 0,
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', 'rspmeac_register_settings', 10 );

add_action(
	'add_option_rspmeac_process_speed',
	function () {
		rspmeac_disable_option_autoload( 'rspmeac_process_speed' );
	},
	10
);
add_action(
	'update_option_rspmeac_process_speed',
	function () {
		rspmeac_disable_option_autoload( 'rspmeac_process_speed' );
	},
	10
);
add_action(
	'add_option_rspmeac_items_per_page',
	function () {
		rspmeac_disable_option_autoload( 'rspmeac_items_per_page' );
	},
	10
);
add_action(
	'update_option_rspmeac_items_per_page',
	function () {
		rspmeac_disable_option_autoload( 'rspmeac_items_per_page' );
	},
	10
);
add_action(
	'add_option_rspmeac_delete_data_on_uninstall',
	function () {
		rspmeac_disable_option_autoload( 'rspmeac_delete_data_on_uninstall' );
	},
	10
);
add_action(
	'update_option_rspmeac_delete_data_on_uninstall',
	function () {
		rspmeac_disable_option_autoload( 'rspmeac_delete_data_on_uninstall' );
	},
	10
);

/**
 * Set admin page title for hidden submenu pages (parent '') so core admin-header does not pass null to strip_tags().
 *
 * @return void
 */
function rspmeac_set_hidden_admin_page_title() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Title only, page context.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$titles = array(
		'rspmeac-settings' => __( 'Settings', 'post-meta-eac-rotistudio' ),
		'rspmeac-help'     => __( 'Help', 'post-meta-eac-rotistudio' ),
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
				'processing'         => __( 'Processing…', 'post-meta-eac-rotistudio' ),
				/* translators: 1: percentage, 2: completed items, 3: total items. */
				'processingProgress' => __( 'Processing: %1$d%% (%2$s / %3$s)', 'post-meta-eac-rotistudio' ),
				'done'               => __( 'Done!', 'post-meta-eac-rotistudio' ),
				/* translators: %d: number of skipped rows. */
				'doneSkipped'        => __( 'Done! Skipped rows: %d (objects, unreadable serialized data and key conflicts are left untouched).', 'post-meta-eac-rotistudio' ),
				'error'              => __( 'An error occurred.', 'post-meta-eac-rotistudio' ),
				'retrying'           => __( 'Connection problem, retrying…', 'post-meta-eac-rotistudio' ),
				'confirmDelete'      => __( 'Are you sure you want to delete this meta key and all its values for all posts? Trashed and auto-draft posts are not affected.', 'post-meta-eac-rotistudio' ),
				'confirmDeleteValue' => __( 'Are you sure you want to clear the values of this meta key for all posts? The key itself will remain. Trashed and auto-draft posts are not affected.', 'post-meta-eac-rotistudio' ),
				/* translators: %d: number of selected items. */
			'confirmBulk'        => __( 'Are you sure you want to perform this action on %d selected items?', 'post-meta-eac-rotistudio' ),
				'selectAction'       => __( 'Please select an action.', 'post-meta-eac-rotistudio' ),
				'selectItems'        => __( 'Please select at least one item.', 'post-meta-eac-rotistudio' ),
				'confirmOverwrite'   => __( 'Are you sure you want to overwrite and replace the full post meta field content? Trashed and auto-draft posts are not affected.', 'post-meta-eac-rotistudio' ),
				'confirmSearchReplaceValue'       => __( 'Are you sure you want to perform search & replace on all values (values only) for this meta key? Trashed and auto-draft posts are not affected.', 'post-meta-eac-rotistudio' ),
				'confirmSearchReplaceValueAndKey' => __( 'Are you sure you want to perform search & replace on all values and keys (name and value) for this meta key? Trashed and auto-draft posts are not affected.', 'post-meta-eac-rotistudio' ),
				'overwriteLabel'     => __( 'New value:', 'post-meta-eac-rotistudio' ),
				'searchLabel'        => __( 'Search:', 'post-meta-eac-rotistudio' ),
				'replaceLabel'       => __( 'Replace with:', 'post-meta-eac-rotistudio' ),
				'applyButton'        => __( 'Apply', 'post-meta-eac-rotistudio' ),
				'cancelButton'       => __( 'Cancel', 'post-meta-eac-rotistudio' ),
				'readingData'        => __( 'Reading data… Please wait, this may take several minutes on large sites.', 'post-meta-eac-rotistudio' ),
			),
			'filterMinLength' => 2,
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
		'rspmeac-main'     => __( 'Post Meta table', 'post-meta-eac-rotistudio' ),
		'rspmeac-settings' => __( 'Settings', 'post-meta-eac-rotistudio' ),
		'rspmeac-help'     => __( 'Help', 'post-meta-eac-rotistudio' ),
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
	<h1 style="display: none !important;"><?php esc_html_e( 'Post Meta EAC', 'post-meta-eac-rotistudio' ); ?></h1>
	<div class="wrap rspmeac-admin-wrap">
		<h2 class="rspmeac-hidden-title"><?php echo esc_html( is_string( $rspmeac_admin_screen_title ) ? $rspmeac_admin_screen_title : '' ); ?></h2>

		<div class="rspmeac-admin-header">
			<h1 class="rspmeac-admin-title"><?php esc_html_e( 'Post Meta Editor and Cleaner by RotiStudio', 'post-meta-eac-rotistudio' ); ?></h1>

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
 * Render the pagination controls of the overview table.
 *
 * Shared by the top and bottom table navigation, so the markup exists only
 * once. Only the top instance renders the 'table-paging' id to avoid
 * duplicated ids in the document.
 *
 * @param int    $current_page Current page number.
 * @param int    $total_pages  Total number of pages.
 * @param int    $total_items  Total number of meta keys.
 * @param string $which        Position: 'top' or 'bottom'.
 * @return void
 */
function rspmeac_render_pagination( $current_page, $total_pages, $total_items, $which ) {
	if ( $total_pages <= 1 ) {
		return;
	}
	?>
	<div class="tablenav-pages">
		<span class="displaying-num">
			<?php
			printf(
				/* translators: %s: number of items */
				esc_html( _n( '%s item', '%s items', $total_items, 'post-meta-eac-rotistudio' ) ),
				'<span class="total-items">' . esc_html( number_format_i18n( $total_items ) ) . '</span>'
			);
			?>
		</span>
		<span class="pagination-links">
			<?php
			// First/Previous.
			if ( $current_page > 1 ) {
				printf(
					'<a class="first-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
					esc_url( add_query_arg( 'paged', 1 ) ),
					esc_html__( 'First page', 'post-meta-eac-rotistudio' ),
					'«'
				);
				printf(
					'<a class="prev-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
					esc_url( add_query_arg( 'paged', max( 1, $current_page - 1 ) ) ),
					esc_html__( 'Previous page', 'post-meta-eac-rotistudio' ),
					'‹'
				);
			} else {
				echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>';
				echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';
			}

			// Current page info.
			echo '<span class="screen-reader-text">' . esc_html__( 'Current Page', 'post-meta-eac-rotistudio' ) . '</span>';
			printf(
				'<span %s class="paging-input">',
				( 'top' === $which ) ? 'id="table-paging"' : ''
			);
			$rspmeac_paging_text = sprintf(
				/* translators: 1: current page number, 2: total pages. */
				__( '%1$s of %2$s', 'post-meta-eac-rotistudio' ),
				number_format_i18n( $current_page ),
				number_format_i18n( $total_pages )
			);
			echo '<span class="tablenav-paging-text">' . esc_html( $rspmeac_paging_text ) . '</span>';
			echo '</span>';

			// Next/Last.
			if ( $current_page < $total_pages ) {
				printf(
					'<a class="next-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
					esc_url( add_query_arg( 'paged', min( $total_pages, $current_page + 1 ) ) ),
					esc_html__( 'Next page', 'post-meta-eac-rotistudio' ),
					'›'
				);
				printf(
					'<a class="last-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
					esc_url( add_query_arg( 'paged', $total_pages ) ),
					esc_html__( 'Last page', 'post-meta-eac-rotistudio' ),
					'»'
				);
			} else {
				echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>';
				echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
			}
			?>
		</span>
	</div>
	<?php
}

/**
 * Whether a cached meta overview payload is complete and usable.
 *
 * @param mixed $overview Transient value.
 * @return bool True when the overview can drive the dashboard table.
 */
function rspmeac_is_meta_overview_valid( $overview ) {
	return is_array( $overview )
		&& isset(
			$overview['meta_keys'],
			$overview['post_types'],
			$overview['post_counts'],
			$overview['sizes'],
			$overview['sources']
		)
		&& is_array( $overview['meta_keys'] )
		&& is_array( $overview['post_types'] )
		&& is_array( $overview['post_counts'] )
		&& is_array( $overview['sizes'] )
		&& is_array( $overview['sources'] );
}

/**
 * Read the cached meta overview, or false when missing / incomplete.
 *
 * @return array|false Overview payload or false.
 */
function rspmeac_get_meta_overview_cache() {
	$rspmeac_overview = get_transient( 'rspmeac_meta_overview' );

	return rspmeac_is_meta_overview_valid( $rspmeac_overview ) ? $rspmeac_overview : false;
}

/**
 * Rebuild the full meta overview index and store it in a transient.
 *
 * This runs the expensive grouped postmeta scan. Call only from an explicit
 * user action (Read data / Refresh all data), never on a cold page view.
 *
 * @return array Overview payload.
 */
function rspmeac_rebuild_meta_overview() {
	global $wpdb;

	if ( function_exists( 'set_time_limit' ) ) {
		// Large WooCommerce catalogs can need several minutes for one scan.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Optional raise for admin rebuild only.
		@set_time_limit( 300 );
	}

	$rspmeac_rebuild_owner = ( false === get_transient( 'rspmeac_meta_overview_lock' ) );

	if ( $rspmeac_rebuild_owner ) {
		set_transient( 'rspmeac_meta_overview_lock', 1, 5 * MINUTE_IN_SECONDS );
	}

	// One grouped scan: per meta_key + post_type counts, and byte size that is
	// later summed per meta_key. COUNT(DISTINCT ...) counts posts, not rows.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin overview rebuild; result cached in a transient.
	$rspmeac_type_rows = $wpdb->get_results(
		"SELECT pm.meta_key, p.post_type,
			COUNT(DISTINCT CASE WHEN pm.meta_value != '' THEN pm.post_id END) AS cnt,
			SUM(LENGTH(pm.meta_key) + IFNULL(LENGTH(pm.meta_value), 0)) AS bytes
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE p.post_status NOT IN ('trash', 'auto-draft')
		GROUP BY pm.meta_key, p.post_type
		ORDER BY pm.meta_key ASC, p.post_type ASC"
	);

	$rspmeac_overview = array(
		'meta_keys'   => array(),
		'post_types'  => array(),
		'post_counts' => array(),
		'sizes'       => array(),
		'sources'     => array(),
	);

	foreach ( (array) $rspmeac_type_rows as $rspmeac_row ) {
		if ( ! isset( $rspmeac_overview['post_types'][ $rspmeac_row->meta_key ] ) ) {
			$rspmeac_overview['meta_keys'][]                            = $rspmeac_row->meta_key;
			$rspmeac_overview['post_types'][ $rspmeac_row->meta_key ]  = array();
			$rspmeac_overview['post_counts'][ $rspmeac_row->meta_key ] = array();
			$rspmeac_overview['sizes'][ $rspmeac_row->meta_key ]       = 0;
		}
		$rspmeac_overview['post_types'][ $rspmeac_row->meta_key ][] = $rspmeac_row->post_type;
		$rspmeac_overview['post_counts'][ $rspmeac_row->meta_key ][ $rspmeac_row->post_type ] = (int) $rspmeac_row->cnt;
		$rspmeac_overview['sizes'][ $rspmeac_row->meta_key ] += (int) $rspmeac_row->bytes;
	}

	$rspmeac_overview['sources'] = rspmeac_resolve_sources_for_keys( $rspmeac_overview['meta_keys'] );

	if ( $rspmeac_rebuild_owner ) {
		// Longer TTL: rebuild is explicit; use Refresh all data for a fresh scan.
		set_transient( 'rspmeac_meta_overview', $rspmeac_overview, 12 * HOUR_IN_SECONDS );
		delete_transient( 'rspmeac_meta_overview_lock' );
	}

	return $rspmeac_overview;
}

/**
 * Handle explicit overview build / full refresh before any dashboard output.
 *
 * Cold page views no longer run the expensive scan. The user must click
 * "Read data" (or "Refresh all data") so the wait is intentional.
 *
 * @return void
 */
function rspmeac_handle_overview_build() {
	$rspmeac_is_build   = isset( $_GET['rspmeac_build'], $_GET['_wpnonce'] );
	$rspmeac_is_refresh = isset( $_GET['rspmeac_refresh'], $_GET['_wpnonce'] );

	if ( ! $rspmeac_is_build && ! $rspmeac_is_refresh ) {
		return;
	}

	if ( ! current_user_can( rspmeac_get_capability() ) ) {
		return;
	}

	$rspmeac_nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

	if ( $rspmeac_is_build && ! wp_verify_nonce( $rspmeac_nonce, 'rspmeac_build' ) ) {
		return;
	}

	if ( $rspmeac_is_refresh && ! wp_verify_nonce( $rspmeac_nonce, 'rspmeac_refresh' ) ) {
		return;
	}

	if ( $rspmeac_is_refresh ) {
		delete_transient( 'rspmeac_meta_overview' );
	}

	rspmeac_rebuild_meta_overview();

	wp_safe_redirect(
		remove_query_arg(
			array(
				'rspmeac_build',
				'rspmeac_refresh',
				'_wpnonce',
			)
		)
	);
	exit;
}
add_action( 'load-tools_page_rspmeac-main', 'rspmeac_handle_overview_build', 10 );

/**
 * Clear the cached post meta overview from Settings (first-launch state again).
 *
 * Does not run the expensive scan - the dashboard shows the Read data CTA.
 *
 * @return void
 */
function rspmeac_handle_overview_reset() {
	if ( ! current_user_can( rspmeac_get_capability() ) ) {
		wp_die( esc_html__( 'Unauthorized', 'post-meta-eac-rotistudio' ) );
	}

	check_admin_referer( 'rspmeac_reset_overview' );

	delete_transient( 'rspmeac_meta_overview' );
	delete_transient( 'rspmeac_meta_overview_lock' );

	wp_safe_redirect( rspmeac_get_admin_page_url( 'rspmeac-main' ) );
	exit;
}
add_action( 'admin_post_rspmeac_reset_overview', 'rspmeac_handle_overview_reset', 10 );

/**
 * Truncate a sample meta value for the overview table display.
 *
 * @param string|null $sample Raw sample value.
 * @return string Display string.
 */
function rspmeac_truncate_sample_value( $sample ) {
	if ( null === $sample || '' === $sample ) {
		return '';
	}

	if ( function_exists( 'mb_strimwidth' ) ) {
		return mb_strimwidth( $sample, 0, 100, '…' );
	}

	return ( strlen( $sample ) > 100 ) ? substr( $sample, 0, 100 ) . '…' : $sample;
}

/**
 * Fetch one short sample value per meta key for the overview table.
 *
 * Uses LIMIT 1 per key instead of GROUP BY / MIN() over the full key set.
 * On large WooCommerce catalogs the aggregate form can scan millions of
 * postmeta rows; an indexed meta_key lookup that stops at the first hit
 * stays near-constant cost per visible table row.
 *
 * @param array $meta_keys Meta keys to sample (current page / selection).
 * @return array<string, string> Map of meta_key => truncated raw sample.
 */
function rspmeac_get_sample_values_for_keys( $meta_keys ) {
	global $wpdb;

	$rspmeac_samples = array();

	foreach ( (array) $meta_keys as $rspmeac_raw_key ) {
		if ( ! is_string( $rspmeac_raw_key ) ) {
			continue;
		}

		$rspmeac_key = wp_check_invalid_utf8( $rspmeac_raw_key );
		if ( '' === $rspmeac_key ) {
			continue;
		}

		// LEFT() keeps huge LONGTEXT values out of PHP; JOIN + status filter
		// matches the count scope (no trash / auto-draft / orphan rows).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin sample preview; prepared LIMIT 1 on indexed meta_key.
		$rspmeac_value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT LEFT(pm.meta_value, 400)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s
				AND pm.meta_value != ''
				AND p.post_status NOT IN ('trash', 'auto-draft')
				LIMIT 1",
				$rspmeac_key
			)
		);

		if ( null !== $rspmeac_value && '' !== $rspmeac_value ) {
			$rspmeac_samples[ $rspmeac_key ] = $rspmeac_value;
		}
	}

	return $rspmeac_samples;
}

/**
 * Build a table-row payload for one meta key from overview fragments.
 *
 * @param string               $meta_key    Meta key.
 * @param array<string, mixed> $post_types  Post types for the key.
 * @param array<string, int>   $post_counts Counts keyed by post type.
 * @param int                  $size_bytes  Byte size.
 * @param string               $source      Source plugin label.
 * @param string|null          $sample      Optional sample value.
 * @return array<string, mixed> Row payload for the admin JS.
 */
function rspmeac_build_overview_row_payload( $meta_key, $post_types, $post_counts, $size_bytes, $source, $sample = null ) {
	$rspmeac_types   = is_array( $post_types ) ? array_values( $post_types ) : array();
	$rspmeac_counts  = is_array( $post_counts ) ? $post_counts : array();
	$rspmeac_total   = array_sum( array_map( 'intval', $rspmeac_counts ) );
	$rspmeac_details = array();

	foreach ( $rspmeac_types as $rspmeac_post_type ) {
		$rspmeac_c         = isset( $rspmeac_counts[ $rspmeac_post_type ] ) ? (int) $rspmeac_counts[ $rspmeac_post_type ] : 0;
		$rspmeac_details[] = $rspmeac_post_type . ': ' . number_format_i18n( $rspmeac_c );
	}

	return array(
		'exists'        => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- JSON/API payload key, not a database query argument.
		'meta_key'      => $meta_key,
		'source'        => (string) $source,
		'post_types'    => implode( ', ', $rspmeac_types ),
		'count'         => number_format_i18n( $rspmeac_total ),
		'count_details' => ( count( $rspmeac_types ) > 1 ) ? implode( ', ', $rspmeac_details ) : '',
		'size'          => rspmeac_format_size_mb( (int) $size_bytes ),
		'sample'        => rspmeac_truncate_sample_value( $sample ),
	);
}

/**
 * Refresh overview cache entries for selected meta keys only.
 *
 * Re-queries counts, sizes, post types, sources and sample values for the
 * given keys, merges them into the cached overview when present, and returns
 * row payloads for an in-place admin table update.
 *
 * @param array $meta_keys Meta keys to refresh.
 * @return array<string, array<string, mixed>> Map of meta_key => row payload.
 */
function rspmeac_refresh_overview_for_keys( $meta_keys ) {
	global $wpdb;

	$rspmeac_keys = array();
	foreach ( (array) $meta_keys as $rspmeac_raw_key ) {
		if ( ! is_string( $rspmeac_raw_key ) ) {
			continue;
		}
		$rspmeac_key = wp_check_invalid_utf8( $rspmeac_raw_key );
		if ( '' === $rspmeac_key ) {
			continue;
		}
		$rspmeac_keys[ $rspmeac_key ] = true;
	}
	$rspmeac_keys = array_keys( $rspmeac_keys );

	if ( empty( $rspmeac_keys ) ) {
		return array();
	}

	$rspmeac_placeholders = implode( ', ', array_fill( 0, count( $rspmeac_keys ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() placeholder count is built from array_fill(); every value is passed to prepare().
	$rspmeac_type_query = $wpdb->prepare(
		"SELECT pm.meta_key, p.post_type,
			COUNT(DISTINCT CASE WHEN pm.meta_value != '' THEN pm.post_id END) AS cnt,
			SUM(LENGTH(pm.meta_key) + IFNULL(LENGTH(pm.meta_value), 0)) AS bytes
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE pm.meta_key IN ( $rspmeac_placeholders )
		AND p.post_status NOT IN ('trash', 'auto-draft')
		GROUP BY pm.meta_key, p.post_type
		ORDER BY pm.meta_key ASC, p.post_type ASC",
		$rspmeac_keys
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Admin selective refresh; query built with prepare() above.
	$rspmeac_type_rows = $wpdb->get_results( $rspmeac_type_query );
	$rspmeac_samples   = rspmeac_get_sample_values_for_keys( $rspmeac_keys );

	$rspmeac_found_types  = array();
	$rspmeac_found_counts = array();
	$rspmeac_found_sizes  = array();

	foreach ( $rspmeac_type_rows as $rspmeac_row ) {
		if ( ! isset( $rspmeac_found_types[ $rspmeac_row->meta_key ] ) ) {
			$rspmeac_found_types[ $rspmeac_row->meta_key ]  = array();
			$rspmeac_found_counts[ $rspmeac_row->meta_key ] = array();
			$rspmeac_found_sizes[ $rspmeac_row->meta_key ]  = 0;
		}
		$rspmeac_found_types[ $rspmeac_row->meta_key ][] = $rspmeac_row->post_type;
		$rspmeac_found_counts[ $rspmeac_row->meta_key ][ $rspmeac_row->post_type ] = (int) $rspmeac_row->cnt;
		$rspmeac_found_sizes[ $rspmeac_row->meta_key ] += (int) $rspmeac_row->bytes;
	}

	$rspmeac_sources = rspmeac_resolve_sources_for_keys( array_keys( $rspmeac_found_types ) );

	$rspmeac_overview = get_transient( 'rspmeac_meta_overview' );
	$rspmeac_can_merge = is_array( $rspmeac_overview )
		&& isset( $rspmeac_overview['meta_keys'], $rspmeac_overview['post_types'], $rspmeac_overview['post_counts'], $rspmeac_overview['sizes'], $rspmeac_overview['sources'] );

	if ( $rspmeac_can_merge ) {
		foreach ( $rspmeac_keys as $rspmeac_key ) {
			$rspmeac_overview['meta_keys'] = array_values(
				array_filter(
					$rspmeac_overview['meta_keys'],
					static function ( $rspmeac_existing ) use ( $rspmeac_key ) {
						return $rspmeac_existing !== $rspmeac_key;
					}
				)
			);
			unset(
				$rspmeac_overview['post_types'][ $rspmeac_key ],
				$rspmeac_overview['post_counts'][ $rspmeac_key ],
				$rspmeac_overview['sizes'][ $rspmeac_key ],
				$rspmeac_overview['sources'][ $rspmeac_key ]
			);

			if ( ! isset( $rspmeac_found_types[ $rspmeac_key ] ) ) {
				continue;
			}

			$rspmeac_overview['meta_keys'][] = $rspmeac_key;
			$rspmeac_overview['post_types'][ $rspmeac_key ]  = $rspmeac_found_types[ $rspmeac_key ];
			$rspmeac_overview['post_counts'][ $rspmeac_key ] = $rspmeac_found_counts[ $rspmeac_key ];
			$rspmeac_overview['sizes'][ $rspmeac_key ]       = $rspmeac_found_sizes[ $rspmeac_key ];
			$rspmeac_overview['sources'][ $rspmeac_key ]     = isset( $rspmeac_sources[ $rspmeac_key ] )
				? $rspmeac_sources[ $rspmeac_key ]
				: rspmeac_get_source_for_key( $rspmeac_key );
		}

		sort( $rspmeac_overview['meta_keys'], SORT_STRING );
		set_transient( 'rspmeac_meta_overview', $rspmeac_overview, 12 * HOUR_IN_SECONDS );
	}

	$rspmeac_rows = array();
	foreach ( $rspmeac_keys as $rspmeac_key ) {
		if ( ! isset( $rspmeac_found_types[ $rspmeac_key ] ) ) {
			$rspmeac_rows[ $rspmeac_key ] = array(
				'exists'   => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- JSON/API payload key, not a database query argument.
				'meta_key' => $rspmeac_key,
			);
			continue;
		}

		$rspmeac_rows[ $rspmeac_key ] = rspmeac_build_overview_row_payload(
			$rspmeac_key,
			$rspmeac_found_types[ $rspmeac_key ],
			$rspmeac_found_counts[ $rspmeac_key ],
			$rspmeac_found_sizes[ $rspmeac_key ],
			isset( $rspmeac_sources[ $rspmeac_key ] ) ? $rspmeac_sources[ $rspmeac_key ] : rspmeac_get_source_for_key( $rspmeac_key ),
			isset( $rspmeac_samples[ $rspmeac_key ] ) ? $rspmeac_samples[ $rspmeac_key ] : null
		);
	}

	return $rspmeac_rows;
}

/**
 * AJAX handler: refresh overview data for selected meta keys only.
 *
 * @return void
 */
function rspmeac_ajax_refresh_meta_overview() {
	check_ajax_referer( 'rspmeac_meta_nonce', 'nonce' );

	if ( ! current_user_can( rspmeac_get_capability() ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'post-meta-eac-rotistudio' ) ) );
	}

	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Meta keys preserved verbatim; validated as strings then queried via prepared SQL.
	$rspmeac_raw_keys = isset( $_POST['meta_keys'] ) ? wp_unslash( $_POST['meta_keys'] ) : array();
	// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	if ( ! is_array( $rspmeac_raw_keys ) || empty( $rspmeac_raw_keys ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'post-meta-eac-rotistudio' ) ) );
	}

	$rspmeac_rows = rspmeac_refresh_overview_for_keys( $rspmeac_raw_keys );

	wp_send_json_success(
		array(
			'rows'  => $rspmeac_rows,
			'total' => count( $rspmeac_rows ),
		)
	);
}
add_action( 'wp_ajax_rspmeac_refresh_meta_overview', 'rspmeac_ajax_refresh_meta_overview', 10 );

/**
 * Recursively apply search & replace on an unserialized value.
 *
 * Serialization-aware: strings that themselves contain serialized data
 * (double-serialized meta) are unserialized without object instantiation,
 * processed recursively and re-serialized, so the s:N byte-length prefixes
 * stay valid. A plain str_replace() on such strings would corrupt them.
 *
 * The whole row is left untouched ('ok' => false) when:
 *  - an object (including __PHP_Incomplete_Class) is found anywhere,
 *  - an embedded serialized string cannot be unserialized,
 *  - a key rename would collide with another key (data would be lost),
 *  - the nesting depth limit is exceeded (self-referencing structures).
 *
 * @param mixed  $value           Any value (string, array, scalar).
 * @param string $search          Text to search for.
 * @param string $replace         Replacement text.
 * @param bool   $replace_in_keys When true, string array keys are replaced too.
 * @param int    $depth           Current recursion depth (internal).
 * @param array  $context         Mutable walk context (nodes, bytes).
 * @return array{ok: bool, changed: bool, value: mixed} Result descriptor.
 */
function rspmeac_replace_in_value( $value, $search, $replace, $replace_in_keys, $depth = 0, $context = null ) {
	if ( null === $context ) {
		$context = array(
			'nodes' => 0,
			'bytes' => 0,
		);
	}

	$unchanged = array(
		'ok'      => true,
		'changed' => false,
		'value'   => $value,
	);
	$skip_row  = array(
		'ok'      => false,
		'changed' => false,
		'value'   => $value,
	);

	$context['nodes']++;
	if ( $context['nodes'] > RSPMEAC_MAX_REPLACE_NODES ) {
		return $skip_row;
	}

	// Depth guard against self-referencing (R:) serialized structures.
	if ( $depth > RSPMEAC_MAX_REPLACE_DEPTH ) {
		return $skip_row;
	}

	// Objects are never rewritten via string replacement; skip the row so it
	// is reported instead of silently half-processed.
	if ( is_object( $value ) ) {
		return $skip_row;
	}

	if ( is_array( $value ) ) {
		if ( $replace_in_keys ) {
			$seen_keys = array();
			foreach ( $value as $k => $unused ) {
				unset( $unused );
				$new_key = is_string( $k ) ? str_replace( $search, $replace, $k ) : $k;
				$norm    = is_int( $new_key ) ? 'i:' . $new_key : ( is_string( $new_key ) && is_numeric( $new_key ) ? 'i:' . (int) $new_key : 's:' . (string) $new_key );

				if ( isset( $seen_keys[ $norm ] ) ) {
					return $skip_row;
				}

				$seen_keys[ $norm ] = true;
			}
		}

		$result  = array();
		$changed = false;

		foreach ( $value as $k => $v ) {
			$new_key = $k;

			if ( $replace_in_keys && is_string( $k ) ) {
				$new_key = str_replace( $search, $replace, $k );
			}

			// PHP casts numeric-string keys to integers, so a renamed key can
			// silently collide with an existing key and drop its data. Abort
			// the whole row instead of losing entries.
			if ( array_key_exists( $new_key, $result ) ) {
				return $skip_row;
			}

			$child = rspmeac_replace_in_value( $v, $search, $replace, $replace_in_keys, $depth + 1, $context );

			if ( ! $child['ok'] ) {
				return $skip_row;
			}

			if ( $child['changed'] || $new_key !== $k ) {
				$changed = true;
			}

			$result[ $new_key ] = $child['value'];
		}

		return array(
			'ok'      => true,
			'changed' => $changed,
			'value'   => $changed ? $result : $value,
		);
	}

	if ( is_string( $value ) ) {
		$context['bytes'] += strlen( $value );
		if ( $context['bytes'] > RSPMEAC_MAX_REPLACE_BYTES ) {
			return $skip_row;
		}

		if ( is_serialized( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Object instantiation blocked; failure handled explicitly.
			$inner = @unserialize( trim( $value ), array( 'allowed_classes' => false ) );

			if ( false === $inner && 'b:0;' !== trim( $value ) ) {
				// Corrupt or unreadable serialized payload: leave it untouched.
				return $skip_row;
			}

			$child = rspmeac_replace_in_value( $inner, $search, $replace, $replace_in_keys, $depth + 1, $context );

			if ( ! $child['ok'] ) {
				return $skip_row;
			}

			if ( ! $child['changed'] ) {
				return $unchanged;
			}

			return array(
				'ok'      => true,
				'changed' => true,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Data was stored serialized inside a string; the format must be preserved.
				'value'   => serialize( $child['value'] ),
			);
		}

		$new = str_replace( $search, $replace, $value );

		return array(
			'ok'      => true,
			'changed' => $new !== $value,
			'value'   => $new,
		);
	}

	// Other scalars and null are never string-replaced.
	return $unchanged;
}

/**
 * Build the operation lock/checkpoint option name for a meta key.
 *
 * @param string $meta_key Meta key being processed.
 * @return string Option name.
 */
function rspmeac_get_operation_lock_name( $meta_key ) {
	return 'rspmeac_op_' . md5( $meta_key );
}

/**
 * Count distinct posts affected by a meta operation (same scope as batch queries).
 *
 * @param string $meta_key    Meta key.
 * @param string $action_type Operation type.
 * @return int Post count.
 */
function rspmeac_count_posts_for_meta_operation( $meta_key, $action_type ) {
	global $wpdb;

	$sql = "SELECT COUNT(DISTINCT pm.post_id)
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE pm.meta_key = %s
		AND p.post_status NOT IN ('trash', 'auto-draft')";

	if ( 'delete_value' === $action_type ) {
		$sql .= " AND pm.meta_value != ''";
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin progress total; static SQL + prepare().
	$rspmeac_count = $wpdb->get_var( $wpdb->prepare( $sql, $meta_key ) );

	return absint( $rspmeac_count );
}

/**
 * AJAX handler: batched post meta delete / edit operations.
 *
 * Concurrency and resume safety:
 *  - A per-meta-key lock record (non-autoloaded option) rejects a second
 *    operation started from another tab or by another admin while one is
 *    still running.
 *  - The lock record stores the last completed keyset cursor. A retried
 *    batch (lost response, network error) can never step backwards, so
 *    already processed rows are not replayed - this keeps non-idempotent
 *    actions such as search & replace safe to retry.
 *
 * @return void
 */
function rspmeac_ajax_process_meta() {
	// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin batch operation, meta_key required, wp_postmeta has native index.
	check_ajax_referer( 'rspmeac_meta_nonce', 'nonce' );

	if ( ! current_user_can( rspmeac_get_capability() ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'post-meta-eac-rotistudio' ) ) );
	}

	// Meta keys and values must be preserved verbatim (HTML, percent-encoded
	// sequences, backslashes…), otherwise the sanitized string no longer
	// matches the data stored in the database. They are validated as UTF-8
	// only and used exclusively through $wpdb->prepare() and the meta API,
	// both of which are injection-safe. Output is escaped elsewhere.
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$meta_key      = isset( $_POST['meta_key'] ) && is_string( $_POST['meta_key'] ) ? wp_check_invalid_utf8( wp_unslash( $_POST['meta_key'] ) ) : '';
	$action_type   = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';
	$cursor        = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
	$op_id         = isset( $_POST['op_id'] ) ? sanitize_key( wp_unslash( $_POST['op_id'] ) ) : '';
	$new_value     = isset( $_POST['new_value'] ) && is_string( $_POST['new_value'] ) ? wp_check_invalid_utf8( wp_unslash( $_POST['new_value'] ) ) : '';
	$search_value  = isset( $_POST['search_value'] ) && is_string( $_POST['search_value'] ) ? wp_check_invalid_utf8( wp_unslash( $_POST['search_value'] ) ) : '';
	$replace_value = isset( $_POST['replace_value'] ) && is_string( $_POST['replace_value'] ) ? wp_check_invalid_utf8( wp_unslash( $_POST['replace_value'] ) ) : '';
	// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	$allowed_actions = array( 'delete', 'delete_value', 'overwrite', 'search_replace_value', 'search_replace_value_and_key' );

	if ( '' === $meta_key || '' === $op_id || ! in_array( $action_type, $allowed_actions, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'post-meta-eac-rotistudio' ) ) );
	}

	$is_search_replace = in_array( $action_type, array( 'search_replace_value', 'search_replace_value_and_key' ), true );
	if ( $is_search_replace && '' === $search_value ) {
		wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'post-meta-eac-rotistudio' ) ) );
	}

	// Server-side operation lock and checkpoint.
	$lock_name   = rspmeac_get_operation_lock_name( $meta_key );
	$now         = time();
	$fingerprint = md5( wp_json_encode( array( $action_type, $new_value, $search_value, $replace_value ) ) );
	$state       = get_option( $lock_name, false );

	if ( is_array( $state ) && isset( $state['op_id'], $state['expires'] ) && $state['expires'] > $now && $state['op_id'] !== $op_id ) {
		wp_send_json_error( array( 'message' => __( 'Another operation is already running on this meta key. Please wait until it finishes, then refresh the page.', 'post-meta-eac-rotistudio' ) ) );
	}

	if ( is_array( $state ) && isset( $state['op_id'], $state['fingerprint'], $state['cursor'] ) && $state['op_id'] === $op_id && $state['fingerprint'] === $fingerprint ) {
		// Retried batch after a lost response: resume from the stored
		// checkpoint instead of replaying already processed rows.
		$cursor = max( $cursor, (int) $state['cursor'] );
	}

	$rspmeac_total     = ( is_array( $state ) && isset( $state['op_id'], $state['total'] ) && $state['op_id'] === $op_id )
		? absint( $state['total'] )
		: rspmeac_count_posts_for_meta_operation( $meta_key, $action_type );
	$rspmeac_completed = ( is_array( $state ) && isset( $state['op_id'], $state['completed'] ) && $state['op_id'] === $op_id )
		? absint( $state['completed'] )
		: 0;

	update_option(
		$lock_name,
		array(
			'op_id'       => $op_id,
			'fingerprint' => $fingerprint,
			'cursor'      => $cursor,
			'total'       => $rspmeac_total,
			'completed'   => $rspmeac_completed,
			'expires'     => $now + 5 * MINUTE_IN_SECONDS,
		),
		false
	);

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
		AND pm.post_id > %d
		AND p.post_status NOT IN ('trash', 'auto-draft')";

	// Only select rows that still need clearing, so already processed posts
	// do not match again.
	if ( 'delete_value' === $action_type ) {
		$sql .= " AND pm.meta_value != ''";
	}

	// Keyset pagination: constant cost per batch even on huge tables, and
	// stable for destructive actions too (post ids only ever increase),
	// unlike LIMIT/OFFSET which degrades quadratically and shifts.
	$sql .= ' ORDER BY pm.post_id ASC LIMIT %d';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin operation; $sql contains static fragments only, values go through prepare(); wp_postmeta has a native meta_key index.
	$posts_with_meta = $wpdb->get_col( $wpdb->prepare( $sql, $meta_key, $cursor, $limit ) );

	// Prefetch every affected meta row of the batch in one query instead of
	// one query per post (N+1). Needed by the per-row actions only.
	$rows_by_post = array();
	if ( ! empty( $posts_with_meta ) && ( 'delete_value' === $action_type || $is_search_replace ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $posts_with_meta ), '%d' ) );
		$batch_sql    = "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ( $placeholders )";

		if ( 'delete_value' === $action_type ) {
			$batch_sql .= " AND meta_value != ''";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter -- meta_id is required for per-row updates; placeholder count is dynamic but built from array_fill(); values go through prepare().
		$batch_rows = $wpdb->get_results( $wpdb->prepare( $batch_sql, array_merge( array( $meta_key ), $posts_with_meta ) ) );

		foreach ( $batch_rows as $batch_row ) {
			$rows_by_post[ (int) $batch_row->post_id ][] = $batch_row;
		}
	}

	// The delete_metadata() / update_metadata() low-level functions are used
	// instead of the *_post_meta() wrappers, because the wrappers silently
	// redirect revision IDs to the parent post (wp_is_post_revision), so meta
	// rows stored on revisions (e.g. Elementor copies its meta onto every
	// revision) could never be deleted or edited.
	$processed_count = 0;
	$skipped_count   = 0;
	$failed_ids      = array();
	$last_post_id    = $cursor;

	foreach ( $posts_with_meta as $post_id ) {
		$post_id      = (int) $post_id;
		$last_post_id = $post_id;
		$post_ok      = true;

		if ( 'delete' === $action_type ) {
			$deleted = delete_metadata( 'post', $post_id, wp_slash( $meta_key ) );

			// A false return only means failure when the rows still exist -
			// a filter or a concurrent change may have removed them already.
			if ( ! $deleted && metadata_exists( 'post', $post_id, $meta_key ) ) {
				$post_ok = false;
			}
		} elseif ( 'delete_value' === $action_type ) {
			$rows = isset( $rows_by_post[ $post_id ] ) ? $rows_by_post[ $post_id ] : array();

			// Clearing row by row (by meta_id) keeps the row count intact for
			// posts storing multiple values under the same key, instead of
			// collapsing them into a single empty row.
			foreach ( $rows as $row ) {
				if ( ! update_metadata_by_mid( 'post', (int) $row->meta_id, '' ) ) {
					$post_ok = false;
				}
			}
		} elseif ( 'overwrite' === $action_type ) {
			// The meta API expects slashed input; without wp_slash() any
			// legitimate backslash in the value would be stripped on save.
			$updated = update_metadata( 'post', $post_id, wp_slash( $meta_key ), wp_slash( $new_value ) );

			if ( ! $updated ) {
				// update_metadata() also returns false when the stored value
				// already equals the new value - verify before flagging failure.
				$current = get_metadata( 'post', $post_id, $meta_key, false );

				foreach ( (array) $current as $current_value ) {
					if ( ! is_scalar( $current_value ) || (string) $current_value !== $new_value ) {
						$post_ok = false;
						break;
					}
				}
			}
		} elseif ( $is_search_replace ) {
			$rows = isset( $rows_by_post[ $post_id ] ) ? $rows_by_post[ $post_id ] : array();

			foreach ( $rows as $row ) {
				// Raw DB value: a single maybe_unserialize() is correct here;
				// nested serialized strings are handled inside the helper.
				$value  = maybe_unserialize( $row->meta_value );
				$result = rspmeac_replace_in_value( $value, $search_value, $replace_value, ( 'search_replace_value_and_key' === $action_type ) );

				if ( ! $result['ok'] ) {
					$skipped_count++;
					continue;
				}

				if ( ! $result['changed'] ) {
					continue;
				}

				if ( ! update_metadata_by_mid( 'post', (int) $row->meta_id, $result['value'] ) ) {
					$post_ok = false;
				}
			}
		}

		if ( $post_ok ) {
			$processed_count++;
		} else {
			$failed_ids[] = $post_id;
		}
	}

	$has_more = count( $posts_with_meta ) === $limit;

	// Posts walked in this batch (including skipped) drive the progress bar.
	$rspmeac_batch_count = count( $posts_with_meta );
	$rspmeac_completed   = min( $rspmeac_total, $rspmeac_completed + $rspmeac_batch_count );

	if ( $has_more && empty( $failed_ids ) ) {
		// Persist the checkpoint for the next batch (and for safe retries).
		update_option(
			$lock_name,
			array(
				'op_id'       => $op_id,
				'fingerprint' => $fingerprint,
				'cursor'      => $last_post_id,
				'total'       => $rspmeac_total,
				'completed'   => $rspmeac_completed,
				'expires'     => time() + 5 * MINUTE_IN_SECONDS,
			),
			false
		);
	} else {
		delete_option( $lock_name );
	}

	// Keep the overview index in sync for the affected key only - a full
	// transient wipe would force another multi-minute site-wide scan.
	if ( ! $has_more || ! empty( $failed_ids ) ) {
		if ( $processed_count > 0 || $skipped_count > 0 || ! empty( $failed_ids ) ) {
			rspmeac_refresh_overview_for_keys( array( $meta_key ) );
		}
	}

	if ( ! empty( $failed_ids ) ) {
		wp_send_json_error(
			array(
				'message'   => sprintf(
					/* translators: %s: comma-separated list of post IDs. */
					__( 'Some items could not be updated (post IDs: %s). The operation has been stopped, no further batches will run.', 'post-meta-eac-rotistudio' ),
					implode( ', ', array_slice( $failed_ids, 0, 20 ) )
				),
				'failed'    => $failed_ids,
				'processed' => $processed_count,
				'total'     => $rspmeac_total,
				'completed' => $rspmeac_completed,
			)
		);
	}

	$response_data = array(
		'processed'   => $processed_count,
		'skipped'     => $skipped_count,
		'has_more'    => $has_more,
		'next_cursor' => $last_post_id,
		'meta_key'    => $meta_key,
		'action'      => $action_type,
		'total'       => $rspmeac_total,
		'completed'   => $has_more ? $rspmeac_completed : $rspmeac_total,
	);

	if ( 'overwrite' === $action_type ) {
		$response_data['new_value'] = $new_value;
	}

	wp_send_json_success( $response_data );
}
add_action( 'wp_ajax_rspmeac_process_meta', 'rspmeac_ajax_process_meta', 10 );

/**
 * AJAX handler: count posts affected by an operation for one or more meta keys.
 *
 * Used by bulk actions to build an overall progress denominator before batches run.
 *
 * @return void
 */
function rspmeac_ajax_count_meta_operations() {
	check_ajax_referer( 'rspmeac_meta_nonce', 'nonce' );

	if ( ! current_user_can( rspmeac_get_capability() ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'post-meta-eac-rotistudio' ) ) );
	}

	$action_type = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';
	$allowed     = array( 'delete', 'delete_value', 'overwrite', 'search_replace_value', 'search_replace_value_and_key' );

	if ( ! in_array( $action_type, $allowed, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'post-meta-eac-rotistudio' ) ) );
	}

	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Meta keys preserved verbatim; validated as strings then counted via prepared SQL.
	$rspmeac_raw_keys = isset( $_POST['meta_keys'] ) ? wp_unslash( $_POST['meta_keys'] ) : array();
	// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	if ( ! is_array( $rspmeac_raw_keys ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'post-meta-eac-rotistudio' ) ) );
	}

	$rspmeac_totals = array();
	$rspmeac_sum    = 0;

	foreach ( $rspmeac_raw_keys as $rspmeac_raw_key ) {
		if ( ! is_string( $rspmeac_raw_key ) ) {
			continue;
		}
		$rspmeac_key = wp_check_invalid_utf8( $rspmeac_raw_key );
		if ( '' === $rspmeac_key ) {
			continue;
		}
		$rspmeac_count                 = rspmeac_count_posts_for_meta_operation( $rspmeac_key, $action_type );
		$rspmeac_totals[ $rspmeac_key ] = $rspmeac_count;
		$rspmeac_sum                   += $rspmeac_count;
	}

	wp_send_json_success(
		array(
			'totals' => $rspmeac_totals,
			'total'  => $rspmeac_sum,
		)
	);
}
add_action( 'wp_ajax_rspmeac_count_meta_operations', 'rspmeac_ajax_count_meta_operations', 10 );

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
			esc_html__( 'Post Meta list', 'post-meta-eac-rotistudio' )
		),
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( rspmeac_get_admin_page_url( 'rspmeac-settings' ) ),
			esc_html__( 'Settings', 'post-meta-eac-rotistudio' )
		),
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( rspmeac_get_admin_page_url( 'rspmeac-help' ) ),
			esc_html__( 'Help', 'post-meta-eac-rotistudio' )
		),
	);

	return array_merge( $custom_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( RSPMEAC_PATH . 'post-meta-eac-rotistudio.php' ), 'rspmeac_plugin_action_links', 10 );
