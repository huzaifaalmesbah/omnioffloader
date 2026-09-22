import apiFetch from '@wordpress/api-fetch';

import type { AttachmentErrors, BulkState, StartBulkResponse } from '../types';

const BASE = '/omnioffloader/v1';

export function startBulkOffload(): Promise< StartBulkResponse > {
	return apiFetch( { path: `${ BASE }/offload/bulk`, method: 'POST' } );
}

export function fetchBulkProgress(): Promise< BulkState > {
	return apiFetch( {
		path: `${ BASE }/offload/bulk/progress?_t=${ Date.now() }`,
		cache: 'no-store',
	} );
}

export function cancelBulkOffload(): Promise< BulkState > {
	return apiFetch( {
		path: `${ BASE }/offload/bulk/cancel`,
		method: 'POST',
	} );
}

export function fetchOffloadErrors(): Promise< AttachmentErrors[] > {
	return apiFetch( {
		path: `${ BASE }/offload/errors?_t=${ Date.now() }`,
		cache: 'no-store',
	} );
}
