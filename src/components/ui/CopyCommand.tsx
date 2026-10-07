import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { copy } from '@wordpress/icons';

import { useCore } from '../../context/CoreContext';
import { copyText } from '../../utils/clipboard';

interface CopyCommandProps {
	command: string;
}

/**
 * A shell command with a copy button, like the rows of the Tools page's
 * WP-CLI reference.
 */
export function CopyCommand( { command }: CopyCommandProps ) {
	const { addNotice } = useCore();

	const handleCopy = () => {
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
		<div className="smowp-copy-command">
			<code>{ command }</code>
			<Button
				icon={ copy }
				size="small"
				label={ sprintf(
					/* translators: %s: a WP-CLI command. */
					__( 'Copy command: %s', 'omnioffloader' ),
					command
				) }
				onClick={ handleCopy }
			/>
		</div>
	);
}
