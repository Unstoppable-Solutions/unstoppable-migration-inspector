/**
 * Unstoppable Migration Inspector: Snapshots screen.
 *
 * Starts snapshot and comparison jobs and drives them one batch at a time
 * through the REST API. All job state is kept on the server, so closing the
 * page only pauses the job; it can be resumed from the list.
 */
( function () {
	'use strict';

	var config = window.uminSnapshots || {};
	var apiFetch = window.wp && window.wp.apiFetch;
	var i18n = ( window.wp && window.wp.i18n ) || {
		__: function ( text ) {
			return text;
		},
		sprintf: function ( text ) {
			return text;
		},
	};
	var __ = i18n.__;
	var sprintf = i18n.sprintf;
	var TEXT_DOMAIN = 'unstoppable-migration-inspector';

	if ( ! apiFetch || ! config.namespace ) {
		return;
	}

	var running = false;
	var cancelRequested = false;
	var currentJob = 0;

	function path( suffix ) {
		return '/' + config.namespace + suffix;
	}

	function progressElements( trigger ) {
		var scope = ( trigger && trigger.closest( '.umin-panel' ) ) || document;
		var box = scope.querySelector( '[data-umin-progress]' ) || document.querySelector( '[data-umin-progress]' );

		return {
			box: box,
			bar: box ? box.querySelector( '[data-umin-progress-bar]' ) : null,
			text: box ? box.querySelector( '[data-umin-progress-text]' ) : null,
			cancel: box ? box.querySelector( '[data-umin-action="cancel"]' ) : null,
		};
	}

	function setBusy( busy ) {
		running = busy;
		document.querySelectorAll( '[data-umin-action]' ).forEach( function ( button ) {
			if ( 'cancel' !== button.getAttribute( 'data-umin-action' ) ) {
				button.disabled = busy;
			}
		} );
	}

	function describe( progress ) {
		progress = progress || {};

		switch ( progress.phase ) {
			case 'discover':
			case 'config':
				return __( 'Reading site settings…', TEXT_DOMAIN );
			case 'tables':
				return sprintf(
					/* translators: 1: tables done, 2: total tables. */
					__( 'Counting database table rows (%1$d of %2$d)…', TEXT_DOMAIN ),
					progress.tables_done || 0,
					progress.tables_total || 0
				);
			case 'records':
				if ( undefined !== progress.records ) {
					return sprintf(
						/* translators: 1: record type, 2: types done, 3: total types, 4: records so far. */
						__( 'Recording %1$s (%2$d of %3$d record types, %4$d records so far)…', TEXT_DOMAIN ),
						progress.current || '',
						progress.types_done || 0,
						progress.types_total || 0,
						progress.records || 0
					);
				}
				return sprintf(
					/* translators: 1: record type, 2: types done, 3: total types. */
					__( 'Comparing %1$s (%2$d of %3$d record types)…', TEXT_DOMAIN ),
					progress.current || '',
					progress.types_done || 0,
					progress.types_total || 0
				);
			case 'finalize':
				return __( 'Finishing…', TEXT_DOMAIN );
		}

		return __( 'Working…', TEXT_DOMAIN );
	}

	function percent( progress ) {
		progress = progress || {};

		if ( 'finalize' === progress.phase ) {
			return 99;
		}
		if ( 'records' === progress.phase && progress.types_total ) {
			return Math.min( 98, 10 + Math.round( ( 88 * progress.types_done ) / progress.types_total ) );
		}
		if ( 'tables' === progress.phase && progress.tables_total ) {
			return Math.round( ( 10 * progress.tables_done ) / progress.tables_total );
		}
		return 2;
	}

	function show( elements, message, value ) {
		if ( ! elements.box ) {
			return;
		}
		elements.box.hidden = false;
		if ( elements.text ) {
			elements.text.textContent = message;
		}
		if ( elements.bar && 'number' === typeof value ) {
			elements.bar.value = value;
		}
	}

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	/**
	 * Run a job until it finishes, is cancelled, or fails.
	 *
	 * @param {number}      jobId   Job ID.
	 * @param {HTMLElement} trigger Button that started it.
	 * @return {Promise<string>} Final status.
	 */
	function runJob( jobId, trigger ) {
		var elements = progressElements( trigger );
		var failures = 0;

		currentJob = jobId;
		cancelRequested = false;
		setBusy( true );

		if ( elements.cancel ) {
			elements.cancel.hidden = false;
			elements.cancel.disabled = false;
		}

		show( elements, __( 'Starting…', TEXT_DOMAIN ), 1 );

		function finish( status ) {
			setBusy( false );
			currentJob = 0;
			if ( elements.cancel ) {
				elements.cancel.hidden = true;
			}
			return status;
		}

		function next() {
			if ( cancelRequested ) {
				return Promise.resolve( finish( 'cancelled' ) );
			}

			return apiFetch( { path: path( '/jobs/' + jobId + '/run' ), method: 'POST' } ).then(
				function ( result ) {
					failures = 0;

					if ( 'completed' === result.status ) {
						show( elements, __( 'Done.', TEXT_DOMAIN ), 100 );
						return finish( 'completed' );
					}

					if ( 'failed' === result.status || 'cancelled' === result.status || 'missing' === result.status ) {
						show( elements, result.message || __( 'The job stopped.', TEXT_DOMAIN ) );
						return finish( result.status );
					}

					if ( 'busy' === result.status ) {
						show( elements, result.message );
						return wait( 5000 ).then( next );
					}

					show( elements, describe( result.progress ), percent( result.progress ) );
					return next();
				},
				function ( error ) {
					failures++;

					if ( failures >= 3 ) {
						show(
							elements,
							( error && error.message ? error.message + ' ' : '' ) +
								__( 'The connection keeps failing. The job is paused and can be resumed from the snapshot list.', TEXT_DOMAIN )
						);
						return finish( 'paused' );
					}

					show( elements, __( 'Connection problem, retrying…', TEXT_DOMAIN ) );
					return wait( 3000 * failures ).then( next );
				}
			);
		}

		return next();
	}

	function reload( url ) {
		window.location.assign( url || window.location.href );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-umin-action]' );

		if ( ! button ) {
			return;
		}

		var action = button.getAttribute( 'data-umin-action' );

		if ( 'cancel' === action ) {
			if ( currentJob ) {
				cancelRequested = true;
				button.disabled = true;
				apiFetch( { path: path( '/jobs/' + currentJob + '/cancel' ), method: 'POST' } ).then( function () {
					reload();
				} );
			}
			return;
		}

		if ( running ) {
			return;
		}

		if ( 'capture' === action ) {
			var labelField = document.getElementById( 'umin-snapshot-label' );
			apiFetch( {
				path: path( '/snapshots' ),
				method: 'POST',
				data: { label: labelField ? labelField.value : '' },
			} ).then(
				function ( started ) {
					return runJob( started.job_id, button ).then( function ( status ) {
						if ( 'completed' === status ) {
							reload();
						}
					} );
				},
				function ( error ) {
					show( progressElements( button ), error.message || __( 'Could not start the snapshot.', TEXT_DOMAIN ) );
				}
			);
			return;
		}

		if ( 'compare' === action ) {
			var snapshotId = button.getAttribute( 'data-umin-snapshot' );
			apiFetch( { path: path( '/snapshots/' + snapshotId + '/compare' ), method: 'POST' } ).then(
				function ( started ) {
					return runJob( started.job_id, button ).then( function ( status ) {
						if ( 'completed' === status ) {
							reload( config.pageUrl + '&comparison=' + encodeURIComponent( started.comparison_id ) );
						}
					} );
				},
				function ( error ) {
					show( progressElements( button ), error.message || __( 'Could not start the comparison.', TEXT_DOMAIN ) );
				}
			);
			return;
		}

		if ( 'resume' === action ) {
			runJob( parseInt( button.getAttribute( 'data-umin-job' ), 10 ), button ).then( function ( status ) {
				if ( 'completed' === status ) {
					reload();
				}
			} );
			return;
		}

		if ( 'delete' === action ) {
			// eslint-disable-next-line no-alert -- A native confirmation is the clearest pattern for an irreversible delete.
			if ( ! window.confirm( __( 'Delete this snapshot and its comparisons? This cannot be undone. Site content is not affected.', TEXT_DOMAIN ) ) ) {
				return;
			}
			apiFetch( { path: path( '/snapshots/' + button.getAttribute( 'data-umin-snapshot' ) ), method: 'DELETE' } ).then( function () {
				reload();
			} );
		}
	} );
} )();
