/**
 * Affiliates: every affiliate, by status.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import {
	Ban,
	Eye,
	Link2,
	Pause,
	Pencil,
	Play,
	Plus,
	Trash2,
	UserCheck,
} from 'lucide-react';
import {
	Button,
	DataViews,
	toast,
	type DataViewAction,
	type DataViewField,
	type DataViewState,
} from '@wedevs/plugin-ui';
import PageHeader from '@/components/PageHeader';
import StatusBadge from '@/components/StatusBadge';
import DateTime from '@/components/DateTime';
import Money from '@/components/Money';
import Truncated from '@/components/Truncated';
import { useListView } from '@/hooks/useListView';
import { useCounts } from '@/hooks/useCounts';
import { errorMessage, send } from '@/lib/api';
import { copyText } from '@/lib/clipboard';
import { getGlobals } from '@/lib/globals';
import { withIconLabels } from '@/lib/actions';
import { buildTabs } from '@/lib/tabs';
import type { Affiliate } from '@/lib/types';
import AffiliateForm from './AffiliateForm';

const DEFAULT_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	// Columns follow the prototype: name, id, earnings, commission counts,
	// then our own payment email and joined date, status last.
	fields: [
		'affiliate_id',
		'paid_earnings',
		'unpaid_earnings',
		'paid_commissions',
		'unpaid_commissions',
		'payment_email',
		'created_at',
		'status',
	],
	titleField: 'name',
	layout: {
		// Explicit widths, or the last column absorbs the leftover space.
		styles: {
			name: { width: '16%' },
			affiliate_id: { width: '9%' },
			paid_earnings: { width: '11%' },
			unpaid_earnings: { width: '11%' },
			paid_commissions: { width: '10%' },
			unpaid_commissions: { width: '11%' },
			payment_email: { width: '14%' },
			created_at: { width: '9%' },
			status: { width: '9%' },
		},
	},
};

export default function AffiliatesPage() {
	const navigate = useNavigate();
	const { statuses } = getGlobals();
	// `#/affiliates?new=1` (the setup wizard's last step) opens the form at
	// once; `?status=pending` (the Dashboard's review link) opens that tab.
	const [ searchParams, setSearchParams ] = useSearchParams();
	const [ status, setStatus ] = useState( () => {
		const wanted = searchParams.get( 'status' ) ?? '';
		return wanted in statuses.affiliate ? wanted : 'all';
	} );
	const [ formOpen, setFormOpen ] = useState(
		searchParams.get( 'new' ) === '1'
	);

	useEffect( () => {
		if ( searchParams.has( 'new' ) || searchParams.has( 'status' ) ) {
			setSearchParams( {}, { replace: true } );
		}
	}, [ searchParams, setSearchParams ] );
	const [ editing, setEditing ] = useState< Affiliate | null >( null );
	// Controlled, or plugin-ui never shows the bulk toolbar (as in Dokan).
	const [ selection, setSelection ] = useState< string[] >( [] );

	const filters = useMemo(
		() => ( status === 'all' ? {} : { status } ),
		[ status ]
	);
	const list = useListView< Affiliate >( {
		path: '/affiliates',
		defaultView: DEFAULT_VIEW,
		filters,
	} );
	const counts = useCounts(
		'/affiliates',
		'status',
		Object.keys( statuses.affiliate )
	);

	const refresh = () => {
		setSelection( [] );
		list.refresh();
		counts.refresh();
	};

	const setStatuses = async ( items: Affiliate[], next: string ) => {
		try {
			await Promise.all(
				items.map( ( item ) =>
					send( `/affiliates/${ item.id }`, 'PUT', { status: next } )
				)
			);
			toast.success(
				sprintf(
					/* translators: %d: number of affiliates */
					_n(
						'%d affiliate updated.',
						'%d affiliates updated.',
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

	const fields: DataViewField< Affiliate >[] = [
		{
			id: 'name',
			label: __( 'Name', 'flyaffiliate' ),
			enableGlobalSearch: true,
			enableSorting: false,
			getValue: ( { item } ) => item.name,
			// The Dokan list shape: the link, and a muted line under it only
			// when the username says something the name does not.
			render: ( { item } ) => (
				<div className="flex min-w-0 flex-col gap-1">
					<Link
						to={ `/affiliates/${ item.id }` }
						className="block font-medium text-primary hover:underline"
					>
						<Truncated text={ item.name } max={ 26 } />
					</Link>
					{ item.user_login && item.user_login !== item.name && (
						<span className="block truncate text-xs text-muted-foreground">
							{ item.user_login }
						</span>
					) }
				</div>
			),
		},
		{
			id: 'affiliate_id',
			label: __( 'Affiliate ID', 'flyaffiliate' ),
			enableSorting: false,
			getValue: ( { item } ) => item.id,
			render: ( { item } ) => (
				<span className="tabular-nums">#{ item.id }</span>
			),
		},
		{
			id: 'paid_earnings',
			label: __( 'Paid Earnings', 'flyaffiliate' ),
			enableSorting: false,
			getValue: ( { item } ) => item.totals?.paid ?? 0,
			render: ( { item } ) => <Money amount={ item.totals?.paid ?? 0 } />,
		},
		{
			id: 'unpaid_earnings',
			label: __( 'Unpaid Earnings', 'flyaffiliate' ),
			enableSorting: false,
			getValue: ( { item } ) => item.totals?.unpaid ?? 0,
			render: ( { item } ) => (
				<Money
					amount={ item.totals?.unpaid ?? 0 }
					className="font-semibold"
				/>
			),
		},
		{
			id: 'paid_commissions',
			label: __( 'Paid Commissions', 'flyaffiliate' ),
			enableSorting: false,
			getValue: ( { item } ) => item.totals?.paid_count ?? 0,
			render: ( { item } ) => (
				<span className="tabular-nums">
					{ item.totals?.paid_count ?? 0 }
				</span>
			),
		},
		{
			id: 'unpaid_commissions',
			label: __( 'Unpaid Commissions', 'flyaffiliate' ),
			enableSorting: false,
			getValue: ( { item } ) => item.totals?.unpaid_count ?? 0,
			render: ( { item } ) => (
				<span className="tabular-nums">
					{ item.totals?.unpaid_count ?? 0 }
				</span>
			),
		},
		{
			id: 'status',
			label: __( 'Status', 'flyaffiliate' ),
			getValue: ( { item } ) => item.status,
			render: ( { item } ) => (
				<StatusBadge status={ item.status } kind="affiliate" />
			),
		},
		{
			id: 'payment_email',
			label: __( 'Payment email', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.payment_email ? (
					<Truncated
						text={ item.payment_email }
						max={ 28 }
						className="text-muted-foreground"
					/>
				) : (
					<span className="text-muted-foreground">—</span>
				),
		},
		{
			id: 'created_at',
			label: __( 'Joined', 'flyaffiliate' ),
			render: ( { item } ) => <DateTime value={ item.created_at } />,
		},
	];

	const statusAction = (
		id: string,
		label: string,
		icon: React.ReactNode,
		next: string,
		tone: 'positive' | 'destructive' | 'default' = 'default'
	): DataViewAction< Affiliate > => ( {
		id,
		label,
		icon,
		supportsBulk: true,
		isEligible: ( item ) => item.status !== next,
		isDestructive: tone !== 'default',
		confirmTone: tone,
		confirmTitle: label,
		confirmMessage: sprintf(
			/* translators: %s: the new status */
			__( 'Set the selected affiliates to “%s”?', 'flyaffiliate' ),
			statuses.affiliate[ next ] ?? next
		),
		callback: ( items ) => setStatuses( items, next ),
	} );

	const actions: DataViewAction< Affiliate >[] = [
		{
			id: 'view',
			label: __( 'View', 'flyaffiliate' ),
			icon: <Eye size={ 16 } />,
			callback: ( [ item ] ) => navigate( `/affiliates/${ item.id }` ),
		},
		{
			id: 'edit',
			label: __( 'Edit', 'flyaffiliate' ),
			icon: <Pencil size={ 16 } />,
			callback: ( [ item ] ) => {
				setEditing( item );
				setFormOpen( true );
			},
		},
		{
			id: 'copy_link',
			label: __( 'Copy referral link', 'flyaffiliate' ),
			icon: <Link2 size={ 16 } />,
			callback: async ( [ item ] ) => {
				if ( await copyText( item.referral_url ) ) {
					toast.success(
						__( 'Referral link copied.', 'flyaffiliate' )
					);
					return;
				}

				toast.error(
					__( 'The link could not be copied.', 'flyaffiliate' )
				);
			},
		},
		statusAction(
			'activate',
			__( 'Activate', 'flyaffiliate' ),
			<UserCheck size={ 16 } />,
			'active',
			'positive'
		),
		statusAction(
			'deactivate',
			__( 'Deactivate', 'flyaffiliate' ),
			<Pause size={ 16 } />,
			'inactive'
		),
		statusAction(
			'suspend',
			__( 'Suspend', 'flyaffiliate' ),
			<Ban size={ 16 } />,
			'suspended',
			'destructive'
		),
		statusAction(
			'reinstate',
			__( 'Set pending', 'flyaffiliate' ),
			<Play size={ 16 } />,
			'pending'
		),
		{
			id: 'delete',
			label: __( 'Delete', 'flyaffiliate' ),
			icon: <Trash2 size={ 16 } />,
			isDestructive: true,
			supportsBulk: true,
			confirmTitle: __( 'Delete affiliate', 'flyaffiliate' ),
			confirmMessage: __(
				'The affiliate record is removed. Their commissions, visits and payouts are financial records and are kept.',
				'flyaffiliate'
			),
			callback: async ( items ) => {
				try {
					await Promise.all(
						items.map( ( item ) =>
							send( `/affiliates/${ item.id }`, 'DELETE', {
								force: true,
							} )
						)
					);
					toast.success( __( 'Affiliate deleted.', 'flyaffiliate' ) );
					refresh();
				} catch ( error ) {
					toast.error(
						errorMessage(
							error,
							__(
								'The affiliate could not be deleted.',
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
			...Object.entries( statuses.affiliate ).map(
				( [ value, label ] ) => ( { value, label } )
			),
		],
		counts.counts,
		counts.loading
	);

	return (
		<>
			<PageHeader
				title={ __( 'Affiliates', 'flyaffiliate' ) }
				actions={
					<Button
						onClick={ () => {
							setEditing( null );
							setFormOpen( true );
						} }
						data-testid="flyaffiliate-add-affiliate"
					>
						<Plus className="size-4" />
						{ __( 'Add affiliate', 'flyaffiliate' ) }
					</Button>
				}
			/>

			<DataViews< Affiliate >
				namespace="flyaffiliate-affiliates"
				data={ list.items }
				fields={ fields }
				view={ list.view }
				onChangeView={ list.setView }
				actions={ withIconLabels( actions ) }
				selection={ selection }
				onChangeSelection={ setSelection }
				isLoading={ list.loading || counts.loading }
				paginationInfo={ list.paginationInfo }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => String( item.id ) }
				search
				searchPlaceholder={ __( 'Search affiliates…', 'flyaffiliate' ) }
				emptyTitle={ __( 'No affiliates found', 'flyaffiliate' ) }
				emptyDescription={ __(
					'Add one here, or share the registration page.',
					'flyaffiliate'
				) }
				tabs={ {
					items: tabs,
					defaultValue: status,
					onSelect: setStatus,
				} }
			/>

			<AffiliateForm
				open={ formOpen }
				onOpenChange={ setFormOpen }
				affiliate={ editing }
				onSaved={ refresh }
			/>
		</>
	);
}
