/**
 * Loading states that keep the shape of the page they stand in for, the way
 * Dokan's admin draws them: every bar sits inside the card it will become,
 * never bare on the page background.
 */
import { Card, Skeleton, cn } from '@wedevs/plugin-ui';
import { StatCardSkeleton } from './StatCard';

/**
 * A back link, a title with a badge, and the action buttons on the right.
 * @param root0
 * @param root0.actions
 * @param root0.description
 */
export function HeaderSkeleton( {
	actions = 1,
	description = true,
}: {
	actions?: number;
	description?: boolean;
} ) {
	return (
		<div className="mb-6">
			<Skeleton className="mb-3 h-4 w-28" />
			<div className="flex flex-wrap items-center justify-between gap-4">
				<div className="flex flex-col gap-2">
					<div className="flex items-center gap-4">
						<Skeleton className="h-7 w-56" />
						<Skeleton className="h-5 w-16 rounded-full" />
					</div>
					{ description && <Skeleton className="h-4 w-72" /> }
				</div>
				<div className="flex items-center gap-2">
					{ Array.from( { length: actions } ).map( ( _, i ) => (
						<Skeleton key={ i } className="h-9 w-32" />
					) ) }
				</div>
			</div>
		</div>
	);
}

/**
 * A row of figure tiles.
 * @param root0
 * @param root0.count
 */
export function StatGridSkeleton( { count = 4 }: { count?: number } ) {
	return (
		<div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
			{ Array.from( { length: count } ).map( ( _, i ) => (
				<StatCardSkeleton key={ i } />
			) ) }
		</div>
	);
}

/**
 * A card of form rows: a label, then a full-width control, as on the form pages.
 * @param root0
 * @param root0.rows
 * @param root0.title
 * @param root0.className
 */
export function FormCardSkeleton( {
	rows = 4,
	title = true,
	className,
}: {
	rows?: number;
	title?: boolean;
	className?: string;
} ) {
	return (
		<Card
			className={ cn(
				'gap-0 rounded-md py-0 shadow ring-0 divide-y divide-border',
				className
			) }
		>
			{ title && (
				<div className="px-5 py-4">
					<Skeleton className="h-5 w-40" />
				</div>
			) }
			{ Array.from( { length: rows } ).map( ( _, i ) => (
				<div key={ i } className="flex flex-col gap-2.5 px-5 py-4">
					<Skeleton className="h-4 w-28" />
					<Skeleton className="h-9 w-full" />
				</div>
			) ) }
		</Card>
	);
}

/**
 * A list card: the tab strip, a header row and the rows under it.
 * @param root0
 * @param root0.rows
 * @param root0.columns
 * @param root0.tabs
 */
export function TableSkeleton( {
	rows = 6,
	columns = 6,
	tabs = 3,
}: {
	rows?: number;
	columns?: number;
	tabs?: number;
} ) {
	return (
		<div className="flex flex-col gap-4">
			{ tabs > 0 && (
				<div className="flex items-center gap-2">
					{ Array.from( { length: tabs } ).map( ( _, i ) => (
						<Skeleton key={ i } className="h-8 w-24 rounded-md" />
					) ) }
				</div>
			) }
			<Card className="gap-0 rounded-md py-0 shadow ring-0 divide-y divide-border">
				<div
					className="grid gap-4 px-4 py-3"
					style={ {
						gridTemplateColumns: `repeat(${ columns }, minmax(0, 1fr))`,
					} }
				>
					{ Array.from( { length: columns } ).map( ( _, i ) => (
						<Skeleton key={ i } className="h-4 w-3/5" />
					) ) }
				</div>
				{ Array.from( { length: rows } ).map( ( _row, r ) => (
					<div
						key={ r }
						className="grid gap-4 px-4 py-4"
						style={ {
							gridTemplateColumns: `repeat(${ columns }, minmax(0, 1fr))`,
						} }
					>
						{ Array.from( { length: columns } ).map(
							( _cell, c ) => (
								<Skeleton
									key={ c }
									className={ cn(
										'h-4',
										c === 0 ? 'w-1/2' : 'w-4/5'
									) }
								/>
							)
						) }
					</div>
				) ) }
			</Card>
		</div>
	);
}
