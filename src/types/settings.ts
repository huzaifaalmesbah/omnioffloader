/**
 * Mirrors OptionsHelper::get_default_settings() — keep both sides in sync.
 */

export enum RetentionPolicy {
	RetainLocal = 0,
	SmartCleanup = 1,
	FullMigration = 2,
}

/** Files uploaded at once: low 1, balanced 3, fast 6, custom = transfer_concurrency. */
export type TransferSpeed = 'low' | 'balanced' | 'fast' | 'custom';

export interface Settings {
	provider: string;
	retention_policy: RetentionPolicy;
	mirror_delete: boolean;
	path_prefix_active: boolean;
	path_prefix: string;
	object_versioning: boolean;
	async_upload: boolean;
	transfer_speed: TransferSpeed;
	/** 1–10; only used when transfer_speed is 'custom'. */
	transfer_concurrency: number;
	bring_back_delete_cloud: boolean;
	remove_data_on_uninstall: boolean;
	/** Safe mode on staging/dev/local copies of the site. */
	protect_copies: boolean;
	/** In Safe mode, allow Bring Back as download-only (default: hidden). */
	safe_mode_bring_back: boolean;
}
