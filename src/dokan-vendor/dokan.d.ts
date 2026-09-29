/**
 * What Dokan shares with a script on its vendor dashboard.
 *
 * These modules are not installed: the build maps each import to a global
 * Dokan puts on the page (see DOKAN_EXTERNALS in webpack.config.js), so only
 * the parts used here are described.
 */
declare module '@dokan/components' {
	import type { ComponentType, ReactNode } from 'react';

	export type DokanField< Item > = {
		id: string;
		label: string;
		enableSorting?: boolean;
		enableHiding?: boolean;
		render?: ( args: { item: Item } ) => ReactNode;
	};

	export type DokanView = {
		type: 'table';
		page: number;
		perPage: number;
		search?: string;
		fields: string[];
		sort?: { field: string; direction: 'asc' | 'desc' };
		layout?: { styles?: Record< string, { width?: string } > };
	};

	export type DokanDataViewsProps< Item > = {
		namespace: string;
		data: Item[];
		fields: DokanField< Item >[];
		view: DokanView;
		onChangeView: ( view: DokanView ) => void;
		paginationInfo: { totalItems: number; totalPages: number };
		getItemId: ( item: Item ) => string;
		defaultLayouts?: Record< string, unknown >;
		isLoading?: boolean;
		search?: boolean;
		searchLabel?: string;
		searchPlaceholder?: string;
		actions?: unknown[];
		emptyTitle?: string;
		emptyDescription?: string;
	};

	export function DataViews< Item >(
		props: DokanDataViewsProps< Item >
	): JSX.Element;

	export const PriceHtml: ComponentType< { price: string | number } >;

	export const DateTimeHtml: ComponentType< {
		date: string;
		defaultDate?: ReactNode;
	} > & {
		Date: ComponentType< { date: string; defaultDate?: ReactNode } >;
	};
}
