/**
 * Link to a page of the public documentation.
 *
 * The address comes from OMNIOFFLOADER_DOCS_URL in omnioffloader.php —
 * change the domain there, never here or in a component.
 *
 * @param path Page path such as 'wp-cli/offload/', optionally with a
 *             '#anchor'. Empty for the docs home page.
 * @return Absolute URL.
 */
export function docsUrl( path = '' ): string {
	const base = window.omnioffloader.docs_url.replace( /\/+$/, '' );

	return `${ base }/${ path.replace( /^\/+/, '' ) }`;
}
