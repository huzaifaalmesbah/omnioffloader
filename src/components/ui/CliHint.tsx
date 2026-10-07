import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { cliSectionId } from '../tools/CliReference';
import { LARGE_LIBRARY } from '../../utils/constants';

interface CliHintProps {
	/** Command name after `wp omnioffloader`, e.g. 'offload'. */
	command: string;
	/** Example to show, e.g. 'wp omnioffloader offload --all'. */
	example: string;
	/** Attachments the job would handle; a large library gets a stronger hint. */
	count: number;
	onNavigate: ( view: string, section?: string ) => void;
}

/**
 * The WP-CLI command for a bulk job, with a link to its entry in the Tools
 * page's WP-CLI reference.
 */
export function CliHint( {
	command,
	example,
	count,
	onNavigate,
}: CliHintProps ) {
	return (
		<p className="smowp-muted">
			{ count >= LARGE_LIBRARY
				? __(
						'Large library? Running this from WP-CLI is usually faster and needs no open browser tab:',
						'omnioffloader'
					)
				: __(
						'The same job also runs from WP-CLI:',
						'omnioffloader'
					) }{ ' ' }
			<code>{ example }</code>{ ' ' }
			<Button
				variant="link"
				onClick={ () => onNavigate( 'tools', cliSectionId( command ) ) }
			>
				{ __( 'See the command in Tools', 'omnioffloader' ) }
			</Button>
		</p>
	);
}
