import { createRoot } from '@wordpress/element';

import { App } from './App';
import { ConfirmProvider } from './components/ui/ConfirmDialog';
import { CoreProvider } from './context/CoreContext';
import './index.scss';

document.addEventListener( 'DOMContentLoaded', () => {
	const mount = document.getElementById( 'omnioffloader-admin-app' );

	if ( ! mount ) {
		return;
	}

	createRoot( mount ).render(
		<CoreProvider>
			<ConfirmProvider>
				<App />
			</ConfirmProvider>
		</CoreProvider>
	);
} );
