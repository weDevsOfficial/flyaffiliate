/**
 * One of FlyAffiliate Pro's extensions, drawn as Pro's Extensions page draws
 * it (its logo, name, what it does, its kind as a badge, Docs) so the page
 * looks the same before and after the upgrade. Its switch cannot turn it on
 * here: like a Pro module's switch on Dokan Lite's Modules page, it opens
 * the upgrade dialog. An extension Pro does not ship yet says Coming soon
 * and has no switch.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Blocks,
	Crown,
	FileText,
	Plug,
	Wallet,
	type LucideIcon,
} from 'lucide-react';
import { Badge, Button, Card, Switch, cn } from '@wedevs/plugin-ui';
import type { ProExtensionPreview } from '@/lib/globals';
import {
	dokanLogo,
	fluentCartLogo,
	payPalLogo,
	stripeLogo,
	sureCartLogo,
} from '@/assets/logos';

export type ExtensionView = 'grid' | 'list';

type Props = {
	extension: ProExtensionPreview;
	view: ExtensionView;
	onUpgrade: () => void;
	onCategory: ( category: string ) => void;
};

/** Each card's mark, by the id Pro's extension uses. */
const LOGOS: Record< string, string > = {
	dokan: dokanLogo,
	fluentcart: fluentCartLogo,
	paypal: payPalLogo,
	stripe: stripeLogo,
	surecart: sureCartLogo,
};

const CATEGORY_ICONS: Record< string, LucideIcon > = {
	integration: Plug,
	payout_method: Wallet,
};

/** The card's frame, as Pro's. */
const FRAME =
	'group gap-0 rounded-xl border border-border p-0 py-0 shadow-xs ring-0 transition-shadow duration-200 hover:shadow-md';

/**
 * The kind of extension, as its badge and the category filter name it.
 *
 * @param {string} category The extension's category.
 * @return {string} Its label.
 */
export function categoryLabel( category: string ): string {
	switch ( category ) {
		case 'integration':
			return __( 'Integration', 'flyaffiliate' );
		case 'payout_method':
			return __( 'Payout method', 'flyaffiliate' );
		default:
			return category;
	}
}

