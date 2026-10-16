/**
 * A message on a page, in a white card like the figures beside it: a tinted
 * icon, a title, what it means and what to do. Pages sit on WordPress's grey,
 * so a message never takes a grey or coloured background of its own: the
 * icon carries its tone.
 */
import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { Card, cn } from '@wedevs/plugin-ui';

/** The icon's colours by tone. */
const TONES = {
	default: 'bg-primary/10 text-primary',
	info: 'bg-info/10 text-info',
	destructive: 'bg-destructive/10 text-destructive',
};

type Props = {
	icon: LucideIcon;
	title: ReactNode;
	tone?: keyof typeof TONES;
	/** Spins the icon, for something still happening. */
	spin?: boolean;
	/** Beside the title, on the right: a count or a figure. */
	aside?: ReactNode;
	children?: ReactNode;
	className?: string;
	testId?: string;
	/** A state for tests to read (`data-state`). */
	state?: string;
};

export default function NoticeCard( {
	icon: Icon,
	title,
	tone = 'default',
	spin = false,
	aside,
	children,
	className,
	testId,
	state,
}: Props ) {
	return (
		<Card
			role="status"
			className={ cn(
				'flex-row items-start gap-3 rounded-md border border-border p-4 py-4 shadow ring-0',
				className
			) }
			data-testid={ testId }
			data-state={ state }
		>
			<div
				className={ cn(
					'flex size-10 shrink-0 items-center justify-center rounded',
					TONES[ tone ]
				) }
			>
				<Icon
					className={ cn( 'size-5', spin && 'animate-spin' ) }
					aria-hidden="true"
				/>
			</div>
			<div className="min-w-0 flex-1">
				<div className="flex flex-wrap items-baseline justify-between gap-x-4">
					<p className="text-sm font-semibold text-foreground">
						{ title }
					</p>
					{ aside }
				</div>
				{ children && (
					<div className="mt-1 text-sm text-muted-foreground">
						{ children }
					</div>
				) }
			</div>
		</Card>
	);
}
