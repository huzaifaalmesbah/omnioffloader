import {
	Icon,
	RadioControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { cloudUpload } from '@wordpress/icons';

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

	return (
		<div className="smowp-page">
			<Card
				title={ __( 'Cloud Provider', 'omnioffloader' ) }
				description={ __(
					'Choose where your media is stored. More providers are coming — each release adds one.',
					'omnioffloader'
				) }
			>
				<div className="smowp-form-layout">
					<div className="smowp-provider-selector-wrap">
						<span className="smowp-provider-selector-label">
							{ __(
								'Storage Provider',
								'omnioffloader'
							) }
						</span>
						<div
							className="smowp-provider-selector-grid"
							role="radiogroup"
							aria-label={ __(
								'Cloud Storage Provider',
								'omnioffloader'
							) }
						>
							{ providers.map( ( provider ) => {
								const isSelected =
									settings.provider === provider.key;
								return (
									<div
										key={ provider.key }
										role="radio"
										aria-checked={ isSelected }
										tabIndex={ 0 }
										className={
											'smowp-provider-card' +
											( isSelected ? ' is-selected' : '' )
										}
										onClick={ () =>
											updateSettings( {
												provider: provider.key,
											} )
										}
										onKeyDown={ ( e ) => {
											if (
												e.key === ' ' ||
												e.key === 'Enter'
											) {
												e.preventDefault();
												updateSettings( {
													provider: provider.key,
												} );
											}
										} }
									>
										<div className="smowp-provider-card__head">
											<div className="smowp-provider-card__icon">
												<Icon
													icon={ cloudUpload }
													size={ 22 }
												/>
											</div>
											<div className="smowp-provider-card__title-wrap">
												<strong>
													{ provider.name }
												</strong>
												<span className="smowp-provider-card__sub">
													{ __(
														'S3-compatible Object Storage',
														'omnioffloader'
													) }
												</span>
											</div>
											<div className="smowp-provider-card__check">
												<span className="smowp-radio-circle">
													{ isSelected && (
														<span className="smowp-radio-dot" />
													) }
												</span>
											</div>
										</div>
									</div>
								);
							} ) }
						</div>
					</div>

					{ settings.provider && (
						<>
							<CredentialFields provider={ settings.provider } />
							<TestConnection provider={ settings.provider } />
						</>
					) }
				</div>
			</Card>

			<Card
				title={ __( 'Retention Policy', 'omnioffloader' ) }
				description={ __(
					'What happens to local files after a successful offload.',
					'omnioffloader'
				) }
			>
				<RadioControl
					selected={ String( settings.retention_policy ) }
					options={ [
						{
							label: __(
								'Retain Local Files — keep everything on the server (cloud is a mirror)',
								'omnioffloader'
							),
							value: String( RetentionPolicy.RetainLocal ),
						},
						{
							label: __(
								'Smart Local Cleanup — delete generated thumbnails, keep originals',
								'omnioffloader'
							),
							value: String( RetentionPolicy.SmartCleanup ),
						},
						{
							label: __(
								'Full Cloud Migration — remove all local copies after offload',
								'omnioffloader'
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

			<Card title={ __( 'Behavior', 'omnioffloader' ) }>
				<ToggleControl
					label={ __( 'Mirror Delete', 'omnioffloader' ) }
					help={ __(
						'When an attachment is deleted in WordPress, also delete its files from the cloud.',
						'omnioffloader'
					) }
					checked={ settings.mirror_delete }
					onChange={ ( mirror_delete: boolean ) =>
						updateSettings( { mirror_delete } )
					}
					__nextHasNoMarginBottom
				/>

				<ToggleControl
					label={ __( 'Object Versioning', 'omnioffloader' ) }
					help={ __(
						'Add a timestamp segment to object keys so CDN caches never serve stale files after re-uploads.',
						'omnioffloader'
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
						'omnioffloader'
					) }
					help={ __(
						'Store objects under a custom prefix inside the bucket.',
						'omnioffloader'
					) }
					checked={ settings.path_prefix_active }
					onChange={ ( path_prefix_active: boolean ) =>
						updateSettings( { path_prefix_active } )
					}
					__nextHasNoMarginBottom
				/>

				{ settings.path_prefix_active && (
					<TextControl
						label={ __( 'Path Prefix', 'omnioffloader' ) }
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

			<Card title={ __( 'Uninstall', 'omnioffloader' ) }>
				<ToggleControl
					label={ __(
						'Remove all data on uninstall',
						'omnioffloader'
					) }
					help={ __(
						'Delete all plugin settings, credentials and offload markers when the plugin is deleted. Cloud files are never touched.',
						'omnioffloader'
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
