import { Button, ToggleControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Card } from '../components/ui/Card';
import { ProgressBar } from '../components/ui/ProgressBar';
import { useCore } from '../context/CoreContext';
import { useBringBack } from '../hooks/useBringBack';
import { formatBytes } from '../utils/formatBytes';

export function BringBackPage() {
	const { stats, settings } = useCore();
	const { preflight, state, busy, start, cancel, showProgress, dismiss } =
		useBringBack();
	const [ deleteCloud, setDeleteCloud ] = useState(
		settings?.bring_back_delete_cloud ?? false
	);

	const running = state?.status === 'running';
	const offloadedCount = preflight?.offloaded ?? stats?.offloaded ?? 0;
	const freeKnown = ( preflight?.free_bytes ?? 0 ) > 0;

	let statusDetail: string = __( 'No errors', 'smart-media-offloader' );
	if ( state && state.errors > 0 ) {
		statusDetail = sprintf(
			/* translators: %d: error count. */
			__( '%d error(s) encountered', 'smart-media-offloader' ),
			state.errors
		);
	} else if ( state?.delete_cloud ) {
		statusDetail = __(
			'Cloud objects deleting after restore',
			'smart-media-offloader'
		);
	}

	return (
		<div className="smowp-page">
			<Card
				title={ __( 'Bring Back', 'smart-media-offloader' ) }
				description={ __(
					'Restore offloaded media from the cloud back to your server. Downloads are atomic and verified — an attachment only switches back to local URLs once every file is safely on disk.',
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
									/* translators: %d: number of offloaded attachments. */
									__(
										'%d attachment(s) are offloaded and can be brought back.',
										'smart-media-offloader'
									),
									offloadedCount
								) }
							</p>

							{ preflight && offloadedCount > 0 && (
								<p className="smowp-muted smowp-preflight-info">
									{ sprintf(
										/* translators: %s: formatted byte size. */
										__(
											'Estimated download: up to %s (files still on disk are skipped).',
											'smart-media-offloader'
										),
										formatBytes( preflight.estimated_bytes )
									) }
									{ freeKnown &&
										' · ' +
											sprintf(
												/* translators: %s: formatted byte size. */
												__(
													'Free disk space: %s',
													'smart-media-offloader'
												),
												formatBytes(
													preflight.free_bytes
												)
											) }
									{ ! freeKnown &&
										' · ' +
											__(
												'Free disk space could not be determined on this host.',
												'smart-media-offloader'
											) }
								</p>
							) }

							{ preflight && ! preflight.disk_ok && (
								<p className="smowp-warning">
									{ __(
										'Warning: free disk space may not be enough for a full restore. Free up space before starting.',
										'smart-media-offloader'
									) }
								</p>
							) }

							<div className="smowp-toggle-group">
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Also delete files from the cloud after each verified restore',
										'smart-media-offloader'
									) }
									help={ __(
										'Off by default. Cloud objects are only removed after all files are verified on your server.',
										'smart-media-offloader'
									) }
									checked={ deleteCloud }
									disabled={ running }
									onChange={ setDeleteCloud }
								/>
							</div>

							{ ! running && (
								<Button
									variant="primary"
									className="smowp-btn-primary"
									disabled={ busy || offloadedCount === 0 }
									isBusy={ busy }
									onClick={ () => void start( deleteCloud ) }
								>
									{ __(
										'Start Bring Back',
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
													'Restoring…',
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
										{ statusDetail }
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
					'You can also bring back one file at a time from the Media Library list — use the "Bring Back" row action on any offloaded attachment. Each attachment restores from the provider it was offloaded to, even if you switched providers since.',
					'smart-media-offloader'
				) }
			/>
		</div>
	);
}
