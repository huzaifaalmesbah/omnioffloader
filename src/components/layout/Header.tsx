import { Icon } from '@wordpress/components';

import { getRoute } from '../../utils/constants';

interface HeaderProps {
	activeView: string;
}

export function Header( { activeView }: HeaderProps ) {
	const route = getRoute( activeView );

	return (
		<header className="smowp-header">
			<span className="smowp-header__badge">
				<Icon icon={ route.icon } size={ 24 } />
			</span>
			<div className="smowp-header__titles">
				<h1>{ route.title }</h1>
				<p>{ route.subtitle }</p>
			</div>
		</header>
	);
}
