/**
 * The FlyAffiliate admin app.
 *
 * Mounts on the element `templates/admin/app.php` renders and routes by URL
 * hash, so every submenu entry (`admin.php?page=flyaffiliate#/commissions`)
 * lands in the same bundle.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import App from './App';
import { highlightMenu } from './lib/menu';
import { installClipboardFallback } from './lib/clipboard';
import './tailwind.css';
import './admin.scss';

domReady( () => {
	installClipboardFallback();

	const mount = document.getElementById( 'flyaffiliate-admin-app' );

	if ( mount ) {
		createRoot( mount ).render( <App /> );
	}

	highlightMenu( 'flyaffiliate' );
} );
