/**
 * Post Meta Editor and Cleaner - Admin script.
 *
 * Batched AJAX processing of post meta operations.
 */

/* global jQuery, rspmeacData */

( function ( $ ) {
	'use strict';

	// Do not initialize twice if the script happens to be loaded twice.
	if ( window.rspmeacScriptLoaded ) {
		return;
	}
	window.rspmeacScriptLoaded = true;

	// Prevents multiple bulk operations from running in parallel.
	var bulkActionRunning = false;

	// Number of automatic retries after a failed/lost request. The server
	// stores a per-operation checkpoint, so resending the same batch is safe.
	var MAX_RETRIES = 2;

	/**
	 * Generate a unique operation id for one logical operation.
	 *
	 * The id is sent with every batch, so the server can lock the meta key
	 * against concurrent operations and resume retried batches from its
	 * stored checkpoint.
	 *
	 * @return {string} Operation id.
	 */
	function generateOpId() {
		return 'op' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 10 );
	}

	/**
	 * Format an integer for progress display (locale-aware when available).
	 *
	 * @param {number} value Number to format.
	 * @return {string} Formatted number.
	 */
	function formatProgressNumber( value ) {
		var number = parseInt( value, 10 ) || 0;
		if ( window.Intl && 'function' === typeof Intl.NumberFormat ) {
			return new Intl.NumberFormat().format( number );
		}
		return String( number );
	}

	/**
	 * Build the unified progress label: Processing: 34% (4200 / 12344).
	 *
	 * @param {number} completed Completed items.
	 * @param {number} total     Total items.
	 * @return {string} Progress text.
	 */
	function formatProcessingProgress( completed, total ) {
		var safeTotal = Math.max( 0, parseInt( total, 10 ) || 0 );
		var safeCompleted = Math.max( 0, parseInt( completed, 10 ) || 0 );
		if ( safeTotal > 0 ) {
			safeCompleted = Math.min( safeCompleted, safeTotal );
		}
		var percent = safeTotal > 0 ? Math.min( 100, Math.round( ( safeCompleted / safeTotal ) * 100 ) ) : 0;
		var template = rspmeacData.i18n.processingProgress || 'Processing: %1$d%% (%2$s / %3$s)';

		return template
			.replace( '%1$d', String( percent ) )
			.replace( '%2$s', formatProgressNumber( safeCompleted ) )
			.replace( '%3$s', formatProgressNumber( safeTotal ) )
			.replace( /%%/g, '%' );
	}

	/**
	 * Render progress text with a spinner into a status element.
	 *
	 * @param {Object} statusEl  jQuery status element.
	 * @param {number} completed Completed items.
	 * @param {number} total     Total items.
	 * @return {void}
	 */
	function renderProcessingProgress( statusEl, completed, total ) {
		statusEl
			.empty()
			.text( formatProcessingProgress( completed, total ) )
			.append( ' ' )
			.append( $( '<span class="spinner is-active rspmeac-status-spinner"></span>' ) );
	}

	/**
	 * Batched AJAX processing for a single meta key.
	 *
	 * @param {string}   metaKey    Meta key name.
	 * @param {string}   actionType Action type (delete|delete_value|overwrite|search_replace_value|search_replace_value_and_key).
	 * @param {number}   cursor     Keyset cursor (last processed post ID).
	 * @param {Object}   statusEl   jQuery element for status display.
	 * @param {Function} callback   Callback on completion (optional).
	 * @param {Object}   extraData  Additional POST data (optional).
	 * @param {string}   opId       Operation id (optional, generated when missing).
	 * @param {number}   retryCount Internal retry counter (optional).
	 */
	function processMeta( metaKey, actionType, cursor, statusEl, callback, extraData, opId, retryCount ) {
		if ( ! opId ) {
			opId = generateOpId();
		}
		if ( ! retryCount ) {
			retryCount = 0;
		}

		// Only paint the initial progress shell once; later batches keep the
		// previous percentage visible until the next response arrives.
		if (
			0 === cursor &&
			! retryCount &&
			! ( extraData && 'function' === typeof extraData._onProgress )
		) {
			renderProcessingProgress( statusEl, 0, 0 );
		}

		var postData = {
			action:      'rspmeac_process_meta',
			nonce:       rspmeacData.nonce,
			meta_key:    metaKey,
			action_type: actionType,
			cursor:      cursor,
			op_id:       opId,
		};

		if ( extraData ) {
			$.extend( postData, extraData );
			// Internal client-side counters / callbacks, not server parameters.
			delete postData._skippedTotal;
			delete postData._onProgress;
		}

		/**
		 * Handle a failed or lost request: retry the same batch a few times.
		 * The server-side checkpoint guarantees that rows already processed
		 * by a batch whose response was lost are not processed again.
		 */
		function handleFailure() {
			if ( retryCount < MAX_RETRIES ) {
				statusEl.text( rspmeacData.i18n.retrying );
				setTimeout( function () {
					processMeta( metaKey, actionType, cursor, statusEl, callback, extraData, opId, retryCount + 1 );
				}, 2000 );
				return;
			}
			statusEl.text( rspmeacData.i18n.error );
			if ( 'function' === typeof callback ) {
				callback( false );
			}
		}

		$.post(
			rspmeacData.ajaxUrl,
			postData,
			function ( response ) {
				if ( ! response.success ) {
					// Server-side validation/lock/DB error: show the exact
					// message and stop, do not retry blindly.
					var message = ( response.data && response.data.message )
						? response.data.message
						: rspmeacData.i18n.error;
					statusEl.text( message );
					if ( 'function' === typeof callback ) {
						callback( false );
					}
					return;
				}

				var data = response.data;
				var total = 'undefined' !== typeof data.total ? parseInt( data.total, 10 ) : 0;
				var completed = 'undefined' !== typeof data.completed ? parseInt( data.completed, 10 ) : 0;

				if ( extraData && 'function' === typeof extraData._onProgress ) {
					extraData._onProgress( completed, total );
				} else {
					renderProcessingProgress( statusEl, completed, total );
				}

				// Accumulate skipped row count across batches.
				var skippedTotal =
					( extraData && extraData._skippedTotal ? extraData._skippedTotal : 0 ) +
					( data.skipped || 0 );

				if ( data.has_more ) {
					var nextExtra = $.extend( {}, extraData, { _skippedTotal: skippedTotal } );
					// Keyset cursor from the server: retries can never step
					// backwards, batches can never skip or repeat posts.
					processMeta( metaKey, actionType, data.next_cursor, statusEl, callback, nextExtra, opId, 0 );
				} else {
					if ( skippedTotal > 0 ) {
						statusEl.text( rspmeacData.i18n.doneSkipped.replace( '%d', skippedTotal ) );
					} else {
						statusEl.text( rspmeacData.i18n.done );
					}
					if ( 'function' === typeof callback ) {
						callback( true, data );
					} else {
						setTimeout( function () {
							window.location.reload();
						}, 1500 );
					}
				}
			}
		).fail( handleFailure );
	}

	/**
	 * Apply refreshed overview row data to a table row.
	 *
	 * @param {Object} $row    jQuery row element.
	 * @param {Object} rowData Payload from the refresh AJAX response.
	 * @return {void}
	 */
	function applyOverviewRowData( $row, rowData ) {
		if ( ! rowData || ! rowData.exists ) {
			$row.next( 'tr.rspmeac-inline-edit-row' ).remove();
			$row.fadeOut( 400, function () {
				$( this ).remove();
			} );
			return;
		}

		var $cells = $row.children( 'td' );
		var $countCell = $cells.eq( 3 );

		$cells.eq( 1 ).text( rowData.source || '' );
		$cells.eq( 2 ).text( rowData.post_types || '' );

		$countCell.empty().append( $( '<strong></strong>' ).text( rowData.count || '0' ) );
		if ( rowData.count_details ) {
			$countCell.append( '<br>' ).append( $( '<small></small>' ).text( rowData.count_details ) );
		}

		$cells.eq( 4 ).text( rowData.size || '' );
		$cells.eq( 5 ).text( rowData.sample || '' );
		$row.find( 'input[type="checkbox"]' ).prop( 'checked', false );
	}

	/**
	 * Refresh overview data for selected meta keys only.
	 *
	 * @param {Array}    metaKeys Array of meta keys.
	 * @param {Object}   statusEl jQuery status element.
	 * @param {Object}   $rowMap  Map of meta key => jQuery row.
	 * @param {Function} onDone   Completion callback.
	 * @return {void}
	 */
	function refreshSelectedOverview( metaKeys, statusEl, $rowMap, onDone ) {
		var total = metaKeys.length;

		renderProcessingProgress( statusEl, 0, total );

		$.post(
			rspmeacData.ajaxUrl,
			{
				action:    'rspmeac_refresh_meta_overview',
				nonce:     rspmeacData.nonce,
				meta_keys: metaKeys,
			},
			function ( response ) {
				if ( ! response.success ) {
					var message = ( response.data && response.data.message )
						? response.data.message
						: rspmeacData.i18n.error;
					statusEl.text( message );
					if ( 'function' === typeof onDone ) {
						onDone( false );
					}
					return;
				}

				renderProcessingProgress( statusEl, total, total );

				var rows = ( response.data && response.data.rows ) ? response.data.rows : {};
				$.each( metaKeys, function ( index, key ) {
					if ( $rowMap[ key ] ) {
						applyOverviewRowData( $rowMap[ key ], rows[ key ] );
					}
				} );

				if ( 'function' === typeof onDone ) {
					onDone( true );
				}
			}
		).fail( function () {
			statusEl.text( rspmeacData.i18n.error );
			if ( 'function' === typeof onDone ) {
				onDone( false );
			}
		} );
	}

	/**
	 * Run a bulk action on multiple meta keys sequentially.
	 *
	 * @param {Array}    metaKeys   Array of meta keys.
	 * @param {string}   actionType Action type.
	 * @param {Object}   statusEl   jQuery element for status display.
	 * @param {Function} onDone     Callback when everything is finished.
	 */
	function processBulkAction( metaKeys, actionType, statusEl, onDone ) {
		var index = 0;
		var bulkTotal = 0;
		var finishedBefore = 0;
		var keyTotals = {};

		/**
		 * Paint overall bulk progress into the shared status element.
		 *
		 * @param {number} currentKeyCompleted Completed posts for the active key.
		 * @return {void}
		 */
		function renderBulkProgress( currentKeyCompleted ) {
			renderProcessingProgress(
				statusEl,
				finishedBefore + ( parseInt( currentKeyCompleted, 10 ) || 0 ),
				bulkTotal
			);
		}

		/**
		 * Process the next meta key in the bulk queue.
		 *
		 * @return {void}
		 */
		function processNext() {
			if ( index >= metaKeys.length ) {
				if ( 'function' === typeof onDone ) {
					onDone( true );
				}
				return;
			}

			var metaKey = metaKeys[ index ];
			renderBulkProgress( 0 );

			processMeta(
				metaKey,
				actionType,
				0,
				statusEl,
				function ( success, data ) {
					if ( ! success ) {
						if ( 'function' === typeof onDone ) {
							onDone( false );
						}
						return;
					}
					finishedBefore += keyTotals[ metaKey ] || ( data && data.total ? parseInt( data.total, 10 ) : 0 );
					index++;
					processNext();
				},
				{
					_onProgress: function ( completed ) {
						renderBulkProgress( completed );
					},
				}
			);
		}

		renderProcessingProgress( statusEl, 0, 0 );

		$.post(
			rspmeacData.ajaxUrl,
			{
				action:      'rspmeac_count_meta_operations',
				nonce:       rspmeacData.nonce,
				action_type: actionType,
				meta_keys:   metaKeys,
			},
			function ( response ) {
				if ( ! response.success ) {
					var message = ( response.data && response.data.message )
						? response.data.message
						: rspmeacData.i18n.error;
					statusEl.text( message );
					if ( 'function' === typeof onDone ) {
						onDone( false );
					}
					return;
				}

				keyTotals = response.data.totals || {};
				bulkTotal = parseInt( response.data.total, 10 ) || 0;
				renderBulkProgress( 0 );
				processNext();
			}
		).fail( function () {
			statusEl.text( rspmeacData.i18n.error );
			if ( 'function' === typeof onDone ) {
				onDone( false );
			}
		} );
	}

	/**
	 * Show / hide the spinner next to the Apply buttons.
	 *
	 * @param {boolean} show Whether to show or hide the spinner.
	 */
	function toggleSpinners( show ) {
		$( '.rspmeac-bulk-spinner' ).toggleClass( 'is-active', show );
	}

	/**
	 * Update the Apply buttons' state based on the current selection.
	 */
	function updateApplyButtons() {
		var checked = $( 'input[name="meta_keys[]"]:checked' ).length;
		$( '#doaction, #doaction2' ).prop( 'disabled', 0 === checked );
	}

	$( function () {
		// Explicit overview scan: disable the control and show wait copy while
		// the long request runs (large WooCommerce catalogs can take several minutes).
		$( document ).on(
			'click',
			'a[href*="rspmeac_build="], a[href*="rspmeac_refresh="]',
			function () {
				var $link = $( this );

				if ( $link.hasClass( 'is-busy' ) ) {
					return false;
				}

				$link.addClass( 'is-busy' ).attr( 'aria-disabled', 'true' );

				var waitText =
					( rspmeacData.i18n && rspmeacData.i18n.readingData )
						? rspmeacData.i18n.readingData
						: 'Reading data…';

				if ( $link.hasClass( 'rspmeac-overview-empty__button' ) ) {
					$link.find( '.dashicons' ).remove();
					$link.text( waitText );
				} else {
					$link.attr( 'title', waitText );
				}
			}
		);

		// Add spinner elements next to the Apply buttons.
		$( '#doaction, #doaction2' ).after( '<span class="spinner rspmeac-bulk-spinner"></span>' );

		// Apply buttons start disabled - nothing is selected yet.
		$( '#doaction, #doaction2' ).prop( 'disabled', true );

		// Block form submit - every operation is handled via AJAX.
		$( '#rspmeac-meta-form' ).on( 'submit', function ( e ) {
			e.preventDefault();
		} );

		/**
		 * Rebuild the current admin URL with column filter query args and reload.
		 *
		 * Full page GET navigation - no AJAX.
		 *
		 * @return {void}
		 */
		function applyTableFilters() {
			var url = new URL( window.location.href );
			var minLength =
				rspmeacData && rspmeacData.filterMinLength
					? parseInt( rspmeacData.filterMinLength, 10 )
					: 2;

			$( '.rspmeac-column-filter-input' ).each( function () {
				var name = this.name;
				var value = $.trim( $( this ).val() );

				if ( value.length >= minLength ) {
					url.searchParams.set( name, value );
				} else {
					url.searchParams.delete( name );
				}
			} );

			$( '.rspmeac-column-filter-select' ).each( function () {
				var name = this.name;
				var value = $( this ).val();

				if ( value ) {
					url.searchParams.set( name, value );
				} else {
					url.searchParams.delete( name );
				}
			} );

			// Keep advanced search params when using column filters.
			[ 'rspmeac_s', 'rspmeac_s_in', 'rspmeac_s_key', 'rspmeac_s_source' ].forEach( function ( param ) {
				var current = new URL( window.location.href ).searchParams.get( param );
				if ( current ) {
					url.searchParams.set( param, current );
				}
			} );

			url.searchParams.delete( 'paged' );
			window.location.href = url.toString();
		}

		// Toggle text/select filter panels under column headers.
		$( document ).on( 'click', '.rspmeac-column-filter-toggle', function ( e ) {
			e.preventDefault();

			var $button = $( this );
			var panelType = $button.attr( 'data-rspmeac-filter-panel' );
			var $th = $button.closest( 'th' );
			var $panel = $th.find(
				'.rspmeac-column-filter[data-rspmeac-filter-panel="' + panelType + '"]'
			);
			var willOpen = $panel.prop( 'hidden' );

			$panel.prop( 'hidden', ! willOpen );
			$button.attr( 'aria-expanded', willOpen ? 'true' : 'false' );
			$button.toggleClass( 'is-active', willOpen );

			if ( willOpen ) {
				$panel.find( 'input, select' ).first().trigger( 'focus' );
			}
		} );

		// Submit text filters via the search icon inside the field.
		$( document ).on( 'click', '.rspmeac-column-filter-search-submit', function ( e ) {
			e.preventDefault();
			applyTableFilters();
		} );

		// Enter in a filter search field also applies filters.
		$( document ).on( 'keydown', '.rspmeac-column-filter-input', function ( e ) {
			if ( 'Enter' === e.key || 13 === e.keyCode ) {
				e.preventDefault();
				applyTableFilters();
			}
		} );

		// Select filters apply immediately on change.
		$( document ).on( 'change', '.rspmeac-column-filter-select', function () {
			applyTableFilters();
		} );

		// Toggle the advanced search panel under the Search button.
		$( '#rspmeac-toggle-advanced-search' ).on( 'click', function () {
			var $button = $( this );
			var $panel = $( '#rspmeac-advanced-search-panel' );
			var willOpen = $panel.prop( 'hidden' );

			$panel.prop( 'hidden', ! willOpen );
			$button.attr( 'aria-expanded', willOpen ? 'true' : 'false' );
			$button.toggleClass( 'is-active', willOpen );

			if ( willOpen ) {
				$panel.find( 'select, input' ).first().trigger( 'focus' );
			}
		} );

		// Select All checkbox.
		$( '#cb-select-all-1' ).on( 'change', function () {
			$( 'input[name="meta_keys[]"]' ).prop( 'checked', $( this ).prop( 'checked' ) );
			updateApplyButtons();
		} );

		// Individual checkboxes: update the Select All state and the buttons.
		$( document ).on( 'change', 'input[name="meta_keys[]"]', function () {
			var total   = $( 'input[name="meta_keys[]"]' ).length;
			var checked = $( 'input[name="meta_keys[]"]:checked' ).length;

			$( '#cb-select-all-1' )
				.prop( 'checked', checked === total )
				.prop( 'indeterminate', checked > 0 && checked < total );

			updateApplyButtons();
		} );

		// Bulk action buttons.
		$( '#doaction, #doaction2' ).off( 'click.rspmeac' ).on( 'click.rspmeac', function ( e ) {
			e.stopImmediatePropagation();

			if ( bulkActionRunning ) {
				return;
			}

			var selectedAction = $( this ).is( '#doaction' )
				? $( '#bulk-action-selector-top' ).val()
				: $( '#bulk-action-selector-bottom' ).val();

			if ( '-1' === selectedAction ) {
				// eslint-disable-next-line no-alert -- Intentional user warning.
				window.alert( rspmeacData.i18n.selectAction );
				return;
			}

			var checkedItems = $( 'input[name="meta_keys[]"]:checked' );

			if ( 0 === checkedItems.length ) {
				return;
			}

			var confirmMsg = rspmeacData.i18n.confirmBulk.replace( '%d', checkedItems.length );

			// eslint-disable-next-line no-alert -- Intentional confirmation dialog.
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}

			bulkActionRunning = true;

			// Collect the affected rows for the DOM update.
			var $rowMap = {};
			var metaKeys = [];
			checkedItems.each( function () {
				var key = $( this ).val();
				metaKeys.push( key );
				$rowMap[ key ] = $( this ).closest( 'tr' );
			} );

			// Disable the buttons and start the spinner.
			$( '#doaction, #doaction2' ).prop( 'disabled', true );
			toggleSpinners( true );

			// Remove any existing status notice and create a new one.
			$( '.rspmeac-bulk-status' ).remove();
			var $statusDiv = $( '<div class="notice notice-info rspmeac-bulk-status"><p></p></div>' );
			$( '#rspmeac-meta-form' ).prepend( $statusDiv );
			var statusEl = $statusDiv.find( 'p' );

			/**
			 * Shared bulk completion: reset controls and show result notice.
			 *
			 * @param {boolean} success Whether the bulk action succeeded.
			 * @return {void}
			 */
			function finishBulkAction( success ) {
				bulkActionRunning = false;
				$( '#doaction, #doaction2' ).prop( 'disabled', false );
				toggleSpinners( false );

				if ( ! success ) {
					statusEl.text( rspmeacData.i18n.error );
					$statusDiv.removeClass( 'notice-info' ).addClass( 'notice-error' );
					return;
				}

				$statusDiv.removeClass( 'notice-info' ).addClass( 'notice-success' );
				statusEl.text( rspmeacData.i18n.done );

				$( '#cb-select-all-1' ).prop( 'checked', false ).prop( 'indeterminate', false );
				updateApplyButtons();
			}

			if ( 'refresh' === selectedAction ) {
				refreshSelectedOverview( metaKeys, statusEl, $rowMap, finishBulkAction );
				return;
			}

			processBulkAction( metaKeys, selectedAction, statusEl, function ( success ) {
				if ( success ) {
					// Update the DOM without a full page reload.
					$.each( $rowMap, function ( key, $row ) {
						if ( 'delete' === selectedAction ) {
							$row.next( 'tr.rspmeac-inline-edit-row' ).remove();
							$row.fadeOut( 400, function () {
								$( this ).remove();
							} );
						} else if ( 'delete_value' === selectedAction ) {
							$row.find( 'td strong' ).first().text( '0' );
							$row.find( 'td small' ).remove();
							$row.find( 'td' ).eq( 5 ).text( '' );
							$row.find( 'input[type="checkbox"]' ).prop( 'checked', false );
						}
					} );
				}

				finishBulkAction( success );
			} );
		} );

		// Toggle Edit / Delete action selects in the merged Actions column.
		$( document ).on( 'click', '.rspmeac-action-toggle', function ( e ) {
			e.preventDefault();

			var $button = $( this );
			var panelType = $button.attr( 'data-rspmeac-action-panel' );
			var $cell = $button.closest( '.rspmeac-row-actions' );
			var $panel = $cell.find( '.rspmeac-action-panel--' + panelType );
			var willOpen = $panel.prop( 'hidden' );

			$cell.find( '.rspmeac-action-panel' ).prop( 'hidden', true );
			$cell.find( '.rspmeac-action-toggle' )
				.removeClass( 'is-active' )
				.attr( 'aria-expanded', 'false' );

			if ( willOpen ) {
				$panel.prop( 'hidden', false );
				$button.addClass( 'is-active' ).attr( 'aria-expanded', 'true' );
				$panel.find( 'select' ).trigger( 'focus' );
			}
		} );

		// Delete actions dropdown - run the delete when an option is selected.
		$( document ).on( 'change', '.rspmeac-delete-actions-select', function () {
			var action   = $( this ).val();
			var $select  = $( this );
			// .attr() keeps the value as a verbatim string; .data() would
			// type-cast keys such as "null", "true" or JSON-looking strings
			// and their AJAX validation would then fail.
			var metaKey  = $select.attr( 'data-key' );
			var statusEl = $select.closest( 'td' ).find( '.rspmeac-meta-status-delete' );
			var $row     = $select.closest( 'tr' );

			if ( '' === action ) {
				return;
			}

			var confirmMsg = 'delete_value' === action
				? rspmeacData.i18n.confirmDeleteValue
				: rspmeacData.i18n.confirmDelete;

			// eslint-disable-next-line no-alert -- Intentional confirmation dialog.
			if ( ! window.confirm( confirmMsg ) ) {
				$select.val( '' );
				return;
			}

			$select.prop( 'disabled', true );

			processMeta( metaKey, action, 0, statusEl, function ( success ) {
				$select.prop( 'disabled', false ).val( '' );
				if ( success && 'delete' === action ) {
					setTimeout( function () {
						$row.fadeOut( 400, function () {
							$( this ).remove();
						} );
					}, 800 );
				} else if ( success && 'delete_value' === action ) {
					$row.find( 'td strong' ).first().text( '0' );
					$row.find( 'td small' ).remove();
					$row.find( 'td' ).eq( 5 ).text( '' );
				}
			} );
		} );

		/**
		 * Close all inline-edit rows and clear their inputs.
		 */
		function closeAllInlineEdits() {
			$( '.rspmeac-inline-edit-row' ).hide();
			$( '.rspmeac-inline-edit-overwrite, .rspmeac-inline-edit-search-replace' ).hide();
			$( '.rspmeac-input-new-value, .rspmeac-input-search, .rspmeac-input-replace' ).val( '' );
			$( '.rspmeac-meta-status-edit' ).text( '' );
		}

		// Edit actions dropdown - open the inline edit panel on selection.
		$( document ).on( 'change', '.rspmeac-edit-actions-select', function () {
			var action   = $( this ).val();
			var $select  = $( this );
			// Use DOM traversal instead of an attribute selector to avoid breakage
			// when the meta key contains CSS-special characters (quotes, brackets…).
			var $editRow = $select.closest( 'tr' ).next( '.rspmeac-inline-edit-row' );

			if ( '' === action ) {
				return;
			}

			$select.val( '' );

			closeAllInlineEdits();

			if ( 'overwrite' === action ) {
				$editRow.show().find( '.rspmeac-inline-edit-overwrite' ).show();
				$editRow.find( '.rspmeac-input-new-value' ).trigger( 'focus' );
			} else {
				$editRow.data( 'searchReplaceAction', action );
				$editRow.show().find( '.rspmeac-inline-edit-search-replace' ).show();
				$editRow.find( '.rspmeac-input-search' ).trigger( 'focus' );
			}
		} );

		// Apply Overwrite.
		$( document ).on( 'click', '.rspmeac-apply-overwrite', function () {
			var $editRow = $( this ).closest( '.rspmeac-inline-edit-row' );
			var metaKey  = $editRow.attr( 'data-key' );
			var newValue = $editRow.find( '.rspmeac-input-new-value' ).val();
			var statusEl = $editRow.find( '.rspmeac-meta-status-edit' );
			var $dataRow = $editRow.prev( 'tr' );
			var $buttons = $editRow.find( 'button' );

			// eslint-disable-next-line no-alert -- Intentional confirmation dialog.
			if ( ! window.confirm( rspmeacData.i18n.confirmOverwrite ) ) {
				return;
			}

			$buttons.prop( 'disabled', true );

			processMeta( metaKey, 'overwrite', 0, statusEl, function ( success, data ) {
				$buttons.prop( 'disabled', false );
				if ( success ) {
					var displayVal = data && data.new_value ? data.new_value : newValue;
					if ( displayVal.length > 100 ) {
						displayVal = displayVal.substring( 0, 100 ) + '\u2026';
					}
					$dataRow.find( 'td[data-label]' ).eq( 5 ).text( displayVal );
					setTimeout( function () {
						closeAllInlineEdits();
					}, 1000 );
				}
			}, { new_value: newValue } );
		} );

		// Apply Search & Replace.
		$( document ).on( 'click', '.rspmeac-apply-search-replace', function () {
			var $editRow     = $( this ).closest( '.rspmeac-inline-edit-row' );
			var metaKey      = $editRow.attr( 'data-key' );
			var action       = $editRow.data( 'searchReplaceAction' ) || 'search_replace_value';
			var searchValue  = $editRow.find( '.rspmeac-input-search' ).val();
			var replaceValue = $editRow.find( '.rspmeac-input-replace' ).val();
			var statusEl     = $editRow.find( '.rspmeac-meta-status-edit' );
			var $buttons     = $editRow.find( 'button' );
			var confirmMsg   = 'search_replace_value_and_key' === action
				? rspmeacData.i18n.confirmSearchReplaceValueAndKey
				: rspmeacData.i18n.confirmSearchReplaceValue;

			if ( '' === searchValue ) {
				$editRow.find( '.rspmeac-input-search' ).trigger( 'focus' );
				return;
			}

			// eslint-disable-next-line no-alert -- Intentional confirmation dialog.
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}

			$buttons.prop( 'disabled', true );

			processMeta( metaKey, action, 0, statusEl, function ( success ) {
				$buttons.prop( 'disabled', false );
				if ( success ) {
					setTimeout( function () {
						closeAllInlineEdits();
					}, 1000 );
				}
			}, { search_value: searchValue, replace_value: replaceValue } );
		} );

		// Cancel inline edit.
		$( document ).on( 'click', '.rspmeac-cancel-inline-edit', function () {
			closeAllInlineEdits();
		} );
	} );
}( jQuery ) );
