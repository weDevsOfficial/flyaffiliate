/**
 * A commission's amount, with its note beside it as an info tip when it has
 * one (the commission resource's `note`, `flyaffiliate_commission_note`).
 */
import Money from '@/components/Money';
import Hint from '@/components/Hint';

export default function CommissionAmount( {
	amount,
	note,
}: {
	amount: number | string;
	note?: string;
} ) {
	return (
		<span className="inline-flex items-center gap-1.5">
			<Money amount={ amount } className="font-semibold" />
			{ note ? <Hint text={ note } /> : null }
		</span>
	);
}
