import { TextControl, ToggleControl } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { useCore } from '../../context/CoreContext';
import { errorMessage } from '../../utils/errorHelper';

interface CredentialFieldsProps {
	provider: string;
	role?: 'primary' | 'secondary';
}

/**
 * Renders a provider's credential form generically from its field
 * definitions served over REST — adding a provider needs no JS changes.
 * @param root0
 * @param root0.provider
 * @param root0.role
 */
export function CredentialFields( {
	provider,
	role = 'primary',
}: CredentialFieldsProps ) {
	const {
		credentialDraft,
		updateCredentialValues,
		secondaryCredentialDraft,
		updateSecondaryCredentialValues,
		credentialsFields,
		loadProviderCredentials,
		addNotice,
	} = useCore();

	const cacheKey = `${ role }:${ provider }`;
	const fields = credentialsFields[ cacheKey ] ?? null;

	const currentDraft =
		role === 'secondary' ? secondaryCredentialDraft : credentialDraft;
	const updateValues =
		role === 'secondary'
			? updateSecondaryCredentialValues
			: updateCredentialValues;

	useEffect( () => {
		if ( ! credentialsFields[ cacheKey ] ) {
			loadProviderCredentials( provider, role ).catch(
				( error: unknown ) => {
					addNotice( 'error', errorMessage( error ) );
				}
			);
		}
	}, [
		provider,
		role,
		cacheKey,
		credentialsFields,
		loadProviderCredentials,
		addNotice,
	] );

	if ( ! fields ) {
		return (
			<p className="smowp-muted">
				{ __( 'Loading credentials…', 'omnioffloader' ) }
			</p>
		);
	}

	const constPrefix =
		role === 'secondary'
			? 'OMNIOFFLOADER_SECONDARY_'
			: 'OMNIOFFLOADER_';

	return (
		<div className="smowp-credential-fields">
			{ fields.map( ( field ) => {
				const draftValue = currentDraft[ field.name ];
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
							`${ constPrefix }${ provider.toUpperCase() }_${ field.name.toUpperCase() }`
					  )
					: field.description;

				if ( field.type === 'checkbox' ) {
					const isChecked = value === '1' || value === 'true';

					return (
						<ToggleControl
							key={ field.name }
							label={ field.label }
							help={ help || undefined }
							checked={ Boolean( isChecked ) }
							disabled={ field.locked }
							onChange={ ( checked: boolean ) =>
								updateValues( {
									[ field.name ]: checked ? '1' : '0',
								} )
							}
							__nextHasNoMarginBottom
						/>
					);
				}

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
							updateValues( {
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
