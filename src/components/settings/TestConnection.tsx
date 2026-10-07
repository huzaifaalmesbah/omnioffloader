import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { testConnection } from '../../api/provider';
import { useCore } from '../../context/CoreContext';
import { errorMessage } from '../../utils/errorHelper';

interface TestConnectionProps {
	provider: string;
}

export function TestConnection( { provider }: TestConnectionProps ) {
	const { credentialDraft } = useCore();
	const [ testing, setTesting ] = useState( false );
	const [ result, setResult ] = useState< 'ok' | 'fail' | null >( null );
	const [ errorDetail, setErrorDetail ] = useState< string | null >( null );
	// Another site sharing the bucket already uses this site's folder.
	const [ folderWarning, setFolderWarning ] = useState< string | null >(
		null
	);

	// Reset badge and error message whenever credentials are edited or provider changes.
	useEffect( () => {
		setResult( null );
		setErrorDetail( null );
		setFolderWarning( null );
	}, [ credentialDraft, provider ] );

	const run = async () => {
		setTesting( true );
		setResult( null );
		setErrorDetail( null );
		setFolderWarning( null );

		try {
			const response = await testConnection( provider, credentialDraft );
			if ( response.connected ) {
				setResult( 'ok' );
				setErrorDetail( null );
				setFolderWarning(
					response.folder?.status === 'other'
						? response.folder.message
						: null
				);
			} else {
				setResult( 'fail' );
				setErrorDetail( response.message );
			}
		} catch ( error: unknown ) {
			setResult( 'fail' );
			setErrorDetail( errorMessage( error ) );
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
					? __( 'Testing…', 'omnioffloader' )
					: __( 'Test Connection', 'omnioffloader' ) }
			</Button>

			{ /* A live region, so the result is read out after the test. */ }
			<span role="status" aria-live="polite">
				{ result === 'ok' && (
					<span className="smowp-badge smowp-badge--ok">
						{ __( 'Connected', 'omnioffloader' ) }
					</span>
				) }
				{ result === 'fail' && (
					<span className="smowp-badge smowp-badge--fail">
						{ __( 'Not connected', 'omnioffloader' ) }
					</span>
				) }
			</span>

			{ result === 'ok' && folderWarning && (
				<div className="smowp-test-connection__error" role="alert">
					<span className="smowp-test-connection__error-text">
						{ folderWarning }
					</span>
				</div>
			) }

			{ result === 'fail' && errorDetail && (
				<div className="smowp-test-connection__error" role="alert">
					<span className="smowp-test-connection__error-text">
						{ errorDetail }
					</span>
				</div>
			) }
		</div>
	);
}
