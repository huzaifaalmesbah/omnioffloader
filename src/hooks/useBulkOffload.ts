import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import {
	cancelBulkOffload,
	fetchBulkProgress,
	startBulkOffload,
} from '../api/offload';
import { useCore } from '../context/CoreContext';
import { errorMessage } from '../utils/errorHelper';
import type { BulkState } from '../types';

const POLL_MIN = 2000;
const POLL_MAX = 6000;
const POLL_STEP = 250;

/**
 * Polling state machine for the bulk offload queue.
 */
export function useBulkOffload() {
	const { addNotice, refreshStats } = useCore();
	const [ state, setState ] = useState< BulkState | null >( null );
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

	const poll = useCallback( () => {
		if ( inFlight.current ) {
			schedule();
			return;
		}

		inFlight.current = true;

		fetchBulkProgress()
			.then( ( fresh ) => {
				setState( fresh );

				if ( fresh.status !== 'running' ) {
					stopPolling();
					refreshStats();
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
	}, [ schedule, stopPolling, refreshStats ] );

	useEffect( () => {
		tick.current = poll;
	}, [ poll ] );

	const startPolling = useCallback( () => {
		stopPolling();
		schedule();
	}, [ schedule, stopPolling ] );

	// Initial load; resume polling when a run is already in flight.
	useEffect( () => {
		fetchBulkProgress()
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
	}, [ startPolling, stopPolling ] );

	const start = useCallback( async () => {
		setBusy( true );
		setDismissed( false );
		// Reset state optimistically so stale 100% from a previous run never flashes.
		setState( {
			status: 'running',
			total: 0,
			processed: 0,
			errors: 0,
			updated_at: Math.floor( Date.now() / 1000 ),
		} );
		setSessionRun( true );

		try {
			const response = await startBulkOffload();
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
	}, [ addNotice, startPolling ] );

	const cancel = useCallback( async () => {
		setBusy( true );

		try {
			const fresh = await cancelBulkOffload();
			setState( fresh );
			stopPolling();
			refreshStats();
		} catch ( error: unknown ) {
			addNotice( 'error', errorMessage( error ) );
		} finally {
			setBusy( false );
		}
	}, [ addNotice, stopPolling, refreshStats ] );

	const dismiss = useCallback( () => setDismissed( true ), [] );

	// Live runs always show; a finished/cancelled run only until dismissed, and
	// never on a fresh page load days after the fact.
	const showProgress =
		state !== null &&
		( state.status === 'running' ||
			( state.status !== 'idle' && sessionRun && ! dismissed ) );

	return { state, busy, start, cancel, showProgress, dismiss };
}
