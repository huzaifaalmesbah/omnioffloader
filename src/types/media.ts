/**
 * Mirrors CoreController::get_stats_response().
 */

export interface MediaStats {
	total: number;
	offloaded: number;
	/** Not offloaded and ready to offload (excludes `missing`). */
	local: number;
	/** Not offloaded, but the file is not on this server: skipped. */
	missing: number;
	configured: boolean;
	provider: string;
	safe_mode: SafeModeStatus;
}

/** Why Safe mode is on; '' when it is off. */
export type SafeModeReason =
	'' | 'constant' | 'environment' | 'local' | 'address' | 'folder';

/**
 * Mirrors SafeMode::status().
 */
export interface SafeModeStatus {
	active: boolean;
	reason: SafeModeReason;
	/** OMNIOFFLOADER_SAFE_MODE is defined in wp-config.php. */
	locked: boolean;
	/** Address recorded as the live site, e.g. "example.com"; '' when none. */
	live_address: string;
	current_address: string;
	/** wp_get_environment_type(). */
	environment: string;
	/** Whether Bring Back is available (false: Safe mode blocks it). */
	bring_back: boolean;
	/** Reason 'folder': address of the site that owns this site's bucket folder. */
	folder_owner: string;
	/** Reason 'folder': the folder, e.g. "attorneys"; '' for the bucket root. */
	folder: string;
}
