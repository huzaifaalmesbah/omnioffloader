import { Button, Tooltip } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { ActivityLog } from '../components/ui/ActivityLog';
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
	const totalCount = stats?.total ?? 0;
	const offloadedCount = preflight?.offloaded ?? stats?.offloaded ?? 0;
	const localCount = stats?.local ?? 0;
	const isAllLocal =
		stats?.configured && offloadedCount === 0 && totalCount > 0;
	const freeKnown = ( preflight?.free_bytes ?? 0 ) > 0;

	const isDisabled = busy || offloadedCount === 0;
	let disabledReason: string = '';
	if ( offloadedCount === 0 ) {
		if ( totalCount === 0 ) {
			disabledReason = __(
				'Media library is empty. No media to restore.',
				'smart-media-offloader'
			);
		} else {
			disabledReason = __(
				'All media attachments are already stored locally on your server.',
				'smart-media-offloader'
			);
		}
	}

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
										'Offloaded (Ready to Restore)',
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
										'Already Local',
										'smart-media-offloader'
									) }
								</span>
								<strong className="smowp-media-chip__value">
									{ localCount }
								</strong>
							</div>
						</div>

						{ isAllLocal && (
							<div className="smowp-notice-banner smowp-notice-banner--info">
								<span className="smowp-notice-banner__icon">
									ℹ
								</span>
								<div className="smowp-notice-banner__text">
									<strong>
										{ __(
											'All media is already stored locally on your server.',
											'smart-media-offloader'
										) }
									</strong>
									<p>
										{ __(
											'There are no offloaded cloud attachments to restore at this time.',
											'smart-media-offloader'
										) }
									</p>
								</div>
							</div>
						) }

						<div className="smowp-bulk-action">
							{ offloadedCount > 0 && (
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
							) }

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

							<div className="smowp-bringback-options">
								<h4 className="smowp-bringback-options__title">
									{ __(
										'Restore Safety & Storage Options',
										'smart-media-offloader'
									) }
								</h4>
								<div
									className="smowp-bringback-options__grid"
									role="radiogroup"
									aria-label={ __(
										'Restore storage options',
										'smart-media-offloader'
									) }
								>
									<div
										role="radio"
										aria-checked={ ! deleteCloud }
										tabIndex={
											running || offloadedCount === 0
												? -1
												: 0
										}
										className={
											'smowp-option-card' +
											( ! deleteCloud
												? ' is-selected'
												: '' ) +
											( running || offloadedCount === 0
												? ' is-disabled'
												: '' )
										}
										onClick={ () => {
											if (
												! running &&
												offloadedCount > 0
											) {
												setDeleteCloud( false );
											}
										} }
										onKeyDown={ ( e ) => {
											if (
												( e.key === ' ' ||
													e.key === 'Enter' ) &&
												! running &&
												offloadedCount > 0
											) {
												e.preventDefault();
												setDeleteCloud( false );
											}
										} }
									>
										<div className="smowp-option-card__radio">
											<span className="smowp-radio-circle">
												{ ! deleteCloud && (
													<span className="smowp-radio-dot" />
												) }
											</span>
										</div>
										<div className="smowp-option-card__content">
											<div className="smowp-option-card__head">
												<strong>
													{ __(
														'Keep Cloud Copies',
														'smart-media-offloader'
													) }
												</strong>
												<span className="smowp-badge smowp-badge--ok">
													{ __(
														'Recommended',
														'smart-media-offloader'
													) }
												</span>
											</div>
											<p>
												{ __(
													'Files are downloaded to your server and served locally. Originals remain safe in your cloud bucket as backups.',
													'smart-media-offloader'
												) }
											</p>
										</div>
									</div>

									<div
										role="radio"
										aria-checked={ deleteCloud }
										tabIndex={
											running || offloadedCount === 0
												? -1
												: 0
										}
										className={
											'smowp-option-card' +
											( deleteCloud
												? ' is-selected'
												: '' ) +
											( running || offloadedCount === 0
												? ' is-disabled'
												: '' )
										}
										onClick={ () => {
											if (
												! running &&
												offloadedCount > 0
											) {
												setDeleteCloud( true );
											}
										} }
										onKeyDown={ ( e ) => {
											if (
												( e.key === ' ' ||
													e.key === 'Enter' ) &&
												! running &&
												offloadedCount > 0
											) {
												e.preventDefault();
												setDeleteCloud( true );
											}
										} }
									>
										<div className="smowp-option-card__radio">
											<span className="smowp-radio-circle">
												{ deleteCloud && (
													<span className="smowp-radio-dot" />
												) }
											</span>
										</div>
										<div className="smowp-option-card__content">
											<div className="smowp-option-card__head">
												<strong>
													{ __(
														'Delete from Cloud After Restore',
														'smart-media-offloader'
													) }
												</strong>
												<span className="smowp-badge smowp-badge--fail">
													{ __(
														'Reclaim Storage',
														'smart-media-offloader'
													) }
												</span>
											</div>
											<p>
												{ __(
													'Reclaims bucket storage. Cloud files are permanently deleted ONLY after 100% verified download on your server.',
													'smart-media-offloader'
												) }
											</p>
										</div>
									</div>
								</div>
							</div>

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
													{ offloadedCount === 0
														? __(
																'No Offloaded Media to Restore',
																'smart-media-offloader'
														  )
														: sprintf(
																/* translators: %d: number of offloaded attachments. */
																__(
																	'Start Bring Back (%d)',
																	'smart-media-offloader'
																),
																offloadedCount
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
											onClick={ () =>
												void start( deleteCloud )
											}
										>
											{ sprintf(
												/* translators: %d: number of offloaded attachments. */
												__(
													'Start Bring Back (%d)',
													'smart-media-offloader'
												),
												offloadedCount
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

								<ActivityLog
									items={ state.recent }
									actionType="restore"
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
					'You can also bring back one file at a time from the Media Library list — use the "Bring Back" row action on any offloaded attachment. Each attachment restores from the provider it was offloaded to, even if you switched providers since.',
					'smart-media-offloader'
				) }
			/>
		</div>
	);
}
