import apiFetch from '@wordpress/api-fetch';

import type {
	RestoreBulkState,
	RestorePreflight,
	StartBulkRestoreResponse,
} from '../types';

const BASE = '/omnioffloader/v1';

export function fetchRestorePreflight(
	refresh = false
): Promise< RestorePreflight > {
	const query = refresh
		? `?refresh=1&_t=${ Date.now() }`
		: `?_t=${ Date.now() }`;
	return apiFetch( {
		path: `${ BASE }/bring-back/preflight${ query }`,
		cache: 'no-store',
	} );
}

export function startBulkRestore(
	deleteCloud: boolean
): Promise< StartBulkRestoreResponse > {
	return apiFetch( {
		path: `${ BASE }/bring-back/bulk`,
		method: 'POST',
		data: { delete_cloud: deleteCloud },
	} );
}

export function fetchRestoreProgress(): Promise< RestoreBulkState > {
	return apiFetch( {
		path: `${ BASE }/bring-back/bulk/progress?_t=${ Date.now() }`,
		cache: 'no-store',
	} );
}

export function cancelBulkRestore(): Promise< RestoreBulkState > {
	return apiFetch( {
		path: `${ BASE }/bring-back/bulk/cancel`,
		method: 'POST',
	} );
}
