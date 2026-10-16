import { Notice } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useCore } from '../../context/CoreContext';
import type { CliJob, JobType } from '../../types';
import { ProgressBar } from './ProgressBar';

/**
 * "A WP-CLI … is running." for a job type.
 *
 * @param job Job type.
 */
export function cliJobTitle( job: JobType | '' ): string {
	switch ( job ) {
		case 'restore':
			return __( 'A WP-CLI Bring Back is running.', 'omnioffloader' );
		case 'retention':
			return __(
				'A WP-CLI retention update is running.',
				'omnioffloader'
			);
		case 'move':
			return __(
				'A WP-CLI move to the current folder is running.',
				'omnioffloader'
			);
		case 'offload':
			return __( 'A WP-CLI offload is running.', 'omnioffloader' );
		case 'verify':
			return __(
				'A WP-CLI verify --repair is running.',
				'omnioffloader'
			);
		default:
			return __( 'A WP-CLI job is running.', 'omnioffloader' );
	}
}

/**
 * A rough duration: "less than a minute", "25 min", "2 h 5 min".
 *
 * @param seconds Seconds.
 */
function duration( seconds: number ): string {
	const minutes = Math.round( seconds / 60 );

	if ( minutes < 1 ) {
		return __( 'less than a minute', 'omnioffloader' );
	}

	if ( minutes < 60 ) {
		/* translators: %d: minutes. */
		return sprintf( __( '%d min', 'omnioffloader' ), minutes );
	}

	return sprintf(
		/* translators: 1: hours, 2: minutes. */
		__( '%1$d h %2$d min', 'omnioffloader' ),
		Math.floor( minutes / 60 ),
		minutes % 60
	);
}

/**
 * A Unix time as the viewer's local clock time.
 *
 * @param unix Unix time in seconds.
 */
function clock( unix: number ): string {
	return new Date( unix * 1000 ).toLocaleTimeString( [], {
		hour: '2-digit',
		minute: '2-digit',
	} );
}

/**
 * The details `wp omnioffloader status` shows, as short facts.
 *
 * @param job The running job.
 */
function facts( job: CliJob ): string[] {
	const list: string[] = [];

	if ( job.started > 0 ) {
		list.push(
			/* translators: %s: time the run started. */
			sprintf( __( 'Started %s', 'omnioffloader' ), clock( job.started ) )
		);
	}

	if ( job.per_minute > 0 ) {
		list.push(
			sprintf(
				/* translators: %s: attachments per minute. */
				__( '%s per minute', 'omnioffloader' ),
				job.per_minute.toLocaleString( undefined, {
					maximumFractionDigits: job.per_minute < 10 ? 1 : 0,
				} )
			)
		);
	}

	// A run that is stopping won't finish the rest.
	if ( ! job.stopping && job.seconds_left > 0 && job.updated > 0 ) {
		list.push(
			sprintf(
				/* translators: 1: time left, 2: clock time it should finish. */
				__( 'about %1$s left (around %2$s)', 'omnioffloader' ),
				duration( job.seconds_left ),
				clock( job.updated + job.seconds_left )
			)
		);
	}

	if ( job.files_at_once > 0 ) {
		list.push(
			sprintf(
				/* translators: %d: files transferred at the same time. */
				_n(
					'%d file at once',
					'%d files at once',
					job.files_at_once,
					'omnioffloader'
				),
				job.files_at_once
			)
		);
	}

	if ( job.pid > 0 && job.background ) {
		list.push(
			sprintf(
				/* translators: %d: process ID. */
				__( 'in the background, process %d', 'omnioffloader' ),
				job.pid
			)
		);
	} else if ( job.pid > 0 ) {
		list.push(
			sprintf(
				/* translators: %d: process ID. */
				__( 'in a terminal, process %d', 'omnioffloader' ),
				job.pid
			)
		);
	}

	return list;
}

/**
 * Shown at the top of each job screen (Offload, Bring Back, Tools) while a
 * WP-CLI job runs: only one job runs at a time, so their Start buttons wait. The figures are the ones
 * `wp omnioffloader status` shows, from the same run record.
 */
export function CliJobNotice() {
	const { cliJob } = useCore();

	if ( ! cliJob ) {
		return null;
	}

	const details = facts( cliJob );

	return (
		<Notice
			status="warning"
			isDismissible={ false }
			className="omnioffloader-cli-job-notice"
		>
			<p>
				<strong>
					{ cliJob.stopping
						? __(
								'A WP-CLI job is stopping: it finishes the files in progress, then exits.',
								'omnioffloader'
							)
						: cliJobTitle( cliJob.job ) }
				</strong>{ ' ' }
				{ cliJob.total > 0 &&
					sprintf(
						/* translators: 1: attachments done, 2: attachments in the run, 3: failed attachments. */
						__(
							'%1$s of %2$s done, %3$s failed.',
							'omnioffloader'
						),
						cliJob.processed.toLocaleString(),
						cliJob.total.toLocaleString(),
						cliJob.failed.toLocaleString()
					) }
			</p>
			{ cliJob.total > 0 && (
				<ProgressBar
					value={ cliJob.processed }
					max={ cliJob.total }
					showPercentage
				/>
			) }
			{ details.length > 0 && (
				<p className="omnioffloader-cli-job-notice__facts">
					{ details.join( ' · ' ) }
				</p>
			) }
			{ cliJob.quiet && (
				<p className="omnioffloader-cli-job-notice__facts">
					{ __(
						'No progress for a few minutes: a large file may still be transferring.',
						'omnioffloader'
					) }
				</p>
			) }
			{ '' !== cliJob.log && (
				<p className="omnioffloader-cli-job-notice__facts">
					{ createInterpolateElement(
						/* translators: <log /> is the log file's path. */
						__( 'Log file: <log />', 'omnioffloader' ),
						{ log: <code>{ cliJob.log }</code> }
					) }
				</p>
			) }
			<p>
				{ cliJob.stopping
					? __(
							'This screen can start a job once it has stopped.',
							'omnioffloader'
						)
					: createInterpolateElement(
							__(
								'Only one job runs at a time, so this screen can start a job once it finishes. Follow it with <status />, or stop it with <stop />.',
								'omnioffloader'
							),
							{
								status: <code>wp omnioffloader status</code>,
								stop: <code>wp omnioffloader stop</code>,
							}
						) }
			</p>
		</Notice>
	);
}
