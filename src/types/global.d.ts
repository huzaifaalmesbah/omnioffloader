/**
 * Data localized by Admin\Settings::enqueue_scripts() via wp_localize_script.
 */
interface SmowpGlobal {
	readonly version: string;
	readonly sdk_loaded: boolean;
	readonly upload_url: string;
	readonly media_url: string;
}

declare global {
	interface Window {
		smowp: SmowpGlobal;
	}
}

export {};
