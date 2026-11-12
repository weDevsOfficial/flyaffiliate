/**
 * Runs last (specs run in file order): no PHP notice, warning, deprecation or
 * fatal from FlyAffiliate during the whole run. The helper mu-plugin
 * (fixtures/pw-helpers.php) logs every PHP error to wp-content/pw-php.log,
 * which global-setup.ts empties at the start.
 *
 * The log is filtered in PHP: other plugins can fill it with megabytes of
 * notices (FlyAffiliate Pro without Dokan, PRO-BUG-001), more than a CLI
 * reply may carry.
 *
 * Scenario id: references/scenarios.md SEC-10.
 */
import { expect, test } from '@playwright/test';
import { php } from '../utils/wp';

test( '[SEC-10] the run logged no PHP error from FlyAffiliate', () => {
	// FlyAffiliate's own files (not -pro, not other plugins, not test eval code).
	const ours = php< string[] >( `
		$file = WP_CONTENT_DIR . '/pw-php.log';
		$out  = [];
		if ( file_exists( $file ) ) {
			foreach ( new SplFileObject( $file ) as $line ) {
				if ( preg_match( "#/plugins/flyaffiliate/(?!.*eval\\\\(\\\\)'d)#", (string) $line ) ) {
					$out[] = substr( trim( (string) $line ), 0, 400 );
				}
				if ( count( $out ) >= 20 ) { break; }
			}
		}
		return $out;
	` );
	expect( ours, ours.join( '\n' ) ).toEqual( [] );
} );
