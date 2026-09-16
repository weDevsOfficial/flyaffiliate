/**
 * A page title with optional actions, matching Dokan's admin screens: a
 * small back link above, the title with a status badge beside it, the
 * actions on the right.
 */
import type { ReactNode } from 'react';
import { ChevronLeft } from 'lucide-react';
import { Link } from 'react-router-dom';

type Props = {
	title: string;
	description?: string;
	actions?: ReactNode;
	badge?: ReactNode;
	backTo?: { to: string; label: string };
};

export default function PageHeader( {
	title,
	description,
	actions,
	badge,
	backTo,
}: Props ) {
	return (
		<div className="mb-6">
			{ backTo && (
				<Link
					to={ backTo.to }
					className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-primary"
				>
					<ChevronLeft className="size-4" aria-hidden="true" />
					{ backTo.label }
				</Link>
			) }
			<div className="flex flex-wrap items-center justify-between gap-4">
				<div className="min-w-0">
					<h1 className="flex flex-wrap items-center gap-4 text-2xl font-semibold leading-tight text-foreground">
						<span className="truncate">{ title }</span>
						{ badge }
					</h1>
					{ description && (
						<p className="mt-1 text-sm text-muted-foreground">
							{ description }
						</p>
					) }
				</div>
				{ actions && (
					<div className="flex shrink-0 items-center gap-2">
						{ actions }
					</div>
				) }
			</div>
		</div>
	);
}
