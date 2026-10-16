import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

import { fetchOffloadErrors, retryFailedOffloads } from '../../api/offload';
import { useCore } from '../../context/CoreContext';
import type { AttachmentErrors } from '../../types';
import { Card } from '../ui/Card';

/** Element id of the card, for links that bring it into view. */
export const FAILED_CARD_ID = 'omnioffloader-failed-items';

const PER_PAGE = 20;

interface FailedItemsCardProps {
	onNavigate: ( view: string ) => void;
}

/**
 * The newest error of an attachment.
 *
 * @param item Attachment with its error log.
 */
function lastError( item: AttachmentErrors ) {
	return item.errors.length > 0
		? item.errors[ item.errors.length - 1 ]
		: null;
}

/**
 * Media with an error log: failed offloads, which can be queued again, and
 * offloaded media whose Bring Back, retention update or move failed.
 *
 * Shown only while there is something to list.
 */
export function FailedItemsCard( { onNavigate }: FailedItemsCardProps ) {
	const { stats, cliJob, refreshStats } = useCore();

	const [ items, setItems ] = useState< AttachmentErrors[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ localTotal, setLocalTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ totalPages, setTotalPages ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ queued, setQueued ] = useState< number | null >( null );

	const load = useCallback( async ( toPage: number ) => {
		try {
			const [ list, local ] = await Promise.all( [
				fetchOffloadErrors( 'all', toPage, PER_PAGE ),
				fetchOffloadErrors( 'local', 1, 1 ),
			] );
			setItems( list.items );
			setTotal( list.total );
			setTotalPages( list.totalPages );
			setLocalTotal( local.total );
			setPage( toPage );
		} catch ( e: unknown ) {
			setError(
				e instanceof Error
					? e.message
					: __( 'Could not load the failed items.', 'omnioffloader' )
			);
		}
	}, [] );

	useEffect( () => {
		void load( 1 );
	}, [ load ] );

	if (
		! stats?.configured ||
		( total === 0 && queued === null && ! error )
	) {
		return null;
	}

	const safeMode = !! stats.safe_mode?.active;
	const canRetry = ! busy && ! cliJob && ! safeMode && localTotal > 0;

	const retry = async () => {
		setBusy( true );
		setError( '' );

		try {
			const response = await retryFailedOffloads();
			setQueued( response.queued );
			refreshStats();
			await load( 1 );
		} catch ( e: unknown ) {
			setError(
				e instanceof Error
					? e.message
					: __( 'Could not start the retry.', 'omnioffloader' )
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<Card
			id={ FAILED_CARD_ID }
			title={ __( 'Failed Items', 'omnioffloader' ) }
			docs="features/failed-items/"
			description={ __(
				'Media whose last offload, Bring Back, retention update or move failed, with the error. Failed offloads can be queued again once the cause is fixed. Every item still has a working copy.',
				'omnioffloader'
			) }
		>
			{ safeMode && (
				<p className="omnioffloader-warning">
					{ __(
						'Safe mode is on, so failed offloads cannot be retried: retrying uploads to your cloud storage.',
						'omnioffloader'
					) }
				</p>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ queued !== null && (
				<Notice
					status="success"
					isDismissible
					onRemove={ () => setQueued( null ) }
					actions={ [
						{
							label: __( 'Open Offload', 'omnioffloader' ),
							onClick: () => onNavigate( 'offload' ),
						},
					] }
				>
					{ sprintf(
						/* translators: %d: number of attachments. */
						_n(
							'Queued %d failed offload again. Follow it on the Offload screen.',
							'Queued %d failed offloads again. Follow them on the Offload screen.',
							queued,
							'omnioffloader'
						),
						queued
					) }
				</Notice>
			) }

			<div className="omnioffloader-media-summary">
				<div className="omnioffloader-media-chip omnioffloader-media-chip--local">
					<span className="omnioffloader-media-chip__label">
						{ __( 'Failed Offloads', 'omnioffloader' ) }
					</span>
					<strong className="omnioffloader-media-chip__value">
						{ localTotal.toLocaleString() }
					</strong>
				</div>
				<div className="omnioffloader-media-chip">
					<span className="omnioffloader-media-chip__label">
						{ __( 'Other Errors', 'omnioffloader' ) }
					</span>
					<strong className="omnioffloader-media-chip__value">
						{ ( total - localTotal ).toLocaleString() }
					</strong>
				</div>
			</div>

			{ items.length > 0 && (
				<ul className="omnioffloader-failed-list">
					{ items.map( ( item ) => {
						const last = lastError( item );

						return (
							<li
								key={ item.id }
								className="omnioffloader-failed-list__item"
							>
								<div className="omnioffloader-failed-list__head">
									{ item.edit_url ? (
										<a href={ item.edit_url }>
											{ item.title ||
												sprintf(
													/* translators: %d: attachment ID. */
													__(
														'Attachment %d',
														'omnioffloader'
													),
													item.id
												) }
										</a>
									) : (
										<span>{ item.title }</span>
									) }
									<span
										className={
											'omnioffloader-ui-badge ' +
											( item.offloaded
												? 'omnioffloader-ui-badge--neutral'
												: 'omnioffloader-ui-badge--fail' )
										}
									>
										{ item.offloaded
											? __( 'Offloaded', 'omnioffloader' )
											: __(
													'Offload failed',
													'omnioffloader'
												) }
									</span>
								</div>
								{ last && (
									<p className="omnioffloader-failed-list__error">
										{ last.message }
										{ last.time > 0 && (
											<span className="omnioffloader-failed-list__when">
												{ ' — ' +
													new Date(
														last.time * 1000
													).toLocaleString() }
											</span>
										) }
									</p>
								) }
							</li>
						);
					} ) }
				</ul>
			) }

			{ totalPages > 1 && (
				<div className="omnioffloader-failed-list__paging">
					<Button
						variant="tertiary"
						disabled={ page <= 1 }
						onClick={ () => void load( page - 1 ) }
					>
						{ __( 'Previous', 'omnioffloader' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: number of pages. */
							__( 'Page %1$d of %2$d', 'omnioffloader' ),
							page,
							totalPages
						) }
					</span>
					<Button
						variant="tertiary"
						disabled={ page >= totalPages }
						onClick={ () => void load( page + 1 ) }
					>
						{ __( 'Next', 'omnioffloader' ) }
					</Button>
				</div>
			) }

			<div className="omnioffloader-failed-list__actions">
				<Button
					variant="primary"
					className="omnioffloader-btn-primary"
					disabled={ ! canRetry }
					isBusy={ busy }
					onClick={ () => void retry() }
				>
					{ sprintf(
						/* translators: %d: number of failed offloads. */
						__( 'Retry Failed Offloads (%d)', 'omnioffloader' ),
						localTotal
					) }
				</Button>
				<Button
					variant="secondary"
					disabled={ busy }
					onClick={ () => void load( page ) }
				>
					{ __( 'Refresh', 'omnioffloader' ) }
				</Button>
			</div>

			<p className="omnioffloader-failed-list__note">
				{ __(
					'In Media → Library (list view), the “Offload failed” filter shows the same failed offloads. From WP-CLI: wp omnioffloader errors.',
					'omnioffloader'
				) }
			</p>
		</Card>
	);
}
