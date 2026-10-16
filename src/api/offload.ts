import apiFetch from '@wordpress/api-fetch';

import type {
	AttachmentErrors,
	AttachmentErrorsPage,
	BulkState,
	ErrorsState,
	StartBulkResponse,
} from '../types';

const BASE = '/omnioffloader/v1';

export function startBulkOffload(
	limit?: number
): Promise< StartBulkResponse > {
	return apiFetch( {
		path: `${ BASE }/offload/bulk`,
		method: 'POST',
		...( limit && limit > 0 ? { data: { limit } } : {} ),
	} );
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

export async function fetchOffloadErrors(
	state: ErrorsState = 'all',
	page = 1,
	perPage = 20
): Promise< AttachmentErrorsPage > {
	const response = await apiFetch< Response, false >( {
		path: `${ BASE }/offload/errors?state=${ state }&page=${ page }&per_page=${ perPage }&_t=${ Date.now() }`,
		cache: 'no-store',
		parse: false,
	} );
	const items = ( await response.json() ) as AttachmentErrors[];

	return {
		items,
		total: Number( response.headers.get( 'X-WP-Total' ) ?? 0 ),
		totalPages: Number( response.headers.get( 'X-WP-TotalPages' ) ?? 0 ),
	};
}

export function retryFailedOffloads(): Promise< StartBulkResponse > {
	return apiFetch( {
		path: `${ BASE }/offload/errors/retry`,
		method: 'POST',
	} );
}
