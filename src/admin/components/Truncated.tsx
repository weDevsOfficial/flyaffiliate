/**
 * Text that is cut short with a tooltip carrying the whole of it.
 */
import { Tooltip, TooltipContent, TooltipTrigger, cn } from '@wedevs/plugin-ui';

type Props = {
	text: string;
	max?: number;
	className?: string;
};

export default function Truncated( { text, max = 28, className }: Props ) {
	if ( text.length <= max ) {
		return <span className={ className }>{ text }</span>;
	}

	return (
		<Tooltip>
			<TooltipTrigger
				render={
					<span className={ cn( 'cursor-default', className ) } />
				}
			>
				{ text.slice( 0, max - 1 ).trimEnd() }…
			</TooltipTrigger>
			<TooltipContent className="max-w-sm break-all">
				{ text }
			</TooltipContent>
		</Tooltip>
	);
}
