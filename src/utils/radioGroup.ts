import type { KeyboardEvent } from 'react';

/**
 * Keyboard support for the custom card radio groups (role="radiogroup" /
 * role="radio"), following the WAI-ARIA radio group pattern: Space/Enter
 * select, arrow keys move and select, Home/End jump to the ends.
 *
 * @param event  Key event from one radio card.
 * @param values Option values in display order.
 * @param own    The value of the card that received the event.
 * @param select Selection callback.
 */
export function radioKeyDown< T >(
	event: KeyboardEvent< HTMLElement >,
	values: readonly T[],
	own: T,
	select: ( value: T ) => void
): void {
	if ( event.key === ' ' || event.key === 'Enter' ) {
		event.preventDefault();
		select( own );
		return;
	}

	const index = values.indexOf( own );
	let next: number;

	switch ( event.key ) {
		case 'ArrowRight':
		case 'ArrowDown':
			next = ( index + 1 ) % values.length;
			break;
		case 'ArrowLeft':
		case 'ArrowUp':
			next = ( index - 1 + values.length ) % values.length;
			break;
		case 'Home':
			next = 0;
			break;
		case 'End':
			next = values.length - 1;
			break;
		default:
			return;
	}

	event.preventDefault();
	select( values[ next ] );

	const radios = event.currentTarget
		.closest( '[role="radiogroup"]' )
		?.querySelectorAll< HTMLElement >( '[role="radio"]' );
	radios?.[ next ]?.focus();
}

/**
 * Roving tabindex: only the selected card (or the first, when none is) is
 * in the tab order; arrow keys move within the group.
 *
 * @param values   Option values in display order.
 * @param own      The card's value.
 * @param current  The selected value.
 * @param disabled Whether the group is disabled.
 */
export function radioTabIndex< T >(
	values: readonly T[],
	own: T,
	current: T,
	disabled = false
): 0 | -1 {
	if ( disabled ) {
		return -1;
	}

	if ( values.includes( current ) ) {
		return own === current ? 0 : -1;
	}

	return own === values[ 0 ] ? 0 : -1;
}
