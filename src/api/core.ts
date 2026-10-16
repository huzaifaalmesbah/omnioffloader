import apiFetch from '@wordpress/api-fetch';

import type {
	CliJob,
	MediaStats,
	ProviderInfo,
	SafeModeStatus,
	Settings,
} from '../types';

import { takePreloaded } from './preload';

const BASE = '/omnioffloader/v1';

export function fetchSettings(): Promise< Settings > {
	const preloaded = takePreloaded< Settings >( '/core/settings' );

	if ( preloaded ) {
		return Promise.resolve( preloaded );
	}

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
	const preloaded = takePreloaded< ProviderInfo[] >( '/core/providers' );

	if ( preloaded ) {
		return Promise.resolve( preloaded );
	}

	return apiFetch( {
		path: `${ BASE }/core/providers?_t=${ Date.now() }`,
		cache: 'no-store',
	} );
}

/** "This is the live site": records the current address as the live one. */
export function confirmLiveSite(): Promise< SafeModeStatus > {
	return apiFetch( {
		path: `${ BASE }/core/safe-mode/live`,
		method: 'POST',
	} );
}

/** "This folder belongs to this site": claims the bucket folder for this site. */
export function takeOverFolder(): Promise< SafeModeStatus > {
	return apiFetch( {
		path: `${ BASE }/core/folder-claim/take-over`,
		method: 'POST',
	} );
}

export function fetchStats( refresh = false ): Promise< MediaStats > {
	const preloaded = refresh
		? undefined
		: takePreloaded< MediaStats >( '/core/stats' );

	if ( preloaded ) {
		return Promise.resolve( preloaded );
	}

	const query = refresh
		? `?refresh=1&_t=${ Date.now() }`
		: `?_t=${ Date.now() }`;
	return apiFetch( {
		path: `${ BASE }/core/stats${ query }`,
		cache: 'no-store',
	} );
}

/** The WP-CLI job running now, if any (see useCliJob). */
export function fetchCliJob(): Promise< CliJob > {
	const preloaded = takePreloaded< CliJob >( '/core/cli-job' );

	if ( preloaded ) {
		return Promise.resolve( preloaded );
	}

	return apiFetch( {
		path: `${ BASE }/core/cli-job?_t=${ Date.now() }`,
		cache: 'no-store',
	} );
}
