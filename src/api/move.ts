import apiFetch from '@wordpress/api-fetch';

import type {
	MoveBulkState,
	MovePreflight,
	StartBulkMoveResponse,
} from '../types';

const BASE = '/omnioffloader/v1';

export function fetchMovePreflight(): Promise< MovePreflight > {
	return apiFetch( {
		path: `${ BASE }/move/preflight?_t=${ Date.now() }`,
		cache: 'no-store',
	} );
}

export function startBulkMove(
	limit?: number
): Promise< StartBulkMoveResponse > {
	return apiFetch( {
		path: `${ BASE }/move/bulk`,
		method: 'POST',
		...( limit && limit > 0 ? { data: { limit } } : {} ),
	} );
}

export function fetchMoveProgress(): Promise< MoveBulkState > {
	return apiFetch( {
		path: `${ BASE }/move/bulk/progress?_t=${ Date.now() }`,
		cache: 'no-store',
	} );
}

export function cancelBulkMove(): Promise< MoveBulkState > {
	return apiFetch( {
		path: `${ BASE }/move/bulk/cancel`,
		method: 'POST',
	} );
}