export default function PreviewCard( {
	extension,
	view,
	onUpgrade,
	onCategory,
}: Props ) {
	const { id, name, description, category, status } = extension;
	const available = 'available' === status;
	const isList = 'list' === view;

	const toggle = available ? (
		<div className="flex items-center gap-2.5">
			<ProBadge />
			<Switch
				checked={ false }
				onCheckedChange={ onUpgrade }
				aria-label={ sprintf(
					/* translators: %s: the extension's name, e.g. Dokan. */
					__( 'Enable %s', 'flyaffiliate' ),
					name
				) }
				data-testid={ `flyaffiliate-extension-${ id }-switch` }
			/>
		</div>
	) : (
		<Badge
			variant="secondary"
			className="h-6 rounded-full px-2.5"
			data-testid={ `flyaffiliate-extension-${ id }-soon` }
		>
			{ __( 'Coming soon', 'flyaffiliate' ) }
		</Badge>
	);

	const title = (
		<h3 className="text-base font-semibold text-foreground">{ name }</h3>
	);

	const badge = (
		<Badge
			variant="secondary"
			className="h-6 cursor-pointer rounded-full bg-primary/10 px-2.5 text-primary hover:bg-primary/15"
			render={ <button type="button" /> }
			onClick={ () => onCategory( category ) }
		>
			{ categoryLabel( category ) }
		</Badge>
	);

	const docs = available && (
		<a
			href={ extension.doc_url }
			target="_blank"
			rel="noopener noreferrer"
			className="inline-flex items-center gap-1.5 text-sm text-muted-foreground no-underline transition-colors hover:text-primary"
			data-testid={ `flyaffiliate-extension-${ id }-docs` }
		>
			<FileText className="size-4" aria-hidden="true" />
			{ __( 'Docs', 'flyaffiliate' ) }
		</a>
	);

	const upgrade = available && (
		<Button
			variant="outline"
			size="sm"
			className="gap-1.5 border-primary/40 bg-card text-primary transition-colors hover:border-primary hover:bg-primary hover:text-primary-foreground"
			onClick={ onUpgrade }
			data-testid={ `flyaffiliate-extension-${ id }-upgrade` }
		>
			<Crown className="size-3.5" aria-hidden="true" />
			{ __( 'Upgrade', 'flyaffiliate' ) }
		</Button>
	);

	if ( isList ) {
		return (
			<Card
				className={ cn(
					FRAME,
					'flex-row items-center gap-4 px-5 py-4'
				) }
				data-testid={ `flyaffiliate-extension-${ id }` }
			>
				<Logo id={ id } category={ category } size="sm" />
				<div className="min-w-0 flex-1">
					<div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
						{ title }
						{ badge }
					</div>
					<p className="mt-1 line-clamp-2 text-sm leading-relaxed text-muted-foreground">
						{ description }
					</p>
				</div>
				<div className="flex shrink-0 items-center gap-5">
					{ docs }
					{ upgrade }
					{ toggle }
				</div>
			</Card>
		);
	}

	return (
		<Card
			className={ FRAME }
			data-testid={ `flyaffiliate-extension-${ id }` }
		>
			<div className="flex flex-1 flex-col gap-4 p-5">
				<div className="flex items-start justify-between gap-3">
					<Logo id={ id } category={ category } />
					{ toggle }
				</div>
				<div>
					{ title }
					<p className="mt-1.5 line-clamp-2 text-sm leading-relaxed text-muted-foreground">
						{ description }
					</p>
				</div>
				<div className="mt-auto">{ badge }</div>
			</div>
			{ available && (
				<div className="flex items-center justify-between gap-3 rounded-b-xl border-t border-border bg-muted/40 px-5 py-3">
					{ docs }
					{ upgrade }
				</div>
			) }
		</Card>
	);
}

/**
 * The small Pro mark beside a locked switch.
 *
 * @return {JSX.Element} The badge.
 */
function ProBadge() {
	return (
		<span className="inline-flex h-6 items-center gap-1 rounded-full bg-primary/10 px-2 text-xs font-semibold text-primary">
			<Crown className="size-3.5" aria-hidden="true" />
			{ __( 'Pro', 'flyaffiliate' ) }
		</span>
	);
}

/**
 * The extension's mark on a tile, or its kind's icon when there is none or
 * the image fails to load.
 *
 * @param {Object} props          The props.
 * @param {string} props.id       The extension's id.
 * @param {string} props.category The extension's category.
 * @param {string} props.size     `default` on a card, `sm` in a list row.
 * @return {JSX.Element} The logo.
 */
function Logo( {
	id,
	category,
	size = 'default',
}: {
	id: string;
	category: string;
	size?: 'default' | 'sm';
} ) {
	const [ failed, setFailed ] = useState( false );
	const url = LOGOS[ id ] ?? '';
	const tile = 'sm' === size ? 'size-12' : 'size-14';

	if ( url && ! failed ) {
		return (
			<div
				className={ cn(
					'flex shrink-0 items-center justify-center rounded-xl border border-border bg-card shadow-xs',
					tile,
					'sm' === size ? 'p-2.5' : 'p-3'
				) }
			>
				<img
					src={ url }
					alt=""
					className="size-full object-contain"
					onError={ () => setFailed( true ) }
				/>
			</div>
		);
	}

	const Icon = CATEGORY_ICONS[ category ] ?? Blocks;

	return (
		<div
			aria-hidden="true"
			className={ cn(
				'flex shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary',
				tile
			) }
		>
			<Icon className={ 'sm' === size ? 'size-5' : 'size-6' } />
		</div>
	);
}
