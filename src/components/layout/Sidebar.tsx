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

	// On narrow screens the menu scrolls sideways: centre the active item.
	// Only the menu scrolls, never the page (App owns the page position).
	useEffect( () => {
		const nav = navRef.current;
		const active = nav?.querySelector< HTMLElement >( '.is-active' );

		if ( ! nav || ! active ) {
			return;
		}

		const offset =
			active.getBoundingClientRect().left -
			nav.getBoundingClientRect().left;

		nav.scrollTo( {
			left:
				nav.scrollLeft +
				offset -
				( nav.clientWidth - active.offsetWidth ) / 2,
			behavior: 'smooth',
		} );
	}, [ activeView ] );

	return (
		<aside className="smowp-sidebar">
			<div className="smowp-sidebar__logo">
				<span className="smowp-sidebar__logo-icon">
					<Icon icon={ PLUGIN_ICON } size={ 20 } />
				</span>
				<div className="smowp-sidebar__logo-info">
					<span className="smowp-sidebar__logo-name">
						{ PLUGIN_NAME }
					</span>
					<span className="smowp-sidebar__version">
						v{ window.omnioffloader?.version ?? '' }
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
