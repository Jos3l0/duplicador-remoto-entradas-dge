/**
 * Admin script for WP Remote Post Duplicator.
 *
 * Handles meta box sync button and row action sync via AJAX.
 *
 * @package EW_Remote_Post_Duplicator
 */

( function () {
	document.addEventListener( 'DOMContentLoaded', function () {
		initMetaBoxSync();
		initRowActionSync();
		initBulkSync();
	} );

	/**
	 * Initialize meta box sync button.
	 */
	function initMetaBoxSync() {
		var triggers = document.querySelectorAll( '.ew-rpd-sync-trigger' );

		triggers.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var postId = parseInt( button.getAttribute( 'data-post-id' ), 10 );

				if ( ! postId || isNaN( postId ) ) {
					return;
				}

				if ( ! window.ewRpdMetabox || ! window.ewRpdMetabox.nonce ) {
					return;
				}

				var container = button.closest( '.ew-rpd-metabox-content' );
				var spinner = container ? container.querySelector( '.ew-rpd-spinner' ) : null;
				var resultEl = container ? container.querySelector( '.ew-rpd-metabox-result' ) : null;
				var originalText = button.textContent;

				setButtonLoading( button, true );

				if ( spinner ) {
					spinner.classList.add( 'is-active' );
				}

				if ( resultEl ) {
					hideResult( resultEl );
				}

				doAjaxSync( postId, 'ew_rpd_metabox_sync', window.ewRpdMetabox.nonce )
					.then( function ( data ) {
						if ( resultEl ) {
							showResult( resultEl, data.message || data, 'success' );
						}
						button.textContent = window.ewRpdMetabox.syncBtn || 'Sincronizar de nuevo';
						updateMetaBoxAfterSuccess( container, data );
					} )
					.catch( function ( error ) {
						if ( resultEl ) {
							showResult( resultEl, error.message || 'Error de conexion', 'error' );
						}
						button.textContent = window.ewRpdMetabox.retryBtn || 'Reintentar';
					} )
					.finally( function () {
						setButtonLoading( button, false );
						if ( spinner ) {
							spinner.classList.remove( 'is-active' );
						}
					} );
			} );
		} );
	}

	/**
	 * Initialize row action sync links.
	 */
	function initRowActionSync() {
		var links = document.querySelectorAll( '.ew-rpd-row-sync' );

		links.forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				var postId = parseInt( link.getAttribute( 'data-post-id' ), 10 );
				var nonce = link.getAttribute( 'data-nonce' );

				if ( ! postId || isNaN( postId ) || ! nonce ) {
					return;
				}

				var originalHTML = link.innerHTML;
				link.innerHTML = '<span class="spinner is-active" style="float:none;margin:0 4px 0 0;visibility:visible;"></span>' + ( window.ewRpdMetabox && window.ewRpdMetabox.syncing ? window.ewRpdMetabox.syncing : '...' );
				link.style.pointerEvents = 'none';

				var row = link.closest( 'tr' );
				var syncCell = row ? row.querySelector( 'td.ew_rpd_sync' ) : null;

				if ( syncCell ) {
					syncCell.innerHTML = '<span class="spinner is-active" style="float:none;margin:0;visibility:visible;"></span>';
				}

				doAjaxSync( postId, 'ew_rpd_row_sync', nonce )
					.then( function ( data ) {
						link.innerHTML = '<span class="dashicons dashicons-cloud ew-rpd-row-ok" style="color:#008a20;font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span> ' + escapeHtml( originalHTML.replace( /<[^>]*>/g, '' ) );

						if ( syncCell ) {
							var tooltip = 'ID remoto: ' + data.remoteId;
							if ( data.lastSync ) {
								tooltip += ' | Ultima sincronizacion: ' + data.lastSync;
							}
							syncCell.innerHTML = '<span class="dashicons dashicons-cloud ew-rpd-col-icon ew-rpd-col-blue" title="' + escapeAttr( tooltip ) + '"></span>';
							if ( data.remoteUrl ) {
								syncCell.innerHTML += ' <a href="' + escapeAttr( data.remoteUrl ) + '" target="_blank" rel="noopener noreferrer" class="ew-rpd-col-link" title="Ver entrada remota"><span class="dashicons dashicons-admin-links"></span></a>';
							}
						}
					} )
					.catch( function ( error ) {
						link.innerHTML = '<span class="dashicons dashicons-warning" style="color:#dba617;font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span> ' + escapeHtml( originalHTML.replace( /<[^>]*>/g, '' ) );

						if ( syncCell ) {
							syncCell.innerHTML = '<span class="dashicons dashicons-warning ew-rpd-col-icon ew-rpd-col-orange" title="' + escapeAttr( error.message || 'Error' ) + '"></span>';
						}
					} )
					.finally( function () {
						link.style.pointerEvents = '';
					} );
			} );
		} );
	}

	/**
	 * Perform AJAX sync request.
	 *
	 * @param {number} postId Post ID.
	 * @param {string} action AJAX action.
	 * @param {string} nonce  Nonce value.
	 * @return {Promise}
	 */
	function doAjaxSync( postId, action, nonce ) {
		var formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'nonce', nonce );
		formData.append( 'post_id', postId );

		return fetch( ( window.ewRpdMetabox && window.ewRpdMetabox.ajaxUrl ) || window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( data ) {
				if ( data.success ) {
					return data.data;
				}
				throw new Error( data.data.message || data.data || 'Error desconocido' );
			} );
	}

	/**
	 * Update meta box UI after successful sync.
	 *
	 * @param {HTMLElement|null} container Meta box container.
	 * @param {Object}           data      Response data.
	 */
	function updateMetaBoxAfterSuccess( container, data ) {
		if ( ! container ) {
			return;
		}

		var statusIcon = container.querySelector( '.ew-rpd-status-icon' );
		var statusText = container.querySelector( '.ew-rpd-status-text' );
		var detailDiv = container.querySelector( '.ew-rpd-metabox-detail' );

		if ( statusIcon ) {
			statusIcon.textContent = '\u2705';
			statusIcon.className = 'ew-rpd-status-icon ew-rpd-status-synced';
			statusIcon.title = 'Sincronizado';
		}

		if ( statusText ) {
			statusText.textContent = 'Sincronizado';
			statusText.style.color = '#008a20';
		}

		if ( detailDiv && data.remoteId && data.remoteUrl ) {
			var html = '<p><strong>ID remoto:</strong> <code>' + escapeHtml( String( data.remoteId ) ) + '</code></p>';
			html += '<p><a href="' + escapeAttr( data.remoteUrl ) + '" target="_blank" rel="noopener noreferrer" class="button button-small">Ver entrada remota \u2197</a></p>';
			if ( data.lastSync ) {
				html += '<p class="ew-rpd-metabox-date">Ultima sincronizacion: <br><strong>' + escapeHtml( data.lastSync ) + '</strong></p>';
			}
			detailDiv.innerHTML = html;
		}
	}

	/**
	 * Set button loading state.
	 *
	 * @param {HTMLElement} button  Button element.
	 * @param {boolean}     loading Is loading.
	 */
	function setButtonLoading( button, loading ) {
		button.disabled = loading;
	}

	/**
	 * Show result message.
	 *
	 * @param {HTMLElement} el      Element.
	 * @param {string}      message Message.
	 * @param {string}      type    success|error.
	 */
	function showResult( el, message, type ) {
		el.textContent = message;
		el.className = 'ew-rpd-metabox-result ew-rpd-' + type;
		el.style.display = 'block';
	}

	/**
	 * Hide result message.
	 *
	 * @param {HTMLElement} el Element.
	 */
	function hideResult( el ) {
		el.style.display = 'none';
		el.textContent = '';
		el.className = 'ew-rpd-metabox-result';
	}

	/**
	 * Escape HTML entities.
	 *
	 * @param {string} str Raw string.
	 * @return {string} Escaped string.
	 */
	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	/**
	 * Escape attribute value.
	 *
	 * @param {string} str Raw string.
	 * @return {string} Escaped string.
	 */
	function escapeAttr( str ) {
		return str.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	/**
	 * Initialize bulk category sync with progress bar.
	 */
	function initBulkSync() {
		var categorySelect = document.getElementById( 'ew-rpd-bulk-category' );
		var syncBtn = document.getElementById( 'ew-rpd-bulk-sync-btn' );
		var cancelBtn = document.getElementById( 'ew-rpd-bulk-cancel-btn' );
		var progressDiv = document.getElementById( 'ew-rpd-bulk-progress' );
		var progressFill = progressDiv ? progressDiv.querySelector( '.ew-rpd-progress-fill' ) : null;
		var progressText = progressDiv ? progressDiv.querySelector( '.ew-rpd-progress-text' ) : null;
		var progressResults = progressDiv ? progressDiv.querySelector( '.ew-rpd-progress-results' ) : null;
		var cancelled = false;

		if ( ! categorySelect || ! syncBtn ) {
			return;
		}

		categorySelect.addEventListener( 'change', function () {
			syncBtn.disabled = ! categorySelect.value;
		} );

		syncBtn.addEventListener( 'click', function () {
			var categorySlug = categorySelect.value;

			if ( ! categorySlug ) {
				return;
			}

			if ( ! window.ewRpdMetabox || ! window.ewRpdMetabox.bulkNonce ) {
				return;
			}

			cancelled = false;
			syncBtn.style.display = 'none';

			if ( cancelBtn ) {
				cancelBtn.style.display = 'inline-block';
			}

			categorySelect.disabled = true;

			if ( progressDiv ) {
				progressDiv.style.display = 'block';
			}
			if ( progressFill ) {
				progressFill.style.width = '0%';
			}
			if ( progressText ) {
				progressText.textContent = 'Iniciando...';
			}
			if ( progressResults ) {
				progressResults.innerHTML = '';
			}

			processBatch( categorySlug, 0, 5 );
		} );

		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				cancelled = true;
				cancelBtn.style.display = 'none';
				syncBtn.style.display = 'inline-block';

				if ( progressText ) {
					progressText.textContent = ( progressText.textContent || '' ) + ' — Cancelado por el usuario.';
				}
			} );
		}

		/**
		 * Process one batch of posts.
		 *
		 * @param {string} categorySlug Category slug.
		 * @param {number} offset       Current offset.
		 * @param {number} batchSize    Batch size.
		 */
		function processBatch( categorySlug, offset, batchSize ) {
			if ( cancelled ) {
				return;
			}

			var formData = new FormData();
			formData.append( 'action', 'ew_rpd_bulk_sync_batch' );
			formData.append( 'nonce', window.ewRpdMetabox.bulkNonce );
			formData.append( 'category_slug', categorySlug );
			formData.append( 'offset', offset );
			formData.append( 'batch_size', batchSize );

			fetch( window.ewRpdMetabox.ajaxUrl || window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'HTTP ' + response.status );
					}
					return response.json();
				} )
				.then( function ( data ) {
					if ( cancelled ) {
						return;
					}

					if ( ! data.success ) {
						throw new Error( data.data.message || 'Error' );
					}

					var d = data.data;
					var total = d.total;
					var newOffset = d.offset;
					var percent = total > 0 ? Math.round( ( newOffset / total ) * 100 ) : 100;

					if ( progressFill ) {
						progressFill.style.width = percent + '%';
					}

					if ( progressText ) {
						progressText.textContent = newOffset + ' / ' + total + ' procesados (' + d.processed + ' OK, ' + d.errors + ' errores)';
					}

					if ( progressResults && d.results ) {
						d.results.forEach( function ( r ) {
							var li = document.createElement( 'li' );
							if ( r.status === 'ok' ) {
								li.innerHTML = '<span style="color:#008a20;">&#x2705;</span> ' + escapeHtml( r.title ) + ' (#' + r.postId + ' &rarr; remoto #' + r.remoteId + ')';
							} else {
								li.innerHTML = '<span style="color:#b32d2e;">&#x26A0;</span> ' + escapeHtml( r.title ) + ' (#' + r.postId + '): ' + escapeHtml( r.message || 'Error' );
							}
							progressResults.appendChild( li );
						} );
					}

					if ( ! d.done && ! cancelled ) {
						setTimeout( function () {
							processBatch( categorySlug, newOffset, batchSize );
						}, 300 );
					} else {
						finishSync();
					}
				} )
				.catch( function ( error ) {
					if ( progressText ) {
						progressText.textContent = 'Error: ' + ( error.message || 'Error de conexion' );
					}
					finishSync();
				} );
		}

		/**
		 * Restore UI after sync completes or is cancelled.
		 */
		function finishSync() {
			syncBtn.style.display = 'inline-block';

			if ( cancelBtn ) {
				cancelBtn.style.display = 'none';
			}

			categorySelect.disabled = false;
		}
	}
} )();
