import apiFetch from '@wordpress/api-fetch';

import type {
	RestoreBulkState,
	RestorePreflight,
	StartBulkRestoreResponse,
} from '../types';

const BASE = '/smowp/v1';

export function fetchRestorePreflight(): Promise< RestorePreflight > {
	return apiFetch( { path: `${ BASE }/bring-back/preflight` } );
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
	return apiFetch( { path: `${ BASE }/bring-back/bulk/progress` } );
}

export function cancelBulkRestore(): Promise< RestoreBulkState > {
	return apiFetch( {
		path: `${ BASE }/bring-back/bulk/cancel`,
		method: 'POST',
	} );
}
