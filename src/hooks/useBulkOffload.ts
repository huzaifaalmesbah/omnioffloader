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
					refreshStats( true );
					return;
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
				schedule();
			} )
			.finally( () => {
				inFlight.current = false;
			} );
	}, [ schedule, stopPolling, refreshStats ] );

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
		// Poll immediately on start (100ms) so the UI responds without a 2s delay.
		timer.current = window.setTimeout( () => tick.current(), 100 );
	}, [ stopPolling ] );

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
		// Clean slate optimistically so previous run logs and counts do not flash.
		setState( {
			status: 'running',
			total: 0,
			processed: 0,
			errors: 0,
			updated_at: Math.floor( Date.now() / 1000 ),
			current: 0,
			current_item: null,
			recent: [],
		} );
		setSessionRun( true );

		try {
			const response = await startBulkOffload();
			setState( response.state );

			if ( response.queued > 0 ) {
				startPolling();
			} else {
				setSessionRun( false );
				refreshStats( true );
			}
		} catch ( error: unknown ) {
			addNotice( 'error', errorMessage( error ) );
			setSessionRun( false );
			// Re-sync with actual server state so optimistic state never stays frozen.
			fetchBulkProgress()
				.then( ( fresh ) => setState( fresh ) )
				.catch( () => setState( null ) );
		} finally {
			setBusy( false );
		}
	}, [ addNotice, refreshStats, startPolling ] );

	const cancel = useCallback( async () => {
		setBusy( true );

		try {
			const fresh = await cancelBulkOffload();
			setState( fresh );
			stopPolling();
			refreshStats( true );
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
