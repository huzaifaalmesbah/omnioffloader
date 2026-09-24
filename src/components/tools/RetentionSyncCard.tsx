import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

import { useCore } from '../../context/CoreContext';
import { useRetentionSync } from '../../hooks/useRetentionSync';
import { RetentionPolicy } from '../../types';
import { RETENTION_LABELS } from '../../utils/constants';
import { formatBytes } from '../../utils/formatBytes';
import { ActivityLog } from '../ui/ActivityLog';
import { BatchScopeSelector, type ScopeMode } from '../ui/BatchScopeSelector';
import { Card } from '../ui/Card';
import { ProgressBar } from '../ui/ProgressBar';
import { RunAnnouncer } from '../ui/RunAnnouncer';

/**
 * Apply the saved retention policy to media offloaded under an older one.
 */
export function RetentionSyncCard() {
	const { stats, isDirty } = useCore();
	const { preflight, state, busy, start, cancel, showProgress, dismiss } =
		useRetentionSync();

	const [ scopeMode, setScopeMode ] = useState< ScopeMode >( 'all' );
	const [ customCount, setCustomCount ] = useState< number >( 10 );
	const [ confirmed, setConfirmed ] = useState( false );

	const running = state?.status === 'running';
	const eligible = preflight?.eligible ?? 0;
	const policy = preflight?.policy ?? RetentionPolicy.RetainLocal;
	const policyLabel = RETENTION_LABELS[ policy ];
	const deletesOriginals =
		policy === RetentionPolicy.FullMigration &&
		( preflight?.to_clean ?? 0 ) > 0;
	const freeKnown = ( preflight?.free_bytes ?? 0 ) > 0;

	useEffect( () => {
		if ( eligible > 0 && customCount > eligible ) {
			setCustomCount( eligible );
		}
	}, [ eligible, customCount ] );

	// A new policy needs a fresh acknowledgement.
	useEffect( () => {
		setConfirmed( false );
	}, [ policy ] );

	if ( ! stats?.configured ) {
		return (
			<Card title={ __( 'Apply Retention Policy', 'omnioffloader' ) }>
				<p className="smowp-muted">
					{ __(
						'Configure a cloud provider in Settings first.',
						'omnioffloader'
					) }
				</p>
			</Card>
		);
	}

	const isCustom = scopeMode === 'custom' && customCount < eligible;
	const count = isCustom ? customCount : eligible;
	const isCustomInvalid =
		scopeMode === 'custom' && ( ! customCount || customCount < 1 );
	const canStart =
		! busy &&
		! running &&
		! isDirty &&
		eligible > 0 &&
		! isCustomInvalid &&
		( ! deletesOriginals || confirmed );

	return (
		<Card
			title={ __(
				'Apply Retention Policy to Existing Media',
				'omnioffloader'
			) }
			description={ __(
				'Changing the retention policy only affects new uploads. Use this to bring media you offloaded earlier in line with the current policy. Attachments stay offloaded — only the copies kept on your server change.',
				'omnioffloader'
			) }
		>
			{ isDirty && (
				<p className="smowp-warning">
					{ __(
						'You have unsaved settings. Save them first — this applies the saved retention policy.',
						'omnioffloader'
					) }
				</p>
			) }

			<div className="smowp-media-summary">
				<div className="smowp-media-chip">
					<span className="smowp-media-chip__label">
						{ __( 'Current Policy', 'omnioffloader' ) }
					</span>
					<strong className="smowp-media-chip__value smowp-media-chip__value--text">
						{ preflight ? policyLabel : '—' }
					</strong>
				</div>
				<div className="smowp-media-chip smowp-media-chip--local">
					<span className="smowp-media-chip__label">
						{ __( 'Need Updating', 'omnioffloader' ) }
					</span>
					<strong className="smowp-media-chip__value">
						{ preflight ? eligible : '—' }
					</strong>
				</div>
				<div className="smowp-media-chip smowp-media-chip--offloaded">
					<span className="smowp-media-chip__label">
						{ preflight && preflight.to_download > 0
							? __( 'To Clean / Download', 'omnioffloader' )
							: __( 'Local Space to Free', 'omnioffloader' ) }
					</span>
					<strong className="smowp-media-chip__value">
						{ preflight && preflight.to_download > 0
							? `${ preflight.to_clean } / ${ preflight.to_download }`
							: formatBytes( preflight?.freed_bytes ?? 0 ) }
					</strong>
				</div>
			</div>

			{ preflight && eligible === 0 && ! running && (
				<div className="smowp-notice-banner smowp-notice-banner--success">
					<span
						className="smowp-notice-banner__icon"
						aria-hidden="true"
					>
						✓
					</span>
					<div className="smowp-notice-banner__text">
						<strong>
							{ sprintf(
								/* translators: %s: retention policy name. */
								__(
									'All offloaded media already follows "%s".',
									'omnioffloader'
								),
								policyLabel
							) }
						</strong>
						<p>
							{ __(
								'Nothing to update. If you change the retention policy in Settings, come back here to apply it to existing media.',
								'omnioffloader'
							) }
						</p>
					</div>
				</div>
			) }

			{ preflight && eligible > 0 && (
				<div className="smowp-bulk-action">
					<p className="smowp-bulk-action__info">
						{ sprintf(
							/* translators: 1: attachment count, 2: retention policy name. */
							_n(
								'%1$d offloaded attachment was offloaded under a different policy than "%2$s".',
								'%1$d offloaded attachments were offloaded under a different policy than "%2$s".',
								eligible,
								'omnioffloader'
							),
							eligible,
							policyLabel
						) }
					</p>

					<ul className="smowp-retention-facts">
						{ preflight.to_clean > 0 && (
							<li>
								{ sprintf(
									/* translators: 1: attachment count, 2: formatted byte size. */
									__(
										'%1$d will have local files removed — frees up to %2$s.',
										'omnioffloader'
									),
									preflight.to_clean,
									formatBytes( preflight.freed_bytes )
								) }
							</li>
						) }
						{ preflight.to_download > 0 && (
							<li>
								{ sprintf(
									/* translators: 1: attachment count, 2: formatted byte size. */
									__(
										'%1$d will get local copies downloaded again — up to %2$s.',
										'omnioffloader'
									),
									preflight.to_download,
									formatBytes( preflight.download_bytes )
								) }
							</li>
						) }
						{ preflight.to_download > 0 && freeKnown && (
							<li>
								{ sprintf(
									/* translators: %s: formatted byte size. */
									__(
										'Free disk space: %s.',
										'omnioffloader'
									),
									formatBytes( preflight.free_bytes )
								) }
							</li>
						) }
						<li>
							{ __(
								'Before a local file is deleted, its cloud copy is checked by size. Files that are missing from the cloud, or differ from the server copy, are uploaded again first. If that fails, the local file is kept and the error is logged.',
								'omnioffloader'
							) }
						</li>
					</ul>

					{ ! preflight.disk_ok && (
						<p className="smowp-warning">
							{ __(
								'Warning: free disk space may not be enough for the downloads. Free up space before starting.',
								'omnioffloader'
							) }
						</p>
					) }

					<BatchScopeSelector
						mode={ scopeMode }
						onModeChange={ setScopeMode }
						customCount={ customCount }
						onCustomCountChange={ setCustomCount }
						totalEligible={ eligible }
						disabled={ running || busy }
						allTitle={ __(
							'All Media Needing Updates',
							'omnioffloader'
						) }
						allDescription={ __(
							'Apply the policy to every attachment that does not follow it yet.',
							'omnioffloader'
						) }
						customDescription={ __(
							'Work through a large library in smaller batches.',
							'omnioffloader'
						) }
					/>

					{ deletesOriginals && (
						<div className="smowp-retention-confirm">
							<CheckboxControl
								label={ __(
									'I understand that original files will only exist in cloud storage. Image editing and thumbnail regeneration will need Bring Back first.',
									'omnioffloader'
								) }
								checked={ confirmed }
								onChange={ setConfirmed }
								disabled={ running || busy }
								__nextHasNoMarginBottom
							/>
						</div>
					) }

					{ ! running && (
						<div className="smowp-bulk-action__btn-wrap">
							<Button
								variant="primary"
								className="smowp-btn-primary"
								disabled={ ! canStart }
								isBusy={ busy }
								onClick={ () =>
									void start(
										scopeMode === 'custom'
											? customCount
											: undefined
									)
								}
							>
								{ isCustom
									? sprintf(
											/* translators: 1: batch count, 2: total count. */
											__(
												'Apply Policy (%1$d of %2$d)',
												'omnioffloader'
											),
											count,
											eligible
										)
									: sprintf(
											/* translators: %d: attachment count. */
											__(
												'Apply Policy (%d)',
												'omnioffloader'
											),
											count
										) }
							</Button>
						</div>
					) }
				</div>
			) }

			{ showProgress && state && (
				<div
					className={
						'smowp-progress-card' +
						( running ? ' is-running' : '' ) +
						( state.status === 'done' ? ' is-done' : '' ) +
						( state.status === 'cancelled'
							? ' is-cancelled'
							: '' ) +
						( state.status === 'failed' ? ' is-failed' : '' )
					}
				>
					<div className="smowp-progress-card__header">
						<div className="smowp-progress-card__status">
							{ running && (
								<span className="smowp-badge smowp-badge--running">
									<span className="smowp-badge-pulse" />
									{ __( 'Applying…', 'omnioffloader' ) }
								</span>
							) }
							{ state.status === 'done' && (
								<span className="smowp-badge smowp-badge--ok">
									{ __( 'Finished', 'omnioffloader' ) }
								</span>
							) }
							{ state.status === 'cancelled' && (
								<span className="smowp-badge smowp-badge--fail">
									{ __( 'Cancelled', 'omnioffloader' ) }
								</span>
							) }
							{ state.status === 'failed' && (
								<span className="smowp-badge smowp-badge--fail">
									{ __( 'Stopped', 'omnioffloader' ) }
								</span>
							) }
							<span className="smowp-progress-card__count">
								{ sprintf(
									/* translators: 1: updated count, 2: total count. */
									__(
										'%1$d of %2$d updated',
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
											( state.processed / state.total ) *
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
					<RunAnnouncer
						status={ state.status }
						processed={ state.processed }
						total={ state.total }
						errors={ state.errors }
						message={ state.message }
					/>

					{ state.status === 'failed' && state.message && (
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
							{ [
								sprintf(
									/* translators: %s: formatted byte size. */
									__( 'Freed %s', 'omnioffloader' ),
									formatBytes( state.freed_bytes )
								),
								state.repaired > 0
									? sprintf(
											/* translators: %d: file count. */
											_n(
												'%d file re-uploaded to the cloud',
												'%d files re-uploaded to the cloud',
												state.repaired,
												'omnioffloader'
											),
											state.repaired
										)
									: '',
								state.errors > 0
									? sprintf(
											/* translators: %d: error count. */
											_n(
												'%d item failed and was left unchanged',
												'%d items failed and were left unchanged',
												state.errors,
												'omnioffloader'
											),
											state.errors
										)
									: __( 'No errors', 'omnioffloader' ),
							]
								.filter( Boolean )
								.join( ' · ' ) }
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
									{ __( 'Cancel', 'omnioffloader' ) }
								</Button>
							) : (
								<Button
									variant="secondary"
									className="smowp-btn-dismiss"
									onClick={ dismiss }
								>
									{ __( 'Dismiss', 'omnioffloader' ) }
								</Button>
							) }
						</div>
					</div>

					<ActivityLog
						items={ state.recent }
						currentItem={ state.current_item }
						actionType="retention"
						running={ running }
						total={ state.total }
						processed={ state.processed }
					/>
				</div>
			) }
		</Card>
	);
}
