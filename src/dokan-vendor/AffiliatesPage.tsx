/**
 * Who sent customers to the vendor's store, and what each of them earned.
 *
 * Drawn with Dokan's own table, so it looks like the vendor dashboard's other
 * lists: highest earner first, ten to a page.
 */
import { __ } from '@wordpress/i18n';
import {
	DataViews,
	DateTimeHtml,
	PriceHtml,
	type DokanField,
	type DokanView,
} from '@dokan/components';
import { useListView } from '@/hooks/useListView';

type VendorAffiliate = {
	id: number;
	name: string;
	avatar_url: string;
	orders: number;
	commissions: number;
	sales: number;
	earned: number;
	pending: number;
	unpaid: number;
	paid: number;
	last_sale: string | null;
};

const DEFAULT_VIEW: DokanView = {
	type: 'table',
	page: 1,
	perPage: 10,
	search: '',
	sort: { field: 'earned', direction: 'desc' },
	fields: [ 'name', 'orders', 'sales', 'earned', 'paid', 'last_sale' ],
	layout: {
		styles: {
			name: { width: '26%' },
			orders: { width: '10%' },
			sales: { width: '16%' },
			earned: { width: '16%' },
			paid: { width: '14%' },
			last_sale: { width: '18%' },
		},
	},
};

const fields: DokanField< VendorAffiliate >[] = [
	{
		id: 'name',
		label: __( 'Affiliate', 'flyaffiliate' ),
		enableSorting: false,
		enableHiding: false,
		render: ( { item } ) => (
			<div className="flex items-center gap-2">
				{ item.avatar_url && (
					<img
						src={ item.avatar_url }
						alt=""
						className="w-8 h-8 rounded-full"
					/>
				) }
				<span className="font-medium">
					{ item.name || __( 'Unknown affiliate', 'flyaffiliate' ) }
				</span>
			</div>
		),
	},
	{
		id: 'orders',
		label: __( 'Orders', 'flyaffiliate' ),
		render: ( { item } ) => <span>{ item.orders }</span>,
	},
	{
		id: 'sales',
		label: __( 'Sales', 'flyaffiliate' ),
		render: ( { item } ) => <PriceHtml price={ item.sales } />,
	},
	{
		id: 'earned',
		label: __( 'Commission', 'flyaffiliate' ),
		render: ( { item } ) => (
			<span className="font-semibold">
				<PriceHtml price={ item.earned } />
			</span>
		),
	},
	{
		id: 'paid',
		label: __( 'Paid', 'flyaffiliate' ),
		render: ( { item } ) => <PriceHtml price={ item.paid } />,
	},
	{
		id: 'last_sale',
		label: __( 'Last sale', 'flyaffiliate' ),
		render: ( { item } ) => (
			<DateTimeHtml.Date date={ item.last_sale ?? '' } />
		),
	},
];

export default function AffiliatesPage() {
	const list = useListView< VendorAffiliate >( {
		path: '/vendor/affiliates',
		// The hook's view is the component library's own type; Dokan's table takes the same shape.
		defaultView: DEFAULT_VIEW as never,
	} );

	return (
		<div
			className="flyaffiliate-vendor-affiliates space-y-6"
			data-testid="flyaffiliate-vendor-affiliates"
		>
			<p className="text-sm text-muted-foreground">
				{ __(
					'Affiliates who sent customers to your store, and what each of them earned. The highest earner comes first.',
					'flyaffiliate'
				) }
			</p>
			<DataViews< VendorAffiliate >
				namespace="flyaffiliate-vendor-affiliates"
				data={ list.items }
				fields={ fields }
				view={ list.view as unknown as DokanView }
				onChangeView={ ( view ) => list.setView( view as never ) }
				paginationInfo={ list.paginationInfo }
				defaultLayouts={ {
					table: { density: 'comfortable' },
					list: {},
				} }
				getItemId={ ( item ) => String( item.id ) }
				isLoading={ list.loading }
				search
				searchLabel={ __( 'Search affiliates', 'flyaffiliate' ) }
				searchPlaceholder={ __( 'Search affiliates', 'flyaffiliate' ) }
				emptyTitle={
					list.view.search
						? __(
								'No affiliates match your search',
								'flyaffiliate'
						  )
						: __( 'No affiliates yet', 'flyaffiliate' )
				}
				emptyDescription={
					list.view.search
						? __( 'Try another name.', 'flyaffiliate' )
						: __(
								'When an affiliate sends a customer who buys from your store, they show up here.',
								'flyaffiliate'
						  )
				}
			/>
		</div>
	);
}
