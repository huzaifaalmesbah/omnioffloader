import apiFetch from '@wordpress/api-fetch';

import type {
	RetentionBulkState,
	RetentionPreflight,
	StartBulkRetentionResponse,
} from '../types';

const BASE = '/omnioffloader/v1';

export function fetchRetentionPreflight(
	refresh = false
): Promise< RetentionPreflight > {
	const query = refresh
		? `?refresh=1&_t=${ Date.now() }`
		: `?_t=${ Date.now() }`;
	return apiFetch( {
		path: `${ BASE }/retention/preflight${ query }`,
		cache: 'no-store',
	} );
}

export function startBulkRetention(
	limit?: number
): Promise< StartBulkRetentionResponse > {
	return apiFetch( {
		path: `${ BASE }/retention/bulk`,
		method: 'POST',
		...( limit && limit > 0 ? { data: { limit } } : {} ),
	} );
}

export function fetchRetentionProgress(): Promise< RetentionBulkState > {
	return apiFetch( {
		path: `${ BASE }/retention/bulk/progress?_t=${ Date.now() }`,
		cache: 'no-store',
	} );
}

export function cancelBulkRetention(): Promise< RetentionBulkState > {
	return apiFetch( {
		path: `${ BASE }/retention/bulk/cancel`,
		method: 'POST',
	} );
}
