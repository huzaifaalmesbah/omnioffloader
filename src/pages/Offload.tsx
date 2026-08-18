import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { Card } from '../components/ui/Card';
import { ProgressBar } from '../components/ui/ProgressBar';
import { useCore } from '../context/CoreContext';
import { useBulkOffload } from '../hooks/useBulkOffload';

export function OffloadPage() {
	const { stats } = useCore();
	const { state, busy, start, cancel, showProgress, dismiss } =
		useBulkOffload();

	const running = state?.status === 'running';
	const localCount = stats?.local ?? 0;

	return (
		<div className="smowp-page">
			<Card
				title={ __( 'Bulk Offload', 'smart-media-offloader' ) }
				description={ __(
					'Move your existing media library to the cloud. Processing runs in the background — you can leave this page and come back.',
					'smart-media-offloader'
				) }
			>
				{ ! stats?.configured && (
					<p className="smowp-muted">
						{ __(
							'Configure a cloud provider in Settings first.',
							'smart-media-offloader'
						) }
					</p>
				) }

				{ stats?.configured && ! running && (
					<>
						<p>
							{ sprintf(
								/* translators: %d: number of local attachments. */
								__(
									'%d attachment(s) are local-only and can be offloaded.',
									'smart-media-offloader'
								),
								localCount
							) }
						</p>
						<Button
							variant="primary"
							disabled={ busy || localCount === 0 }
							isBusy={ busy }
							onClick={ () => void start() }
						>
							{ __(
								'Start Bulk Offload',
								'smart-media-offloader'
							) }
						</Button>
					</>
				) }

				{ showProgress && state && (
					<div className="smowp-bulk-progress">
						<ProgressBar
							value={ state.processed }
							max={ state.total }
						/>
						<p className="smowp-muted">
							{ sprintf(
								/* translators: 1: processed count, 2: total count, 3: error count. */
								__(
									'%1$d of %2$d processed — %3$d error(s)',
									'smart-media-offloader'
								),
								state.processed,
								state.total,
								state.errors
							) }
							{ state.status === 'done' &&
								' · ' +
									__( 'Finished.', 'smart-media-offloader' ) }
							{ state.status === 'cancelled' &&
								' · ' +
									__(
										'Cancelled.',
										'smart-media-offloader'
									) }
						</p>

						{ running ? (
							<Button
								variant="secondary"
								isDestructive
								disabled={ busy }
								onClick={ () => void cancel() }
							>
								{ __( 'Cancel', 'smart-media-offloader' ) }
							</Button>
						) : (
							<Button variant="tertiary" onClick={ dismiss }>
								{ __( 'Dismiss', 'smart-media-offloader' ) }
							</Button>
						) }
					</div>
				) }
			</Card>

			<Card
				title={ __( 'Single attachments', 'smart-media-offloader' ) }
				description={ __(
					'You can also offload one file at a time from the Media Library list — use the "Offload now" row action. Offload state is shown in each attachment\'s details, and the library can be filtered by Offloaded / Local only.',
					'smart-media-offloader'
				) }
			/>
		</div>
	);
}
