import { Button, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

import { ActivityLog } from '../components/ui/ActivityLog';
import {
	BatchScopeSelector,
	type ScopeMode,
} from '../components/ui/BatchScopeSelector';
import { Card } from '../components/ui/Card';
import { CliHint } from '../components/ui/CliHint';
import { CliJobNotice, cliJobTitle } from '../components/ui/CliJobNotice';
import { useConfirm } from '../components/ui/ConfirmDialog';
import { ProgressBar } from '../components/ui/ProgressBar';
import { RunAnnouncer } from '../components/ui/RunAnnouncer';
import { useCore } from '../context/CoreContext';
import { useBringBack } from '../hooks/useBringBack';
import { formatBytes } from '../utils/formatBytes';
import { radioKeyDown, radioTabIndex } from '../utils/radioGroup';

const DELETE_CLOUD_OPTIONS: readonly boolean[] = [ false, true ];

interface BringBackProps {
	onNavigate: ( view: string, section?: string ) => void;
}

export function BringBackPage( { onNavigate }: BringBackProps ) {
	const { stats, savedSettings: settings, cliJob } = useCore();
	const askConfirm = useConfirm();
	const { preflight, state, busy, start, cancel, showProgress, dismiss } =
		useBringBack();
	const [ deleteCloud, setDeleteCloud ] = useState(
		settings?.bring_back_delete_cloud ?? false
	);

	const running = state?.status === 'running';
	const totalCount = stats?.total ?? 0;
	// Mid-run the preflight is not refreshed (it is a full-library scan);
	// the stats count is cheap and kept current by the poll.
	const offloadedCount = running
		? ( stats?.offloaded ?? 0 )
		: ( preflight?.offloaded ?? stats?.offloaded ?? 0 );
	const localCount = stats?.local ?? 0;
	const isAllLocal =
		stats?.configured && offloadedCount === 0 && totalCount > 0;
	const freeKnown = ( preflight?.free_bytes ?? 0 ) > 0;
	// On a copy of the site Bring Back only downloads; the delete-from-cloud
	// choice is hidden and the server refuses it too.
	const safeMode = !! stats?.safe_mode?.active;
	const quota = preflight?.quota ?? null;

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
	// Safe mode turns Bring Back off unless the owner allowed it in Settings.
	const bringBackBlocked = stats?.safe_mode?.bring_back === false;
	const isDisabled =
		busy ||
		!! cliJob ||
		offloadedCount === 0 ||
		isCustomInvalid ||
		bringBackBlocked;
	let disabledReason: string = '';
	if ( cliJob ) {
		disabledReason = cliJobTitle( cliJob.job );
	} else if ( bringBackBlocked ) {
		disabledReason = __(
			'Safe mode is on: Bring Back is turned off on this copy of the site. You can allow it (download only) in Settings.',
			'omnioffloader'
		);
	} else if ( offloadedCount === 0 ) {
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
			_n(
				'%d error encountered',
				'%d errors encountered',
				state.errors,
				'omnioffloader'
			),
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

	const handleStart = async () => {
		// Never on a copy of the site.
		const removeCloud = deleteCloud && ! safeMode;

		// Permanent, like the CLI's --delete-cloud, which also asks first.
		if (
			removeCloud &&
			! ( await askConfirm( {
				title: __( 'Delete the cloud copies?', 'omnioffloader' ),
				message: __(
					'Each file is deleted from your cloud storage once it is restored and checked on this server. Deleted cloud files cannot be recovered unless your bucket keeps versions.',
					'omnioffloader'
				),
				confirmLabel: __(
					'Bring back and delete from cloud',
					'omnioffloader'
				),
				isDestructive: true,
			} ) )
		) {
			return;
		}

		void start(
			removeCloud,
			scopeMode === 'custom' ? customCount : undefined
		);
	};

	return (
		<div className="omnioffloader-page">
			<Card
				title={ __( 'Bring Back', 'omnioffloader' ) }
				docs="features/bring-back/"
				description={ __(
					'Restore offloaded media from the cloud back to your server. Downloads are atomic and verified — an attachment only switches back to local URLs once every file is safely on disk.',
					'omnioffloader'
				) }
			>
				<CliJobNotice />

				{ ! stats?.configured && (
					<p className="omnioffloader-muted">
						{ __(
							'Configure a cloud provider in Settings first.',
							'omnioffloader'
						) }
					</p>
				) }

				{ stats?.configured && (
					<>
						<div className="omnioffloader-media-summary">
							<div className="omnioffloader-media-chip">
								<span className="omnioffloader-media-chip__label">
									{ __(
										'Total Library Media',
										'omnioffloader'
									) }
								</span>
								<strong className="omnioffloader-media-chip__value">
									{ totalCount }
								</strong>
							</div>
							<div className="omnioffloader-media-chip omnioffloader-media-chip--offloaded">
								<span className="omnioffloader-media-chip__label">
									{ __(
										'Offloaded (Ready to Restore)',
										'omnioffloader'
									) }
								</span>
								<strong className="omnioffloader-media-chip__value">
									{ offloadedCount }
								</strong>
							</div>
							<div className="omnioffloader-media-chip omnioffloader-media-chip--local">
								<span className="omnioffloader-media-chip__label">
									{ __( 'Already Local', 'omnioffloader' ) }
								</span>
								<strong className="omnioffloader-media-chip__value">
									{ localCount }
								</strong>
							</div>
						</div>

						{ isAllLocal && (
							<div className="omnioffloader-notice-banner omnioffloader-notice-banner--info">
								<span
									className="omnioffloader-notice-banner__icon"
									aria-hidden="true"
								>
									ℹ
								</span>
								<div className="omnioffloader-notice-banner__text">
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

						<div className="omnioffloader-bulk-action">
							{ offloadedCount > 0 && (
								<p className="omnioffloader-bulk-action__info">
									{ sprintf(
										/* translators: %d: number of offloaded attachments. */
										_n(
											'%d attachment is offloaded and can be brought back.',
											'%d attachments are offloaded and can be brought back.',
											offloadedCount,
											'omnioffloader'
										),
										offloadedCount
									) }
								</p>
							) }

							{ offloadedCount > 0 && (
								<CliHint
									command="restore"
									count={ offloadedCount }
									onNavigate={ onNavigate }
								/>
							) }

							{ preflight && offloadedCount > 0 && (
								<p className="omnioffloader-muted omnioffloader-preflight-info">
									{ scopeMode === 'custom' &&
									customCount > 0 &&
									customCount < offloadedCount
										? sprintf(
												/* translators: 1: batch download size, 2: full download size */
												__(
													'Estimated download for this batch: ~%1$s (full library: %2$s)',
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
													'Estimated download: %s (files your retention policy kept on the server are not downloaded again)',
													'omnioffloader'
												),
												formatBytes(
													preflight.estimated_bytes
												)
											) }
									{ quota &&
										' · ' +
											sprintf(
												/* translators: 1: space left, 2: plan size, 3: date and time. */
												__(
													'Space left in your hosting plan: %1$s of %2$s (from cPanel, %3$s)',
													'omnioffloader'
												),
												formatBytes( quota.remaining ),
												formatBytes( quota.limit ),
												new Date(
													quota.updated_at * 1000
												).toLocaleString(
													// The admin's <html lang> is the WordPress locale.
													document.documentElement
														.lang || undefined
												)
											) }
									{ ! quota &&
										freeKnown &&
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
									{ ! quota &&
										! freeKnown &&
										' · ' +
											__(
												'Free disk space could not be determined on this host.',
												'omnioffloader'
											) }
								</p>
							) }

							{ preflight &&
								offloadedCount > 0 &&
								( preflight.unsized > 0 ||
									( ! quota && freeKnown ) ) && (
									<p className="omnioffloader-muted omnioffloader-preflight-info">
										{ preflight.unsized > 0 &&
											sprintf(
												/* translators: %d: number of attachments. */
												_n(
													'%d attachment has no recorded file size (uploaded before WordPress 6.0) and is not included in the estimate, so the real download may be larger.',
													'%d attachments have no recorded file size (uploaded before WordPress 6.0) and are not included in the estimate, so the real download may be larger.',
													preflight.unsized,
													'omnioffloader'
												),
												preflight.unsized
											) + ' ' }
										{ ! quota &&
											freeKnown &&
											__(
												'Free disk space is what the server reports; if your hosting plan has a storage quota, check how much of it is left in your hosting control panel.',
												'omnioffloader'
											) }
									</p>
								) }

							{ preflight && ! preflight.disk_ok && (
								<p className="omnioffloader-warning">
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

							{ ! safeMode && (
								<div className="omnioffloader-bringback-options">
									<h4 className="omnioffloader-bringback-options__title">
										{ __(
											'Restore Safety & Storage Options',
											'omnioffloader'
										) }
									</h4>
									<div
										className="omnioffloader-bringback-options__grid"
										role="radiogroup"
										aria-label={ __(
											'Restore storage options',
											'omnioffloader'
										) }
									>
										<div
											role="radio"
											aria-checked={ ! deleteCloud }
											aria-disabled={
												running || offloadedCount === 0
											}
											tabIndex={ radioTabIndex(
												DELETE_CLOUD_OPTIONS,
												false,
												deleteCloud,
												running || offloadedCount === 0
											) }
											className={
												'omnioffloader-option-card' +
												( ! deleteCloud
													? ' is-selected'
													: '' ) +
												( running ||
												offloadedCount === 0
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
													! running &&
													offloadedCount > 0
												) {
													radioKeyDown(
														e,
														DELETE_CLOUD_OPTIONS,
														false,
														setDeleteCloud
													);
												}
											} }
										>
											<div className="omnioffloader-option-card__radio">
												<span className="omnioffloader-radio-circle">
													{ ! deleteCloud && (
														<span className="omnioffloader-radio-dot" />
													) }
												</span>
											</div>
											<div className="omnioffloader-option-card__content">
												<div className="omnioffloader-option-card__head">
													<strong>
														{ __(
															'Keep Cloud Copies',
															'omnioffloader'
														) }
													</strong>
													<span className="omnioffloader-ui-badge omnioffloader-ui-badge--ok">
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
											aria-disabled={
												running || offloadedCount === 0
											}
											tabIndex={ radioTabIndex(
												DELETE_CLOUD_OPTIONS,
												true,
												deleteCloud,
												running || offloadedCount === 0
											) }
											className={
												'omnioffloader-option-card' +
												( deleteCloud
													? ' is-selected'
													: '' ) +
												( running ||
												offloadedCount === 0
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
													! running &&
													offloadedCount > 0
												) {
													radioKeyDown(
														e,
														DELETE_CLOUD_OPTIONS,
														true,
														setDeleteCloud
													);
												}
											} }
										>
											<div className="omnioffloader-option-card__radio">
												<span className="omnioffloader-radio-circle">
													{ deleteCloud && (
														<span className="omnioffloader-radio-dot" />
													) }
												</span>
											</div>
											<div className="omnioffloader-option-card__content">
												<div className="omnioffloader-option-card__head">
													<strong>
														{ __(
															'Delete from Cloud After Restore',
															'omnioffloader'
														) }
													</strong>
													<span className="omnioffloader-ui-badge omnioffloader-ui-badge--fail">
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
							) }

							{ ! running && (
								<div className="omnioffloader-bulk-action__btn-wrap">
									{ isDisabled && disabledReason ? (
										<>
											<Button
												variant="primary"
												className="omnioffloader-btn-primary"
												disabled
												accessibleWhenDisabled
												aria-describedby="omnioffloader-bring-back-disabled-reason"
												isBusy={ busy }
											>
												{ buttonLabel }
											</Button>
											<p
												id="omnioffloader-bring-back-disabled-reason"
												className="omnioffloader-disabled-reason"
											>
												{ disabledReason }
											</p>
										</>
									) : (
										<Button
											variant="primary"
											className="omnioffloader-btn-primary"
											disabled={ busy }
											isBusy={ busy }
											onClick={ () => void handleStart() }
										>
											{ buttonLabel }
										</Button>
									) }
								</div>
							) }
						</div>

						{ /* Always mounted, so the start of a run is announced too. */ }
						<RunAnnouncer
							status={
								showProgress && state ? state.status : 'idle'
							}
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
								<div className="omnioffloader-progress-card__header">
									<div className="omnioffloader-progress-card__status">
										{ running && (
											<span className="omnioffloader-ui-badge omnioffloader-ui-badge--running">
												<span className="omnioffloader-ui-badge-pulse" />
												{ __(
													'Restoring…',
													'omnioffloader'
												) }
											</span>
										) }
										{ state.status === 'done' && (
											<span className="omnioffloader-ui-badge omnioffloader-ui-badge--ok">
												{ __(
													'Finished',
													'omnioffloader'
												) }
											</span>
										) }
										{ state.status === 'cancelled' && (
											<span className="omnioffloader-ui-badge omnioffloader-ui-badge--fail">
												{ __(
													'Cancelled',
													'omnioffloader'
												) }
											</span>
										) }
										{ state.status === 'failed' && (
											<span className="omnioffloader-ui-badge omnioffloader-ui-badge--fail">
												{ __(
													'Stopped',
													'omnioffloader'
												) }
											</span>
										) }
										<span className="omnioffloader-progress-card__count">
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

									<span className="omnioffloader-progress-card__pct">
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
											className="omnioffloader-progress-card__notice"
										>
											{ state.message }
										</Notice>
									) }

								<div className="omnioffloader-progress-card__footer">
									<span className="omnioffloader-progress-card__errors">
										{ statusDetail }
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
												{ __(
													'Cancel',
													'omnioffloader'
												) }
											</Button>
										) : (
											<Button
												variant="secondary"
												className="omnioffloader-btn-dismiss"
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
				docs="features/media-library/"
				description={ __(
					'You can also bring back one file at a time from the Media Library list — use the "Bring Back" row action on any offloaded attachment. Each attachment restores from the provider it was offloaded to, even if you switched providers since.',
					'omnioffloader'
				) }
			/>
		</div>
	);
}
