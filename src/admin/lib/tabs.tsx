/**
 * The status tabs above a list, with their counts.
 *
 * While the counts load, each tab shows a small placeholder where the number
 * goes, so the labels do not jump when the figures arrive.
 */
import { Skeleton } from '@wedevs/plugin-ui';

export type TabSpec = { value: string; label: string };

type TabItem = { value: string; label: string; count?: number };

/**
 * Build DataViews tab items from labels and counts.
 *
 * @param {TabSpec[]} specs   The tabs, in order.
 * @param {Object}    counts  Value => count.
 * @param {boolean}   loading Whether the counts are still loading.
 * @return {TabItem[]} The items.
 */
export function buildTabs(
	specs: TabSpec[],
	counts: Record< string, number >,
	loading: boolean
): TabItem[] {
	return specs.map( ( { value, label } ) => {
		if ( loading && counts[ value ] === undefined ) {
			return {
				value,
				// plugin-ui types the label as a string but renders it as a
				// node, which is what lets the placeholder sit inline.
				label: (
					<span className="inline-flex items-center gap-1.5">
						{ label }
						<Skeleton className="inline-block h-3.5 w-6 rounded-sm" />
					</span>
				 ) as unknown as string,
			};
		}

		return { value, label, count: counts[ value ] ?? 0 };
	} );
}
