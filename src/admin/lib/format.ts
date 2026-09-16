/**
 * Money formatting from FlyAffiliate's Currency settings, and dates in the site's format.
 */
import { dateI18n, getSettings } from '@wordpress/date';
import { getGlobals } from './globals';

/**
 * Format an amount the way the Currency settings say.
 *
 * @param {number|string} amount The amount in major units.
 * @return {string} The formatted amount with its currency symbol.
 */
export function formatMoney( amount: number | string ): string {
	const { currency } = getGlobals();
	const value = Number( amount ) || 0;
	const negative = value < 0;
	const fixed = Math.abs( value ).toFixed( currency.decimals );
	const [ whole, fraction ] = fixed.split( '.' );
	const grouped = whole.replace(
		/\B(?=(\d{3})+(?!\d))/g,
		currency.thousandSeparator
	);
	const number =
		fraction !== undefined
			? `${ grouped }${ currency.decimalSeparator }${ fraction }`
			: grouped;

	let formatted: string;

	switch ( currency.position ) {
		case 'right':
			formatted = `${ number }${ currency.symbol }`;
			break;
		case 'right_space':
			formatted = `${ number } ${ currency.symbol }`;
			break;
		case 'left_space':
			formatted = `${ currency.symbol } ${ number }`;
			break;
		default:
			formatted = `${ currency.symbol }${ number }`;
	}

	return negative ? `-${ formatted }` : formatted;
}

/**
 * Format a GMT date in the site's date format and timezone.
 *
 * The format and timezone come from `@wordpress/date`'s settings, which
 * WordPress fills from Settings → General (`date_format`, `time_format`,
 * `timezone_string` / `gmt_offset`) wherever `wp-date` is loaded — the way
 * Dokan's `DateTimeHtml` reads them.
 *
 * The REST API sends `mysql_to_rfc3339()` values, which carry no offset but
 * are GMT; read without one, a 06:37 click would show as 06:37 site time
 * instead of 12:37 on a UTC+6 site. A value that does carry an offset is used
 * as it is.
 *
 * @param {string|null|undefined} value    The date, in GMT unless it says otherwise.
 * @param {boolean}               withTime Whether to append the time.
 * @return {string} The formatted date, or a dash when there is none.
 */
export function formatDate(
	value: string | null | undefined,
	withTime = false
): string {
	if ( ! value ) {
		return '—';
	}

	const { formats, timezone } = getSettings();
	const gmt = /(Z|[+-]\d{2}:?\d{2})$/i.test( value )
		? value
		: `${ value.replace( ' ', 'T' ) }Z`;

	// `formats.datetime` is a fixed string; the site's own two formats together
	// are what Settings → General promises.
	return dateI18n(
		withTime ? `${ formats.date } ${ formats.time }` : formats.date,
		gmt,
		// Empty when the site uses a UTC offset; dateI18n then falls back to it.
		timezone.string || undefined
	);
}

/**
 * A calendar date as `Y-m-d`, in the browser's own timezone.
 *
 * @param {Date} date The date.
 * @return {string} The formatted date.
 */
export function toYmd( date: Date ): string {
	const pad = ( n: number ) => String( n ).padStart( 2, '0' );

	return `${ date.getFullYear() }-${ pad( date.getMonth() + 1 ) }-${ pad(
		date.getDate()
	) }`;
}
