/**
 * The payout preview: everyone owed, grouped by the payout method that pays
 * them, each with a checkbox. A row the method cannot pay says why before any
 * money moves, and can be switched to Manual for this payout.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link } from 'react-router-dom';
import { Hand, TriangleAlert, Zap } from 'lucide-react';
import { Button, Checkbox, LabeledCheckbox, cn } from '@wedevs/plugin-ui';
import MethodName from '@/components/MethodName';
import Money from '@/components/Money';
import type { PayoutPreview, PayoutPreviewRow } from '@/lib/types';

type Props = {
	preview: PayoutPreview;
	/** The affiliates ticked to be paid. */
	selected: Set< number >;
	onToggle: ( ids: number[], checked: boolean ) => void;
	onSwitchToManual: ( affiliateId: number ) => void;
	busy: boolean;
};

/**
 * The checkbox state for a set of rows: all, some or none ticked.
 *
 * @param {number[]}    ids      The rows.
 * @param {Set<number>} selected The ticked rows.
 * @return {Object} `checked` and `indeterminate`.
 */
function tickState( ids: number[], selected: Set< number > ) {
	const ticked = ids.filter( ( id ) => selected.has( id ) ).length;

	return {
		checked: ids.length > 0 && ticked === ids.length,
		indeterminate: ticked > 0 && ticked < ids.length,
	};
}

export default function PreviewList( {
	preview,
	selected,
	onToggle,
	onSwitchToManual,
	busy,
}: Props ) {
	const allIds = preview.rows.map( ( row ) => row.affiliate_id );
	const rowsFor = ( method: string ): PayoutPreviewRow[] =>
		preview.rows.filter( ( row ) => row.method === method );

	return (
		<div className="overflow-hidden rounded-md border border-border">
			<table
				className="w-full border-collapse text-sm"
				data-testid="flyaffiliate-payout-preview-table"
			>
				<thead>
					<tr className="border-b border-border bg-muted/30">
						<th
							colSpan={ 3 }
							className="px-4 py-3 text-left font-normal"
						>
							<div className="flex items-center justify-between gap-4">
								<LabeledCheckbox
									{ ...tickState( allIds, selected ) }
									disabled={ busy }
									onCheckedChange={ ( checked ) =>
										onToggle( allIds, !! checked )
									}
									label={ __( 'Select all', 'flyaffiliate' ) }
									data-testid="flyaffiliate-payout-select-all"
								/>
								<span className="whitespace-nowrap text-muted-foreground tabular-nums">
									{ sprintf(
										/* translators: 1: affiliates ticked, 2: affiliates owed */
										__(
											'%1$d of %2$d selected',
											'flyaffiliate'
										),
										selected.size,
										allIds.length
									) }
								</span>
							</div>
						</th>
					</tr>
				</thead>

				{ preview.groups.map( ( group ) => {
					const rows = rowsFor( group.method );
					const ids = rows.map( ( row ) => row.affiliate_id );
					const warnings = rows.filter(
						( row ) => row.warning
					).length;

					return (
						<tbody
							key={ group.method }
							data-testid={ `flyaffiliate-payout-group-${ group.method }` }
						>
							<tr className="border-b border-border bg-muted/50">
								<td className="w-12 py-3 pl-4 align-middle">
									<Checkbox
										{ ...tickState( ids, selected ) }
										disabled={ busy }
										onCheckedChange={ ( checked ) =>
											onToggle( ids, !! checked )
										}
										aria-label={ sprintf(
											/* translators: %s: payout method */
											__(
												'Select everyone paid by %s',
												'flyaffiliate'
											),
											group.label
										) }
									/>
								</td>
								<td className="py-3">
									<div className="flex flex-wrap items-center gap-x-3 gap-y-1">
										<MethodName
											id={ group.method }
											label={ group.label }
											className="font-semibold text-foreground"
										/>
										<span
											className={ cn(
												'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium leading-4',
												group.automatic
													? 'border-primary/40 bg-primary/10 text-primary'
													: 'border-border bg-muted text-foreground'
											) }
										>
											{ group.automatic ? (
												<Zap
													className="size-3"
													aria-hidden="true"
												/>
											) : (
												<Hand
													className="size-3"
													aria-hidden="true"
												/>
											) }
											{ group.automatic
												? __(
														'Sent automatically',
														'flyaffiliate'
												  )
												: __(
														'Paid by hand',
														'flyaffiliate'
												  ) }
										</span>
										<span className="text-muted-foreground">
											{ sprintf(
												/* translators: %d: number of affiliates */
												_n(
													'%d affiliate',
													'%d affiliates',
													group.count,
													'flyaffiliate'
												),
												group.count
											) }
										</span>
										{ warnings > 0 && (
											<span className="text-destructive">
												{ sprintf(
													/* translators: %d: affiliates the method cannot pay yet */
													_n(
														'· %d needs attention',
														'· %d need attention',
														warnings,
														'flyaffiliate'
													),
													warnings
												) }
											</span>
										) }
									</div>
								</td>
								<td className="py-3 pr-4 text-right">
									<Money
										amount={ group.total }
										className="font-medium tabular-nums text-foreground"
									/>
								</td>
							</tr>

							{ rows.map( ( row ) => (
								<tr
									key={ row.affiliate_id }
									className="border-b border-border last:border-b-0"
									data-testid={ `flyaffiliate-payout-row-${ row.affiliate_id }` }
								>
									<td className="w-12 py-4 pl-4 align-top">
										<Checkbox
											checked={ selected.has(
												row.affiliate_id
											) }
											disabled={ busy }
											onCheckedChange={ ( checked ) =>
												onToggle(
													[ row.affiliate_id ],
													!! checked
												)
											}
											aria-label={ sprintf(
												/* translators: %s: affiliate name */
												__( 'Pay %s', 'flyaffiliate' ),
												row.name
											) }
										/>
									</td>
									<td className="py-4">
										<div className="flex flex-wrap items-baseline gap-x-2">
											<Link
												to={ `/affiliates/${ row.affiliate_id }` }
												className="font-medium text-primary hover:underline"
											>
												{ row.name }
											</Link>
											<span className="text-xs text-muted-foreground">
												{ sprintf(
													/* translators: %d: number of commissions */
													_n(
														'%d commission',
														'%d commissions',
														row.commissions.length,
														'flyaffiliate'
													),
													row.commissions.length
												) }
											</span>
										</div>
										{ /* With a warning, the warning says what is missing. */ }
										{ ( row.recipient ||
											! row.warning ) && (
											<div className="mt-1 truncate text-muted-foreground">
												{ row.recipient || '—' }
											</div>
										) }
										{ row.warning && (
											<div
												className="mt-2 flex flex-wrap items-center gap-3 text-destructive"
												role="alert"
											>
												<span className="inline-flex items-center gap-1.5">
													<TriangleAlert className="size-4" />
													{ row.warning }
												</span>
												{ 'manual' !== row.method && (
													<Button
														size="sm"
														variant="outline"
														disabled={ busy }
														onClick={ () =>
															onSwitchToManual(
																row.affiliate_id
															)
														}
													>
														{ __(
															'Switch to Manual',
															'flyaffiliate'
														) }
													</Button>
												) }
											</div>
										) }
									</td>
									<td
										className={ cn(
											'py-4 pr-4 text-right align-top',
											! selected.has(
												row.affiliate_id
											) && 'opacity-60'
										) }
									>
										<Money
											amount={ row.amount }
											className="font-semibold tabular-nums text-foreground"
										/>
									</td>
								</tr>
							) ) }
						</tbody>
					);
				} ) }
			</table>
		</div>
	);
}
