/**
 * One line that fills its column and is cut short by the column's width, with
 * the whole of it in a tooltip — Dokan's treatment for long cells, but keyed
 * to the space available rather than to a character count.
 */
import type { ReactNode } from 'react';
import { Tooltip, TooltipContent, TooltipTrigger, cn } from '@wedevs/plugin-ui';

type Props = {
	/** What the tooltip says; usually the untruncated text. */
	tooltip: string;
	className?: string;
	children: ReactNode;
};

export default function TruncatedLine( {
	tooltip,
	className,
	children,
}: Props ) {
	return (
		<Tooltip>
			<TooltipTrigger
				render={
					<span
						className={ cn(
							'block min-w-0 max-w-full truncate',
							className
						) }
					/>
				}
			>
				{ children }
			</TooltipTrigger>
			<TooltipContent className="max-w-sm break-words">
				{ tooltip }
			</TooltipContent>
		</Tooltip>
	);
}
