/**
 * A commission's reference: a link to its order when that order exists, the
 * bare number when it does not (a manual commission's note, or an order since
 * deleted), and the origin when there is none — never a link to nothing.
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink } from 'lucide-react';
import { Tooltip, TooltipContent, TooltipTrigger, cn } from '@wedevs/plugin-ui';
import { getGlobals } from '@/lib/globals';
import type { Commission } from '@/lib/types';

type Props = {
	commission: Pick< Commission, 'order_id' | 'order_url' | 'source' >;
	/** Shown when there is no reference at all. Defaults to the origin's label. */
	fallback?: string;
	className?: string;
};

export default function Reference( {
	commission,
	fallback,
	className,
}: Props ) {
	const { sources } = getGlobals();

	if ( commission.order_url ) {
		return (
			<a
				href={ commission.order_url }
				className={ cn(
					'inline-flex items-center gap-1 font-medium text-primary hover:underline',
					className
				) }
			>
				#{ commission.order_id }
				<ExternalLink className="size-3" aria-hidden="true" />
			</a>
		);
	}

	if ( commission.order_id > 0 && commission.source !== 'woocommerce' ) {
		// A manual commission's reference is whatever the admin noted.
		return (
			<span className={ cn( 'text-foreground tabular-nums', className ) }>
				#{ commission.order_id }
			</span>
		);
	}

	if ( commission.order_id > 0 ) {
		return (
			<Tooltip>
				<TooltipTrigger
					render={
						<span
							className={ cn(
								'cursor-default text-foreground tabular-nums',
								className
							) }
						/>
					}
				>
					#{ commission.order_id }
				</TooltipTrigger>
				<TooltipContent>
					{ __(
						'No WooCommerce order has this number.',
						'flyaffiliate'
					) }
				</TooltipContent>
			</Tooltip>
		);
	}

	return (
		<span className={ cn( 'text-muted-foreground', className ) }>
			{ fallback ?? sources[ commission.source ] ?? commission.source }
		</span>
	);
}
