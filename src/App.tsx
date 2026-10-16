import type { JSX } from 'react';

import { Spinner } from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs, getQueryArg } from '@wordpress/url';

import { MainLayout } from './components/layout/MainLayout';
import { useCore } from './context/CoreContext';
import { BringBackPage } from './pages/BringBack';
import { DashboardPage } from './pages/Dashboard';
import { OffloadPage } from './pages/Offload';
import { SettingsPage } from './pages/Settings';
import { ToolsPage } from './pages/Tools';
import { DEFAULT_VIEW, ROUTES } from './utils/constants';
import { revealSection } from './utils/revealSection';

function initialView(): string {
	const view = getQueryArg( window.location.href, 'view' );

	if (
		typeof view === 'string' &&
		ROUTES.some( ( route ) => route.view === view )
	) {
		return view;
	}

	return DEFAULT_VIEW;
}

export function App() {
	const { loading } = useCore();
	const [ activeView, setActiveView ] = useState( initialView );
	// Where each page was left, so returning to it shows the same area.
	const scrollPositions = useRef< Record< string, number > >( {} );
	// A section the next page should open at (a card's element id).
	const pendingSection = useRef< string | undefined >( undefined );

	const navigate = useCallback(
		( view: string, section?: string ) => {
			scrollPositions.current[ activeView ] = window.scrollY;
			pendingSection.current = section;
			setActiveView( view );
		},
		[ activeView ]
	);

	// Before the new page paints: open at the requested section, else where
	// the page was left (the top on a first visit).
	useLayoutEffect( () => {
		const section = pendingSection.current;
		pendingSection.current = undefined;

		if ( section ) {
			window.scrollTo( 0, 0 );
			return revealSection( section );
		}

		window.scrollTo( 0, scrollPositions.current[ activeView ] ?? 0 );
		return undefined;
	}, [ activeView ] );

	// Keep the view in the URL so reloads and deep links work.
	useEffect( () => {
		const url = addQueryArgs( window.location.href, { view: activeView } );
		window.history.replaceState( null, '', url );
	}, [ activeView ] );

	if ( loading ) {
		return (
			<div className="omnioffloader-loading" role="status">
				<Spinner />
				<span className="screen-reader-text">
					{ __( 'Loading…', 'omnioffloader' ) }
				</span>
			</div>
		);
	}

	let page: JSX.Element;

	switch ( activeView ) {
		case 'settings':
			page = <SettingsPage onNavigate={ navigate } />;
			break;
		case 'offload':
			page = <OffloadPage onNavigate={ navigate } />;
			break;
		case 'bring-back':
			page = <BringBackPage onNavigate={ navigate } />;
			break;
		case 'tools':
			page = <ToolsPage onNavigate={ navigate } />;
			break;
		default:
			page = <DashboardPage onNavigate={ navigate } />;
	}

	return (
		<MainLayout activeView={ activeView } onNavigate={ navigate }>
			{ page }
		</MainLayout>
	);
}
