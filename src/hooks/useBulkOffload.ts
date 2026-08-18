import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import {
	cancelBulkOffload,
	fetchBulkProgress,
	startBulkOffload,
} from '../api/offload';
import { useCore } from '../context/CoreContext';
import { errorMessage } from '../utils/errorHelper';
import type { BulkState } from '../types';

const POLL_INTERVAL = 2000;

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

	const stopPolling = useCallback( () => {
		if ( timer.current !== null ) {
			window.clearInterval( timer.current );
			timer.current = null;
		}
	}, [] );

	const poll = useCallback( () => {
		fetchBulkProgress()
			.then( ( fresh ) => {
				setState( fresh );

				if ( fresh.status !== 'running' ) {
					stopPolling();
					refreshStats();
				}
			} )
			.catch( () => {
				// Transient polling failures are ignored; next tick retries.
			} );
	}, [ stopPolling, refreshStats ] );

	const startPolling = useCallback( () => {
		stopPolling();
		timer.current = window.setInterval( poll, POLL_INTERVAL );
	}, [ poll, stopPolling ] );

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
		setSessionRun( true );

		try {
			const response = await startBulkOffload();
			setState( response.state );

			if ( response.queued > 0 ) {
				startPolling();
			}
		} catch ( error: unknown ) {
			addNotice( 'error', errorMessage( error ) );
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
