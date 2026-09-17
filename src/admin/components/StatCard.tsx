/**
 * One figure in a card: an icon, a label with an info tooltip and the number,
 * the way Dokan's dashboard tiles present their totals. Nothing else goes in
 * the card, so a row of them stays level.
 */
import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { Card, Skeleton, cn } from '@wedevs/plugin-ui';
import Hint from './Hint';

type Props = {
	icon: LucideIcon;
	label: string;
	value: ReactNode;
	/** What the figure means, behind an info icon beside the label, as on Dokan's report cards. */
	tooltip?: string;
	className?: string;
};

export default function StatCard( {
	icon: Icon,
	label,
	value,
	tooltip,
	className,
}: Props ) {
	return (
		<Card
			className={ cn(
				'gap-2 rounded-md border border-border p-4 py-4 shadow ring-0',
				className
			) }
		>
			<div className="flex size-10 items-center justify-center rounded bg-primary/10 text-primary">
				<Icon className="size-5" aria-hidden="true" />
			</div>
			<div className="flex items-center gap-1.5 text-sm font-semibold text-foreground">
				{ label }
				{ tooltip && <Hint text={ tooltip } /> }
			</div>
			<div className="-mt-1 whitespace-nowrap text-2xl font-bold leading-tight text-foreground tabular-nums">
				{ value }
			</div>
		</Card>
	);
}

export function StatCardSkeleton( { className }: { className?: string } ) {
	return (
		<Card
			className={ cn(
				'gap-2 rounded-md p-4 py-4 shadow ring-0',
				className
			) }
		>
			<Skeleton className="size-10 rounded" />
			<Skeleton className="h-4 w-24" />
			<Skeleton className="h-8 w-32" />
		</Card>
	);
}
