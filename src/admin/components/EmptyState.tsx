/**
 * Nothing to show yet: an icon in a tinted circle, a title and a line under
 * it, the same shape plugin-ui's lists use when they are empty.
 */
import type { ReactNode } from 'react';
import { FileSearch, type LucideIcon } from 'lucide-react';
import { cn } from '@wedevs/plugin-ui';

type Props = {
	icon?: LucideIcon;
	title: string;
	description?: string;
	action?: ReactNode;
	className?: string;
	compact?: boolean;
	/** The element the title renders as; the 404 page makes it the page heading. */
	titleAs?: 'div' | 'h1' | 'h2';
};

export default function EmptyState( {
	icon: Icon = FileSearch,
	title,
	description,
	action,
	className,
	compact = false,
	titleAs: Title = 'div',
}: Props ) {
	return (
		<div
			className={ cn(
				'flex w-full items-center justify-center',
				compact ? 'py-16' : 'py-40',
				className
			) }
		>
			<div className="text-center">
				<div className="mx-auto mb-4 flex size-32 items-center justify-center rounded-full bg-accent text-primary">
					<Icon size={ 52 } aria-hidden="true" />
				</div>
				<Title className="text-lg font-semibold text-foreground">
					{ title }
				</Title>
				{ description && (
					<div className="mt-1 text-sm text-muted-foreground">
						{ description }
					</div>
				) }
				{ action && <div className="mt-6">{ action }</div> }
			</div>
		</div>
	);
}
