import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import type { CredentialsResponse, TestConnectionResponse } from '../types';

const BASE = '/omnioffloader/v1';

export function fetchCredentials(
	provider: string
): Promise< CredentialsResponse > {
	return apiFetch( {
		path: addQueryArgs( `${ BASE }/provider/credentials`, { provider } ),
	} );
}

export function saveCredentials(
	provider: string,
	values: Record< string, string >
): Promise< CredentialsResponse > {
	return apiFetch( {
		path: `${ BASE }/provider/credentials`,
		method: 'POST',
		data: { provider, values },
	} );
}

export function testConnection(
	provider: string
): Promise< TestConnectionResponse > {
	return apiFetch( {
		path: `${ BASE }/provider/test-connection`,
		method: 'POST',
		data: { provider },
	} );
}
