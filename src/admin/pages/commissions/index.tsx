/**
 * Commissions: the ledger, by status.
 *
 * `CommissionsTable` is reused by the affiliate detail page with a fixed
 * affiliate; the page itself adds the header and the affiliate filter.
 */
import { useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import {
	Check,
	Clock,
	ExternalLink,
	Pencil,
	Plus,
	Trash2,
	UserRound,
	XCircle,
} from 'lucide-react';
import {
	Button,
	DataViews,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	toast,
	type DataViewAction,
	type DataViewField,
	type DataViewState,
} from '@wedevs/plugin-ui';
import PageHeader from '@/components/PageHeader';
import StatusBadge from '@/components/StatusBadge';
import DateTime from '@/components/DateTime';
import Matures from '@/components/Matures';
import Money from '@/components/Money';
import AffiliatePicker from '@/components/AffiliatePicker';
import { useListView } from '@/hooks/useListView';
import { useCounts } from '@/hooks/useCounts';
import { errorMessage, send } from '@/lib/api';
import { getGlobals } from '@/lib/globals';
import { withIconLabels } from '@/lib/actions';
import { buildTabs } from '@/lib/tabs';
import type { Commission } from '@/lib/types';
import Truncated from '@/components/Truncated';

const DEFAULT_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	// Column order follows the prototype: affiliate, amount, reference, type,
	// platform, date, status last.
	fields: [
		'affiliate',
		'base_amount',
		'rate',
		'amount',
		'order',
		'type',
		'platform',
		'created_at',
		'matures_at',
		'status',
	],
	titleField: 'id',
	layout: {
		// Explicit widths, or the last column absorbs the leftover space.
		styles: {
			id: { width: '8%' },
			affiliate: { width: '13%' },
			base_amount: { width: '7%' },
			rate: { width: '5%' },
			amount: { width: '8%' },
			order: { width: '7%' },
			type: { width: '6%' },
			platform: { width: '10%' },
			created_at: { width: '13%' },
			matures_at: { width: '13%' },
			status: { width: '10%' },
		},
	},
};

type TableProps = {
	affiliateId?: number;
	headerContent?: React.ReactNode[];
	extraFilters?: Record< string, unknown >;
	onChanged?: () => void;
};

