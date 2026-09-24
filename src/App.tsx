import type { JSX } from 'react';

import { Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { addQueryArgs, getQueryArg } from '@wordpress/url';

import { MainLayout } from './components/layout/MainLayout';
import { useCore } from './context/CoreContext';
import { BringBackPage } from './pages/BringBack';
import { DashboardPage } from './pages/Dashboard';
import { OffloadPage } from './pages/Offload';
import { SettingsPage } from './pages/Settings';
import { ToolsPage } from './pages/Tools';
import { DEFAULT_VIEW, ROUTES } from './utils/constants';

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

	// Keep the view in the URL so reloads and deep links work.
	useEffect( () => {
		const url = addQueryArgs( window.location.href, { view: activeView } );
		window.history.replaceState( null, '', url );
	}, [ activeView ] );

	if ( loading ) {
		return (
			<div className="smowp-loading">
				<Spinner />
			</div>
		);
	}

	let page: JSX.Element;

	switch ( activeView ) {
		case 'settings':
			page = <SettingsPage onNavigate={ setActiveView } />;
			break;
		case 'offload':
			page = <OffloadPage />;
			break;
		case 'bring-back':
			page = <BringBackPage />;
			break;
		case 'tools':
			page = <ToolsPage onNavigate={ setActiveView } />;
			break;
		default:
			page = <DashboardPage onNavigate={ setActiveView } />;
	}

	return (
		<MainLayout activeView={ activeView } onNavigate={ setActiveView }>
			{ page }
		</MainLayout>
	);
}
