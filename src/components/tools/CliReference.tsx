import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { copy } from '@wordpress/icons';

import { useCore } from '../../context/CoreContext';
import { Card } from '../ui/Card';

interface CliOption {
	flag: string;
	description: string;
}

interface CliExample {
	command: string;
	description: string;
}

interface CliCommand {
	name: string;
	summary: string;
	/** Admin screen that runs the same job, as a view key. */
	view: string;
	viewLabel: string;
	examples: CliExample[];
	options: CliOption[];
}

/**
 * Mirrors the docblocks of includes/Cli/*Command.php — keep both in sync.
 */
function getCommands(): CliCommand[] {
	return [
		{
			name: 'wp omnioffloader status',
			summary: __(
				'Show the provider, bucket, connection check, media counts and any running bulk job.',
				'omnioffloader'
			),
			view: 'dashboard',
			viewLabel: __( 'Open Dashboard', 'omnioffloader' ),
			examples: [
				{
					command: 'wp omnioffloader status',
					description: __( 'Quick health check.', 'omnioffloader' ),
				},
			],
			options: [],
		},
		{
			name: 'wp omnioffloader stop',
			summary: __(
				'Stop the running offload, Bring Back or retention job, from any terminal. A WP-CLI run finishes the file it is transferring first; an admin job is cancelled.',
				'omnioffloader'
			),
			view: 'dashboard',
			viewLabel: __( 'Open Dashboard', 'omnioffloader' ),
			examples: [
				{
					command: 'wp omnioffloader stop',
					description: __(
						'Stop it and wait until it has ended.',
						'omnioffloader'
					),
				},
			],
			options: [
				{
					flag: '--wait=<seconds>',
					description: __(
						'How long to wait for the current file (default 120).',
						'omnioffloader'
					),
				},
			],
		},
		{
			name: 'wp omnioffloader offload',
			summary: __(
				'Upload media to the cloud with a progress bar. Local files follow the saved retention policy after each verified upload.',
				'omnioffloader'
			),
			view: 'offload',
			viewLabel: __( 'Open Offload', 'omnioffloader' ),
			examples: [
				{
					command: 'wp omnioffloader offload --all --dry-run',
					description: __(
						'List what would be offloaded.',
						'omnioffloader'
					),
				},
				{
					command: 'wp omnioffloader offload --all',
					description: __(
						'Offload every local attachment.',
						'omnioffloader'
					),
				},
				{
					command: 'wp omnioffloader offload --all --limit=500',
					description: __(
						'Work through a large library in chunks.',
						'omnioffloader'
					),
				},
				{
					command:
						'nohup wp omnioffloader offload --all > offload.log 2>&1 &',
					description: __(
						'Keep it running after you close the terminal; progress goes to offload.log.',
						'omnioffloader'
					),
				},
				{
					command: 'wp omnioffloader offload --ids=12,15,42',
					description: __(
						'Offload specific attachments.',
						'omnioffloader'
					),
				},
				{
					command: 'wp omnioffloader offload --all --link-existing',
					description: __(
						'Already copied your uploads to the bucket (rclone, AWS CLI…)? Link them: only missing or different files are uploaded.',
						'omnioffloader'
					),
				},
			],
			options: [
				{
					flag: '--all',
					description: __(
						'Every attachment not offloaded yet.',
						'omnioffloader'
					),
				},
				{
					flag: '--ids=<ids>',
					description: __(
						'Comma-separated attachment IDs.',
						'omnioffloader'
					),
				},
				{
					flag: '--limit=<number>',
					description: __(
						'Stop after this many attachments.',
						'omnioffloader'
					),
				},
				{
					flag: '--dry-run',
					description: __(
						'Show what would happen, change nothing.',
						'omnioffloader'
					),
				},
				{
					flag: '--force',
					description: __(
						'Run even while an admin bulk job is active.',
						'omnioffloader'
					),
				},
				{
					flag: '--link-existing',
					description: __(
						'Skip files already in the bucket with the same size. Needs Object Versioning off.',
						'omnioffloader'
					),
				},
			],
		},
		{
			name: 'wp omnioffloader restore',
			summary: __(
				'Bring Back: download offloaded media to the server and serve it locally again. Cloud copies are kept unless you ask to delete them.',
				'omnioffloader'
			),
			view: 'bring-back',
			viewLabel: __( 'Open Bring Back', 'omnioffloader' ),
			examples: [
				{
					command: 'wp omnioffloader restore --all --dry-run',
					description: __(
						'List what would be restored.',
						'omnioffloader'
					),
				},
				{
					command: 'wp omnioffloader restore --all',
					description: __(
						'Restore everything, keep cloud copies.',
						'omnioffloader'
					),
				},
				{
					command: 'wp omnioffloader restore --all --limit=500',
					description: __( 'Restore in chunks.', 'omnioffloader' ),
				},
				{
					command:
						'wp omnioffloader restore --all --delete-cloud --yes',
					description: __(
						'Restore, then delete cloud copies (destructive).',
						'omnioffloader'
					),
				},
			],
			options: [
				{
					flag: '--all',
					description: __(
						'Every offloaded attachment.',
						'omnioffloader'
					),
				},
				{
					flag: '--ids=<ids>',
					description: __(
						'Comma-separated attachment IDs.',
						'omnioffloader'
					),
				},
				{
					flag: '--limit=<number>',
					description: __(
						'Stop after this many attachments.',
						'omnioffloader'
					),
				},
				{
					flag: '--delete-cloud',
					description: __(
						'Delete cloud objects after each verified restore. Asks for confirmation.',
						'omnioffloader'
					),
				},
				{
					flag: '--yes',
					description: __(
						'Skip the confirmation prompt.',
						'omnioffloader'
					),
				},
				{
					flag: '--dry-run',
					description: __(
						'Show what would happen, change nothing.',
						'omnioffloader'
					),
				},
				{
					flag: '--force',
					description: __(
						'Run even while an admin bulk job is active.',
						'omnioffloader'
					),
				},
			],
		},
		{
			name: 'wp omnioffloader retention',
			summary: __(
				'Apply a retention policy to media that is already offloaded. A stricter policy deletes verified local files (refused while Serve Media from Cloud is off); a looser one downloads local copies again.',
				'omnioffloader'
			),
			view: 'tools',
			viewLabel: __( 'Use the card above', 'omnioffloader' ),
			examples: [
				{
					command: 'wp omnioffloader retention --dry-run',
					description: __(
						'Review what the saved policy would change.',
						'omnioffloader'
					),
				},
				{
					command: 'wp omnioffloader retention',
					description: __(
						'Apply the saved policy (asks before deleting).',
						'omnioffloader'
					),
				},
				{
					command:
						'wp omnioffloader retention --policy=1 --limit=500',
					description: __(
						'Smart Cleanup, 500 attachments at a time.',
						'omnioffloader'
					),
				},
				{
					command: 'wp omnioffloader retention --policy=2 --yes',
					description: __(
						'Full Cloud Migration without the prompt.',
						'omnioffloader'
					),
				},
				{
					command: 'wp omnioffloader retention --policy=0',
					description: __(
						'Download local copies of everything again.',
						'omnioffloader'
					),
				},
			],
			options: [
				{
					flag: '--policy=<0|1|2>',
					description: __(
						'0 Retain Local, 1 Smart Cleanup, 2 Full Migration. Defaults to the saved policy.',
						'omnioffloader'
					),
				},
				{
					flag: '--ids=<ids>',
					description: __(
						'Comma-separated attachment IDs.',
						'omnioffloader'
					),
				},
				{
					flag: '--limit=<number>',
					description: __(
						'Stop after this many attachments.',
						'omnioffloader'
					),
				},
				{
					flag: '--dry-run',
					description: __(
						'Show a table of changes, touch nothing.',
						'omnioffloader'
					),
				},
				{
					flag: '--yes',
					description: __(
						'Skip the confirmation before local files are deleted.',
						'omnioffloader'
					),
				},
				{
					flag: '--force',
					description: __(
						'Run even while an admin bulk job is active.',
						'omnioffloader'
					),
				},
			],
		},
	];
}

