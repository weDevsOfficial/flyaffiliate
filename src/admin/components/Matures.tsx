/**
 * When a pending commission is due to become unpaid.
 *
 * With the hold period set to zero the maturity is the moment the commission
 * was created, and printing that date twice in a row says nothing; the cell
 * says there is no wait instead.
 */
import { __ } from '@wordpress/i18n';
import DateTime from './DateTime';

export default function Matures( {
	maturesAt,
	createdAt,
}: {
	maturesAt: string | null | undefined;
	createdAt: string | null | undefined;
} ) {
	const matures = maturesAt ? Date.parse( maturesAt ) : NaN;
	const created = createdAt ? Date.parse( createdAt ) : NaN;
	const immediate =
		! maturesAt ||
		( ! Number.isNaN( matures ) &&
			! Number.isNaN( created ) &&
			matures <= created );

	if ( immediate ) {
		return (
			<span className="text-muted-foreground whitespace-nowrap">
				{ __( 'Immediately', 'flyaffiliate' ) }
			</span>
		);
	}

	return <DateTime value={ maturesAt } />;
}
