import { ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { docsUrl } from '../../utils/docs';

interface DocsLinkProps {
	/** Docs page path, e.g. 'features/mirror-delete/'. */
	path: string;
	children?: React.ReactNode;
}

/**
 * Link to a docs page, opened in a new tab.
 */
export function DocsLink( { path, children }: DocsLinkProps ) {
	return (
		<ExternalLink className="smowp-docs-link" href={ docsUrl( path ) }>
			{ children ?? __( 'Learn more', 'omnioffloader' ) }
		</ExternalLink>
	);
}
