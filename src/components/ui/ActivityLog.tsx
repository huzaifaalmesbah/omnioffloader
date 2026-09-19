import { Icon, image } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

import type { ProcessedMediaItem } from '../../types';

interface ActivityLogProps {
	items?: ProcessedMediaItem[];
	currentItem?: ProcessedMediaItem | null;
	actionType: 'offload' | 'restore';
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

	const successLabel =
		actionType === 'offload'
			? __( 'Offloaded', 'omnioffloader' )
			: __( 'Restored', 'omnioffloader' );

	const logTitle =
		actionType === 'offload'
			? __( 'Offloaded Media Log', 'omnioffloader' )
			: __( 'Restored Media Log', 'omnioffloader' );

	// Filter out any duplicate if an item just transitioned
	const displayItems = currentItem
		? items.filter( ( item ) => item.id !== currentItem.id )
		: items;

	let countMessage: string = sprintf(
		/* translators: %d: count of processed media items. */
		__( '%d item(s)', 'omnioffloader' ),
		items.length
	);
	if ( total > 0 && running ) {
		countMessage =
			actionType === 'restore'
				? sprintf(
						/* translators: 1: brought back count, 2: total count. */
						__( '%1$d of %2$d brought back', 'omnioffloader' ),
						processed,
						total
				  )
				: sprintf(
						/* translators: 1: uploaded count, 2: total count. */
						__( '%1$d of %2$d uploaded', 'omnioffloader' ),
						processed,
						total
				  );
	}

	return (
		<div className="smowp-activity-log">
			<div className="smowp-activity-log__header">
				<div className="smowp-activity-log__title-wrap">
					<h3 className="smowp-activity-log__title">{ logTitle }</h3>
					{ running && (
						<span className="smowp-activity-log__live-pill">
							<span className="smowp-badge-pulse" />
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
				<span className="smowp-activity-log__count">
					{ countMessage }
				</span>
			</div>

			<div className="smowp-activity-log__list">
				{ running && currentItem && (
					<div className="smowp-activity-log__item is-processing">
						<div className="smowp-activity-log__thumb">
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

						<div className="smowp-activity-log__meta">
							<span
								className="smowp-activity-log__filename"
								title={ currentItem.title }
							>
								{ currentItem.title }
							</span>
							<span className="smowp-activity-log__sub">
								<span className="smowp-activity-log__status-now">
									{ actionType === 'restore'
										? __( 'Restoring…', 'omnioffloader' )
										: __( 'Uploading…', 'omnioffloader' ) }
								</span>
							</span>
						</div>

						<div className="smowp-activity-log__badge-wrap">
							<span className="smowp-badge smowp-badge--processing">
								<span className="smowp-spinner-dot" />
								<span>
									{ actionType === 'restore'
										? __( 'Restoring…', 'omnioffloader' )
										: __( 'Uploading…', 'omnioffloader' ) }
								</span>
							</span>
						</div>
					</div>
				) }

				{ displayItems.length === 0 && running && ! currentItem && (
					<div className="smowp-activity-log__empty">
						<span className="smowp-badge-pulse" />
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
					const timeStr = item.timestamp
						? new Date( item.timestamp * 1000 ).toLocaleTimeString()
						: '';

					return (
						<div
							key={ `${ item.id }-${ item.timestamp }` }
							className={
								'smowp-activity-log__item' +
								( isOk ? ' is-ok' : ' is-error' )
							}
						>
							<div className="smowp-activity-log__thumb">
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

							<div className="smowp-activity-log__meta">
								<span
									className="smowp-activity-log__filename"
									title={ item.title }
								>
									{ item.title }
								</span>
								<span className="smowp-activity-log__sub">
									{ sprintf(
										/* translators: 1: attachment ID, 2: time. */
										__( '#%1$d · %2$s', 'omnioffloader' ),
										item.id,
										timeStr
									) }
									{ ! isOk && item.error && (
										<span className="smowp-activity-log__error-text">
											{ ` — ${ item.error }` }
										</span>
									) }
								</span>
							</div>

							<div className="smowp-activity-log__badge-wrap">
								<span
									className={
										'smowp-badge ' +
										( isOk
											? 'smowp-badge--ok'
											: 'smowp-badge--fail' )
									}
								>
									{ isOk
										? `✓ ${ successLabel }`
										: `✕ ${ __(
												'Failed',
												'omnioffloader'
										  ) }` }
								</span>
							</div>
						</div>
					);
				} ) }
			</div>
		</div>
	);
}
