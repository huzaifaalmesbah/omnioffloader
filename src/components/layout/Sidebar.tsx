import { Icon } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { PLUGIN_ICON, PLUGIN_NAME, ROUTES } from '../../utils/constants';

interface SidebarProps {
	activeView: string;
	onNavigate: ( view: string ) => void;
}

export function Sidebar( { activeView, onNavigate }: SidebarProps ) {
	const navRef = useRef< HTMLElement | null >( null );

	useEffect( () => {
		if ( ! navRef.current ) {
			return;
		}
		const activeEl = navRef.current.querySelector(
			'.is-active'
		) as HTMLElement | null;
		if ( activeEl ) {
			activeEl.scrollIntoView( {
				behavior: 'smooth',
				block: 'nearest',
				inline: 'nearest',
			} );
		}
	}, [ activeView ] );

	return (
		<aside className="smowp-sidebar">
			<div className="smowp-sidebar__logo">
				<span className="smowp-sidebar__logo-icon">
					<Icon icon={ PLUGIN_ICON } size={ 28 } />
				</span>
				<div className="smowp-sidebar__logo-info">
					<span className="smowp-sidebar__logo-name">
						{ PLUGIN_NAME }
					</span>
					<span className="smowp-sidebar__version">
						v{ window.omnioffloader?.version || '0.1.3' }
					</span>
				</div>
			</div>

			<div className="smowp-sidebar__nav-wrapper">
				<nav
					ref={ navRef }
					className="smowp-sidebar__nav"
					aria-label={ __(
						'OmniOffloader sections',
						'omnioffloader'
					) }
				>
					{ ROUTES.map( ( route ) => (
						<button
							key={ route.view }
							type="button"
							className={
								'smowp-sidebar__item' +
								( route.view === activeView
									? ' is-active'
									: '' )
							}
							aria-current={
								route.view === activeView ? 'page' : undefined
							}
							onClick={ () => onNavigate( route.view ) }
						>
							<span className="smowp-sidebar__item-icon">
								<Icon icon={ route.icon } size={ 20 } />
							</span>
							<span className="smowp-sidebar__item-text">
								<strong>{ route.title }</strong>
								<small>{ route.subtitle }</small>
							</span>
						</button>
					) ) }
				</nav>
			</div>
		</aside>
	);
}
