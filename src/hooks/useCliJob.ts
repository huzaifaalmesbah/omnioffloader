import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import { fetchCliJob } from '../api/core';
import type { CliJob } from '../types';

const POLL_MIN = 5000;
const POLL_MAX = 15000;
const POLL_STEP = 2500;

/**
 * The WP-CLI job running now, kept light on purpose: checked when the admin
 * app opens, when its tab is shown again, and on request (a Start refused
 * because a job runs). Polled only while a WP-CLI job runs (5 s, backing off
 * to 15 s) and never in a hidden tab.
 *
 * @param onEnded Called when a running job is seen to have ended.
 */
export function useCliJob( onEnded: () => void ) {
	const [ job, setJob ] = useState< CliJob | null >( null );
	const timer = useRef< number | undefined >( undefined );
	const delay = useRef( POLL_MIN );
	const inFlight = useRef( false );
	const wasRunning = useRef( false );
	const last = useRef( '' );
	const active = useRef( true );
	const endedRef = useRef( onEnded );
	endedRef.current = onEnded;

	const check = useCallback( () => {
		if ( inFlight.current ) {
			return;
		}

		window.clearTimeout( timer.current );
		inFlight.current = true;

		fetchCliJob()
			.then( ( fresh ) => {
				if ( ! active.current ) {
					return;
				}

				const running = fresh.running;
				const key = running ? JSON.stringify( fresh ) : '';

				// The whole admin app reads this: re-render only on change.
				if ( key !== last.current ) {
					last.current = key;
					setJob( running ? fresh : null );
				}

				if ( wasRunning.current && ! running ) {
					endedRef.current();
				}

				wasRunning.current = running;

				if ( ! running ) {
					delay.current = POLL_MIN;
					return;
				}

				if ( ! document.hidden ) {
					timer.current = window.setTimeout( check, delay.current );
					delay.current = Math.min(
						POLL_MAX,
						delay.current + POLL_STEP
					);
				}
			} )
			.catch( () => {
				// Informational only; the start routes refuse a second job anyway.
			} )
			.finally( () => {
				inFlight.current = false;
			} );
	}, [] );

	useEffect( () => {
		active.current = true;
		check();

		const onVisible = () => {
			if ( ! document.hidden ) {
				delay.current = POLL_MIN;
				check();
			}
		};

		document.addEventListener( 'visibilitychange', onVisible );

		return () => {
			active.current = false;
			document.removeEventListener( 'visibilitychange', onVisible );
			window.clearTimeout( timer.current );
		};
	}, [ check ] );

	return { cliJob: job, recheckCliJob: check };
}