/**
 * Copy text to the clipboard, falling back to a hidden textarea on
 * non-secure (http://) admin URLs where the Clipboard API is unavailable.
 *
 * @param text Text to copy.
 */
async function copyText( text: string ): Promise< boolean > {
	if ( window.isSecureContext && navigator.clipboard ) {
		try {
			await navigator.clipboard.writeText( text );
			return true;
		} catch {
			// Fall through to the legacy path.
		}
	}

	const area = document.createElement( 'textarea' );
	area.value = text;
	area.setAttribute( 'readonly', '' );
	area.style.position = 'fixed';
	area.style.opacity = '0';
	document.body.appendChild( area );
	area.select();

	let ok = false;
	try {
		ok = document.execCommand( 'copy' );
	} catch {
		ok = false;
	}

	document.body.removeChild( area );
	return ok;
}

interface CliReferenceProps {
	onNavigate: ( view: string ) => void;
}

/**
 * Reference for every `wp omnioffloader` command, with copy buttons and a
 * link to the admin screen that runs the same job.
 */
export function CliReference( { onNavigate }: CliReferenceProps ) {
	const { addNotice } = useCore();

	const handleCopy = ( command: string ) => {
		void copyText( command ).then( ( ok ) =>
			addNotice(
				ok ? 'success' : 'error',
				ok
					? __( 'Command copied to clipboard.', 'omnioffloader' )
					: __(
							'Could not copy — select the command and copy it manually.',
							'omnioffloader'
						)
			)
		);
	};

	return (
		<Card
			title={ __( 'WP-CLI Commands', 'omnioffloader' ) }
			description={ __(
				'Every bulk job can also run from the terminal over SSH. That is the most reliable option for large libraries, cron schedules, or hosts that time out long admin requests. Run commands from your WordPress directory, or add --path=<wordpress-dir>. Admin jobs and CLI commands never run at the same time.',
				'omnioffloader'
			) }
		>
			<div className="smowp-cli">
				{ getCommands().map( ( cmd ) => (
					<section key={ cmd.name } className="smowp-cli__command">
						<div className="smowp-cli__head">
							<code className="smowp-cli__name">
								{ cmd.name }
							</code>
							{ cmd.view !== 'tools' && (
								<Button
									variant="link"
									onClick={ () => onNavigate( cmd.view ) }
								>
									{ cmd.viewLabel }
								</Button>
							) }
						</div>
						<p className="smowp-cli__summary">{ cmd.summary }</p>

						<ul className="smowp-cli__examples">
							{ cmd.examples.map( ( example ) => (
								<li
									key={ example.command }
									className="smowp-cli__example"
								>
									<div className="smowp-cli__example-text">
										<code>{ example.command }</code>
										<span>{ example.description }</span>
									</div>
									<Button
										icon={ copy }
										size="small"
										label={ __(
											'Copy command',
											'omnioffloader'
										) }
										onClick={ () =>
											handleCopy( example.command )
										}
									/>
								</li>
							) ) }
						</ul>

						{ cmd.options.length > 0 && (
							<dl className="smowp-cli__options">
								{ cmd.options.map( ( option ) => (
									<div
										key={ option.flag }
										className="smowp-cli__option"
									>
										<dt>
											<code>{ option.flag }</code>
										</dt>
										<dd>{ option.description }</dd>
									</div>
								) ) }
							</dl>
						) }
					</section>
				) ) }
			</div>
		</Card>
	);
}
