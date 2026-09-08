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

	const stopPolling = useCallback( () => {
		if ( timer.current !== null ) {
			window.clearTimeout( timer.current );
			timer.current = null;
		}
		delay.current = POLL_MIN;
	}, [] );

	const schedule = useCallback( () => {
		timer.current = window.setTimeout(
			() => tick.current(),
			delay.current
		);
	}, [] );

	const refreshPreflight = useCallback( () => {
		fetchRestorePreflight()
			.then( setPreflight )
			.catch( () => {
				// Preflight is informational; the page shows a fallback.
			} );
	}, [] );

	const poll = useCallback( () => {
		if ( inFlight.current ) {
			schedule();
			return;
		}

		inFlight.current = true;

		fetchRestoreProgress()
			.then( ( fresh ) => {
				setState( fresh );

				if ( fresh.status !== 'running' ) {
					stopPolling();
					refreshStats();
					refreshPreflight();
					return;
				}

				// Ease off as the run settles in: a multi-hour job does not
				// need second-by-second updates.
				delay.current = Math.min( POLL_MAX, delay.current + POLL_STEP );
				schedule();
			} )
			.catch( () => {
				// Transient polling failures are ignored; the next tick retries.
				schedule();
			} )
			.finally( () => {
				inFlight.current = false;
			} );
	}, [ schedule, stopPolling, refreshStats, refreshPreflight ] );

	useEffect( () => {
		tick.current = poll;
	}, [ poll ] );

	const startPolling = useCallback( () => {
		stopPolling();
		schedule();
	}, [ schedule, stopPolling ] );

	// Initial load; resume polling when a run is already in flight.
	useEffect( () => {
		refreshPreflight();

		fetchRestoreProgress()
			.then( ( fresh ) => {
				setState( fresh );
				if ( fresh.status === 'running' ) {
					setSessionRun( true );
					startPolling();
				}
			} )
			.catch( () => {
				// Engine may be unconfigured; page shows the empty state.
			} );

		return stopPolling;
	}, [ refreshPreflight, startPolling, stopPolling ] );

	const start = useCallback(
		async ( deleteCloud: boolean ) => {
			setBusy( true );
			setDismissed( false );
			// Reset state optimistically so stale 100% from a previous run never flashes.
			setState( {
				status: 'running',
				total: 0,
				processed: 0,
				errors: 0,
				delete_cloud: deleteCloud,
				updated_at: Math.floor( Date.now() / 1000 ),
			} );
			setSessionRun( true );

			try {
				const response = await startBulkRestore( deleteCloud );
				setState( response.state );

				if ( response.queued > 0 ) {
					startPolling();
				}
			} catch ( error: unknown ) {
				addNotice( 'error', errorMessage( error ) );
				setSessionRun( false );
			} finally {
				setBusy( false );
			}
		},
		[ addNotice, startPolling ]
	);

	const cancel = useCallback( async () => {
		setBusy( true );

		try {
			const fresh = await cancelBulkRestore();
			setState( fresh );
			stopPolling();
			refreshStats();
			refreshPreflight();
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

	return { preflight, state, busy, start, cancel, showProgress, dismiss };
}
