import { Button, Notice, Tooltip } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { ActivityLog } from '../components/ui/ActivityLog';
import {
	BatchScopeSelector,
	type ScopeMode,
} from '../components/ui/BatchScopeSelector';
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
	const baseOffloaded = preflight?.offloaded ?? stats?.offloaded ?? 0;
	const offloadedCount =
		running && state
			? Math.max( 0, baseOffloaded - state.processed )
			: baseOffloaded;
	const localCount =
		running && state
			? Math.min( totalCount, ( stats?.local ?? 0 ) + state.processed )
			: ( stats?.local ?? 0 );
	const isAllLocal =
		stats?.configured && offloadedCount === 0 && totalCount > 0;
	const freeKnown = ( preflight?.free_bytes ?? 0 ) > 0;

	const [ scopeMode, setScopeMode ] = useState< ScopeMode >( 'all' );
	const [ customCount, setCustomCount ] = useState< number >( () =>
		Math.min( 10, offloadedCount > 0 ? offloadedCount : 10 )
	);

	useEffect( () => {
		if ( offloadedCount > 0 && customCount > offloadedCount ) {
			setCustomCount( offloadedCount );
		}
	}, [ offloadedCount, customCount ] );

	const isCustomInvalid =
		scopeMode === 'custom' && ( ! customCount || customCount < 1 );
	const isDisabled = busy || offloadedCount === 0 || isCustomInvalid;
	let disabledReason: string = '';
	if ( offloadedCount === 0 ) {
		if ( totalCount === 0 ) {
			disabledReason = __(
				'Media library is empty. No media to restore.',
				'omnioffloader'
			);
		} else {
			disabledReason = __(
				'All media attachments are already stored locally on your server.',
				'omnioffloader'
			);
		}
	} else if ( isCustomInvalid ) {
		disabledReason = __(
			'Please enter a valid number of attachments to bring back.',
			'omnioffloader'
		);
	}

	let statusDetail: string = __( 'No errors', 'omnioffloader' );
	if ( state && state.errors > 0 ) {
		statusDetail = sprintf(
			/* translators: %d: error count. */
			__( '%d error(s) encountered', 'omnioffloader' ),
			state.errors
		);
	} else if ( state?.delete_cloud && running ) {
		statusDetail = __(
			'Cloud objects deleting after restore',
			'omnioffloader'
		);
	}

	let buttonLabel: string;
	if ( offloadedCount === 0 ) {
		buttonLabel = __( 'No Offloaded Media to Restore', 'omnioffloader' );
	} else if (
		scopeMode === 'custom' &&
		customCount > 0 &&
		customCount < offloadedCount
	) {
		buttonLabel = sprintf(
			/* translators: 1: batch count, 2: total offloaded count */
			__( 'Start Bring Back (%1$d of %2$d)', 'omnioffloader' ),
			customCount,
			offloadedCount
		);
	} else {
		buttonLabel = sprintf(
			/* translators: %d: number of offloaded attachments. */
			__( 'Start Bring Back (%d)', 'omnioffloader' ),
			scopeMode === 'custom' && customCount > 0
				? customCount
				: offloadedCount
		);
	}

	return (
		<div className="smowp-page">
			<Card
				title={ __( 'Bring Back', 'omnioffloader' ) }
				description={ __(
					'Restore offloaded media from the cloud back to your server. Downloads are atomic and verified — an attachment only switches back to local URLs once every file is safely on disk.',
					'omnioffloader'
				) }
			>
				{ ! stats?.configured && (
					<p className="smowp-muted">
						{ __(
							'Configure a cloud provider in Settings first.',
							'omnioffloader'
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
										'omnioffloader'
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
										'omnioffloader'
									) }
								</span>
								<strong className="smowp-media-chip__value">
									{ offloadedCount }
								</strong>
							</div>
							<div className="smowp-media-chip smowp-media-chip--local">
								<span className="smowp-media-chip__label">
									{ __( 'Already Local', 'omnioffloader' ) }
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
											'omnioffloader'
										) }
									</strong>
									<p>
										{ __(
											'There are no offloaded cloud attachments to restore at this time.',
											'omnioffloader'
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
											'omnioffloader'
										),
										offloadedCount
									) }
								</p>
							) }

							{ preflight && offloadedCount > 0 && (
								<p className="smowp-muted smowp-preflight-info">
									{ scopeMode === 'custom' &&
									customCount > 0 &&
									customCount < offloadedCount
										? sprintf(
												/* translators: 1: batch download size, 2: full download size */
												__(
													'Estimated download for this batch: ~%1$s (full library: %2$s).',
													'omnioffloader'
												),
												formatBytes(
													Math.round(
														( preflight.estimated_bytes /
															offloadedCount ) *
															customCount
													)
												),
												formatBytes(
													preflight.estimated_bytes
												)
											)
										: sprintf(
												/* translators: %s: formatted byte size. */
												__(
													'Estimated download: up to %s (files still on disk are skipped).',
													'omnioffloader'
												),
												formatBytes(
													preflight.estimated_bytes
												)
											) }
									{ freeKnown &&
										' · ' +
											sprintf(
												/* translators: %s: formatted byte size. */
												__(
													'Free disk space: %s',
													'omnioffloader'
												),
												formatBytes(
													preflight.free_bytes
												)
											) }
									{ ! freeKnown &&
										' · ' +
											__(
												'Free disk space could not be determined on this host.',
												'omnioffloader'
											) }
								</p>
							) }

							{ preflight && ! preflight.disk_ok && (
								<p className="smowp-warning">
									{ __(
										'Warning: free disk space may not be enough for a full restore. Free up space before starting.',
										'omnioffloader'
									) }
								</p>
							) }

							{ offloadedCount > 0 && (
								<BatchScopeSelector
									mode={ scopeMode }
									onModeChange={ setScopeMode }
									customCount={ customCount }
									onCustomCountChange={ setCustomCount }
									totalEligible={ offloadedCount }
									disabled={ running || busy }
									allTitle={ __(
										'All Offloaded Media',
										'omnioffloader'
									) }
									allDescription={ __(
										'Restore every cloud attachment back to your server.',
										'omnioffloader'
									) }
									customTitle={ __(
										'Custom Amount',
										'omnioffloader'
									) }
									customDescription={ __(
										'Restore a specific number of attachments instead of all.',
										'omnioffloader'
									) }
								/>
							) }

							<div className="smowp-bringback-options">
								<h4 className="smowp-bringback-options__title">
									{ __(
										'Restore Safety & Storage Options',
										'omnioffloader'
									) }
								</h4>
								<div
									className="smowp-bringback-options__grid"
									role="radiogroup"
									aria-label={ __(
										'Restore storage options',
										'omnioffloader'
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
														'omnioffloader'
													) }
												</strong>
												<span className="smowp-badge smowp-badge--ok">
													{ __(
														'Recommended',
														'omnioffloader'
													) }
												</span>
											</div>
											<p>
												{ __(
													'Files are downloaded to your server and served locally. Originals remain safe in your cloud bucket as backups.',
													'omnioffloader'
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
														'omnioffloader'
													) }
												</strong>
												<span className="smowp-badge smowp-badge--fail">
													{ __(
														'Reclaim Storage',
														'omnioffloader'
													) }
												</span>
											</div>
											<p>
												{ __(
													'Reclaims bucket storage. Cloud files are permanently deleted ONLY after 100% verified download on your server.',
													'omnioffloader'
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
													{ buttonLabel }
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
												void start(
													deleteCloud,
													scopeMode === 'custom'
														? customCount
														: undefined
												)
											}
										>
											{ buttonLabel }
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
										: '' ) +
									( state.status === 'failed'
										? ' is-failed'
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
													'omnioffloader'
												) }
											</span>
										) }
										{ state.status === 'done' && (
											<span className="smowp-badge smowp-badge--ok">
												{ __(
													'Finished',
													'omnioffloader'
												) }
											</span>
										) }
										{ state.status === 'cancelled' && (
											<span className="smowp-badge smowp-badge--fail">
												{ __(
													'Cancelled',
													'omnioffloader'
												) }
											</span>
										) }
										{ state.status === 'failed' && (
											<span className="smowp-badge smowp-badge--fail">
												{ __(
													'Stopped',
													'omnioffloader'
												) }
											</span>
										) }
										<span className="smowp-progress-card__count">
											{ state.total === 0 && running
												? __(
														'Preparing restore…',
														'omnioffloader'
													)
												: sprintf(
														/* translators: 1: brought back count, 2: total count. */
														__(
															'%1$d of %2$d brought back',
															'omnioffloader'
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

								{ state.status === 'failed' &&
									state.message && (
										<Notice
											status="error"
											isDismissible={ false }
											className="smowp-progress-card__notice"
										>
											{ state.message }
										</Notice>
									) }

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
													'omnioffloader'
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
													'omnioffloader'
												) }
											</Button>
										) }
									</div>
								</div>

								<ActivityLog
									items={ state.recent }
									currentItem={ state.current_item }
									actionType="restore"
									running={ running }
									total={ state.total }
									processed={ state.processed }
								/>
							</div>
						) }
					</>
				) }
			</Card>

			<Card
				title={ __( 'Single attachments', 'omnioffloader' ) }
				description={ __(
					'You can also bring back one file at a time from the Media Library list — use the "Bring Back" row action on any offloaded attachment. Each attachment restores from the provider it was offloaded to, even if you switched providers since.',
					'omnioffloader'
				) }
			/>
		</div>
	);
}
