/**
 * Unstoppable Migration Inspector: Scan Website.
 *
 * Starts a scan (or resumes an unfinished one) and drives it one batch at a
 * time through the REST API. All state is kept on the server: closing the
 * page only pauses the scan.
 */
( function () {
	'use strict';

	var config = window.uminScan || {};
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

	var cancelRequested = false;

	function path( suffix ) {
		return '/' + config.namespace + suffix;
	}

	function elements() {
		var box = document.querySelector( '[data-umin-progress]' );

		return {
			box: box,
			bar: box ? box.querySelector( '[data-umin-progress-bar]' ) : null,
			text: box ? box.querySelector( '[data-umin-progress-text]' ) : null,
			cancel: box ? box.querySelector( '[data-umin-action="cancel"]' ) : null,
		};
	}

	function setBusy( busy ) {
		document.querySelectorAll( '[data-umin-action]' ).forEach( function ( button ) {
			if ( 'cancel' !== button.getAttribute( 'data-umin-action' ) ) {
				button.disabled = busy;
			}
		} );
	}

	function show( message, value ) {
		var el = elements();

		if ( ! el.box ) {
			return;
		}
		el.box.hidden = false;
		if ( el.text ) {
			el.text.textContent = message;
		}
		if ( el.bar && 'number' === typeof value ) {
			el.bar.value = value;
		}
	}

	function describe( progress ) {
		progress = progress || {};

		if ( 'checks' === progress.phase ) {
			return sprintf(
				/* translators: 1: check name, 2: checks done, 3: total checks. */
				__( 'Checking: %1$s (%2$d of %3$d)…', TEXT_DOMAIN ),
				progress.current || '',
				( progress.checks_done || 0 ) + 1,
				progress.checks_total || 0
			);
		}
		if ( 'finalize' === progress.phase ) {
			return __( 'Summarising results…', TEXT_DOMAIN );
		}
		return __( 'Reading site settings…', TEXT_DOMAIN );
	}

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	function goToResults( scanId ) {
		var url = config.resultsUrl + ( -1 === config.resultsUrl.indexOf( '?' ) ? '?' : '&' ) + 'scan=' + encodeURIComponent( scanId );
		window.location.assign( url );
	}

	function runJob( jobId, scanId ) {
		var failures = 0;
		var el = elements();

		cancelRequested = false;
		setBusy( true );

		if ( el.cancel ) {
			el.cancel.hidden = false;
			el.cancel.disabled = false;
			el.cancel.onclick = function () {
				cancelRequested = true;
				el.cancel.disabled = true;
				apiFetch( { path: path( '/jobs/' + jobId + '/cancel' ), method: 'POST' } ).catch( function () {} );
			};
		}

		show( __( 'Starting the scan…', TEXT_DOMAIN ), 1 );

		function finish( message ) {
			setBusy( false );
			if ( el.cancel ) {
				el.cancel.hidden = true;
			}
			if ( message ) {
				show( message );
			}
		}

		function next() {
			if ( cancelRequested ) {
				finish( __( 'Scan cancelled.', TEXT_DOMAIN ) );
				return Promise.resolve();
			}

			return apiFetch( { path: path( '/jobs/' + jobId + '/run' ), method: 'POST' } ).then(
				function ( result ) {
					failures = 0;

					if ( 'completed' === result.status ) {
						show( __( 'Scan complete. Opening the results…', TEXT_DOMAIN ), 100 );
						goToResults( scanId );
						return;
					}

					if ( 'failed' === result.status || 'cancelled' === result.status || 'missing' === result.status ) {
						finish( result.message || __( 'The scan stopped.', TEXT_DOMAIN ) );
						return;
					}

					if ( 'busy' === result.status ) {
						show( result.message );
						return wait( 5000 ).then( next );
					}

					var progress = result.progress || {};
					show( describe( progress ), Math.max( 2, Math.min( 99, progress.percent || 2 ) ) );
					return next();
				},
				function ( error ) {
					failures++;

					if ( failures >= 3 ) {
						finish(
							( error && error.message ? error.message + ' ' : '' ) +
								__( 'The connection keeps failing. The scan is paused; open Scan Results to resume it.', TEXT_DOMAIN )
						);
						return;
					}

					show( __( 'Connection problem, retrying…', TEXT_DOMAIN ) );
					return wait( 3000 * failures ).then( next );
				}
			);
		}

		return next();
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-umin-action]' );

		if ( ! button || button.disabled ) {
			return;
		}

		var action = button.getAttribute( 'data-umin-action' );

		if ( 'scan' === action ) {
			event.preventDefault();
			setBusy( true );
			show( __( 'Starting the scan…', TEXT_DOMAIN ), 1 );

			apiFetch( { path: path( '/scans' ), method: 'POST' } ).then(
				function ( result ) {
					return runJob( result.job_id, result.scan_id );
				},
				function ( error ) {
					setBusy( false );
					show( ( error && error.message ) || __( 'The scan could not be started.', TEXT_DOMAIN ) );
				}
			);
		}

		if ( 'resume-scan' === action ) {
			event.preventDefault();
			apiFetch( { path: path( '/scans' ), method: 'POST' } ).then(
				function ( result ) {
					return runJob( result.job_id, result.scan_id );
				},
				function ( error ) {
					show( ( error && error.message ) || __( 'The scan could not be resumed.', TEXT_DOMAIN ) );
				}
			);
		}

		if ( 'delete-scan' === action ) {
			event.preventDefault();
			// eslint-disable-next-line no-alert -- A native confirmation is the clearest pattern for an irreversible delete.
			if ( ! window.confirm( __( 'Delete this scan\'s results? This removes only data stored by this plugin.', TEXT_DOMAIN ) ) ) {
				return;
			}
			button.disabled = true;
			apiFetch( { path: path( '/scans/' + button.getAttribute( 'data-umin-scan' ) ), method: 'DELETE' } ).then(
				function () {
					window.location.assign( config.resultsUrl );
				},
				function ( error ) {
					button.disabled = false;
					show( ( error && error.message ) || __( 'The scan could not be deleted.', TEXT_DOMAIN ) );
				}
			);
		}
	} );
}() );
