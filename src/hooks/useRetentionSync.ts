import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import {
	cancelBulkRetention,
	fetchRetentionPreflight,
	fetchRetentionProgress,
	startBulkRetention,
} from '../api/retention';
import { useCore } from '../context/CoreContext';
import { errorMessage } from '../utils/errorHelper';
import type { RetentionBulkState, RetentionPreflight } from '../types';

const POLL_MIN = 2000;
const POLL_MAX = 6000;
const POLL_STEP = 250;

/**
 * Polling state machine for the bulk retention queue (apply the saved
 * retention policy to media offloaded before it was chosen), plus the
 * preflight counts.
 */
export function useRetentionSync() {
	const { addNotice, refreshStats } = useCore();
	const [ preflight, setPreflight ] = useState< RetentionPreflight | null >(
		null
	);
	const [ state, setState ] = useState< RetentionBulkState | null >( null );
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
	const lastRefresh = useRef( 0 );

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

	const refreshPreflight = useCallback( ( force = true ) => {
		fetchRetentionPreflight( force )
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

		fetchRetentionProgress()
			.then( ( fresh ) => {
				setState( fresh );

				if ( fresh.status !== 'running' ) {
					stopPolling();
					refreshStats( true );
					refreshPreflight( true );
					return;
				}

				const now = Date.now();
				if ( now - lastRefresh.current >= 6000 ) {
					lastRefresh.current = now;
					refreshPreflight( true );
				}

				// Ease off as the run settles in.
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
		timer.current = window.setTimeout( () => tick.current(), 100 );
	}, [ stopPolling ] );

	// Initial load; resume polling when a run is already in flight.
	useEffect( () => {
		refreshPreflight( true );

		fetchRetentionProgress()
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
		async ( limit?: number ) => {
			setBusy( true );
			setDismissed( false );
			// Clean slate optimistically so the previous run's log and counts
			// do not flash before the first poll.
			setState( ( current ) => ( {
				status: 'running',
				total: limit && limit > 0 ? limit : 0,
				processed: 0,
				errors: 0,
				updated_at: Math.floor( Date.now() / 1000 ),
				current: 0,
				current_item: null,
				recent: [],
				policy: current?.policy ?? preflight?.policy ?? 0,
				freed_bytes: 0,
				repaired: 0,
			} ) );
			setSessionRun( true );

			try {
				const response = await startBulkRetention( limit );
				setState( response.state );

				if ( response.queued > 0 ) {
					lastRefresh.current = Date.now();
					startPolling();
				} else {
					setSessionRun( false );
					refreshPreflight( true );
				}
			} catch ( error: unknown ) {
				addNotice( 'error', errorMessage( error ) );
				setSessionRun( false );
				fetchRetentionProgress()
					.then( ( fresh ) => setState( fresh ) )
					.catch( () => setState( null ) );
			} finally {
				setBusy( false );
			}
		},
		[ addNotice, preflight, refreshPreflight, startPolling ]
	);

	const cancel = useCallback( async () => {
		setBusy( true );

		try {
			const fresh = await cancelBulkRetention();
			setState( fresh );
			stopPolling();
			refreshPreflight( true );
		} catch ( error: unknown ) {
			addNotice( 'error', errorMessage( error ) );
		} finally {
			setBusy( false );
		}
	}, [ addNotice, stopPolling, refreshPreflight ] );

	const dismiss = useCallback( () => setDismissed( true ), [] );

	const showProgress =
		state !== null &&
		( state.status === 'running' ||
			( state.status !== 'idle' && sessionRun && ! dismissed ) );

	return {
		preflight,
		refreshPreflight,
		state,
		busy,
		start,
		cancel,
		showProgress,
		dismiss,
	};
}
