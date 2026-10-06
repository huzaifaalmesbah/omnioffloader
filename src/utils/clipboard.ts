/**
 * Copy text to the clipboard, falling back to a hidden textarea on
 * non-secure (http://) admin URLs where the Clipboard API is unavailable.
 *
 * @param text Text to copy.
 */
export async function copyText( text: string ): Promise< boolean > {
	if ( window.isSecureContext && navigator.clipboard ) {
		try {
			await navigator.clipboard.writeText( text );
			return true;
		} catch {
			// Fall through to the legacy path.
		}
	}

	const area = document.createElement( 'textarea' );
	area.value = text;
	area.setAttribute( 'readonly', '' );
	area.style.position = 'fixed';
	area.style.opacity = '0';
	document.body.appendChild( area );
	area.select();

	let ok = false;
	try {
		ok = document.execCommand( 'copy' );
	} catch {
		ok = false;
	}

	document.body.removeChild( area );
	return ok;
}
