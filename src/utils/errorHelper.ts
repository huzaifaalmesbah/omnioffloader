import { __ } from '@wordpress/i18n';

/**
 * Extract a human-readable message from an apiFetch rejection.
 * @param error
 */
export function errorMessage( error: unknown ): string {
	if (
		typeof error === 'object' &&
		error !== null &&
		'message' in error &&
		typeof ( error as { message: unknown } ).message === 'string'
	) {
		return ( error as { message: string } ).message;
	}

	return __( 'Something went wrong. Please try again.', 'omnioffloader' );
}

/**
 * Whether an apiFetch rejection is a start refused because another bulk job
 * (possibly a WP-CLI run) is running.
 *
 * @param error
 */
export function isJobRunningError( error: unknown ): boolean {
	return (
		typeof error === 'object' &&
		error !== null &&
		'code' in error &&
		( error as { code: unknown } ).code === 'omnioffloader_bulk_running'
	);
}
