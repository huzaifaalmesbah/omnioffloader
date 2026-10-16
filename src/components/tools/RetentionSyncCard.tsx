import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

import { useCore } from '../../context/CoreContext';
import { useRetentionSync } from '../../hooks/useRetentionSync';
import { RetentionPolicy } from '../../types';
import { RETENTION_LABELS } from '../../utils/constants';
import { formatBytes } from '../../utils/formatBytes';
import { revealSection } from '../../utils/revealSection';
import { ActivityLog } from '../ui/ActivityLog';
import { BatchScopeSelector, type ScopeMode } from '../ui/BatchScopeSelector';
import { Card } from '../ui/Card';
import { CliHint } from '../ui/CliHint';
import { CliJobNotice, cliJobTitle } from '../ui/CliJobNotice';
import { ProgressBar } from '../ui/ProgressBar';
import { RunAnnouncer } from '../ui/RunAnnouncer';

/**
 * Apply the saved retention policy to media offloaded under an older one.
 */
export function RetentionSyncCard() {
	const { stats, isDirty, cliJob } = useCore();
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
				<p className="omnioffloader-muted">
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
		! cliJob &&
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
			docs="features/retention-policies/#apply-a-policy-to-existing-media"
			description={ __(
				'Changing the retention policy only affects new uploads. Use this to bring media you offloaded earlier in line with the current policy. Attachments stay offloaded — only the copies kept on your server change.',
				'omnioffloader'
			) }
		>
			<CliJobNotice />

			{ isDirty && (
				<p className="omnioffloader-warning">
					{ __(
						'You have unsaved settings. Save them first — this applies the saved retention policy.',
						'omnioffloader'
					) }
				</p>
			) }

			<div className="omnioffloader-media-summary">
				<div className="omnioffloader-media-chip">
					<span className="omnioffloader-media-chip__label">
						{ __( 'Current Policy', 'omnioffloader' ) }
					</span>
					<strong className="omnioffloader-media-chip__value omnioffloader-media-chip__value--text">
						{ preflight ? policyLabel : '—' }
					</strong>
				</div>
				<div className="omnioffloader-media-chip omnioffloader-media-chip--local">
					<span className="omnioffloader-media-chip__label">
						{ __( 'Need Updating', 'omnioffloader' ) }
					</span>
					<strong className="omnioffloader-media-chip__value">
						{ preflight ? eligible : '—' }
					</strong>
				</div>
				<div className="omnioffloader-media-chip omnioffloader-media-chip--offloaded">
					<span className="omnioffloader-media-chip__label">
						{ preflight && preflight.to_download > 0
							? __( 'To Clean / Download', 'omnioffloader' )
							: __( 'Local Space to Free', 'omnioffloader' ) }
					</span>
					<strong className="omnioffloader-media-chip__value">
						{ preflight && preflight.to_download > 0
							? `${ preflight.to_clean } / ${ preflight.to_download }`
							: formatBytes( preflight?.freed_bytes ?? 0 ) }
					</strong>
				</div>
			</div>

			{ preflight && eligible === 0 && ! running && (
				<div className="omnioffloader-notice-banner omnioffloader-notice-banner--success">
					<span
						className="omnioffloader-notice-banner__icon"
						aria-hidden="true"
					>
						✓
					</span>
					<div className="omnioffloader-notice-banner__text">
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
				<div className="omnioffloader-bulk-action">
					<p className="omnioffloader-bulk-action__info">
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

					<ul className="omnioffloader-retention-facts">
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

					<CliHint
						command="retention"
						count={ eligible }
						// The command's section is further down this page.
						onNavigate={ ( _view, section ) => {
							if ( section ) {
								revealSection( section );
							}
						} }
					/>

					{ ! preflight.disk_ok && (
						<p className="omnioffloader-warning">
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
						<div className="omnioffloader-retention-confirm">
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
						<div className="omnioffloader-bulk-action__btn-wrap">
							<Button
								variant="primary"
								className="omnioffloader-btn-primary"
								disabled={ ! canStart }
								aria-describedby={
									cliJob
										? 'omnioffloader-retention-disabled-reason'
										: undefined
								}
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
							{ cliJob && (
								<p
									id="omnioffloader-retention-disabled-reason"
									className="omnioffloader-disabled-reason"
								>
									{ cliJobTitle( cliJob.job ) }
								</p>
							) }
						</div>
					) }
				</div>
			) }

			{ /* Always mounted, so the start of a run is announced too. */ }
			<RunAnnouncer
				status={ showProgress && state ? state.status : 'idle' }
				processed={ state?.processed ?? 0 }
				total={ state?.total ?? 0 }
				errors={ state?.errors ?? 0 }
				message={ state?.message }
			/>

			{ showProgress && state && (
				<div
					className={
						'omnioffloader-progress-card' +
						( running ? ' is-running' : '' ) +
						( state.status === 'done' ? ' is-done' : '' ) +
						( state.status === 'cancelled'
							? ' is-cancelled'
							: '' ) +
						( state.status === 'failed' ? ' is-failed' : '' )
					}
				>
					<div className="omnioffloader-progress-card__header">
						<div className="omnioffloader-progress-card__status">
							{ running && (
								<span className="omnioffloader-ui-badge omnioffloader-ui-badge--running">
									<span className="omnioffloader-ui-badge-pulse" />
									{ __( 'Applying…', 'omnioffloader' ) }
								</span>
							) }
							{ state.status === 'done' && (
								<span className="omnioffloader-ui-badge omnioffloader-ui-badge--ok">
									{ __( 'Finished', 'omnioffloader' ) }
								</span>
							) }
							{ state.status === 'cancelled' && (
								<span className="omnioffloader-ui-badge omnioffloader-ui-badge--fail">
									{ __( 'Cancelled', 'omnioffloader' ) }
								</span>
							) }
							{ state.status === 'failed' && (
								<span className="omnioffloader-ui-badge omnioffloader-ui-badge--fail">
									{ __( 'Stopped', 'omnioffloader' ) }
								</span>
							) }
							<span className="omnioffloader-progress-card__count">
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

						<span className="omnioffloader-progress-card__pct">
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

					{ state.status === 'failed' && state.message && (
						<Notice
							status="error"
							isDismissible={ false }
							className="omnioffloader-progress-card__notice"
						>
							{ state.message }
						</Notice>
					) }

					<div className="omnioffloader-progress-card__footer">
						<span className="omnioffloader-progress-card__errors">
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

						<div className="omnioffloader-progress-card__actions">
							{ running ? (
								<Button
									variant="secondary"
									isDestructive
									className="omnioffloader-btn-destructive"
									disabled={ busy }
									onClick={ () => void cancel() }
								>
									{ __( 'Cancel', 'omnioffloader' ) }
								</Button>
							) : (
								<Button
									variant="secondary"
									className="omnioffloader-btn-dismiss"
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
