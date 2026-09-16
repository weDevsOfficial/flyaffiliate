/**
 * What came of a click: nothing yet, or an order. The order number itself
 * lives in its own column, as on the Commissions list.
 */
import { __ } from '@wordpress/i18n';
import StatusBadge from './StatusBadge';
import type { Visit } from '@/lib/types';

type Props = {
	visit: Pick< Visit, 'converted' >;
};

export default function VisitResult( { visit }: Props ) {
	return visit.converted ? (
		<StatusBadge
			status="converted"
			label={ __( 'Converted', 'flyaffiliate' ) }
		/>
	) : (
		<StatusBadge
			status="inactive"
			label={ __( 'Not converted', 'flyaffiliate' ) }
		/>
	);
}
