import { Button, Tooltip } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { ActivityLog } from '../components/ui/ActivityLog';
import { Card } from '../components/ui/Card';
import { ProgressBar } from '../components/ui/ProgressBar';
import { useCore } from '../context/CoreContext';
import { useBulkOffload } from '../hooks/useBulkOffload';

export function OffloadPage() {
	const { stats } = useCore();
	const { state, busy, start, cancel, showProgress, dismiss } =
		useBulkOffload();

	const running = state?.status === 'running';
	const totalCount = stats?.total ?? 0;
	const offloadedCount = stats?.offloaded ?? 0;
	const localCount = stats?.local ?? 0;
	const isAllOffloaded =
		stats?.configured && localCount === 0 && totalCount > 0;

	const isDisabled = busy || localCount === 0;
	let disabledReason: string = '';
	if ( localCount === 0 ) {
		if ( totalCount === 0 ) {
			disabledReason = __(
				'Media library is empty. Upload media files first.',
				'smart-media-offloader'
			);
		} else {
			disabledReason = __(
				'All media attachments are already offloaded to the cloud.',
				'smart-media-offloader'
			);
		}
	}

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
						<div className="smowp-media-summary">
							<div className="smowp-media-chip">
								<span className="smowp-media-chip__label">
									{ __(
										'Total Library Media',
										'smart-media-offloader'
									) }
								</span>
								<strong className="smowp-media-chip__value">
									{ totalCount }
								</strong>
							</div>
							<div className="smowp-media-chip smowp-media-chip--offloaded">
								<span className="smowp-media-chip__label">
									{ __(
										'Already Offloaded',
										'smart-media-offloader'
									) }
								</span>
								<strong className="smowp-media-chip__value">
									{ offloadedCount }
								</strong>
							</div>
							<div className="smowp-media-chip smowp-media-chip--local">
								<span className="smowp-media-chip__label">
									{ __(
										'Local (Ready to Offload)',
										'smart-media-offloader'
									) }
								</span>
								<strong className="smowp-media-chip__value">
									{ localCount }
								</strong>
							</div>
						</div>

						{ isAllOffloaded && (
							<div className="smowp-notice-banner smowp-notice-banner--success">
								<span className="smowp-notice-banner__icon">
									✓
								</span>
								<div className="smowp-notice-banner__text">
									<strong>
										{ __(
											'All media is already offloaded to the cloud!',
											'smart-media-offloader'
										) }
									</strong>
									<p>
										{ __(
											'All attachments in your library are currently stored in the cloud. Any newly uploaded media will be offloaded automatically.',
											'smart-media-offloader'
										) }
									</p>
								</div>
							</div>
						) }

						{ totalCount === 0 && (
							<div className="smowp-notice-banner smowp-notice-banner--info">
								<span className="smowp-notice-banner__icon">
									ℹ
								</span>
								<div className="smowp-notice-banner__text">
									<strong>
										{ __(
											'Your WordPress media library is empty.',
											'smart-media-offloader'
										) }
									</strong>
									<p>
										{ __(
											'Upload images or media files to WordPress to start offloading.',
											'smart-media-offloader'
										) }
									</p>
								</div>
							</div>
						) }

						<div className="smowp-bulk-action">
							{ localCount > 0 && (
								<p className="smowp-bulk-action__info">
									{ sprintf(
										/* translators: %d: number of local attachments. */
										__(
											'%d attachment(s) are local-only and ready to be offloaded.',
											'smart-media-offloader'
										),
										localCount
									) }
								</p>
							) }

							{ ! running && (
								<div className="smowp-bulk-action__btn-wrap">
									{ isDisabled && disabledReason ? (
										<Tooltip text={ disabledReason }>
											<span
												className="smowp-btn-disabled-wrap"
												tabIndex={ 0 }
												role="button"
												aria-disabled="true"
											>
												<Button
													variant="primary"
													className="smowp-btn-primary"
													disabled
													isBusy={ busy }
												>
													{ localCount === 0
														? __(
																'All Media Offloaded',
																'smart-media-offloader'
														  )
														: sprintf(
																/* translators: %d: number of local attachments. */
																__(
																	'Start Bulk Offload (%d)',
																	'smart-media-offloader'
																),
																localCount
														  ) }
												</Button>
											</span>
										</Tooltip>
									) : (
										<Button
											variant="primary"
											className="smowp-btn-primary"
											disabled={ busy }
											isBusy={ busy }
											onClick={ () => void start() }
										>
											{ sprintf(
												/* translators: %d: number of local attachments. */
												__(
													'Start Bulk Offload (%d)',
													'smart-media-offloader'
												),
												localCount
											) }
										</Button>
									) }
								</div>
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

								<ActivityLog
									items={ state.recent }
									actionType="offload"
									running={ running }
								/>
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
