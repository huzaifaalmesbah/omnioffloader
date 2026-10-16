import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { confirmLiveSite, takeOverFolder } from '../../api/core';
import { useCore } from '../../context/CoreContext';
import type { SafeModeStatus } from '../../types';
import { errorMessage } from '../../utils/errorHelper';
import { useConfirm } from '../ui/ConfirmDialog';

/**
 * Why this site is treated as a copy, in words.
 *
 * @param status Safe mode state from the stats endpoint.
 */
export function safeModeReasonText( status: SafeModeStatus ): string {
	switch ( status.reason ) {
		case 'constant':
			return __(
				'OMNIOFFLOADER_SAFE_MODE is set to true in wp-config.php.',
				'omnioffloader'
			);
		case 'environment':
			return sprintf(
				/* translators: %s: environment type, e.g. "staging". */
				__(
					'WordPress reports this as a "%s" site (WP_ENVIRONMENT_TYPE).',
					'omnioffloader'
				),
				status.environment
			);
		case 'local':
			return sprintf(
				/* translators: %s: site address. */
				__( '%s is a local development address.', 'omnioffloader' ),
				status.current_address
			);
		case 'address':
			return sprintf(
				/* translators: 1: live site address, 2: this site's address. */
				__(
					'The site address changed from %1$s to %2$s, so this looks like a copy of the live site.',
					'omnioffloader'
				),
				status.live_address,
				status.current_address
			);
		case 'folder':
			return status.folder
				? sprintf(
						/* translators: 1: folder name in the bucket, 2: other site's address. */
						__(
							'The bucket folder "%1$s" is already used by another site (%2$s).',
							'omnioffloader'
						),
						status.folder,
						status.folder_owner
					)
				: sprintf(
						/* translators: %s: other site's address. */
						__(
							'The root of the bucket is already used by another site (%s).',
							'omnioffloader'
						),
						status.folder_owner
					);
		default:
			return '';
	}
}

interface SafeModeBannerProps {
	onNavigate: ( view: string ) => void;
}

/**
 * Banner on every plugin screen while Safe mode is on.
 *
 * @param props            Props.
 * @param props.onNavigate Switches the app to another screen.
 */
export function SafeModeBanner( { onNavigate }: SafeModeBannerProps ) {
	const { stats, refreshStats, addNotice } = useCore();
	const askConfirm = useConfirm();
	const [ busy, setBusy ] = useState( false );
	const safeMode = stats?.safe_mode;

	if ( ! stats?.configured || ! safeMode?.active ) {
		return null;
	}

	if ( 'folder' === safeMode.reason ) {
		const takeOver = async () => {
			const ok = await askConfirm( {
				title: __( 'Take over this folder?', 'omnioffloader' ),
				message: sprintf(
					/* translators: %s: other site's address. */
					__(
						'Only continue if %s no longer uses this folder, or it is this same site at its old address. If both sites keep writing here, they will overwrite and delete each other’s files.',
						'omnioffloader'
					),
					safeMode.folder_owner
				),
				confirmLabel: __( 'Take over the folder', 'omnioffloader' ),
				isDestructive: true,
			} );

			if ( ! ok ) {
				return;
			}

			setBusy( true );
			takeOverFolder()
				.then( () => {
					refreshStats( true );
					addNotice(
						'success',
						__(
							'The folder now belongs to this site. Uploads and deletes are back on.',
							'omnioffloader'
						)
					);
				} )
				.catch( ( error: unknown ) => {
					addNotice( 'error', errorMessage( error ) );
				} )
				.finally( () => setBusy( false ) );
		};

		return (
			<Notice
				status="error"
				isDismissible={ false }
				className="omnioffloader-safe-mode-banner"
			>
				<p>
					<strong>
						{ __(
							'Another site uses this bucket folder.',
							'omnioffloader'
						) }
					</strong>{ ' ' }
					{ safeModeReasonText( safeMode ) }{ ' ' }
					{ __(
						'Sites that share one bucket each need their own Path Prefix, or they overwrite and delete each other’s files. Until this is fixed, nothing is uploaded to or deleted from the cloud; media already there still loads, and new uploads stay on this server.',
						'omnioffloader'
					) }
				</p>
				<div className="omnioffloader-safe-mode-banner__actions">
					<Button
						variant="primary"
						onClick={ () => onNavigate( 'settings' ) }
					>
						{ __( 'Choose a Path Prefix', 'omnioffloader' ) }
					</Button>
					<Button
						variant="tertiary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ () => void takeOver() }
					>
						{ __(
							'This folder belongs to this site',
							'omnioffloader'
						) }
					</Button>
				</div>
			</Notice>
		);
	}

	const confirm = async () => {
		const ok = await askConfirm( {
			title: __( 'Is this the live site?', 'omnioffloader' ),
			message: sprintf(
				/* translators: 1: this site's address, 2: live site address. */
				__(
					'Only continue if the live site really moved to %1$s. If this is a staging or development copy of %2$s, it will upload to and delete from the live site’s cloud storage, and the live site may stop uploading.',
					'omnioffloader'
				),
				safeMode.current_address,
				safeMode.live_address
			),
			confirmLabel: __( 'Yes, this is the live site', 'omnioffloader' ),
			isDestructive: true,
		} );

		if ( ! ok ) {
			return;
		}

		setBusy( true );
		confirmLiveSite()
			.then( () => {
				refreshStats( true );
				addNotice(
					'success',
					__(
						'This site is now recorded as the live site. Safe mode is off.',
						'omnioffloader'
					)
				);
			} )
			.catch( ( error: unknown ) => {
				addNotice( 'error', errorMessage( error ) );
			} )
			.finally( () => setBusy( false ) );
	};

	return (
		<Notice
			status="warning"
			isDismissible={ false }
			className="omnioffloader-safe-mode-banner"
		>
			<p>
				<strong>{ __( 'Safe mode is on.', 'omnioffloader' ) }</strong>{ ' ' }
				{ safeModeReasonText( safeMode ) }{ ' ' }
				{ __(
					'Nothing is uploaded to or deleted from your cloud storage, so the live site’s files stay safe. Media already in the cloud still loads from it, and new uploads stay on this server.',
					'omnioffloader'
				) }{ ' ' }
				{ safeMode.bring_back
					? __(
							'Bring Back is allowed and only downloads: the cloud copy is always kept.',
							'omnioffloader'
						)
					: __(
							'Bring Back is turned off; you can allow it (download only) in Settings.',
							'omnioffloader'
						) }
			</p>
			{ 'address' === safeMode.reason && (
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => void confirm() }
				>
					{ __(
						'This is the live site (it moved to a new address)',
						'omnioffloader'
					) }
				</Button>
			) }
		</Notice>
	);
}
