import {
	Button,
	Icon,
	Notice,
	RadioControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import { cloudUpload } from '@wordpress/icons';

import { CredentialFields } from '../components/settings/CredentialFields';
import { TestConnection } from '../components/settings/TestConnection';
import { Card } from '../components/ui/Card';
import { DocsLink } from '../components/ui/DocsLink';
import { safeModeReasonText } from '../components/layout/SafeModeBanner';
import { fetchRetentionPreflight } from '../api/retention';
import { useCore } from '../context/CoreContext';
import { providerGuidePath } from '../utils/docs';
import { folderLabel, uploadFolder } from '../utils/folder';
import { radioKeyDown, radioTabIndex } from '../utils/radioGroup';
import {
	RetentionPolicy,
	type RetentionPreflight,
	type TransferSpeed,
} from '../types';

interface SettingsProps {
	onNavigate: ( view: string ) => void;
}

export function SettingsPage( { onNavigate }: SettingsProps ) {
	const {
		settings,
		savedSettings,
		providers,
		updateSettings,
		isDirty,
		stats,
	} = useCore();
	const [ retentionPreflight, setRetentionPreflight ] =
		useState< RetentionPreflight | null >( null );

	// Re-check once the draft is saved: the preflight describes the saved
	// policy, which is what the Tools page applies.
	useEffect( () => {
		if ( isDirty || ! stats?.configured ) {
			return;
		}

		fetchRetentionPreflight()
			.then( setRetentionPreflight )
			.catch( () => {
				// Informational only.
			} );
	}, [ isDirty, stats?.configured ] );

	if ( ! settings ) {
		return null;
	}

	const providerKeys = providers.map( ( p ) => p.key );

	// Offloaded media keeps the folder it was offloaded to; only new
	// offloads follow a changed Path Prefix.
	const savedFolder = savedSettings ? uploadFolder( savedSettings ) : '';
	const offloadedCount = stats?.offloaded ?? 0;
	const folderChanges =
		!! savedSettings &&
		offloadedCount > 0 &&
		savedFolder !== uploadFolder( settings );
	const keepsLocalFiles =
		settings.retention_policy === RetentionPolicy.RetainLocal;

	return (
		<div className="smowp-page">
			<Card
				title={ __( 'Cloud Provider', 'omnioffloader' ) }
				docs="providers/"
				description={ __(
					'Choose your cloud storage provider where your WordPress media library is offloaded.',
					'omnioffloader'
				) }
			>
				<div className="smowp-form-layout">
					<div className="smowp-provider-selector-wrap">
						<span className="smowp-provider-selector-label">
							{ __( 'Storage Provider', 'omnioffloader' ) }
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
										tabIndex={ radioTabIndex(
											providerKeys,
											provider.key,
											settings.provider
										) }
										className={
											'smowp-provider-card' +
											( isSelected ? ' is-selected' : '' )
										}
										onClick={ () =>
											updateSettings( {
												provider: provider.key,
											} )
										}
										onKeyDown={ ( e ) =>
											radioKeyDown(
												e,
												providerKeys,
												provider.key,
												( key ) =>
													updateSettings( {
														provider: key,
													} )
											)
										}
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

					{ settings.provider &&
						providers.some(
							( p ) => p.key === settings.provider
						) && (
							<>
								<p className="smowp-muted">
									{ __(
										'Need a bucket, access keys or a CDN address?',
										'omnioffloader'
									) }{ ' ' }
									<DocsLink
										path={ providerGuidePath(
											settings.provider
										) }
									>
										{ sprintf(
											/* translators: %s: provider name. */
											__(
												'Follow the %s setup guide',
												'omnioffloader'
											),
											providers.find(
												( p ) =>
													p.key === settings.provider
											)?.name ?? settings.provider
										) }
									</DocsLink>
								</p>
								<CredentialFields
									provider={ settings.provider }
								/>
								<TestConnection
									provider={ settings.provider }
								/>
							</>
						) }
				</div>
			</Card>

			<Card
				title={ __( 'Retention Policy', 'omnioffloader' ) }
				docs="features/retention-policies/"
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

				<p className="smowp-muted smowp-retention-hint">
					{ __(
						'A new policy applies to new uploads. Media you already offloaded keeps its current local files until you apply the policy to it from Tools.',
						'omnioffloader'
					) }
				</p>

				{ /* Cleanup policies delete local files, so their media always
				loads from the cloud and there is nothing to choose. */ }
				{ keepsLocalFiles && (
					<ToggleControl
						label={ __(
							'Serve Media from Cloud',
							'omnioffloader'
						) }
						help={
							<>
								{ settings.serve_from_cloud
									? __(
											'Your site loads offloaded media from your cloud storage or CDN. Turn off to keep loading it from this server, with the cloud as a backup copy.',
											'omnioffloader'
										)
									: __(
											'Your site loads media from this server; the cloud keeps a copy. Turn on when your CDN is ready — every offloaded file switches over at once, nothing is uploaded again. Clear your page cache after changing this.',
											'omnioffloader'
										) }{ ' ' }
								<DocsLink path="features/url-rewriting/#offload-now-serve-from-the-cdn-later" />
							</>
						}
						checked={ settings.serve_from_cloud }
						onChange={ ( serve_from_cloud: boolean ) =>
							updateSettings( { serve_from_cloud } )
						}
						__nextHasNoMarginBottom
					/>
				) }

				{ ! isDirty &&
					retentionPreflight &&
					retentionPreflight.eligible > 0 && (
						<div className="smowp-notice-banner smowp-notice-banner--info smowp-retention-notice">
							<span
								className="smowp-notice-banner__icon"
								aria-hidden="true"
							>
								ℹ
							</span>
							<div className="smowp-notice-banner__text">
								<strong>
									{ sprintf(
										/* translators: %d: attachment count. */
										_n(
											'%d offloaded attachment still follows an earlier retention policy.',
											'%d offloaded attachments still follow an earlier retention policy.',
											retentionPreflight.eligible,
											'omnioffloader'
										),
										retentionPreflight.eligible
									) }
								</strong>
								<p>
									{ __(
										'Apply the current policy to them to free up server space or get local copies back. Every file is checked in cloud storage before its local copy is deleted.',
										'omnioffloader'
									) }
								</p>
							</div>
							<Button
								variant="secondary"
								onClick={ () => onNavigate( 'tools' ) }
							>
								{ __(
									'Apply to existing media',
									'omnioffloader'
								) }
							</Button>
						</div>
					) }
			</Card>

			<Card title={ __( 'Behavior', 'omnioffloader' ) }>
				<ToggleControl
					label={ __( 'Background Cloud Offload', 'omnioffloader' ) }
					help={
						<>
							{ __(
								'Offload newly uploaded media in the background so local uploads finish instantly without waiting for cloud transfers.',
								'omnioffloader'
							) }{ ' ' }
							<DocsLink path="features/automatic-offload/#background-uploads" />
						</>
					}
					checked={ settings.async_upload }
					onChange={ ( async_upload: boolean ) =>
						updateSettings( { async_upload } )
					}
					__nextHasNoMarginBottom
				/>

				<div className="smowp-setting-row">
					<SelectControl
						label={ __( 'Transfer Speed', 'omnioffloader' ) }
						help={
							<>
								{ __(
									'How many files move between your server and the cloud at the same time. Faster settings finish Bulk Offload and Bring Back sooner and still use a single PHP process; choose Low on small shared hosting plans. WP-CLI uses this setting too.',
									'omnioffloader'
								) }{ ' ' }
								<DocsLink path="features/transfer-speed/" />
							</>
						}
						value={ settings.transfer_speed }
						options={ [
							{
								label: __(
									'Low — one file at a time, lowest resource use',
									'omnioffloader'
								),
								value: 'low',
							},
							{
								label: __(
									'Balanced — 3 files at a time (recommended)',
									'omnioffloader'
								),
								value: 'balanced',
							},
							{
								label: __(
									'Fast — 6 files at a time, for VPS and dedicated servers',
									'omnioffloader'
								),
								value: 'fast',
							},
							{
								label: __(
									'Custom — choose the number of files',
									'omnioffloader'
								),
								value: 'custom',
							},
						] }
						onChange={ ( transfer_speed: TransferSpeed ) =>
							updateSettings( { transfer_speed } )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>

					{ settings.transfer_speed === 'custom' && (
						<TextControl
							className="smowp-setting-row__sub"
							type="number"
							min={ 1 }
							max={ 10 }
							step={ 1 }
							label={ __(
								'Files at the same time',
								'omnioffloader'
							) }
							help={ __(
								'Between 1 and 10. Each extra file uses a little more memory and bandwidth, not another PHP process.',
								'omnioffloader'
							) }
							value={ String( settings.transfer_concurrency ) }
							onChange={ ( value: string ) => {
								const parsed = parseInt( value, 10 );
								updateSettings( {
									transfer_concurrency: Number.isNaN( parsed )
										? 1
										: Math.min( 10, Math.max( 1, parsed ) ),
								} );
							} }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					) }
				</div>

				<ToggleControl
					label={ __( 'Mirror Delete', 'omnioffloader' ) }
					help={
						<>
							{ __(
								'When an attachment is deleted in WordPress, also delete its files from the cloud, so they stop being publicly reachable and stop costing storage. Deletes cannot be undone; turn on versioning in your bucket if you want a safety net. Never runs on a staging or dev copy of the site (Safe mode).',
								'omnioffloader'
							) }{ ' ' }
							<DocsLink path="features/mirror-delete/" />
						</>
					}
					checked={ settings.mirror_delete }
					onChange={ ( mirror_delete: boolean ) =>
						updateSettings( { mirror_delete } )
					}
					__nextHasNoMarginBottom
				/>

				<ToggleControl
					label={ __( 'Object Versioning', 'omnioffloader' ) }
					help={
						<>
							{ __(
								'Add a timestamp segment to object keys so CDN caches never serve stale files after re-uploads.',
								'omnioffloader'
							) }{ ' ' }
							<DocsLink path="features/path-prefix-and-versioning/" />
						</>
					}
					checked={ settings.object_versioning }
					onChange={ ( object_versioning: boolean ) =>
						updateSettings( { object_versioning } )
					}
					__nextHasNoMarginBottom
				/>

				<ToggleControl
					label={ __( 'Custom Path Prefix', 'omnioffloader' ) }
					help={
						<>
							{ __(
								'Store objects under a custom prefix inside the bucket. Sharing one bucket between several sites (for example example.com and blog.example.com)? Give each site its own prefix: a site will not upload into a folder another site already uses.',
								'omnioffloader'
							) }{ ' ' }
							<DocsLink path="features/path-prefix-and-versioning/" />
						</>
					}
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

				{ folderChanges && (
					<Notice
						status="warning"
						isDismissible={ false }
						className="smowp-prefix-notice"
					>
						{ sprintf(
							/* translators: 1: number of offloaded attachments, 2: current bucket folder, 3: new bucket folder. */
							_n(
								'%1$s offloaded attachment stays in %2$s and keeps working from there. Only media offloaded from now on goes to %3$s. To move existing media into the new folder later, run: wp omnioffloader move --all',
								'%1$s offloaded attachments stay in %2$s and keep working from there. Only media offloaded from now on goes to %3$s. To move existing media into the new folder later, run: wp omnioffloader move --all',
								offloadedCount,
								'omnioffloader'
							),
							offloadedCount.toLocaleString(),
							folderLabel( savedFolder ),
							folderLabel( uploadFolder( settings ) )
						) }
					</Notice>
				) }
			</Card>

			<Card
				title={ __( 'Staging & Dev Copies', 'omnioffloader' ) }
				docs="features/safe-mode/"
			>
				<p className="smowp-muted">
					{ stats?.safe_mode?.active
						? __(
								'Safe mode is on for this site.',
								'omnioffloader'
							) +
							' ' +
							safeModeReasonText( stats.safe_mode )
						: __(
								'This site is treated as the live site.',
								'omnioffloader'
							) }
				</p>
				<ToggleControl
					label={ __(
						'Protect the live site when this is a copy',
						'omnioffloader'
					) }
					help={
						stats?.safe_mode?.locked
							? __(
									'Set by OMNIOFFLOADER_SAFE_MODE in wp-config.php.',
									'omnioffloader'
								)
							: __(
									'When this site is a staging, development or local copy (its address changed, WordPress reports a non-production environment, or it runs on a local address), nothing is uploaded to or deleted from your cloud storage, so the live site’s files are never overwritten or removed. Media already in the cloud still loads from it, and new uploads stay on this server.',
									'omnioffloader'
								)
					}
					checked={ settings.protect_copies }
					disabled={ !! stats?.safe_mode?.locked }
					onChange={ ( protect_copies: boolean ) =>
						updateSettings( { protect_copies } )
					}
					__nextHasNoMarginBottom
				/>
				<ToggleControl
					label={ __(
						'Allow Bring Back on a copy (download only)',
						'omnioffloader'
					) }
					help={ __(
						'Off: in Safe mode, Bring Back is hidden everywhere (Media Library, media editor, Bring Back page, WP-CLI). On: it downloads media from the cloud to this copy, and the cloud copy is always kept, even if "delete from cloud" is requested.',
						'omnioffloader'
					) }
					checked={ settings.safe_mode_bring_back }
					disabled={
						! settings.protect_copies && ! stats?.safe_mode?.locked
					}
					onChange={ ( safe_mode_bring_back: boolean ) =>
						updateSettings( { safe_mode_bring_back } )
					}
					__nextHasNoMarginBottom
				/>
			</Card>

			<Card
				title={ __( 'Uninstall', 'omnioffloader' ) }
				docs="help/safety/#deleting-uninstalling-the-plugin"
			>
				<ToggleControl
					label={ __(
						'Remove all data on uninstall',
						'omnioffloader'
					) }
					help={ __(
						'Delete plugin settings and credentials when the plugin is deleted. Offload records are also removed, except for media that exists only in the cloud, so it can still be recovered after reinstalling. Cloud files are never touched.',
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
