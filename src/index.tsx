import { createRoot } from '@wordpress/element';

import { App } from './App';
import { CoreProvider } from './context/CoreContext';
import './index.scss';

document.addEventListener( 'DOMContentLoaded', () => {
	const mount = document.getElementById( 'smowp-admin-app' );

	if ( ! mount ) {
		return;
	}

	createRoot( mount ).render(
		<CoreProvider>
			<App />
		</CoreProvider>
	);
} );
