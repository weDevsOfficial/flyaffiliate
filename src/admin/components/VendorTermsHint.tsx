/**
 * On a marketplace, where a commission's rate and lock came from: the store
 * that set its own terms, or the marketplace's terms for every store.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import Hint from './Hint';
import type { VendorProgram } from '@/lib/types';

export default function VendorTermsHint( {
	program,
	className,
}: {
	program?: VendorProgram | null;
	className?: string;
} ) {
	if ( ! program ) {
		return null;
	}

	const store = program.store_name || __( 'This store', 'flyaffiliate' );
	const rate = String( Number( program.rate ) );
	const lock =
		program.hold_days > 0
			? sprintf(
					/* translators: %d: number of days */
					_n(
						'payable %d day after the sale',
						'payable %d days after the sale',
						program.hold_days,
						'flyaffiliate'
					),
					program.hold_days
			  )
			: __( 'payable as soon as the order is paid', 'flyaffiliate' );

	const text = program.overridden
		? sprintf(
				/* translators: 1: store name, 2: the rate, e.g. 15, 3: when the commission can be paid */
				__(
					'%1$s set its own terms: affiliates earn %2$s%% on its products, %3$s.',
					'flyaffiliate'
				),
				store,
				rate,
				lock
		  )
		: sprintf(
				/* translators: 1: store name, 2: the rate, e.g. 15, 3: when the commission can be paid */
				__(
					'%1$s uses the marketplace’s terms: affiliates earn %2$s%%, %3$s.',
					'flyaffiliate'
				),
				store,
				rate,
				lock
		  );

	return <Hint text={ text } className={ className } />;
}
