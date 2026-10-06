import { Button, ExternalLink, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { copy } from '@wordpress/icons';

import { fetchRcloneSetup } from '../../api/provider';
import { useCore } from '../../context/CoreContext';
import type { RcloneSetupResponse } from '../../types';
import { copyText } from '../../utils/clipboard';
import { errorMessage } from '../../utils/errorHelper';
import { Card } from '../ui/Card';

const WRITE_CONFIG = 'wp omnioffloader rclone --write-config';

interface BlockProps {
	text: string;
	label: string;
	onCopy: ( text: string ) => void;
}

/**
 * A dark, copyable block of terminal text.
 *
 * @param props        Props.
 * @param props.text   Text to show and copy.
 * @param props.label  Accessible label of the copy button.
 * @param props.onCopy Copy handler.
 */
function CopyBlock( { text, label, onCopy }: BlockProps ) {
	return (
		<div className="smowp-rclone__block">
			<pre>{ text }</pre>
			<Button
				icon={ copy }
				size="small"
				label={ label }
				onClick={ () => onCopy( text ) }
			/>
		</div>
	);
}

/**
 * rclone setup for copying a large library, built from this site's
 * settings (GET /provider/rclone). The secret key is never shown.
 */
export function RcloneCard() {
	const { addNotice, savedSettings } = useCore();
	const [ setup, setSetup ] = useState< RcloneSetupResponse | null >( null );
	const [ error, setError ] = useState( '' );

	// Refetch when saved settings change: bucket, prefix and versioning
	// all feed into the commands.
	const savedKey = JSON.stringify( [
		savedSettings?.provider,
		savedSettings?.path_prefix,
		savedSettings?.path_prefix_active,
		savedSettings?.object_versioning,
	] );

	useEffect( () => {
		let active = true;

		fetchRcloneSetup()
			.then( ( response ) => {
				if ( active ) {
					setSetup( response );
					setError( '' );
				}
			} )
			.catch( ( err: unknown ) => {
				if ( active ) {
					setError( errorMessage( err ) );
				}
			} );

		return () => {
			active = false;
		};
	}, [ savedKey ] );

	const handleCopy = ( text: string ) => {
		void copyText( text ).then( ( ok ) =>
			addNotice(
				ok ? 'success' : 'error',
				ok
					? __( 'Copied to clipboard.', 'omnioffloader' )
					: __(
							'Could not copy — select the text and copy it manually.',
							'omnioffloader'
						)
			)
		);
	};

	const copyLabel = __( 'Copy', 'omnioffloader' );

	return (
		<Card
			className="smowp-rclone"
			title={ __( 'Copy a Large Library with rclone', 'omnioffloader' ) }
			description={ __(
				'For tens of thousands of attachments, copying with rclone is much faster than uploading through WordPress. Copy your media with rclone, then link it: files already in the bucket are only checked, not uploaded again. Everything below is built from this site’s settings, including its Path Prefix folder.',
				'omnioffloader'
			) }
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! setup && ! error && <Spinner /> }

			{ setup && ! setup.available && (
				<Notice status="warning" isDismissible={ false }>
					{ setup.message }
				</Notice>
			) }

			{ setup && setup.available && (
				<div className="smowp-rclone__steps">
					{ setup.warnings.map( ( warning ) => (
						<Notice
							key={ warning }
							status="warning"
							isDismissible={ false }
						>
							{ warning }
						</Notice>
					) ) }

					<section className="smowp-rclone__step">
						<h3>
							{ __(
								'1. Add this remote to rclone',
								'omnioffloader'
							) }
						</h3>
						<p>
							{ sprintf(
								/* translators: %s: placeholder text in the rclone config. */
								__(
									'Paste it into your rclone config file (the command “rclone config file” shows where it is) and replace %s with your Secret Access Key. The key is never shown here. Or run this on the server, which saves the remote with your key, readable only by your user:',
									'omnioffloader'
								),
								'PASTE_YOUR_SECRET_ACCESS_KEY_HERE'
							) }
						</p>
						<CopyBlock
							text={ WRITE_CONFIG }
							label={ copyLabel }
							onCopy={ handleCopy }
						/>
						<CopyBlock
							text={ setup.config }
							label={ copyLabel }
							onCopy={ handleCopy }
						/>
					</section>

					<section className="smowp-rclone__step">
						<h3>{ __( '2. Copy your media', 'omnioffloader' ) }</h3>
						<p>
							{ sprintf(
								/* translators: 1: local uploads folder, 2: rclone destination. */
								__(
									'From %1$s to %2$s. Only the year/month folders are copied, so logs and backups in the uploads folder stay off the bucket. Run the dry run first: it copies nothing.',
									'omnioffloader'
								),
								setup.source,
								setup.destination
							) }
						</p>
						<CopyBlock
							text={ setup.copy_dry_run }
							label={ copyLabel }
							onCopy={ handleCopy }
						/>
						<CopyBlock
							text={ setup.copy }
							label={ copyLabel }
							onCopy={ handleCopy }
						/>
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Use rclone copy, never rclone sync: sync deletes files in the bucket that are not on this server, including this folder’s ownership marker and, in a shared bucket, other sites’ files.',
								'omnioffloader'
							) }
						</Notice>
					</section>

					<section className="smowp-rclone__step">
						<h3>
							{ __(
								'3. Link the copied files',
								'omnioffloader'
							) }
						</h3>
						<p>
							{ __(
								'Check what is already in the bucket, then link it. Missing or different files are uploaded as usual.',
								'omnioffloader'
							) }
						</p>
						<CopyBlock
							text={ setup.link_dry_run }
							label={ copyLabel }
							onCopy={ handleCopy }
						/>
						<CopyBlock
							text={ setup.link }
							label={ copyLabel }
							onCopy={ handleCopy }
						/>
					</section>

					<p className="smowp-muted">
						<ExternalLink
							href={ `${ window.omnioffloader.docs_url }wp-cli/large-libraries/` }
						>
							{ __(
								'Full guide: installing rclone and copying a large library',
								'omnioffloader'
							) }
						</ExternalLink>
					</p>
				</div>
			) }
		</Card>
	);
}
