import { Button, Icon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { code } from '@wordpress/icons';

import { cliSectionId } from '../tools/CliReference';
import { LARGE_LIBRARY } from '../../utils/constants';

interface CliHintProps {
	/** Command name after `wp omnioffloader`, e.g. 'offload'. */
	command: string;
	/** Attachments the job would handle; a large library is told to prefer it. */
	count: number;
	onNavigate: ( view: string, section?: string ) => void;
}

/**
 * A panel pointing to the bulk job's command in the Tools page's WP-CLI
 * reference, recommended for large libraries.
 */
export function CliHint( { command, count, onNavigate }: CliHintProps ) {
	const large = count >= LARGE_LIBRARY;

	return (
		<div className="omnioffloader-cli-hint">
			<span className="omnioffloader-cli-hint__icon" aria-hidden="true">
				<Icon icon={ code } size={ 20 } />
			</span>
			<div className="omnioffloader-cli-hint__text">
				<strong>
					{ __( 'Run this job from WP-CLI', 'omnioffloader' ) }
				</strong>
				{ large && (
					<span className="omnioffloader-ui-badge omnioffloader-ui-badge--accent">
						{ __( 'Recommended', 'omnioffloader' ) }
					</span>
				) }
				<p>
					{ large
						? __(
								'For a library this size, the terminal is usually faster and needs no open browser tab.',
								'omnioffloader'
							)
						: __(
								'The same job from the terminal over SSH: no open browser tab, and no web request time limits.',
								'omnioffloader'
							) }
				</p>
			</div>
			<Button
				variant="secondary"
				className="omnioffloader-cli-hint__button"
				onClick={ () => onNavigate( 'tools', cliSectionId( command ) ) }
			>
				{ __( 'View command', 'omnioffloader' ) }
			</Button>
		</div>
	);
}
