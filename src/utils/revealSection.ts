/** How long to wait for a section that renders after its data loads. */
const WAIT_MS = 10000;

/** Space left between the covering bars and the section. */
const GAP = 16;

/**
 * Height covered at the top of the window: the sticky page header (which
 * sits below the admin bar), or on narrow screens just a fixed admin bar.
 */
function coveredTop(): number {
	const header = document.querySelector< HTMLElement >( '.smowp-header' );

	if ( header && getComputedStyle( header ).position === 'sticky' ) {
		return header.getBoundingClientRect().bottom;
	}

	const adminBar = document.getElementById( 'wpadminbar' );

	return adminBar && getComputedStyle( adminBar ).position === 'fixed'
		? adminBar.offsetHeight
		: 0;
}

/**
 * Jump to a section and move focus to it. Cards often render only once their
 * data has loaded, so this waits for the element to appear.
 *
 * @param id Element id of the section.
 * @return Stops waiting (for an effect cleanup).
 */
export function revealSection( id: string ): () => void {
	const reveal = ( element: HTMLElement ) => {
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

	const element = document.getElementById( id );

	if ( element ) {
		reveal( element );
		return () => {};
	}

	let timer = 0;
	const observer = new MutationObserver( () => {
		const found = document.getElementById( id );

		if ( found ) {
			stop();
			reveal( found );
		}
	} );
	const stop = () => {
		observer.disconnect();
		window.clearTimeout( timer );
	};

	observer.observe( document.body, { childList: true, subtree: true } );
	timer = window.setTimeout( stop, WAIT_MS );

	return stop;
}
