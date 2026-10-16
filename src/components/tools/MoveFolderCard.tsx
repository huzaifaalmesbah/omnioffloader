import { Button, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

import { useCore } from '../../context/CoreContext';
import { useMoveSync } from '../../hooks/useMoveSync';
import { folderLabel } from '../../utils/folder';
import { ActivityLog } from '../ui/ActivityLog';
import { BatchScopeSelector, type ScopeMode } from '../ui/BatchScopeSelector';
import { Card } from '../ui/Card';
import { cliJobTitle } from '../ui/CliJobNotice';
import { useConfirm } from '../ui/ConfirmDialog';
import { ProgressBar } from '../ui/ProgressBar';
import { RunAnnouncer } from '../ui/RunAnnouncer';

/** Element id of the card, for links that bring it into view. */
export const MOVE_CARD_ID = 'omnioffloader-move-media';

/**
 * Move media offloaded under an earlier Path Prefix into the current folder.
 *
 * Shown only while there is something to move or a run to report: most
 * sites never change the prefix.
 */
export function MoveFolderCard() {
	const { stats, isDirty, cliJob } = useCore();
	const { preflight, state, busy, start, cancel, showProgress, dismiss } =
		useMoveSync();
	const askConfirm = useConfirm();

	const [ scopeMode, setScopeMode ] = useState< ScopeMode >( 'all' );
	const [ customCount, setCustomCount ] = useState< number >( 10 );

	const running = state?.status === 'running';
	const elsewhere = preflight?.elsewhere ?? 0;

	useEffect( () => {
		if ( elsewhere > 0 && customCount > elsewhere ) {
			setCustomCount( elsewhere );
		}
	}, [ elsewhere, customCount ] );

	if ( ! stats?.configured || ( elsewhere === 0 && ! showProgress ) ) {
		return null;
	}

	const folder = folderLabel( preflight?.folder ?? state?.folder ?? '' );
	const safeMode = !! stats.safe_mode?.active;
	const isCustom = scopeMode === 'custom' && customCount < elsewhere;
	const count = isCustom ? customCount : elsewhere;
	const isCustomInvalid =
		scopeMode === 'custom' && ( ! customCount || customCount < 1 );
	const canStart =
		! busy &&
		! running &&
		! cliJob &&
		! isDirty &&
		! safeMode &&
		elsewhere > 0 &&
		! isCustomInvalid;

	const handleStart = async () => {
		const ok = await askConfirm( {
			title: __( 'Move media to the current folder?', 'omnioffloader' ),
			message: sprintf(
				/* translators: 1: number of attachments, 2: bucket folder. */
				_n(
					'%1$s attachment moves into %2$s. Its old copy is deleted once the new copy is confirmed.',
					'%1$s attachments move into %2$s. Each old copy is deleted once its new copy is confirmed.',
					count,
					'omnioffloader'
				),
				count.toLocaleString(),
				folder
			),
			confirmLabel: __( 'Move media', 'omnioffloader' ),
		} );

		if ( ! ok ) {
			return;
		}

		void start( scopeMode === 'custom' ? customCount : undefined );
	};

	return (
		<Card
			id={ MOVE_CARD_ID }
			title={ __( 'Move Media to the Current Folder', 'omnioffloader' ) }
			docs="features/path-prefix-and-versioning/#move-existing-media-to-the-new-folder"
			description={ __(
				'Media keeps the bucket folder it was offloaded to, so after a Path Prefix change it is split across folders. This moves it into the current folder. Within the same bucket, your cloud storage copies each file itself: nothing is downloaded and your server is not involved. Each old copy is deleted once its new copy is confirmed.',
				'omnioffloader'
			) }
		>
			{ isDirty && (
				<p className="omnioffloader-warning">
					{ __(
						'You have unsaved settings. Save them first — this moves media into the saved folder.',
						'omnioffloader'
					) }
				</p>
			) }

			{ safeMode && (
				<p className="omnioffloader-warning">
					{ __(
						'Safe mode is on, so nothing can be moved: moving writes to and deletes from your cloud storage.',
						'omnioffloader'
					) }
				</p>
			) }

			<div className="omnioffloader-media-summary">
				<div className="omnioffloader-media-chip">
					<span className="omnioffloader-media-chip__label">
						{ __( 'Current Folder', 'omnioffloader' ) }
					</span>
					<strong className="omnioffloader-media-chip__value omnioffloader-media-chip__value--text">
						{ preflight ? folder : '—' }
					</strong>
				</div>
				<div className="omnioffloader-media-chip omnioffloader-media-chip--local">
					<span className="omnioffloader-media-chip__label">
						{ __( 'In Other Folders', 'omnioffloader' ) }
					</span>
					<strong className="omnioffloader-media-chip__value">
						{ preflight ? elsewhere.toLocaleString() : '—' }
					</strong>
				</div>
			</div>

			{ elsewhere > 0 && (
				<div className="omnioffloader-bulk-action">
					<ul className="omnioffloader-retention-facts">
						<li>
							{ __(
								'Files are copied inside your cloud storage, then the attachment switches to the new folder. Files on this server are not touched.',
								'omnioffloader'
							) }
						</li>
						<li>
							{ __(
								'A file that cannot be copied that way (over 5 GB, or in another bucket) is moved through this server instead: brought back, then offloaded again.',
								'omnioffloader'
							) }
						</li>
						<li>
							{ __(
								'Every attachment keeps a working copy throughout; old copies are only deleted once the new ones are confirmed.',
								'omnioffloader'
							) }
						</li>
					</ul>

					<BatchScopeSelector
						mode={ scopeMode }
						onModeChange={ setScopeMode }
						customCount={ customCount }
						onCustomCountChange={ setCustomCount }
						totalEligible={ elsewhere }
						disabled={ running || busy }
						allTitle={ __(
							'All Media in Other Folders',
							'omnioffloader'
						) }
						allDescription={ __(
							'Move every attachment that is outside the current folder.',
							'omnioffloader'
						) }
						customDescription={ __(
							'Work through a large library in smaller batches.',
							'omnioffloader'
						) }
					/>

					{ ! running && (
						<div className="omnioffloader-bulk-action__btn-wrap">
							<Button
								variant="primary"
								className="omnioffloader-btn-primary"
								disabled={ ! canStart }
								aria-describedby={
									cliJob
										? 'omnioffloader-move-disabled-reason'
										: undefined
								}
								isBusy={ busy }
								onClick={ () => void handleStart() }
							>
								{ isCustom
									? sprintf(
											/* translators: 1: batch count, 2: total count. */
											__(
												'Move Media (%1$d of %2$d)',
												'omnioffloader'
											),
											count,
											elsewhere
										)
									: sprintf(
											/* translators: %d: attachment count. */
											__(
												'Move Media (%d)',
												'omnioffloader'
											),
											count
										) }
							</Button>
							{ cliJob && (
								<p
									id="omnioffloader-move-disabled-reason"
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
									{ __( 'Moving…', 'omnioffloader' ) }
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
									/* translators: 1: moved count, 2: total count. */
									__( '%1$d of %2$d moved', 'omnioffloader' ),
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
							{ state.errors > 0
								? sprintf(
										/* translators: %d: error count. */
										_n(
											'%d item failed and kept a working copy',
											'%d items failed and kept a working copy',
											state.errors,
											'omnioffloader'
										),
										state.errors
									)
								: __( 'No errors', 'omnioffloader' ) }
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
						items={ state.recent ?? [] }
						currentItem={ state.current_item }
						actionType="move"
						running={ running }
						total={ state.total }
						processed={ state.processed }
					/>
				</div>
			) }
		</Card>
	);
}
