/**
 * The data PHP hands the admin app as `window.flyaffiliate`.
 */
export type CurrencyConfig = {
	code: string;
	symbol: string;
	position: 'left' | 'right' | 'left_space' | 'right_space' | string;
	decimals: number;
	decimalSeparator: string;
	thousandSeparator: string;
};

export type AdminGlobals = {
	version: string;
	restNonce: string;
	currency: CurrencyConfig;
	settings: {
		payoutMinimum: number;
	};
	setup: {
		done: boolean;
		pages: {
			register: string;
			dashboard: string;
		};
	};
	urls: {
		app: string;
		wizard: string;
		docs: string;
		support: string;
		payoutCsv: string;
		users: string;
		orders: string;
		products: string;
	};
	statuses: {
		affiliate: Record< string, string >;
		commission: Record< string, string >;
	};
	sources: Record< string, string >;
	/** The origins a new commission may be given: manual, plus each active integration's. */
	availableSources: Record< string, string >;
	types: Record< string, string >;
	methods: Record< string, string >;
	/** Only on the affiliate dashboard page. */
	dashboard: {
		tab: string;
		notice: string;
	};
	/** Only on the affiliate dashboard page: the terms of the programme. */
	program: {
		rate: number;
		rateType: 'percentage' | 'fixed' | string;
		cookieDays: number;
		holdDays: number;
		payoutMinimum: number;
	};
};

declare global {
	interface Window {
		flyaffiliate?: AdminGlobals;
	}
}

const fallback: AdminGlobals = {
	version: '',
	restNonce: '',
	currency: {
		code: 'USD',
		symbol: '$',
		position: 'left',
		decimals: 2,
		decimalSeparator: '.',
		thousandSeparator: ',',
	},
	settings: { payoutMinimum: 0 },
	setup: { done: false, pages: { register: '', dashboard: '' } },
	urls: {
		app: '',
		wizard: '',
		docs: '',
		support: '',
		payoutCsv: '',
		users: '',
		orders: '',
		products: '',
	},
	statuses: { affiliate: {}, commission: {} },
	sources: {},
	availableSources: { manual: 'Manual' },
	types: {},
	methods: {},
	dashboard: { tab: 'overview', notice: '' },
	program: {
		rate: 0,
		rateType: 'percentage',
		cookieDays: 0,
		holdDays: 0,
		payoutMinimum: 0,
	},
};

/**
 * The globals, with a safe fallback so a component can render without them.
 *
 * @return {AdminGlobals} The globals.
 */
export function getGlobals(): AdminGlobals {
	// The dashboard page localizes a subset; the fallback fills the rest.
	return { ...fallback, ...( window.flyaffiliate ?? {} ) } as AdminGlobals;
}

/**
 * Record in the running app that the setup wizard is done, so the Settings
 * page stops offering it before the next full load.
 */
export function markSetupDone(): void {
	if ( window.flyaffiliate?.setup ) {
		window.flyaffiliate.setup.done = true;
	}
}
