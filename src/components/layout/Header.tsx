import { Icon } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getRoute } from '../../utils/constants';
import { DocsLink } from '../ui/DocsLink';

interface HeaderProps {
	activeView: string;
}

export function Header( { activeView }: HeaderProps ) {
	const route = getRoute( activeView );
	const heading = useRef< HTMLHeadingElement >( null );
	const firstView = useRef( true );

	// After switching pages, start keyboard and screen-reader users at the
	// new page's title; not on the first load, where focus stays put.
	useEffect( () => {
		if ( firstView.current ) {
			firstView.current = false;
			return;
		}

		// App owns the scroll position; focusing must not move it.
		heading.current?.focus( { preventScroll: true } );
	}, [ activeView ] );

	return (
		<header className="smowp-header">
			<span className="smowp-header__badge">
				<Icon icon={ route.icon } size={ 24 } />
			</span>
			<div className="smowp-header__titles">
				<h1 ref={ heading } tabIndex={ -1 }>
					{ route.title }
				</h1>
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
