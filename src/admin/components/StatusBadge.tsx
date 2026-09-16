/**
 * A status: the pill Dokan's lists use, or — with `variant="text"` — the bare
 * word, taking its colour from the line it sits in.
 */
import { cn } from '@wedevs/plugin-ui';
import { getGlobals } from '@/lib/globals';

type Tone = 'success' | 'warning' | 'info' | 'danger' | 'neutral';

const TONES: Record< string, Tone > = {
	active: 'success',
	paid: 'success',
	converted: 'success',
	pending: 'warning',
	unpaid: 'info',
	inactive: 'neutral',
	suspended: 'danger',
	rejected: 'danger',
};

const CLASSES: Record< Tone, string > = {
	success: 'bg-[#d4fbef] text-[#00563f] border-[#00563f]',
	warning: 'bg-[#faedcd] text-[#8a610f] border-[#8a610f]',
	info: 'bg-[#dbeafe] text-[#2947bf] border-[#2947bf]',
	danger: 'bg-[#f8e3e6] text-[#9f2225] border-[#9f2225]',
	neutral: 'bg-[#f1f1f4] text-[#393939] border-[#393939]',
};

type Props = {
	status: string;
	kind?: 'affiliate' | 'commission';
	label?: string;
	/** `text` drops the pill and every style with it: just the word. */
	variant?: 'pill' | 'text';
	className?: string;
};

export default function StatusBadge( {
	status,
	kind,
	label,
	variant = 'pill',
	className,
}: Props ) {
	const { statuses } = getGlobals();
	const text =
		label ??
		( kind ? statuses[ kind ]?.[ status ] : undefined ) ??
		status.charAt( 0 ).toUpperCase() + status.slice( 1 );

	return (
		<span
			className={ cn(
				'whitespace-nowrap',
				'pill' === variant &&
					cn(
						'inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium leading-4',
						CLASSES[ TONES[ status ] ?? 'neutral' ]
					),
				className
			) }
			data-status={ status }
		>
			{ text }
		</span>
	);
}
