/**
 * A payout method's name, with its logo before it when the method has one
 * (`PayoutMethod::get_logo_url()`), wherever a payment says how it is paid.
 */
import { cn } from '@wedevs/plugin-ui';
import { getGlobals } from '@/lib/globals';

type Props = {
	/** The method id. */
	id: string;
	/** Its name, as the payment or the method gives it. */
	label: string;
	className?: string;
	/** The logo's size class. */
	logoClassName?: string;
};

/**
 * The logo of a method, or an empty string.
 *
 * @param {string} id The method id.
 * @return {string} The logo URL.
 */
export function methodLogo( id: string ): string {
	return (
		getGlobals().payoutMethods?.find( ( method ) => method.id === id )
			?.logo ?? ''
	);
}

export default function MethodName( {
	id,
	label,
	className,
	logoClassName = 'size-4',
}: Props ) {
	const logo = methodLogo( id );

	return (
		<span className={ cn( 'inline-flex items-center gap-1.5', className ) }>
			{ logo && (
				<img
					src={ logo }
					alt=""
					aria-hidden="true"
					className={ cn( 'shrink-0 object-contain', logoClassName ) }
				/>
			) }
			{ label }
		</span>
	);
}
