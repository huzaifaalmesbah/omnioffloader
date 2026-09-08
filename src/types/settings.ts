/**
 * Mirrors OptionsHelper::get_default_settings() — keep both sides in sync.
 */

export enum RetentionPolicy {
	RetainLocal = 0,
	SmartCleanup = 1,
	FullMigration = 2,
}

export interface Settings {
	provider: string;
	secondary_active: boolean;
	secondary_provider: string;
	retention_policy: RetentionPolicy;
	mirror_delete: boolean;
	path_prefix_active: boolean;
	path_prefix: string;
	object_versioning: boolean;
	bring_back_delete_cloud: boolean;
	remove_data_on_uninstall: boolean;
}
