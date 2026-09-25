import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { confirmLiveSite } from '../../api/core';
import { useCore } from '../../context/CoreContext';
import type { SafeModeStatus } from '../../types';

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
		default:
			return '';
	}
}

/**
 * Banner on every plugin screen while Safe mode is on.
 */
export function SafeModeBanner() {
	const { stats, refreshStats, addNotice } = useCore();
	const [ busy, setBusy ] = useState( false );
	const safeMode = stats?.safe_mode;

	if ( ! stats?.configured || ! safeMode?.active ) {
		return null;
	}

	const confirm = () => {
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
			.catch( () => {
				addNotice(
					'error',
					__( 'Could not update Safe mode.', 'omnioffloader' )
				);
			} )
			.finally( () => setBusy( false ) );
	};

	return (
		<Notice
			status="warning"
			isDismissible={ false }
			className="smowp-safe-mode-banner"
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
					onClick={ confirm }
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
