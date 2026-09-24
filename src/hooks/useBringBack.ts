import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import {
	cancelBulkRestore,
	fetchRestorePreflight,
	fetchRestoreProgress,
	startBulkRestore,
} from '../api/bringBack';
import { useCore } from '../context/CoreContext';
import { errorMessage } from '../utils/errorHelper';
import type { RestoreBulkState, RestorePreflight } from '../types';

const POLL_MIN = 2000;
const POLL_MAX = 6000;
const POLL_STEP = 250;

/**
 * Polling state machine for the bulk restore (Bring Back) queue,
 * plus the disk-space preflight.
 */
export function useBringBack() {
	const { addNotice, refreshStats } = useCore();
	const [ preflight, setPreflight ] = useState< RestorePreflight | null >(
		null
	);
	const [ state, setState ] = useState< RestoreBulkState | null >( null );
	const [ busy, setBusy ] = useState( false );
	// A finished run stays in the option forever; only surface the result of a
	// run this session saw, and let the user dismiss it.
	const [ sessionRun, setSessionRun ] = useState( false );
	const [ dismissed, setDismissed ] = useState( false );
	const timer = useRef< number | null >( null );
	// Each poll can make the server process a batch, so requests are chained
	// rather than fired on an interval — an interval would stack them up
	// whenever a poll outlasts its own period.
	const delay = useRef( POLL_MIN );
	const inFlight = useRef( false );
	const tick = useRef< () => void >( () => {} );
	// Bumped whenever polling stops, so a response still in flight from an
	// earlier chain (e.g. one that lands after Cancel) is ignored instead of
	// restarting the poll.
	const generation = useRef( 0 );
	const mounted = useRef( true );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );
	const lastStatsRefresh = useRef( 0 );

	const stopPolling = useCallback( () => {
		if ( timer.current !== null ) {
			window.clearTimeout( timer.current );
			timer.current = null;
		}
		delay.current = POLL_MIN;
		generation.current += 1;
	}, [] );

	const schedule = useCallback( () => {
		if ( ! mounted.current ) {
			return;
		}
		// Never run two timer chains side by side.
		if ( timer.current !== null ) {
			window.clearTimeout( timer.current );
		}
		timer.current = window.setTimeout(
			() => tick.current(),
			delay.current
		);
	}, [] );

	// Default to the server's short-lived cache: a forced preflight re-reads
	// the metadata of every offloaded attachment.
	const refreshPreflight = useCallback( ( force = false ) => {
		fetchRestorePreflight( force )
			.then( setPreflight )
			.catch( () => {
				// Preflight is informational; the page shows a fallback.
			} );
	}, [] );

	const poll = useCallback( () => {
		// The request in flight schedules the next tick when it settles.
		if ( inFlight.current ) {
			return;
		}

		inFlight.current = true;
		const gen = generation.current;

		fetchRestoreProgress()
			.then( ( fresh ) => {
				if ( gen !== generation.current || ! mounted.current ) {
					return;
				}

				setState( fresh );

				if ( fresh.status !== 'running' ) {
					stopPolling();
					refreshStats( true );
					refreshPreflight( true );
					return;
				}

				// Periodically refresh stats during active run so summary chips stay synchronized.
				const now = Date.now();
				if ( now - lastStatsRefresh.current >= 6000 ) {
					lastStatsRefresh.current = now;
					// Stats are one indexed count; the preflight is refreshed
					// once the run ends, never mid-run.
					refreshStats( true );
				}

				// Ease off as the run settles in: a multi-hour job does not
				// need second-by-second updates.
				delay.current = Math.min( POLL_MAX, delay.current + POLL_STEP );
				if ( document.hidden ) {
					delay.current = Math.max( delay.current, 12000 );
				}
				schedule();
			} )
			.catch( () => {
				// Transient polling failures are ignored; the next tick retries.
				if ( gen === generation.current ) {
					schedule();
				}
			} )
			.finally( () => {
				inFlight.current = false;
			} );
	}, [ schedule, stopPolling, refreshStats, refreshPreflight ] );

	useEffect( () => {
		tick.current = poll;
	}, [ poll ] );

	useEffect( () => {
		const handleVisibility = () => {
			if ( ! document.hidden && state?.status === 'running' ) {
				delay.current = POLL_MIN;
				if ( timer.current !== null ) {
					window.clearTimeout( timer.current );
				}
				timer.current = window.setTimeout( () => tick.current(), 100 );
			}
		};

		document.addEventListener( 'visibilitychange', handleVisibility );
		return () => {
			document.removeEventListener(
				'visibilitychange',
				handleVisibility
			);
		};
	}, [ state?.status ] );

	const startPolling = useCallback( () => {
		stopPolling();
		if ( ! mounted.current ) {
			return;
		}
		// Poll immediately on start (100ms) so the UI responds without a 2s delay.
		timer.current = window.setTimeout( () => tick.current(), 100 );
	}, [ stopPolling ] );

	// Initial load; resume polling when a run is already in flight.
	useEffect( () => {
		refreshPreflight();

		fetchRestoreProgress()
			.then( ( fresh ) => {
				if ( ! mounted.current ) {
					return;
				}
				setState( fresh );
				if ( fresh.status === 'running' ) {
					setSessionRun( true );
					refreshStats( true );
					startPolling();
				}
			} )
			.catch( () => {
				// Engine may be unconfigured; page shows the empty state.
			} );

		return stopPolling;
	}, [ refreshPreflight, refreshStats, startPolling, stopPolling ] );

	const start = useCallback(
		async ( deleteCloud: boolean, limit?: number ) => {
			setBusy( true );
			setDismissed( false );
			// Clean slate optimistically so previous run logs and counts do not flash.
			setState( {
				status: 'running',
				total: limit && limit > 0 ? limit : 0,
				processed: 0,
				errors: 0,
				updated_at: Math.floor( Date.now() / 1000 ),
				current: 0,
				current_item: null,
				recent: [],
				delete_cloud: deleteCloud,
			} );
			setSessionRun( true );

			try {
				const response = await startBulkRestore( deleteCloud, limit );
				setState( response.state );

				if ( response.queued > 0 ) {
					lastStatsRefresh.current = Date.now();
					refreshStats( true );
					startPolling();
				} else {
					setSessionRun( false );
					refreshStats( true );
					refreshPreflight( true );
				}
			} catch ( error: unknown ) {
				addNotice( 'error', errorMessage( error ) );
				setSessionRun( false );
				// Re-sync with actual server state so optimistic state never stays frozen.
				fetchRestoreProgress()
					.then( ( fresh ) => setState( fresh ) )
					.catch( () => setState( null ) );
			} finally {
				setBusy( false );
			}
		},
		[ addNotice, refreshPreflight, refreshStats, startPolling ]
	);

	const cancel = useCallback( async () => {
		setBusy( true );

		try {
			const fresh = await cancelBulkRestore();
			setState( fresh );
			stopPolling();
			refreshStats( true );
			refreshPreflight( true );
		} catch ( error: unknown ) {
			addNotice( 'error', errorMessage( error ) );
		} finally {
			setBusy( false );
		}
	}, [ addNotice, stopPolling, refreshStats, refreshPreflight ] );

	const dismiss = useCallback( () => setDismissed( true ), [] );

	// Live runs always show; a finished/cancelled run only until dismissed, and
	// never on a fresh page load days after the fact.
	const showProgress =
		state !== null &&
		( state.status === 'running' ||
			( state.status !== 'idle' && sessionRun && ! dismissed ) );

	return {
		preflight,
		state,
		busy,
		start,
		cancel,
		showProgress,
		dismiss,
		refreshPreflight,
	};
}
