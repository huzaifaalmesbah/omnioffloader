import { __ } from '@wordpress/i18n';

import type { Settings } from '../types';

/**
 * One folder segment cleaned like sanitize_file_name().
 *
 * @param segment Folder segment.
 * @return Cleaned segment.
 */
function cleanSegment( segment: string ): string {
	return segment
		.replace( /[?[\]\/\\=<>:;,'"&$#*()|~`!{}%+’«»”“\u0000]/g, '' )
		.replace( /[\r\n\t ]+/g, '-' )
		.replace( /-+/g, '-' )
		.replace( /^[.\-_]+|[.\-_]+$/g, '' );
}

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

	// Like Helpers::sanitize_path(): no empty, "." or ".." segments, each
	// segment cleaned the way sanitize_file_name() does it, so "My Media"
	// shows as the "My-Media" the server saves.
	const prefix = settings.path_prefix
		.replace( /\\/g, '/' )
		.split( '/' )
		.map( ( segment ) => segment.trim() )
		.filter(
			( segment ) => !! segment && segment !== '.' && segment !== '..'
		)
		.map( cleanSegment )
		.filter( ( segment ) => !! segment )
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
