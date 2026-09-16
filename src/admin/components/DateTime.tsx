/**
 * A date in the site's format.
 */
import { formatDate } from '@/lib/format';

export default function DateTime( {
	value,
	withTime = false,
}: {
	value: string | null | undefined;
	withTime?: boolean;
} ) {
	return (
		<span className="text-muted-foreground whitespace-nowrap">
			{ formatDate( value, withTime ) }
		</span>
	);
}
