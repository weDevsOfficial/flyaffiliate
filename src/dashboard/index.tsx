/**
 * The affiliate dashboard.
 *
 * Mounts on the element `templates/affiliate-dashboard/dashboard.php`
 * renders for a logged-in, active affiliate. Everything it shows comes from
 * the self-scoped `me` REST routes.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import App from './App';
import { installClipboardFallback } from '@/lib/clipboard';
import './tailwind.css';
import './dashboard.scss';

domReady( () => {
	installClipboardFallback();

	const mount = document.getElementById( 'flyaffiliate-dashboard' );

	if ( mount ) {
		createRoot( mount ).render( <App /> );
	}
} );
