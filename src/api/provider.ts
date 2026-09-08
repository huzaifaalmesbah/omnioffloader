import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import type { CredentialsResponse, TestConnectionResponse } from '../types';

const BASE = '/omnioffloader/v1';

export function fetchCredentials(
	provider: string,
	role: 'primary' | 'secondary' = 'primary'
): Promise< CredentialsResponse > {
	return apiFetch( {
		path: addQueryArgs( `${ BASE }/provider/credentials`, { provider, role } ),
	} );
}

export function saveCredentials(
	provider: string,
	values: Record< string, string >,
	role: 'primary' | 'secondary' = 'primary'
): Promise< CredentialsResponse > {
	return apiFetch( {
		path: `${ BASE }/provider/credentials`,
		method: 'POST',
		data: { provider, values, role },
	} );
}

export function testConnection(
	provider: string,
	role: 'primary' | 'secondary' = 'primary'
): Promise< TestConnectionResponse > {
	return apiFetch( {
		path: `${ BASE }/provider/test-connection`,
		method: 'POST',
		data: { provider, role },
	} );
}
