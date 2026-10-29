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
	/** The payout method the affiliate chose; empty for the default. */
	payout_method?: string;
	/** Method id => field id => value. Only with context=edit (and on `/me`). */
	payout_details?: Record< string, Record< string, string > >;
	/** Method id => an account they are paid to without entering anything. Only with context=edit (and on `/me`). */
	connected_accounts?: Record< string, ConnectedAccount >;
	promo_method: string;
	website: string;
	referral_url: string;
	created_at: string | null;
	updated_at: string | null;
};

/** An account a payout method pays an affiliate to without them entering anything. */
export type ConnectedAccount = {
	/** What it is: "Your store's PayPal account". */
	label: string;
	/** Which one (its email); empty when unknown. */
	detail: string;
	/** Where they change it, or connect it; empty for nowhere. */
	url: string;
	/** False for one they could connect there but have not: it is offered, not used. */
	connected: boolean;
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
	/** Where the commission comes from or who funds it, shown as an info tip; empty for none. */
	note?: string;
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

/** A page an affiliate saved a referral link for, with the visits it brought. */
export type ReferralLink = {
	id: number;
	affiliate_id: number;
	affiliate_name: string;
	url: string;
	referral_url: string;
	visits: number;
	conversions: number;
	last_visit_at: string | null;
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
	method_label: string;
	status: string;
	/** What an automatic payout method reports about sending it; null when nothing was sent. */
	transfer: PayoutTransfer | null;
	/** Locked against changes by hand while its method sends it, or after it did. */
	locked?: boolean;
	/** Whether it can be sent again through its method. */
	can_retry?: boolean;
	reference: string;
	note: string;
	period_start: string | null;
	period_end: string | null;
	created_by: number;
	created_at: string | null;
	paid_at: string | null;
	commissions: number[];
};

/** A transfer by an automatic payout method, as `Payout\Manager` reports it. */
export type PayoutTransfer = {
	status: 'queued' | 'processing' | 'pending' | 'paid' | 'failed' | string;
	message?: string;
	retryable?: boolean;
	reference?: string;
	/** Details of the method's own, for the columns it adds (`flyaffiliate_payment_columns`). */
	[ key: string ]: unknown;
};

/** A payout method, from `window.flyaffiliate.payoutMethods`. */
export type PayoutMethodField = {
	id: string;
	type: 'email' | 'text' | 'select' | string;
	label: string;
	description: string;
	placeholder: string;
	required: boolean;
	options: Record< string, string >;
};

export type PayoutMethodInfo = {
	id: string;
	label: string;
	/** The method's logo; empty for none. */
	logo?: string;
	automatic: boolean;
	enabled: boolean;
	fields: PayoutMethodField[];
};

/** Payments counted by what is happening to them. */
export type PayoutCounts = {
	paid: number;
	manual_unpaid: number;
	not_sent: number;
	queued: number;
	processing: number;
	pending: number;
	failed: number;
	unavailable: number;
};

/** Where a payout stands, from `GET /payouts/batch/{key}`. */
export type PayoutSummary = {
	batch_key: string;
	note: string;
	reference: string;
	created_at: string | null;
	created_by: number;
	affiliates: number;
	total: number;
	paid_total: number;
	paid: number;
	counts: PayoutCounts;
	in_flight: number;
	status: 'processing' | 'needs_attention' | 'paid' | 'unpaid' | string;
	methods: { id: string; label: string }[];
	/** Figures payout methods add beside the totals (`flyaffiliate_payout_summary`). */
	stats: { key: string; label: string; value: string; hint?: string }[];
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
	counts?: PayoutCounts;
	in_flight?: number;
	status?: PayoutSummary[ 'status' ];
	methods?: { id: string; label: string }[];
};

export type PayoutPreviewRow = {
	affiliate_id: number;
	name: string;
	payment_email: string;
	commissions: number[];
	amount: number;
	method: string;
	method_label: string;
	automatic: boolean;
	/** Where the money goes, by this method. */
	recipient: string;
	/** Why this method cannot pay the affiliate now; empty when it can. */
	warning: string;
};

export type PayoutPreviewGroup = {
	method: string;
	label: string;
	automatic: boolean;
	count: number;
	total: number;
};

export type PayoutPreview = {
	rows: PayoutPreviewRow[];
	groups: PayoutPreviewGroup[];
	total: number;
	count: number;
	/** What the same selection holds that is still pending, so not payable. */
	pending: { count: number; amount: number };
	/** Unpaid commissions the selection caught that are still inside their hold period. */
	held: { count: number; amount: number };
};

export type PayoutBatchResult = {
	batch_key: string;
	total: number;
	payouts: Payout[];
};
