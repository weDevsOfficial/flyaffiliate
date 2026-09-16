/**
 * What the `me` routes return.
 */
import type { Affiliate, Commission } from '@/lib/types';

/** `GET /me`: the affiliate with their totals, for the selected dates when there are any. */
export type AffiliateProfile = Affiliate & {
	totals: Record< string, number >;
	visits: { all: number; converted: number; not_converted: number };
};

/** The dashboard's date range, as `Y-m-d` days in the site's timezone. */
export type DateArgs = {
	after?: string;
	before?: string;
};

/** `GET /me/commissions/{id}`: a commission with its item and the visit it came from. */
export type CommissionDetail = Commission & {
	product: { id: number; name: string; url: string } | null;
	visit: {
		id: number;
		url: string;
		referrer: string;
		created_at: string | null;
	} | null;
};
