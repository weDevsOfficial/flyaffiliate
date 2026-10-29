/**
 * An info icon that explains a field or a figure on hover, beside its label —
 * the way Dokan's forms carry help text — so the fields stay uncluttered.
 */
import { Info } from 'lucide-react';
import { Tooltip, TooltipContent, TooltipTrigger, cn } from '@wedevs/plugin-ui';

type Props = {
	text: string;
	className?: string;
};

export default function Hint( { text, className }: Props ) {
	return (
		<Tooltip>
			<TooltipTrigger
				render={
					<button
						type="button"
						className={ cn(
							'inline-flex cursor-default text-muted-foreground hover:text-foreground',
							className
						) }
						aria-label={ text }
					/>
				}
			>
				<Info className="size-3.5" aria-hidden="true" />
			</TooltipTrigger>
			<TooltipContent className="max-w-xs">{ text }</TooltipContent>
		</Tooltip>
	);
}
