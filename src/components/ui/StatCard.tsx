import type { JSX } from 'react';

import { Icon } from '@wordpress/components';

interface StatCardProps {
	icon: JSX.Element;
	value: string;
	label: string;
	variant?: 'default' | 'success' | 'local';
	badge?: string;
	onClick?: () => void;
}

export function StatCard( {
	icon,
	value,
	label,
	variant = 'default',
	badge,
	onClick,
}: StatCardProps ) {
	const isClickable = typeof onClick === 'function';

	return (
		<div
			className={
				'smowp-card smowp-stat' +
				` smowp-stat--${ variant }` +
				( isClickable ? ' is-clickable' : '' )
			}
			role={ isClickable ? 'button' : undefined }
			tabIndex={ isClickable ? 0 : undefined }
			onClick={ onClick }
			onKeyDown={
				isClickable
					? ( e ) => {
							if ( e.key === 'Enter' || e.key === ' ' ) {
								e.preventDefault();
								onClick();
							}
						}
					: undefined
			}
		>
			<div className="smowp-stat__top">
				<span className="smowp-stat__icon">
					<Icon icon={ icon } size={ 22 } />
				</span>
				{ badge && (
					<span className="smowp-stat__badge">{ badge }</span>
				) }
			</div>
			<div className="smowp-stat__body">
				<strong className="smowp-stat__value">{ value }</strong>
				<span className="smowp-stat__label">{ label }</span>
			</div>
		</div>
	);
}
