/** How long to wait for a section that renders after its data loads. */
const WAIT_MS = 10000;

/** How long the page must stay unchanged before the jump. */
const QUIET_MS = 250;

/** Space left between the covering bars and the section. */
const GAP = 16;

/**
 * Height covered at the top of the window: the sticky page header (which
 * sits below the admin bar), or on narrow screens just a fixed admin bar.
 */
function coveredTop(): number {
	const header = document.querySelector< HTMLElement >(
		'.omnioffloader-header'
	);

	if ( header && getComputedStyle( header ).position === 'sticky' ) {
		return header.getBoundingClientRect().bottom;
	}

	const adminBar = document.getElementById( 'wpadminbar' );

	return adminBar && getComputedStyle( adminBar ).position === 'fixed'
		? adminBar.offsetHeight
		: 0;
}

/**
 * Jump to a section and move focus to it, once the page has settled: the
 * section exists and nothing has changed for a moment. Cards render after
 * their data loads, either the section itself or cards above it that would
 * push it down after an early jump.
 *
 * @param id Element id of the section.
 * @return Stops waiting (for an effect cleanup).
 */
export function revealSection( id: string ): () => void {
	let quiet = 0;
	let timer = 0;

	const stop = () => {
		observer.disconnect();
		window.clearTimeout( quiet );
		window.clearTimeout( timer );
	};

	const settle = () => {
		const element = document.getElementById( id );

		// Not rendered yet: wait for the next change.
		if ( ! element ) {
			return;
		}

		stop();
		window.scrollTo(
			0,
			element.getBoundingClientRect().top +
				window.scrollY -
				coveredTop() -
				GAP
		);
		// Keyboard and screen-reader users land on the section too.
		element.focus( { preventScroll: true } );
	};

	const observer = new MutationObserver( () => {
		window.clearTimeout( quiet );
		quiet = window.setTimeout( settle, QUIET_MS );
	} );

	observer.observe( document.body, { childList: true, subtree: true } );
	quiet = window.setTimeout( settle, QUIET_MS );
	timer = window.setTimeout( stop, WAIT_MS );

	return stop;
}
