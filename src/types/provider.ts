/**
 * Mirrors ProviderController / CoreController provider payloads.
 */

export type CredentialFieldType = 'text' | 'password' | 'checkbox';

/** Static field definition from ProviderInterface::credential_fields(). */
export interface CredentialFieldDef {
	name: string;
	label: string;
	type: CredentialFieldType;
	placeholder: string;
	description: string;
}

/** Field definition merged with per-site state (GET /provider/credentials). */
export interface CredentialField extends CredentialFieldDef {
	value: string;
	locked: boolean;
	is_set: boolean;
}

/** One entry of GET /core/providers. */
export interface ProviderInfo {
	key: string;
	name: string;
	fields: CredentialFieldDef[];
}

/** GET/POST /provider/credentials response. */
export interface CredentialsResponse {
	provider: string;
	role?: string;
	fields: CredentialField[];
}

/** POST /provider/test-connection response. */
export interface TestConnectionResponse {
	connected: boolean;
	message: string;
	checked_at: string;
}
