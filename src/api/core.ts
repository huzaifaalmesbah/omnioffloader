import apiFetch from '@wordpress/api-fetch';

import type { MediaStats, ProviderInfo, Settings } from '../types';

const BASE = '/smowp/v1';

export function fetchSettings(): Promise< Settings > {
	return apiFetch( { path: `${ BASE }/core/settings` } );
}

export function saveSettings( settings: Settings ): Promise< Settings > {
	return apiFetch( {
		path: `${ BASE }/core/settings`,
		method: 'POST',
		data: { settings },
	} );
}

export function fetchProviders(): Promise< ProviderInfo[] > {
	return apiFetch( { path: `${ BASE }/core/providers` } );
}

export function fetchStats(): Promise< MediaStats > {
	return apiFetch( { path: `${ BASE }/core/stats` } );
}
