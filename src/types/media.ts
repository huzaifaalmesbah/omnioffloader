/**
 * Mirrors CoreController::get_stats_response().
 */

export interface MediaStats {
	total: number;
	offloaded: number;
	local: number;
	configured: boolean;
	provider: string;
	safe_mode: SafeModeStatus;
}

/** Why Safe mode is on; '' when it is off. */
export type SafeModeReason =
	'' | 'constant' | 'environment' | 'local' | 'address';

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
}
