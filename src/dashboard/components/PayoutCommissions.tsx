/**
 * The commissions inside one payout, as SliceWP's affiliate account expands a
 * payment into its commissions. DataViews has no row expansion, so the list
 * opens in a dialog, the way a commission opens its details.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	DataViews,
	Dialog,
	DialogContent,
	DialogHeader,
	DialogTitle,
	type DataViewField,
	type DataViewState,
} from '@wedevs/plugin-ui';
import DateTime from '@/components/DateTime';
import Money from '@/components/Money';
import StatusBadge from '@/components/StatusBadge';
import { useListView } from '@/hooks/useListView';
import { formatMoney } from '@/lib/format';
import { getGlobals } from '@/lib/globals';
import type { Commission, Payout } from '@/lib/types';

const DEFAULT_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	fields: [ 'base_amount', 'amount', 'status', 'created_at' ],
	titleField: 'order',
	layout: {
		styles: {
			order: { width: '20%' },
			base_amount: { width: '20%' },
			amount: { width: '20%' },
			status: { width: '16%' },
			created_at: { width: '24%' },
		},
	},
};

function CommissionsList( { payoutId }: { payoutId: number } ) {
	const { sources } = getGlobals();
	const list = useListView< Commission >( {
		path: '/me/commissions',
		defaultView: DEFAULT_VIEW,
		filters: { payout_id: payoutId },
	} );

	const fields: DataViewField< Commission >[] = [
		{
			id: 'order',
			label: __( 'Reference', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.order_id ? (
					<span className="font-medium">#{ item.order_id }</span>
				) : (
					<span className="text-muted-foreground">
						{ sources[ item.source ] ?? item.source }
					</span>
				),
		},
		{
			id: 'base_amount',
			label: __( 'Sale', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				Number( item.base_amount ) > 0 ? (
					<Money
						amount={ item.base_amount }
						className="text-muted-foreground"
					/>
				) : (
					<span className="text-muted-foreground">—</span>
				),
		},
		{
			id: 'amount',
			label: __( 'Amount', 'flyaffiliate' ),
			render: ( { item } ) => (
				<Money amount={ item.amount } className="font-semibold" />
			),
		},
		{
			id: 'status',
			label: __( 'Status', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<StatusBadge status={ item.status } kind="commission" />
			),
		},
		{
			id: 'created_at',
			label: __( 'Date', 'flyaffiliate' ),
			render: ( { item } ) => <DateTime value={ item.created_at } />,
		},
	];

	return (
		<div className="px-6 pb-6">
			<div
				className="flyaffiliate-payout-commissions overflow-hidden rounded-md border border-border"
				data-testid="flyaffiliate-payout-commissions"
			>
				<DataViews< Commission >
					namespace="flyaffiliate-my-payout-commissions"
					data={ list.items }
					fields={ fields }
					view={ list.view }
					onChangeView={ list.setView }
					isLoading={ list.loading }
					paginationInfo={ list.paginationInfo }
					defaultLayouts={ { table: {} } }
					getItemId={ ( item ) => String( item.id ) }
					search={ false }
					emptyTitle={ __( 'No commissions', 'flyaffiliate' ) }
					emptyDescription={ __(
						'This payout has no commissions attached.',
						'flyaffiliate'
					) }
					tabs={ { items: [], headerContent: [] } }
				/>
			</div>
		</div>
	);
}

type Props = {
	payout: Payout | null;
	onClose: () => void;
};

/**
 * One fact about the payout: a quiet label with the value under it.
 * @param root0
 * @param root0.label
 * @param root0.children
 */
function Fact( {
	label,
	children,
}: {
	label: string;
	children: React.ReactNode;
} ) {
	return (
		<div className="flex min-w-0 flex-col gap-1">
			<dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
				{ label }
			</dt>
			<dd className="m-0 truncate text-sm text-foreground">
				{ children }
			</dd>
		</div>
	);
}

export default function PayoutCommissions( { payout, onClose }: Props ) {
	// The last payout stays for the title while the dialog closes.
	const [ shown, setShown ] = useState< Payout | null >( payout );

	useEffect( () => {
		if ( payout ) {
			setShown( payout );
		}
	}, [ payout ] );

	return (
		<Dialog
			open={ Boolean( payout ) }
			onOpenChange={ ( open ) => ! open && onClose() }
		>
			<DialogContent
				className="gap-0 overflow-hidden p-0 sm:max-w-3xl"
				data-testid="flyaffiliate-payout-details"
			>
				<DialogHeader className="border-b border-border px-6 py-4 pr-14">
					<DialogTitle className="text-lg font-semibold leading-6 text-foreground">
						{ shown
							? sprintf(
									/* translators: %s: the payout amount */
									__( 'Payout of %s', 'flyaffiliate' ),
									formatMoney( shown.amount )
							  )
							: __( 'Payout', 'flyaffiliate' ) }
					</DialogTitle>
				</DialogHeader>

				<div className="flex flex-col gap-4 px-6 py-5">
					{ shown && (
						<dl className="m-0 grid grid-cols-1 gap-4 sm:grid-cols-3">
							<Fact label={ __( 'Date', 'flyaffiliate' ) }>
								<DateTime value={ shown.created_at } withTime />
							</Fact>
							<Fact label={ __( 'Status', 'flyaffiliate' ) }>
								<StatusBadge status={ shown.status } />
							</Fact>
							<Fact label={ __( 'Reference', 'flyaffiliate' ) }>
								{ shown.reference || '—' }
							</Fact>
						</dl>
					) }
					<p className="m-0 text-sm text-muted-foreground">
						{ __(
							'The following commissions have been included in this payout.',
							'flyaffiliate'
						) }
					</p>
				</div>
				{ payout && <CommissionsList payoutId={ payout.id } /> }
			</DialogContent>
		</Dialog>
	);
}
