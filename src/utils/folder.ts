import { __ } from '@wordpress/i18n';

import type { Settings } from '../types';

/**
 * The bucket folder new offloads go to, as FolderClaim::prefix() builds it:
 * "folder/", or '' for the bucket root.
 *
 * @param settings Settings.
 * @return Folder with a trailing slash, or ''.
 */
export function uploadFolder( settings: Settings ): string {
	if ( ! settings.path_prefix_active ) {
		return '';
	}

	// Like Helpers::sanitize_path(): no empty, "." or ".." segments.
	const prefix = settings.path_prefix
		.replace( /\\/g, '/' )
		.split( '/' )
		.map( ( segment ) => segment.trim() )
		.filter(
			( segment ) => !! segment && segment !== '.' && segment !== '..'
		)
		.join( '/' );

	return prefix ? `${ prefix }/` : '';
}

/**
 * A folder for messages: "folder/", or "the bucket root".
 *
 * @param folder Folder from uploadFolder().
 * @return Label.
 */
export function folderLabel( folder: string ): string {
	return folder || __( 'the bucket root', 'omnioffloader' );
}
