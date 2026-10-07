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
import { ProgressBar } from '../components/ui/ProgressBar';
import { RunAnnouncer } from '../components/ui/RunAnnouncer';
import { useCore } from '../context/CoreContext';
import { useBulkOffload } from '../hooks/useBulkOffload';

interface OffloadProps {
	onNavigate: ( view: string, section?: string ) => void;
}

export function OffloadPage( { onNavigate }: OffloadProps ) {
	const { stats, cliJob } = useCore();
	const { state, busy, start, cancel, showProgress, dismiss } =
		useBulkOffload();

	const running = state?.status === 'running';
	const totalCount = stats?.total ?? 0;
	const offloadedCount = stats?.offloaded ?? 0;
	const localCount = stats?.local ?? 0;
	const isAllOffloaded =
		stats?.configured && localCount === 0 && totalCount > 0;

	const [ scopeMode, setScopeMode ] = useState< ScopeMode >( 'all' );
	const [ customCount, setCustomCount ] = useState< number >( () =>
		Math.min( 10, localCount > 0 ? localCount : 10 )
	);

	useEffect( () => {
		if ( localCount > 0 && customCount > localCount ) {
			setCustomCount( localCount );
		}
	}, [ localCount, customCount ] );

	const isCustomInvalid =
		scopeMode === 'custom' && ( ! customCount || customCount < 1 );
	const safeMode = !! stats?.safe_mode?.active;
	const isDisabled =
		busy || !! cliJob || localCount === 0 || isCustomInvalid || safeMode;
	let disabledReason: string = '';
	if ( cliJob ) {
		disabledReason = cliJobTitle( cliJob.job );
	} else if ( safeMode ) {
		disabledReason = __(
			'Safe mode is on: this site looks like a copy, so nothing is uploaded to your cloud storage.',
			'omnioffloader'
		);
	} else if ( localCount === 0 ) {
		if ( totalCount === 0 ) {
			disabledReason = __(
				'Media library is empty. Upload media files first.',
				'omnioffloader'
			);
		} else {
			disabledReason = __(
				'All media attachments are already offloaded to the cloud.',
				'omnioffloader'
			);
		}
	} else if ( isCustomInvalid ) {
		disabledReason = __(
			'Please enter a valid number of attachments to offload.',
			'omnioffloader'
		);
	}

	let buttonLabel: string;
	if ( localCount === 0 ) {
		buttonLabel = __( 'All Media Offloaded', 'omnioffloader' );
	} else if (
		scopeMode === 'custom' &&
		customCount > 0 &&
		customCount < localCount
	) {
		buttonLabel = sprintf(
			/* translators: 1: batch count, 2: total local count */
			__( 'Start Bulk Offload (%1$d of %2$d)', 'omnioffloader' ),
			customCount,
			localCount
		);
	} else {
		buttonLabel = sprintf(
			/* translators: %d: number of local attachments. */
			__( 'Start Bulk Offload (%d)', 'omnioffloader' ),
			scopeMode === 'custom' && customCount > 0 ? customCount : localCount
		);
	}

	return (
		<div className="smowp-page">
			<Card
				title={ __( 'Bulk Offload', 'omnioffloader' ) }
				docs="features/bulk-offload/"
				description={ __(
					'Move your existing media library to the cloud. Processing runs in the background — you can leave this page and come back.',
					'omnioffloader'
				) }
			>
				<CliJobNotice />

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
										'Already Offloaded',
										'omnioffloader'
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
										'omnioffloader'
									) }
								</span>
								<strong className="smowp-media-chip__value">
									{ localCount }
								</strong>
							</div>
						</div>

						{ isAllOffloaded && (
							<div className="smowp-notice-banner smowp-notice-banner--success">
								<span
									className="smowp-notice-banner__icon"
									aria-hidden="true"
								>
									✓
								</span>
								<div className="smowp-notice-banner__text">
									<strong>
										{ __(
											'All media is already offloaded to the cloud!',
											'omnioffloader'
										) }
									</strong>
									<p>
										{ __(
											'All attachments in your library are currently stored in the cloud. Any newly uploaded media will be offloaded automatically.',
											'omnioffloader'
										) }
									</p>
								</div>
							</div>
						) }

						{ totalCount === 0 && (
							<div className="smowp-notice-banner smowp-notice-banner--info">
								<span
									className="smowp-notice-banner__icon"
									aria-hidden="true"
								>
									ℹ
								</span>
								<div className="smowp-notice-banner__text">
									<strong>
										{ __(
											'Your WordPress media library is empty.',
											'omnioffloader'
										) }
									</strong>
									<p>
										{ __(
											'Upload images or media files to WordPress to start offloading.',
											'omnioffloader'
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
										_n(
											'%d attachment is local-only and ready to be offloaded.',
											'%d attachments are local-only and ready to be offloaded.',
											localCount,
											'omnioffloader'
										),
										localCount
									) }
								</p>
							) }

							{ localCount > 0 && (
								<CliHint
									command="offload"
									count={ localCount }
									onNavigate={ onNavigate }
								/>
							) }

							{ localCount > 0 && (
								<BatchScopeSelector
									mode={ scopeMode }
									onModeChange={ setScopeMode }
									customCount={ customCount }
									onCustomCountChange={ setCustomCount }
									totalEligible={ localCount }
									disabled={ running || busy }
									allTitle={ __(
										'All Local Media',
										'omnioffloader'
									) }
									allDescription={ __(
										'Offload every eligible attachment currently on your server.',
										'omnioffloader'
									) }
									customTitle={ __(
										'Custom Amount',
										'omnioffloader'
									) }
									customDescription={ __(
										'Offload a specific number of attachments instead of all.',
										'omnioffloader'
									) }
								/>
							) }

							{ ! running && (
								<div className="smowp-bulk-action__btn-wrap">
									{ isDisabled && disabledReason ? (
										<>
											<Button
												variant="primary"
												className="smowp-btn-primary"
												disabled
												accessibleWhenDisabled
												aria-describedby="smowp-offload-disabled-reason"
												isBusy={ busy }
											>
												{ buttonLabel }
											</Button>
											<p
												id="smowp-offload-disabled-reason"
												className="smowp-disabled-reason"
											>
												{ disabledReason }
											</p>
										</>
									) : (
										<Button
											variant="primary"
											className="smowp-btn-primary"
											disabled={ busy }
											isBusy={ busy }
											onClick={ () =>
												void start(
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
													'Offloading…',
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
														'Preparing offload…',
														'omnioffloader'
													)
												: sprintf(
														/* translators: 1: uploaded count, 2: total count. */
														__(
															'%1$d of %2$d uploaded',
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
										{ state.errors > 0
											? sprintf(
													/* translators: %d: error count. */
													_n(
														'%d error encountered',
														'%d errors encountered',
														state.errors,
														'omnioffloader'
													),
													state.errors
												)
											: __(
													'No errors',
													'omnioffloader'
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
									actionType="offload"
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
					'You can also offload one file at a time from the Media Library list — use the "Offload now" row action. Offload state is shown in each attachment\'s details, and the library can be filtered by Offloaded / Local only.',
					'omnioffloader'
				) }
			/>
		</div>
	);
}
