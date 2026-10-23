/**
 * The data PHP hands the admin app as `window.flyaffiliate`.
 */
import type { PayoutMethodInfo } from './types';

export type CurrencyConfig = {
	code: string;
	symbol: string;
	position: 'left' | 'right' | 'left_space' | 'right_space' | string;
	decimals: number;
	decimalSeparator: string;
	thousandSeparator: string;
};

/** One of Pro's extensions, as a preview card while Pro is not active. */
export type ProExtensionPreview = {
	id: string;
	name: string;
	description: string;
	/** `integration` or `payout_method`. */
	category: string;
	doc_url: string;
};

/** What the Extensions page shows while Pro is not active (`Admin\ProExtensions`). */
export type ProExtensionsData = {
	upgradeUrl: string;
	pluginsUrl: string;
	extensions: ProExtensionPreview[];
	/** What Pro adds today, for the upgrade dialog. */
	features: string[];
};

/** One link in the top bar's help menu. */
export type HelpMenuItem = {
	id: string;
	title: string;
	url: string;
	/** A key `HelpMenu` maps to an icon. */
	icon: string;
	/** Opens in a new tab. */
	external: boolean;
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
		/** Whether the site shares usage data with Appsero (Share Essentials). */
		shareEssentials: boolean;
	};
	urls: {
		app: string;
		wizard: string;
		/** FlyAffiliate's icon, for the top bar. */
		logo: string;
		/** Appsero's privacy policy, linked from Share Essentials. */
		insightsPolicy: string;
		docs: string;
		support: string;
		payoutCsv: string;
		users: string;
		orders: string;
		products: string;
	};
	/** The top bar's help menu. */
	helpMenu: HelpMenuItem[];
	statuses: {
		affiliate: Record< string, string >;
		commission: Record< string, string >;
		/** What an automatic payout method reports about a payment. */
		transfer: Record< string, string >;
		/** Where a payout stands. */
		batch: Record< string, string >;
	};
	sources: Record< string, string >;
	/** The origins a new commission may be given: manual, plus each active integration's. */
	availableSources: Record< string, string >;
	types: Record< string, string >;
	methods: Record< string, string >;
	/** Every payout method (admin), or the ones an affiliate can choose (dashboard). */
	payoutMethods: PayoutMethodInfo[];
	/** Only on the affiliate dashboard page: the method an affiliate gets without choosing. */
	payoutDefault?: string;
	/** Only while FlyAffiliate Pro is not active: Pro's extensions as previews. */
	proExtensions?: ProExtensionsData;
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
		/** A sentence on holds of its own (a marketplace's sellers); empty when every commission waits the hold period. */
		holdNote?: string;
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
	setup: {
		done: false,
		pages: { register: '', dashboard: '' },
		shareEssentials: false,
	},
	urls: {
		app: '',
		wizard: '',
		logo: '',
		insightsPolicy: '',
		docs: '',
		support: '',
		payoutCsv: '',
		users: '',
		orders: '',
		products: '',
	},
	helpMenu: [],
	statuses: { affiliate: {}, commission: {}, transfer: {}, batch: {} },
	sources: {},
	availableSources: { manual: 'Manual' },
	types: {},
	methods: {},
	payoutMethods: [],
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
