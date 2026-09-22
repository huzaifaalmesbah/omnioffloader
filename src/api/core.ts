import apiFetch from '@wordpress/api-fetch';

import type { MediaStats, ProviderInfo, Settings } from '../types';

const BASE = '/omnioffloader/v1';

export function fetchSettings(): Promise< Settings > {
	return apiFetch( {
		path: `${ BASE }/core/settings?_t=${ Date.now() }`,
		cache: 'no-store',
	} );
}

export function saveSettings( settings: Settings ): Promise< Settings > {
	return apiFetch( {
		path: `${ BASE }/core/settings`,
		method: 'POST',
		data: { settings },
	} );
}

export function fetchProviders(): Promise< ProviderInfo[] > {
	return apiFetch( {
		path: `${ BASE }/core/providers?_t=${ Date.now() }`,
		cache: 'no-store',
	} );
}

export function fetchStats( refresh = false ): Promise< MediaStats > {
	const query = refresh
		? `?refresh=1&_t=${ Date.now() }`
		: `?_t=${ Date.now() }`;
	return apiFetch( {
		path: `${ BASE }/core/stats${ query }`,
		cache: 'no-store',
	} );
}
