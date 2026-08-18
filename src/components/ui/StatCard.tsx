import type { JSX } from 'react';

import { Icon } from '@wordpress/components';

interface StatCardProps {
	icon: JSX.Element;
	value: string;
	label: string;
}

export function StatCard( { icon, value, label }: StatCardProps ) {
	return (
		<div className="smowp-card smowp-stat">
			<span className="smowp-stat__icon">
				<Icon icon={ icon } size={ 24 } />
			</span>
			<strong className="smowp-stat__value">{ value }</strong>
			<span className="smowp-stat__label">{ label }</span>
		</div>
	);
}
