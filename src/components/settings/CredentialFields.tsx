import { TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { fetchCredentials } from '../../api/provider';
import { useCore } from '../../context/CoreContext';
import { errorMessage } from '../../utils/errorHelper';
import type { CredentialField } from '../../types';

interface CredentialFieldsProps {
	provider: string;
}

/**
 * Renders a provider's credential form generically from its field
 * definitions served over REST — adding a provider needs no JS changes.
 * @param root0
 * @param root0.provider
 */
export function CredentialFields( { provider }: CredentialFieldsProps ) {
	const { credentialDraft, updateCredentialValues, addNotice } = useCore();
	const [ fields, setFields ] = useState< CredentialField[] | null >( null );

	useEffect( () => {
		setFields( null );

		fetchCredentials( provider )
			.then( ( response ) => setFields( response.fields ) )
			.catch( ( error: unknown ) => {
				addNotice( 'error', errorMessage( error ) );
			} );
	}, [ provider, addNotice ] );

	if ( ! fields ) {
		return (
			<p className="smowp-muted">
				{ __( 'Loading credentials…', 'omnioffloader' ) }
			</p>
		);
	}

	return (
		<div className="smowp-credential-fields">
			{ fields.map( ( field ) => {
				const draftValue = credentialDraft[ field.name ];
				const value =
					draftValue !== undefined ? draftValue : field.value;

				const placeholder =
					field.type === 'password' && field.is_set
						? __(
								'•••••••• (saved — leave empty to keep)',
								'omnioffloader'
						  )
						: field.placeholder;

				const help = field.locked
					? sprintf(
							/* translators: %s: wp-config constant name. */
							__(
								'Locked by the %s constant in wp-config.php.',
								'omnioffloader'
							),
							`SMOWP_${ provider.toUpperCase() }_${ field.name.toUpperCase() }`
					  )
					: field.description;

				return (
					<TextControl
						key={ field.name }
						label={ field.label }
						type={ field.type }
						value={ value }
						placeholder={ placeholder }
						help={ help || undefined }
						disabled={ field.locked }
						onChange={ ( newValue: string ) =>
							updateCredentialValues( {
								[ field.name ]: newValue,
							} )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				);
			} ) }
		</div>
	);
}
