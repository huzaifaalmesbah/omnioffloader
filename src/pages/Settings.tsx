import {
	RadioControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { CredentialFields } from '../components/settings/CredentialFields';
import { TestConnection } from '../components/settings/TestConnection';
import { Card } from '../components/ui/Card';
import { useCore } from '../context/CoreContext';
import { RetentionPolicy } from '../types';

export function SettingsPage() {
	const { settings, providers, updateSettings } = useCore();

	if ( ! settings ) {
		return null;
	}

	const providerOptions = [
		{
			label: __( '— Select a provider —', 'smart-media-offloader' ),
			value: '',
		},
		...providers.map( ( provider ) => ( {
			label: provider.name,
			value: provider.key,
		} ) ),
	];

	return (
		<div className="smowp-page">
			<Card
				title={ __( 'Cloud Provider', 'smart-media-offloader' ) }
				description={ __(
					'Choose where your media is stored. More providers are coming — each release adds one.',
					'smart-media-offloader'
				) }
			>
				<SelectControl
					label={ __( 'Provider', 'smart-media-offloader' ) }
					value={ settings.provider }
					options={ providerOptions }
					onChange={ ( provider: string ) =>
						updateSettings( { provider } )
					}
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>

				{ settings.provider && (
					<>
						<CredentialFields provider={ settings.provider } />
						<TestConnection provider={ settings.provider } />
					</>
				) }
			</Card>

			<Card
				title={ __( 'Retention Policy', 'smart-media-offloader' ) }
				description={ __(
					'What happens to local files after a successful offload.',
					'smart-media-offloader'
				) }
			>
				<RadioControl
					selected={ String( settings.retention_policy ) }
					options={ [
						{
							label: __(
								'Retain Local Files — keep everything on the server (cloud is a mirror)',
								'smart-media-offloader'
							),
							value: String( RetentionPolicy.RetainLocal ),
						},
						{
							label: __(
								'Smart Local Cleanup — delete generated thumbnails, keep originals',
								'smart-media-offloader'
							),
							value: String( RetentionPolicy.SmartCleanup ),
						},
						{
							label: __(
								'Full Cloud Migration — remove all local copies after offload',
								'smart-media-offloader'
							),
							value: String( RetentionPolicy.FullMigration ),
						},
					] }
					onChange={ ( value: string ) =>
						updateSettings( {
							retention_policy: Number(
								value
							) as RetentionPolicy,
						} )
					}
				/>
			</Card>

			<Card title={ __( 'Behavior', 'smart-media-offloader' ) }>
				<ToggleControl
					label={ __( 'Mirror Delete', 'smart-media-offloader' ) }
					help={ __(
						'When an attachment is deleted in WordPress, also delete its files from the cloud.',
						'smart-media-offloader'
					) }
					checked={ settings.mirror_delete }
					onChange={ ( mirror_delete: boolean ) =>
						updateSettings( { mirror_delete } )
					}
					__nextHasNoMarginBottom
				/>

				<ToggleControl
					label={ __( 'Object Versioning', 'smart-media-offloader' ) }
					help={ __(
						'Add a timestamp segment to object keys so CDN caches never serve stale files after re-uploads.',
						'smart-media-offloader'
					) }
					checked={ settings.object_versioning }
					onChange={ ( object_versioning: boolean ) =>
						updateSettings( { object_versioning } )
					}
					__nextHasNoMarginBottom
				/>

				<ToggleControl
					label={ __(
						'Custom Path Prefix',
						'smart-media-offloader'
					) }
					help={ __(
						'Store objects under a custom prefix inside the bucket.',
						'smart-media-offloader'
					) }
					checked={ settings.path_prefix_active }
					onChange={ ( path_prefix_active: boolean ) =>
						updateSettings( { path_prefix_active } )
					}
					__nextHasNoMarginBottom
				/>

				{ settings.path_prefix_active && (
					<TextControl
						label={ __( 'Path Prefix', 'smart-media-offloader' ) }
						value={ settings.path_prefix }
						placeholder="wp-content/uploads"
						onChange={ ( path_prefix: string ) =>
							updateSettings( { path_prefix } )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				) }
			</Card>

			<Card title={ __( 'Uninstall', 'smart-media-offloader' ) }>
				<ToggleControl
					label={ __(
						'Remove all data on uninstall',
						'smart-media-offloader'
					) }
					help={ __(
						'Delete all plugin settings, credentials and offload markers when the plugin is deleted. Cloud files are never touched.',
						'smart-media-offloader'
					) }
					checked={ settings.remove_data_on_uninstall }
					onChange={ ( remove_data_on_uninstall: boolean ) =>
						updateSettings( { remove_data_on_uninstall } )
					}
					__nextHasNoMarginBottom
				/>
			</Card>
		</div>
	);
}
