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

/**
 * Setup guide of a built-in provider, as a docsUrl() path.
 */
const PROVIDER_GUIDES: Record< string, string > = {
	amazon_s3: 'providers/amazon-s3/',
	cloudflare_r2: 'providers/cloudflare-r2/',
	backblaze_b2: 'providers/backblaze-b2/',
	digitalocean_spaces: 'providers/digitalocean-spaces/',
	wasabi: 'providers/wasabi/',
	s3_compatible: 'providers/s3-compatible/',
};

/**
 * Docs path of a provider's setup guide; the providers overview for a
 * provider added through the omnioffloader_cloud_providers filter.
 *
 * @param key Provider key.
 * @return Path for docsUrl().
 */
export function providerGuidePath( key: string ): string {
	return PROVIDER_GUIDES[ key ] ?? 'providers/';
}
