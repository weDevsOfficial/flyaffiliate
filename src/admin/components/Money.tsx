/**
 * An amount, written the way the Currency settings say.
 */
import { formatMoney } from '@/lib/format';

export default function Money( {
	amount,
	className = '',
}: {
	amount: number | string;
	className?: string;
} ) {
	return (
		<span className={ `tabular-nums ${ className }` }>
			{ formatMoney( amount ) }
		</span>
	);
}
