/**
 * The REST resources as the API returns them.
 */
/** Commission amounts and counts by status, as the REST controllers send them. */
export type CommissionTotals = {
	paid: number;
	unpaid: number;
	pending: number;
	total: number;
	paid_count: number;
	unpaid_count: number;
	pending_count: number;
	count: number;
};

export type Affiliate = {
	id: number;
	user_id: number;
	name: string;
	user_login: string;
	email: string;
	totals?: CommissionTotals;
	status: string;
	payment_email: string;
	promo_method: string;
	website: string;
	referral_url: string;
	created_at: string | null;
	updated_at: string | null;
};

export type Commission = {
	id: number;
	affiliate_id: number;
	affiliate_name: string;
	order_id: number;
	order_url: string | null;
	order_item_id: number | null;
	product_id: number;
	vendor_id: number;
	base_amount: number;
	rate: number;
	rate_type: string;
	amount: number;
	currency: string;
	source: string;
	type: string;
	status: string;
	payout_id: number | null;
	matures_at: string | null;
	created_at: string | null;
	updated_at: string | null;
};

export type Visit = {
	id: number;
	affiliate_id: number;
	affiliate_name: string;
	url: string;
	referrer: string;
	converted: boolean;
	order_id: number | null;
	order_url: string | null;
	created_at: string | null;
};

export type Payout = {
	id: number;
	batch_key: string;
	affiliate_id: number;
	affiliate_name: string;
	payment_email: string;
	amount: number;
	currency: string;
	method: string;
	status: string;
	reference: string;
	note: string;
	period_start: string | null;
	period_end: string | null;
	created_by: number;
	created_at: string | null;
	commissions: number[];
};

/** The Dashboard figures, from `GET /dashboard`. */
export type DashboardStats = {
	range: { after: string; before: string };
	earnings: {
		referral_revenue: number;
		commissions: number;
		net_revenue: number;
		paid: number;
		unpaid: number;
		pending: number;
	};
	performance: {
		commissions: number;
		visits: number;
		converted: number;
		conversion_rate: number;
	};
	trend: { date: string; visits: number; converted: number }[];
	top_affiliates: {
		id: number;
		name: string;
		earned: number;
		commissions: number;
		visits: number;
	}[];
	top_products: {
		product_id: number;
		name: string;
		url: string;
		commissions: number;
		revenue: number;
	}[];
	recent_visits: {
		id: number;
		affiliate_id: number;
		affiliate_name: string;
		url: string;
		referrer: string;
		converted: boolean;
		created_at: string;
	}[];
	recent_commissions: {
		id: number;
		affiliate_id: number;
		affiliate_name: string;
		order_id: number;
		order_url: string | null;
		amount: number;
		status: string;
		created_at: string;
	}[];
	affiliates: {
		total: number;
		pending: number;
		active: number;
		/** Whether the pending-review notice is due for this admin. */
		pending_notice: boolean;
	};
};

/** One batch — a payout on the admin screens — summarised over its payments. */
export type PayoutBatch = {
	batch_key: string;
	note: string;
	created_at: string;
	created_by: number;
	affiliates: number;
	paid: number;
	total: number;
	paid_total: number;
};

export type PayoutPreviewRow = {
	affiliate_id: number;
	name: string;
	payment_email: string;
	commissions: number[];
	amount: number;
};

export type PayoutPreview = {
	rows: PayoutPreviewRow[];
	total: number;
	count: number;
	/** What the same selection holds that is still pending, so not payable. */
	pending: { count: number; amount: number };
};

export type PayoutBatchResult = {
	batch_key: string;
	total: number;
	payouts: Payout[];
};
