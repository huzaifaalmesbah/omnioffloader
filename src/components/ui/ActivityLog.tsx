import { Icon, image } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

import type { ProcessedMediaItem } from '../../types';

interface ActivityLogProps {
	items?: ProcessedMediaItem[];
	actionType: 'offload' | 'restore';
	running?: boolean;
}

export function ActivityLog( {
	items = [],
	actionType,
	running = false,
}: ActivityLogProps ) {
	if ( items.length === 0 && ! running ) {
		return null;
	}

	const successLabel =
		actionType === 'offload'
			? __( 'Offloaded', 'omnioffloader' )
			: __( 'Restored', 'omnioffloader' );

	const logTitle =
		actionType === 'offload'
			? __( 'Offloaded Media Log', 'omnioffloader' )
			: __( 'Restored Media Log', 'omnioffloader' );

	return (
		<div className="smowp-activity-log">
			<div className="smowp-activity-log__header">
				<div className="smowp-activity-log__title-wrap">
					<h3 className="smowp-activity-log__title">{ logTitle }</h3>
					{ running && (
						<span className="smowp-activity-log__live-pill">
							<span className="smowp-badge-pulse" />
							{ __( 'Live', 'omnioffloader' ) }
						</span>
					) }
				</div>
				<span className="smowp-activity-log__count">
					{ sprintf(
						/* translators: %d: count of processed media items. */
						__( '%d item(s)', 'omnioffloader' ),
						items.length
					) }
				</span>
			</div>

			<div className="smowp-activity-log__list">
				{ items.length === 0 && running && (
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

				{ items.map( ( item ) => {
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
										__(
											'#%1$d · %2$s',
											'omnioffloader'
										),
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