export function CommissionsTable( {
	affiliateId,
	headerContent = [],
	extraFilters = {},
	onChanged,
}: TableProps ) {
	const { statuses, urls, sources, types } = getGlobals();
	const navigate = useNavigate();
	const location = useLocation();
	const [ status, setStatus ] = useState( 'all' );
	// The form page comes back here when it is done.
	const here = { from: location.pathname };
	// Controlled, or plugin-ui never shows the bulk toolbar (as in Dokan).
	const [ selection, setSelection ] = useState< string[] >( [] );

	const baseFilters = useMemo(
		() => ( {
			...( affiliateId ? { affiliate_id: affiliateId } : {} ),
			...extraFilters,
		} ),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ affiliateId, JSON.stringify( extraFilters ) ]
	);
	const filters = useMemo(
		() => ( status === 'all' ? baseFilters : { ...baseFilters, status } ),
		[ baseFilters, status ]
	);

	const list = useListView< Commission >( {
		path: '/commissions',
		defaultView: DEFAULT_VIEW,
		filters,
	} );
	const counts = useCounts(
		'/commissions',
		'status',
		Object.keys( statuses.commission ),
		baseFilters
	);

	const refresh = () => {
		setSelection( [] );
		list.refresh();
		counts.refresh();
		onChanged?.();
	};

	const setStatuses = async ( items: Commission[], next: string ) => {
		try {
			await Promise.all(
				items.map( ( item ) =>
					send( `/commissions/${ item.id }`, 'PUT', { status: next } )
				)
			);
			toast.success(
				sprintf(
					/* translators: %d: number of commissions */
					_n(
						'%d commission updated.',
						'%d commissions updated.',
						items.length,
						'flyaffiliate'
					),
					items.length
				)
			);
			refresh();
		} catch ( error ) {
			toast.error(
				errorMessage(
					error,
					__( 'The status could not be changed.', 'flyaffiliate' )
				)
			);
		}
	};

	const fields: DataViewField< Commission >[] = [
		{
			id: 'id',
			label: __( 'Commission ID', 'flyaffiliate' ),
			render: ( { item } ) => (
				<Link
					to={ `/commissions/${ item.id }/edit` }
					state={ here }
					className="font-medium text-primary hover:underline"
				>
					#{ item.id }
				</Link>
			),
		},
		{
			id: 'affiliate',
			label: __( 'Affiliate', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<Link
					to={ `/affiliates/${ item.affiliate_id }` }
					className="font-medium text-primary hover:underline"
				>
					<Truncated
						text={
							item.affiliate_name || `#${ item.affiliate_id }`
						}
						max={ 22 }
					/>
				</Link>
			),
		},
		{
			id: 'order',
			label: __( 'Reference', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.order_id ? (
					<a
						href={ `${ urls.orders }${ item.order_id }` }
						className="font-medium text-primary hover:underline"
					>
						#{ item.order_id }
					</a>
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
			id: 'rate',
			label: __( 'Rate', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.source === 'manual' ? (
					<span className="text-muted-foreground">—</span>
				) : (
					<span>
						{ item.rate_type === 'percentage' ? (
							`${ item.rate }%`
						) : (
							<Money amount={ item.rate } />
						) }
					</span>
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
			id: 'type',
			label: __( 'Type', 'flyaffiliate' ),
			enableSorting: false,
			getValue: ( { item } ) => item.type,
			render: ( { item } ) => (
				<span>{ types[ item.type ] ?? item.type }</span>
			),
		},
		{
			id: 'platform',
			label: __( 'Platform', 'flyaffiliate' ),
			enableSorting: false,
			getValue: ( { item } ) => item.source,
			render: ( { item } ) => (
				<span className="text-muted-foreground">
					{ sources[ item.source ] ?? item.source }
				</span>
			),
		},
		{
			id: 'status',
			label: __( 'Status', 'flyaffiliate' ),
			render: ( { item } ) => (
				<StatusBadge status={ item.status } kind="commission" />
			),
		},
		{
			id: 'matures_at',
			label: __( 'Matures', 'flyaffiliate' ),
			render: ( { item } ) => (
				<Matures
					maturesAt={ item.matures_at }
					createdAt={ item.created_at }
				/>
			),
		},
		{
			id: 'created_at',
			label: __( 'Date', 'flyaffiliate' ),
			render: ( { item } ) => <DateTime value={ item.created_at } />,
		},
	];

	const actions: DataViewAction< Commission >[] = [
		{
			id: 'view-affiliate',
			label: __( 'View affiliate', 'flyaffiliate' ),
			icon: <UserRound size={ 16 } />,
			callback: ( [ item ] ) =>
				navigate( `/affiliates/${ item.affiliate_id }` ),
		},
		{
			id: 'view-order',
			label: __( 'View order', 'flyaffiliate' ),
			icon: <ExternalLink size={ 16 } />,
			isEligible: ( item ) => item.order_id > 0,
			callback: ( [ item ] ) => {
				window.location.assign( `${ urls.orders }${ item.order_id }` );
			},
		},
		{
			id: 'approve',
			label: __( 'Mark unpaid', 'flyaffiliate' ),
			icon: <Check size={ 16 } />,
			supportsBulk: true,
			isEligible: ( item ) =>
				item.status === 'pending' || item.status === 'rejected',
			callback: ( items ) => setStatuses( items, 'unpaid' ),
		},
		{
			id: 'hold',
			label: __( 'Mark pending', 'flyaffiliate' ),
			icon: <Clock size={ 16 } />,
			supportsBulk: true,
			isEligible: ( item ) =>
				item.status === 'unpaid' || item.status === 'rejected',
			callback: ( items ) => setStatuses( items, 'pending' ),
		},
		{
			id: 'reject',
			label: __( 'Reject', 'flyaffiliate' ),
			icon: <XCircle size={ 16 } />,
			supportsBulk: true,
			isDestructive: true,
			confirmTitle: __( 'Reject commission', 'flyaffiliate' ),
			confirmMessage: __(
				'A rejected commission is never paid. This cannot be undone.',
				'flyaffiliate'
			),
			isEligible: ( item ) =>
				item.status === 'pending' || item.status === 'unpaid',
			callback: ( items ) => setStatuses( items, 'rejected' ),
		},
		{
			id: 'edit',
			label: __( 'Edit', 'flyaffiliate' ),
			icon: <Pencil size={ 16 } />,
			callback: ( [ item ] ) =>
				navigate( `/commissions/${ item.id }/edit`, { state: here } ),
		},
		{
			id: 'delete',
			label: __( 'Delete', 'flyaffiliate' ),
			icon: <Trash2 size={ 16 } />,
			isDestructive: true,
			supportsBulk: true,
			confirmTitle: __( 'Delete commission', 'flyaffiliate' ),
			confirmMessage: __(
				'The commission is removed from the ledger. Paid commissions cannot be deleted.',
				'flyaffiliate'
			),
			isEligible: ( item ) => item.status !== 'paid',
			callback: async ( items ) => {
				try {
					await Promise.all(
						items.map( ( item ) =>
							send( `/commissions/${ item.id }`, 'DELETE' )
						)
					);
					toast.success(
						__( 'Commission deleted.', 'flyaffiliate' )
					);
					refresh();
				} catch ( error ) {
					toast.error(
						errorMessage(
							error,
							__(
								'The commission could not be deleted.',
								'flyaffiliate'
							)
						)
					);
				}
			},
		},
	];

	const tabs = buildTabs(
		[
			{ value: 'all', label: __( 'All', 'flyaffiliate' ) },
			...Object.entries( statuses.commission ).map(
				( [ value, label ] ) => ( { value, label } )
			),
		],
		counts.counts,
		counts.loading
	);

	return (
		<>
			<DataViews< Commission >
				namespace="flyaffiliate-commissions"
				data={ list.items }
				fields={ fields }
				view={ list.view }
				onChangeView={ list.setView }
				actions={ withIconLabels( actions ) }
				selection={ selection }
				onChangeSelection={ setSelection }
				isLoading={ list.loading }
				paginationInfo={ list.paginationInfo }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => String( item.id ) }
				search={ false }
				emptyTitle={ __( 'No commissions', 'flyaffiliate' ) }
				emptyDescription={ __(
					'Referred orders will show up here once tracking is on.',
					'flyaffiliate'
				) }
				tabs={ {
					items: tabs,
					defaultValue: status,
					onSelect: setStatus,
					headerContent: [
						...headerContent,
						<Button
							key="add"
							onClick={ () =>
								navigate(
									affiliateId
										? `/commissions/new?affiliate=${ affiliateId }`
										: '/commissions/new',
									{ state: here }
								)
							}
							data-testid="flyaffiliate-add-commission"
						>
							<Plus className="size-4 mr-1" />
							{ __( 'Add commission', 'flyaffiliate' ) }
						</Button>,
					],
				} }
			/>
		</>
	);
}

export default function CommissionsPage() {
	const { sources } = getGlobals();
	const [ affiliate, setAffiliate ] = useState< number | null >( null );
	const [ source, setSource ] = useState( 'all' );

	const extraFilters = useMemo(
		() => ( source === 'all' ? {} : { source } ),
		[ source ]
	);

	return (
		<>
			<PageHeader
				title={ __( 'Commissions', 'flyaffiliate' ) }
				description={ __(
					'Pending commissions become unpaid once the hold period ends. Unpaid commissions are what the next payout covers.',
					'flyaffiliate'
				) }
			/>

			<CommissionsTable
				affiliateId={ affiliate ?? undefined }
				extraFilters={ extraFilters }
				headerContent={ [
					<div key="affiliate" className="w-56">
						<AffiliatePicker
							value={ affiliate }
							onChange={ setAffiliate }
							placeholder={ __(
								'All affiliates',
								'flyaffiliate'
							) }
						/>
					</div>,
					<Select
						key="source"
						value={ source }
						onValueChange={ ( v ) => setSource( String( v ) ) }
					>
						<SelectTrigger
							className="w-40"
							aria-label={ __( 'Source', 'flyaffiliate' ) }
						>
							<SelectValue>
								{ source === 'all'
									? __( 'All sources', 'flyaffiliate' )
									: sources[ source ] ?? source }
							</SelectValue>
						</SelectTrigger>
						<SelectContent>
							<SelectItem value="all">
								{ __( 'All sources', 'flyaffiliate' ) }
							</SelectItem>
							{ Object.entries( sources ).map(
								( [ value, label ] ) => (
									<SelectItem key={ value } value={ value }>
										{ label }
									</SelectItem>
								)
							) }
						</SelectContent>
					</Select>,
				] }
			/>
		</>
	);
}
