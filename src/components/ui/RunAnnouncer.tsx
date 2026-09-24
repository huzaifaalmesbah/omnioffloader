import { __, sprintf } from '@wordpress/i18n';

import type { BulkStatus } from '../../types';

interface RunAnnouncerProps {
	status: BulkStatus;
	processed: number;
	total: number;
	errors: number;
	message?: string;
}

/**
 * Screen-reader announcement of a bulk run's state changes.
 *
 * The text only changes when the run starts, finishes, stops or is
 * cancelled — never per item — so the polite live region is not chatty.
 *
 * @param props           Props.
 * @param props.status    Run status.
 * @param props.processed Items processed.
 * @param props.total     Items queued.
 * @param props.errors    Failed items.
 * @param props.message   Why the run stopped, when it failed.
 */
export function RunAnnouncer( {
	status,
	processed,
	total,
	errors,
	message,
}: RunAnnouncerProps ) {
	let text = '';

	if ( status === 'running' ) {
		text = __( 'Processing started.', 'omnioffloader' );
	} else if ( status === 'done' ) {
		text = sprintf(
			/* translators: 1: processed count, 2: total count, 3: error count. */
			__(
				'Finished: %1$d of %2$d processed, %3$d errors.',
				'omnioffloader'
			),
			processed,
			total,
			errors
		);
	} else if ( status === 'cancelled' ) {
		text = __( 'Cancelled.', 'omnioffloader' );
	} else if ( status === 'failed' ) {
		text = message || __( 'Stopped because of errors.', 'omnioffloader' );
	}

	return (
		<div className="screen-reader-text" role="status" aria-live="polite">
			{ text }
		</div>
	);
}
