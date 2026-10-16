/**
 * REST responses embedded in the admin page (Admin\Settings::preload_data()),
 * so the first screen draws without waiting for requests.
 *
 * Each entry is read once: later calls go to the server, so a refresh
 * always sees current data.
 */

const BASE = '/omnioffloader/v1';

const store: Record< string, unknown > = {
	...( window.omnioffloaderPreload ?? {} ),
};

/**
 * The preloaded body for a path, once.
 *
 * @param path Route below /omnioffloader/v1, with its query, e.g. '/core/settings'.
 */
export function takePreloaded< T >( path: string ): T | undefined {
	const key = BASE + path;

	if ( ! ( key in store ) ) {
		return undefined;
	}

	const body = store[ key ] as T;
	delete store[ key ];

	return body;
}
