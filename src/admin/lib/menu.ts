/**
 * Keep the wp-admin submenu highlight in step with hash navigation.
 *
 * WordPress marks the current submenu entry on the server, by page slug; every
 * entry under FlyAffiliate shares one slug and differs only by hash, so the
 * highlight has to follow the hash on the client.
 *
 * @param {string} slug The top-level menu slug.
 */
export function highlightMenu( slug: string ): void {
	const root = document.getElementById( `toplevel_page_${ slug }` );

	if ( ! root ) {
		return;
	}

	const links = Array.from(
		root.querySelectorAll< HTMLAnchorElement >( 'ul.wp-submenu a' )
	);

	const sync = () => {
		const hash = window.location.hash || '#/';
		const route = hash.replace( /^#\//, '' ).split( /[/?]/ )[ 0 ];
		let matched = false;

		links.forEach( ( link ) => {
			const item = link.closest( 'li' );
			const href = link.getAttribute( 'href' ) || '';
			const linkRoute = href.includes( '#/' )
				? href.split( '#/' )[ 1 ].split( /[/?]/ )[ 0 ]
				: '';
			const current = linkRoute === route && route !== '';

			item?.classList.toggle( 'current', current );
			matched = matched || current;
		} );

		// The top-level entry links to `#/`, which redirects to the first route.
		if ( ! matched && links[ 0 ] ) {
			links[ 0 ].closest( 'li' )?.classList.add( 'current' );
		}
	};

	window.addEventListener( 'hashchange', sync );
	sync();
}
