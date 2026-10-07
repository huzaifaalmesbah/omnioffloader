import { Notice } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { useCore } from '../../context/CoreContext';
import type { JobType } from '../../types';
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
		default:
			return __( 'A WP-CLI job is running.', 'omnioffloader' );
	}
}

interface CliJobNoticeProps {
	/** The job this screen starts; its own CLI run also shows progress. */
	page: JobType;
}

/**
 * Shown on a job screen while a WP-CLI job runs: only one job runs at a
 * time, so the screen's Start button waits for it.
 */
export function CliJobNotice( { page }: CliJobNoticeProps ) {
	const { cliJob } = useCore();

	if ( ! cliJob ) {
		return null;
	}

	const started =
		cliJob.started > 0
			? new Date( cliJob.started * 1000 ).toLocaleTimeString( [], {
					hour: '2-digit',
					minute: '2-digit',
				} )
			: '';

	return (
		<Notice
			status="warning"
			isDismissible={ false }
			className="smowp-cli-job-notice"
		>
			<p>
				<strong>{ cliJobTitle( cliJob.job ) }</strong>{ ' ' }
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
					) }{ ' ' }
				{ '' !== started &&
					sprintf(
						/* translators: %s: time the run started. */
						__( 'Started at %s.', 'omnioffloader' ),
						started
					) }
			</p>
			{ cliJob.job === page && cliJob.total > 0 && (
				<ProgressBar
					value={ cliJob.processed }
					max={ cliJob.total }
					showPercentage
				/>
			) }
			<p>
				{ createInterpolateElement(
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
