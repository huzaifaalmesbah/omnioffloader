import { Icon, image } from '@wordpress/icons';
import { __, sprintf, _n } from '@wordpress/i18n';

import type { ProcessedMediaItem } from '../../types';

interface ActivityLogProps {
	items?: ProcessedMediaItem[];
	currentItem?: ProcessedMediaItem | null;
	actionType: 'offload' | 'restore' | 'retention' | 'move';
	running?: boolean;
	total?: number;
	processed?: number;
}

export function ActivityLog( {
	items = [],
	currentItem = null,
	actionType,
	running = false,
	total = 0,
	processed = 0,
}: ActivityLogProps ) {
	if ( items.length === 0 && ! running && ! currentItem ) {
		return null;
	}

	const percentage =
		total > 0
			? Math.min( 100, Math.round( ( processed / total ) * 100 ) )
			: 0;

	const labels = {
		offload: {
			success: __( 'Offloaded', 'omnioffloader' ),
			title: __( 'Offloaded Media Log', 'omnioffloader' ),
			working: __( 'Uploading…', 'omnioffloader' ),
		},
		restore: {
			success: __( 'Restored', 'omnioffloader' ),
			title: __( 'Restored Media Log', 'omnioffloader' ),
			working: __( 'Restoring…', 'omnioffloader' ),
		},
		retention: {
			success: __( 'Updated', 'omnioffloader' ),
			title: __( 'Retention Update Log', 'omnioffloader' ),
			working: __( 'Applying…', 'omnioffloader' ),
		},
		move: {
			success: __( 'Moved', 'omnioffloader' ),
			title: __( 'Moved Media Log', 'omnioffloader' ),
			working: __( 'Moving…', 'omnioffloader' ),
		},
	}[ actionType ];

	const successLabel = labels.success;
	const logTitle = labels.title;

	// Filter out any duplicate if an item just transitioned
	const displayItems = currentItem
		? items.filter( ( item ) => item.id !== currentItem.id )
		: items;

	let countMessage: string = sprintf(
		/* translators: %d: count of processed media items. */
		_n( '%d item', '%d items', items.length, 'omnioffloader' ),
		items.length
	);
	if ( total > 0 && running ) {
		if ( actionType === 'restore' ) {
			countMessage = sprintf(
				/* translators: 1: brought back count, 2: total count. */
				_n(
					'%1$d of %2$d brought back',
					'%1$d of %2$d brought back',
					total,
					'omnioffloader'
				),
				processed,
				total
			);
		} else if ( actionType === 'move' ) {
			countMessage = sprintf(
				/* translators: 1: moved count, 2: total count. */
				_n(
					'%1$d of %2$d moved',
					'%1$d of %2$d moved',
					total,
					'omnioffloader'
				),
				processed,
				total
			);
		} else if ( actionType === 'retention' ) {
			countMessage = sprintf(
				/* translators: 1: updated count, 2: total count. */
				_n(
					'%1$d of %2$d updated',
					'%1$d of %2$d updated',
					total,
					'omnioffloader'
				),
				processed,
				total
			);
		} else {
			countMessage = sprintf(
				/* translators: 1: uploaded count, 2: total count. */
				_n(
					'%1$d of %2$d uploaded',
					'%1$d of %2$d uploaded',
					total,
					'omnioffloader'
				),
				processed,
				total
			);
		}
	}

	return (
		<div className="omnioffloader-activity-log">
			<div className="omnioffloader-activity-log__header">
				<div className="omnioffloader-activity-log__title-wrap">
					<h3 className="omnioffloader-activity-log__title">
						{ logTitle }
					</h3>
					{ running && (
						<span className="omnioffloader-activity-log__live-pill">
							<span className="omnioffloader-ui-badge-pulse" />
							{ total > 0
								? sprintf(
										/* translators: %d: percentage complete */
										__( 'Live · %d%%', 'omnioffloader' ),
										percentage
									)
								: __( 'Live', 'omnioffloader' ) }
						</span>
					) }
				</div>
				<span className="omnioffloader-activity-log__count">
					{ countMessage }
				</span>
			</div>

			<div className="omnioffloader-activity-log__list">
				{ running && currentItem && (
					<div className="omnioffloader-activity-log__item is-processing">
						<div className="omnioffloader-activity-log__thumb">
							{ currentItem.thumb ? (
								<img
									src={ currentItem.thumb }
									alt=""
									loading="lazy"
								/>
							) : (
								<Icon icon={ image } size={ 20 } />
							) }
						</div>

						<div className="omnioffloader-activity-log__meta">
							<span
								className="omnioffloader-activity-log__filename"
								title={ currentItem.title }
							>
								{ currentItem.title }
							</span>
							<span className="omnioffloader-activity-log__sub">
								<span className="omnioffloader-activity-log__status-now">
									{ labels.working }
								</span>
							</span>
						</div>

						<div className="omnioffloader-activity-log__badge-wrap">
							<span className="omnioffloader-ui-badge omnioffloader-ui-badge--processing">
								<span className="omnioffloader-spinner-dot" />
								<span>{ labels.working }</span>
							</span>
						</div>
					</div>
				) }

				{ displayItems.length === 0 && running && ! currentItem && (
					<div className="omnioffloader-activity-log__empty">
						<span className="omnioffloader-ui-badge-pulse" />
						<p>
							{ __(
								'Processing queue… completed items will appear here.',
								'omnioffloader'
							) }
						</p>
					</div>
				) }

				{ displayItems.map( ( item ) => {
					const isOk = item.status === 'success';
					const isSkipped = item.status === 'skipped';
					let badgeVariant = 'omnioffloader-ui-badge--fail';
					if ( isOk ) {
						badgeVariant = 'omnioffloader-ui-badge--ok';
					} else if ( isSkipped ) {
						badgeVariant = 'omnioffloader-ui-badge--neutral';
					}
					const timeStr = item.timestamp
						? new Date( item.timestamp * 1000 ).toLocaleTimeString(
								// The admin's <html lang> is the WordPress locale.
								document.documentElement.lang || undefined
							)
						: '';

					return (
						<div
							key={ `${ item.id }-${ item.timestamp }` }
							className={
								'omnioffloader-activity-log__item' +
								( isOk || isSkipped ? ' is-ok' : ' is-error' )
							}
						>
							<div className="omnioffloader-activity-log__thumb">
								{ item.thumb ? (
									<img
										src={ item.thumb }
										alt=""
										loading="lazy"
									/>
								) : (
									<Icon icon={ image } size={ 20 } />
								) }
							</div>

							<div className="omnioffloader-activity-log__meta">
								<span
									className="omnioffloader-activity-log__filename"
									title={ item.title }
								>
									{ item.title }
								</span>
								<span className="omnioffloader-activity-log__sub">
									{ sprintf(
										/* translators: 1: attachment ID, 2: time. */
										__( '#%1$d · %2$s', 'omnioffloader' ),
										item.id,
										timeStr
									) }
									{ ! isOk && !! item.error && (
										<span className="omnioffloader-activity-log__error-text">
											{ ` — ${ item.error }` }
										</span>
									) }
								</span>
							</div>

							<div className="omnioffloader-activity-log__badge-wrap">
								<span
									className={
										'omnioffloader-ui-badge ' + badgeVariant
									}
								>
									{ isOk && `✓ ${ successLabel }` }
									{ isSkipped &&
										`– ${ __( 'Skipped', 'omnioffloader' ) }` }
									{ ! isOk &&
										! isSkipped &&
										`✕ ${ __( 'Failed', 'omnioffloader' ) }` }
								</span>
							</div>
						</div>
					);
				} ) }
			</div>
		</div>
	);
}
