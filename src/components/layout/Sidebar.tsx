import { Icon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { PLUGIN_ICON, PLUGIN_NAME, ROUTES } from '../../utils/constants';

interface SidebarProps {
	activeView: string;
	onNavigate: ( view: string ) => void;
}

export function Sidebar( { activeView, onNavigate }: SidebarProps ) {
	return (
		<aside className="smowp-sidebar">
			<div className="smowp-sidebar__logo">
				<span className="smowp-sidebar__logo-icon">
					<Icon icon={ PLUGIN_ICON } size={ 28 } />
				</span>
				<span className="smowp-sidebar__logo-name">
					{ PLUGIN_NAME }
				</span>
			</div>

			<nav
				className="smowp-sidebar__nav"
				aria-label={ __( 'OmniOffloader sections', 'omnioffloader' ) }
			>
				{ ROUTES.map( ( route ) => (
					<button
						key={ route.view }
						type="button"
						className={
							'smowp-sidebar__item' +
							( route.view === activeView ? ' is-active' : '' )
						}
						aria-current={
							route.view === activeView ? 'page' : undefined
						}
						onClick={ () => onNavigate( route.view ) }
					>
						<span className="smowp-sidebar__item-icon">
							<Icon icon={ route.icon } size={ 22 } />
						</span>
						<span className="smowp-sidebar__item-text">
							<strong>{ route.title }</strong>
							<small>{ route.subtitle }</small>
						</span>
					</button>
				) ) }
			</nav>
		</aside>
	);
}
