import { Icon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { getRoute } from '../../utils/constants';
import { DocsLink } from '../ui/DocsLink';

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
			<div className="smowp-header__docs">
				<DocsLink path="">
					{ __( 'Documentation', 'omnioffloader' ) }
				</DocsLink>
			</div>
		</header>
	);
}
