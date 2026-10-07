import { TextControl, ToggleControl } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { useCore } from '../../context/CoreContext';
import { errorMessage } from '../../utils/errorHelper';

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
	const {
		credentialDraft,
		updateCredentialValues,
		credentialsFields,
		loadProviderCredentials,
		addNotice,
		stats,
		savedSettings,
	} = useCore();

	const fields = credentialsFields[ provider ] ?? null;
	const currentDraft = credentialDraft;
	const updateValues = updateCredentialValues;

	useEffect( () => {
		if ( ! credentialsFields[ provider ] ) {
			loadProviderCredentials( provider ).catch( ( error: unknown ) => {
				addNotice( 'error', errorMessage( error ) );
			} );
		}
	}, [ provider, credentialsFields, loadProviderCredentials, addNotice ] );

	if ( ! fields ) {
		return (
			<p className="smowp-muted">
				{ __( 'Loading credentials…', 'omnioffloader' ) }
			</p>
		);
	}

	const constPrefix = 'OMNIOFFLOADER_';

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

				// Media in the saved bucket would lose its URLs; the server
				// refuses the change (omnioffloader_bucket_locked).
				const bucketChanges =
					'bucket' === field.name &&
					provider === savedSettings?.provider &&
					'' !== field.value &&
					value.trim() !== field.value &&
					( stats?.offloaded ?? 0 ) > 0 &&
					! stats?.safe_mode?.active;

				let help: React.ReactNode = field.description;

				if ( field.locked ) {
					help = sprintf(
						/* translators: %s: wp-config constant name. */
						__(
							'Locked by the %s constant in wp-config.php.',
							'omnioffloader'
						),
						`${ constPrefix }${ provider.toUpperCase() }_${ field.name.toUpperCase() }`
					);
				} else if ( bucketChanges ) {
					help = (
						<span className="smowp-field-warning">
							{ sprintf(
								/* translators: %s: bucket name. */
								__(
									'Media is offloaded to "%s". Bring it all back first (Bring Back), then change the bucket and offload again — otherwise its URLs break.',
									'omnioffloader'
								),
								field.value
							) }
						</span>
					);
				}

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
