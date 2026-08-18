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

	return __(
		'Something went wrong. Please try again.',
		'smart-media-offloader'
	);
}
