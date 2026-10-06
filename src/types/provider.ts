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
	fields: CredentialField[];
}

/** Mirrors the FolderClaim states (Core\FolderClaim). */
export type FolderClaimStatus = 'ours' | 'other' | 'free' | 'unknown';

/** Who uses this site's folder in the bucket (read-only check). */
export interface FolderCheck {
	status: FolderClaimStatus;
	/** Address named in the folder's marker; '' when free or unknown. */
	owner: string;
	/** The saved Path Prefix folder; '' for the bucket root. */
	folder: string;
	/** Why the folder cannot be used; '' unless status is 'other'. */
	message: string;
}

/** GET /provider/rclone — rclone setup built from this site's settings. */
export interface RcloneSetupResponse {
	/** False when there is no provider, no bucket, or Safe mode is on. */
	available: boolean;
	/** Why the setup is not available; '' when it is. */
	message: string;
	/** rclone remote name. */
	remote: string;
	/** Config section; the secret key is always a placeholder. */
	config: string;
	copy_dry_run: string;
	copy: string;
	link_dry_run: string;
	link: string;
	/** This site's uploads folder on the server. */
	source: string;
	/** remote:bucket[/prefix]. */
	destination: string;
	warnings: string[];
}

/** POST /provider/test-connection response. */
export interface TestConnectionResponse {
	connected: boolean;
	message: string;
	checked_at: string;
	/** Absent when the AWS SDK is missing; null when not connected. */
	folder?: FolderCheck | null;
}
