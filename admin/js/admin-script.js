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

	/**
	 * Batched AJAX processing for a single meta key.
	 *
	 * @param {string}   metaKey    Meta key name.
	 * @param {string}   actionType Action type (delete|delete_value|overwrite|search_replace_value|search_replace_value_and_key).
	 * @param {number}   offset     Processing offset.
	 * @param {Object}   statusEl   jQuery element for status display.
	 * @param {Function} callback   Callback on completion (optional).
	 * @param {Object}   extraData  Additional POST data (optional).
	 */
	function processMeta( metaKey, actionType, offset, statusEl, callback, extraData ) {
		statusEl
			.empty()
			.text( rspmeacData.i18n.processing )
			.append( ' ' )
			.append( $( '<span class="spinner is-active rspmeac-status-spinner"></span>' ) );

		var postData = {
			action:      'rspmeac_process_meta',
			nonce:       rspmeacData.nonce,
			meta_key:    metaKey,
			action_type: actionType,
			offset:      offset,
		};

		if ( extraData ) {
			$.extend( postData, extraData );
		}

		$.post(
			rspmeacData.ajaxUrl,
			postData,
			function ( response ) {
				if ( ! response.success ) {
					statusEl.text( rspmeacData.i18n.error );
					if ( 'function' === typeof callback ) {
						callback( false );
					}
					return;
				}

				var data = response.data;

				if ( data.has_more ) {
					// The server owns the continuation logic: destructive
					// actions restart from 0 because processed rows drop out
					// of the result set, other actions continue where the
					// previous batch stopped.
					processMeta( metaKey, actionType, data.next_offset, statusEl, callback, extraData );
				} else {
					statusEl.text( rspmeacData.i18n.done );
					if ( 'function' === typeof callback ) {
						callback( true, data );
					} else {
						setTimeout( function () {
							window.location.reload();
						}, 1500 );
					}
				}
			}
		).fail( function () {
			statusEl.text( rspmeacData.i18n.error );
			if ( 'function' === typeof callback ) {
				callback( false );
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
		var total = metaKeys.length;

		function processNext() {
			if ( index >= total ) {
				if ( 'function' === typeof onDone ) {
					onDone( true );
				}
				return;
			}

			var metaKey = metaKeys[ index ];
			statusEl.text( rspmeacData.i18n.processing + ' (' + ( index + 1 ) + '/' + total + ')' );

			processMeta( metaKey, actionType, 0, $( '<span>' ), function ( success ) {
				if ( ! success ) {
					if ( 'function' === typeof onDone ) {
						onDone( false );
					}
					return;
				}
				index++;
				processNext();
			} );
		}

		processNext();
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
		// Add spinner elements next to the Apply buttons.
		$( '#doaction, #doaction2' ).after( '<span class="spinner rspmeac-bulk-spinner"></span>' );

		// Apply buttons start disabled - nothing is selected yet.
		$( '#doaction, #doaction2' ).prop( 'disabled', true );

		// Block form submit - every operation is handled via AJAX.
		$( '#rspmeac-meta-form' ).on( 'submit', function ( e ) {
			e.preventDefault();
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

			processBulkAction( metaKeys, selectedAction, statusEl, function ( success ) {
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

				// Update the DOM without a full page reload.
				$.each( $rowMap, function ( key, $row ) {
					if ( 'delete' === selectedAction ) {
						$row.fadeOut( 400, function () {
							$( this ).remove();
						} );
					} else if ( 'delete_value' === selectedAction ) {
						$row.find( 'td strong' ).first().text( '0' );
						$row.find( 'td small' ).remove();
						$row.find( 'td' ).eq( 4 ).text( '' );
						$row.find( 'input[type="checkbox"]' ).prop( 'checked', false );
					}
				} );

				// Reset the Select All checkbox and the Apply buttons.
				$( '#cb-select-all-1' ).prop( 'checked', false ).prop( 'indeterminate', false );
				updateApplyButtons();
			} );
		} );

		// Delete actions dropdown - run the delete when an option is selected.
		$( '.rspmeac-delete-actions-select' ).on( 'change', function () {
			var action   = $( this ).val();
			var $select  = $( this );
			var metaKey  = $select.data( 'key' );
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
					$row.find( 'td' ).eq( 4 ).text( '' );
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
		$( '.rspmeac-edit-actions-select' ).on( 'change', function () {
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
			var metaKey  = $editRow.data( 'key' );
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
					$dataRow.find( 'td[data-label]' ).eq( 4 ).text( displayVal );
					setTimeout( function () {
						closeAllInlineEdits();
					}, 1000 );
				}
			}, { new_value: newValue } );
		} );

		// Apply Search & Replace.
		$( document ).on( 'click', '.rspmeac-apply-search-replace', function () {
			var $editRow     = $( this ).closest( '.rspmeac-inline-edit-row' );
			var metaKey      = $editRow.data( 'key' );
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
