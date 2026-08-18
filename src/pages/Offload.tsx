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

				{ stats?.configured && (
					<>
						<div className="smowp-bulk-action">
							<p className="smowp-bulk-action__info">
								{ sprintf(
									/* translators: %d: number of local attachments. */
									__(
										'%d attachment(s) are local-only and can be offloaded.',
										'smart-media-offloader'
									),
									localCount
								) }
							</p>
							{ ! running && (
								<Button
									variant="primary"
									className="smowp-btn-primary"
									disabled={ busy || localCount === 0 }
									isBusy={ busy }
									onClick={ () => void start() }
								>
									{ __(
										'Start Bulk Offload',
										'smart-media-offloader'
									) }
								</Button>
							) }
						</div>

						{ showProgress && state && (
							<div
								className={
									'smowp-progress-card' +
									( running ? ' is-running' : '' ) +
									( state.status === 'done'
										? ' is-done'
										: '' ) +
									( state.status === 'cancelled'
										? ' is-cancelled'
										: '' )
								}
							>
								<div className="smowp-progress-card__header">
									<div className="smowp-progress-card__status">
										{ running && (
											<span className="smowp-badge smowp-badge--running">
												<span className="smowp-badge-pulse" />
												{ __(
													'Offloading…',
													'smart-media-offloader'
												) }
											</span>
										) }
										{ state.status === 'done' && (
											<span className="smowp-badge smowp-badge--ok">
												{ __(
													'Finished',
													'smart-media-offloader'
												) }
											</span>
										) }
										{ state.status === 'cancelled' && (
											<span className="smowp-badge smowp-badge--fail">
												{ __(
													'Cancelled',
													'smart-media-offloader'
												) }
											</span>
										) }
										<span className="smowp-progress-card__count">
											{ sprintf(
												/* translators: 1: processed count, 2: total count. */
												__(
													'%1$d of %2$d processed',
													'smart-media-offloader'
												),
												state.processed,
												state.total
											) }
										</span>
									</div>

									<span className="smowp-progress-card__pct">
										{ state.total > 0
											? Math.min(
													100,
													Math.round(
														( state.processed /
															state.total ) *
															100
													)
											  )
											: 0 }
										%
									</span>
								</div>

								<ProgressBar
									value={ state.processed }
									max={ state.total }
									showPercentage={ false }
								/>

								<div className="smowp-progress-card__footer">
									<span className="smowp-progress-card__errors">
										{ state.errors > 0
											? sprintf(
													/* translators: %d: error count. */
													__(
														'%d error(s) encountered',
														'smart-media-offloader'
													),
													state.errors
											  )
											: __(
													'No errors',
													'smart-media-offloader'
											  ) }
									</span>

									<div className="smowp-progress-card__actions">
										{ running ? (
											<Button
												variant="secondary"
												isDestructive
												className="smowp-btn-destructive"
												disabled={ busy }
												onClick={ () => void cancel() }
											>
												{ __(
													'Cancel',
													'smart-media-offloader'
												) }
											</Button>
										) : (
											<Button
												variant="secondary"
												className="smowp-btn-dismiss"
												onClick={ dismiss }
											>
												{ __(
													'Dismiss',
													'smart-media-offloader'
												) }
											</Button>
										) }
									</div>
								</div>
							</div>
						) }
					</>
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
