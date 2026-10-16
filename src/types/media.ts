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
	/** Mirrors MediaCounters::storage(). */
	storage?: StorageStats;
}

/** One retention policy's share of the offloaded media. */
export interface StoragePolicyStats {
	policy: 0 | 1 | 2;
	count: number;
	cloud_bytes: number;
	server_bytes: number;
	freed_bytes: number;
}

/** Storage totals for the Dashboard (from the running counters). */
export interface StorageStats {
	/** Every offloaded file, original and sizes. */
	cloud_bytes: number;
	/** Files of offloaded media that their retention policy keeps on this server. */
	server_bytes: number;
	/** Removed from this server by the retention policy. */
	freed_bytes: number;
	/** Offloaded items with no recorded file size (uploaded before WordPress 6.0). */
	unsized: number;
	policies: StoragePolicyStats[];
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
