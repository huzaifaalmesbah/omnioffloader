import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { testConnection } from '../../api/provider';
import { useCore } from '../../context/CoreContext';
import { errorMessage } from '../../utils/errorHelper';

interface TestConnectionProps {
	provider: string;
}

export function TestConnection( { provider }: TestConnectionProps ) {
	const { isDirty, save, addNotice } = useCore();
	const [ testing, setTesting ] = useState( false );
	const [ result, setResult ] = useState< 'ok' | 'fail' | null >( null );

	const run = async () => {
		setTesting( true );
		setResult( null );

		try {
			// Persist pending edits first so the test uses what's on screen.
			if ( isDirty ) {
				await save();
			}

			const response = await testConnection( provider );
			setResult( response.connected ? 'ok' : 'fail' );
			addNotice(
				response.connected ? 'success' : 'error',
				response.message
			);
		} catch ( error: unknown ) {
			setResult( 'fail' );
			addNotice( 'error', errorMessage( error ) );
		} finally {
			setTesting( false );
		}
	};

	return (
		<div className="smowp-test-connection">
			<Button
				variant="secondary"
				isBusy={ testing }
				disabled={ testing }
				onClick={ () => void run() }
			>
				{ testing
					? __( 'Testing…', 'smart-media-offloader' )
					: __( 'Test Connection', 'smart-media-offloader' ) }
			</Button>

			{ result === 'ok' && (
				<span className="smowp-badge smowp-badge--ok">
					{ __( 'Connected', 'smart-media-offloader' ) }
				</span>
			) }
			{ result === 'fail' && (
				<span className="smowp-badge smowp-badge--fail">
					{ __( 'Not connected', 'smart-media-offloader' ) }
				</span>
			) }
		</div>
	);
}
