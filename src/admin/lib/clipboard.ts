/**
 * Copy text to the clipboard.
 *
 * The async Clipboard API only exists on secure origins; a local site over
 * plain HTTP has none, so the hidden-textarea fallback covers it.
 *
 * @param {string} value The text.
 * @return {Promise<boolean>} Whether the copy went through.
 */
export async function copyText( value: string ): Promise< boolean > {
	if ( navigator.clipboard?.writeText ) {
		try {
			await navigator.clipboard.writeText( value );
			return true;
		} catch ( error ) {
			// Denied or unavailable: fall through to the legacy path.
		}
	}

	const textarea = document.createElement( 'textarea' );
	textarea.value = value;
	textarea.setAttribute( 'readonly', '' );
	textarea.style.position = 'fixed';
	textarea.style.opacity = '0';
	document.body.appendChild( textarea );
	textarea.select();

	let copied = false;

	try {
		copied = document.execCommand( 'copy' );
	} catch ( error ) {
		copied = false;
	}

	document.body.removeChild( textarea );

	return copied;
}

/**
 * Give `navigator.clipboard.writeText` to a page that has none.
 *
 * Browsers only expose the Clipboard API on secure origins. A local site over
 * plain HTTP has no `navigator.clipboard` at all, and every component that
 * calls `writeText` — plugin-ui's copy inputs included — throws before it can
 * show its "copied" state. This installs a stand-in that routes through the
 * hidden-textarea path, and does nothing where the real API exists.
 */
export function installClipboardFallback(): void {
	if (
		typeof navigator === 'undefined' ||
		typeof navigator.clipboard?.writeText === 'function'
	) {
		return;
	}

	const fallback = {
		writeText: async ( value: string ): Promise< void > => {
			if ( ! ( await copyText( value ) ) ) {
				throw new Error( 'Copy failed' );
			}
		},
	};

	try {
		Object.defineProperty( navigator, 'clipboard', {
			value: fallback,
			configurable: true,
		} );
	} catch ( error ) {
		// A frozen navigator: the components fall back to their own errors.
	}
}
