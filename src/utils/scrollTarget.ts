/**
 * A section to bring into view after a page switch. The target page's card
 * often renders only once its data has loaded, so the link records the
 * request and the card takes it when it appears.
 */
let pending: { id: string; until: number } | null = null;

/** How long a request waits for its section to appear. */
const WAIT_MS = 10000;

/**
 * Ask for a section to be brought into view once it renders.
 *
 * @param id Element id of the section.
 */
export function requestScroll( id: string ): void {
	pending = { id, until: Date.now() + WAIT_MS };
}

/**
 * Scroll to a section and move focus to it, if it was requested and the
 * request has not expired.
 *
 * @param id Element id of the section.
 */
export function takeScroll( id: string ): void {
	if ( ! pending || pending.id !== id ) {
		return;
	}

	const expired = Date.now() > pending.until;
	pending = null;

	const element = document.getElementById( id );

	if ( expired || ! element ) {
		return;
	}

	element.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	// Keyboard and screen-reader users land on the section, not the page title.
	element.focus( { preventScroll: true } );
}
