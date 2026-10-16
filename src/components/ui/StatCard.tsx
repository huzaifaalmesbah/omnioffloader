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
				'omnioffloader-card omnioffloader-stat' +
				` omnioffloader-stat--${ variant }` +
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
			<div className="omnioffloader-stat__top">
				<span className="omnioffloader-stat__icon">
					<Icon icon={ icon } size={ 22 } />
				</span>
				{ badge && (
					<span className="omnioffloader-stat__badge">{ badge }</span>
				) }
			</div>
			<div className="omnioffloader-stat__body">
				<strong className="omnioffloader-stat__value">{ value }</strong>
				<span className="omnioffloader-stat__label">{ label }</span>
			</div>
		</div>
	);
}
