/**
 * Post Meta Editor and Cleaner - Admin script.
 *
 * Meta adatok törlése AJAX kötegelt feldolgozással.
 */

/* global jQuery, rspmeacData */

( function ( $ ) {
	'use strict';

	// Ha a script kétszer töltődne be, ne inicializáljon újra.
	if ( window.rspmeacScriptLoaded ) {
		return;
	}
	window.rspmeacScriptLoaded = true;

	// Megakadályozza, hogy párhuzamosan fusson több bulk művelet.
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

				var data      = response.data;
				var newOffset = offset + data.processed;

				if ( data.has_more ) {
					processMeta( metaKey, actionType, newOffset, statusEl, callback, extraData );
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
	 * Bulk művelet végrehajtása több meta key-re egymás után.
	 *
	 * @param {Array}    metaKeys   Meta kulcsok tömbje.
	 * @param {string}   actionType Művelet típusa.
	 * @param {Object}   statusEl   jQuery elem a státusz megjelenítéséhez.
	 * @param {Function} onDone     Callback a teljes befejezéskor.
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
	 * Spinner megjelenítése / elrejtése az Apply gombok mellett.
	 *
	 * @param {boolean} show Megjelenítés vagy elrejtés.
	 */
	function toggleSpinners( show ) {
		$( '.rspmeac-bulk-spinner' ).toggleClass( 'is-active', show );
	}

	/**
	 * Apply gombok állapotának frissítése a kijelölés alapján.
	 */
	function updateApplyButtons() {
		var checked = $( 'input[name="meta_keys[]"]:checked' ).length;
		$( '#doaction, #doaction2' ).prop( 'disabled', 0 === checked );
	}

	$( function () {
		// Spinner elemek hozzáadása az Apply gombok mellé.
		$( '#doaction, #doaction2' ).after( '<span class="spinner rspmeac-bulk-spinner"></span>' );

		// Apply gombok kezdetben disabled - nincs kijelölés.
		$( '#doaction, #doaction2' ).prop( 'disabled', true );

		// Form submit blokkolása - minden műveletet AJAX kezel.
		$( '#rspmeac-meta-form' ).on( 'submit', function ( e ) {
			e.preventDefault();
		} );

		// Select All checkbox.
		$( '#cb-select-all-1' ).on( 'change', function () {
			$( 'input[name="meta_keys[]"]' ).prop( 'checked', $( this ).prop( 'checked' ) );
			updateApplyButtons();
		} );

		// Egyedi checkbox-ok: frissíti a Select All állapotát és a gombokat.
		$( document ).on( 'change', 'input[name="meta_keys[]"]', function () {
			var total   = $( 'input[name="meta_keys[]"]' ).length;
			var checked = $( 'input[name="meta_keys[]"]:checked' ).length;

			$( '#cb-select-all-1' )
				.prop( 'checked', checked === total )
				.prop( 'indeterminate', checked > 0 && checked < total );

			updateApplyButtons();
		} );

		// Bulk action gombok.
		$( '#doaction, #doaction2' ).off( 'click.rspmeac' ).on( 'click.rspmeac', function ( e ) {
			e.stopImmediatePropagation();

			if ( bulkActionRunning ) {
				return;
			}

			var selectedAction = $( this ).is( '#doaction' )
				? $( '#bulk-action-selector-top' ).val()
				: $( '#bulk-action-selector-bottom' ).val();

			if ( '-1' === selectedAction ) {
				// eslint-disable-next-line no-alert -- Szándékos felhasználói figyelmeztetés.
				window.alert( rspmeacData.i18n.selectAction );
				return;
			}

			var checkedItems = $( 'input[name="meta_keys[]"]:checked' );

			if ( 0 === checkedItems.length ) {
				return;
			}

			var confirmMsg = rspmeacData.i18n.confirmBulk.replace( '%d', checkedItems.length );

			// eslint-disable-next-line no-alert -- Szándékos megerősítés kérés.
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}

			bulkActionRunning = true;

			// Érintett sorok összegyűjtése a DOM frissítéshez.
			var $rowMap = {};
			var metaKeys = [];
			checkedItems.each( function () {
				var key = $( this ).val();
				metaKeys.push( key );
				$rowMap[ key ] = $( this ).closest( 'tr' );
			} );

			// Gombok letiltása, spinner indítása.
			$( '#doaction, #doaction2' ).prop( 'disabled', true );
			toggleSpinners( true );

			// Meglévő status eltávolítása és új létrehozása.
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

				// DOM frissítése reload nélkül.
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

				// Select All és Apply gombok visszaállítása.
				$( '#cb-select-all-1' ).prop( 'checked', false ).prop( 'indeterminate', false );
				updateApplyButtons();
			} );
		} );

		// Delete actions dropdown — törlés végrehajtása kiválasztásra.
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

		// Edit actions dropdown — inline edit panel megnyitása kiválasztásra.
		$( '.rspmeac-edit-actions-select' ).on( 'change', function () {
			var action   = $( this ).val();
			var $select  = $( this );
			var metaKey  = $select.data( 'key' );
			var $editRow = $( '.rspmeac-inline-edit-row[data-key="' + metaKey + '"]' );

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
