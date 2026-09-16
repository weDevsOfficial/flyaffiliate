/**
 * Turn any page of this site into a referral link, as SliceWP's affiliate
 * account does: the pasted address must be on this site, and it gets the same
 * referral variable the affiliate's own link carries.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	Field,
	FieldDescription,
	FieldError,
	FieldLabel,
	Input,
} from '@wedevs/plugin-ui';
import CopyField from '@/components/CopyField';

type Result = { url: string; error: '' } | { url: ''; error: string };

/**
 * The host without a leading `www.`, so www and bare domains match.
 *
 * @param {string} host A hostname.
 * @return {string} The bare host.
 */
const bareHost = ( host: string ) => host.toLowerCase().replace( /^www\./, '' );

/**
 * Build a referral link for a page of this site.
 *
 * @param {string} input       What the affiliate pasted.
 * @param {string} referralUrl Their own referral link, which carries the variable and id.
 * @return {Result} The link, or why there is none.
 */
export function buildReferralLink(
	input: string,
	referralUrl: string
): Result {
	const value = input.trim();

	if ( ! value ) {
		return {
			url: '',
			error: __( 'Paste a link from this site first.', 'flyaffiliate' ),
		};
	}

	let base: URL;
	let target: URL;

	try {
		base = new URL( referralUrl );
		// "example.com/shop" has no scheme; a path starting with "/" is on this site.
		const withScheme =
			/^https?:\/\//i.test( value ) || value.startsWith( '/' )
				? value
				: `${ base.protocol }//${ value }`;
		target = new URL( withScheme, base.origin );
	} catch ( error ) {
		return {
			url: '',
			error: __( 'That is not a valid link.', 'flyaffiliate' ),
		};
	}

	if (
		! /^https?:$/.test( target.protocol ) ||
		bareHost( target.hostname ) !== bareHost( base.hostname )
	) {
		return {
			url: '',
			error: __(
				'Only links to pages of this website can earn a commission.',
				'flyaffiliate'
			),
		};
	}

	base.searchParams.forEach( ( id, variable ) =>
		target.searchParams.set( variable, id )
	);

	return { url: target.toString(), error: '' };
}

export default function GenerateLink( {
	referralUrl,
}: {
	referralUrl: string;
} ) {
	const [ input, setInput ] = useState( '' );
	const [ result, setResult ] = useState< Result | null >( null );

	const generate = ( event: React.FormEvent ) => {
		event.preventDefault();
		setResult( buildReferralLink( input, referralUrl ) );
	};

	return (
		<Card className="gap-3 rounded-md border border-border px-5 py-4 shadow ring-0">
			<form
				onSubmit={ generate }
				className="flex h-full flex-col gap-3"
				data-testid="flyaffiliate-generate-link"
			>
				<Field data-invalid={ Boolean( result?.error ) || undefined }>
					<FieldLabel
						htmlFor="flyaffiliate-generate-link-input"
						className="text-sm font-semibold text-foreground"
					>
						{ __( 'Generate a referral link', 'flyaffiliate' ) }
					</FieldLabel>
					<FieldDescription>
						{ __(
							'Paste the address of any page on this site, a product for example, to get a link to it that earns you a commission.',
							'flyaffiliate'
						) }
					</FieldDescription>
					<div className="flex flex-wrap gap-2">
						<Input
							id="flyaffiliate-generate-link-input"
							value={ input }
							onChange={ ( event ) => {
								setInput( event.target.value );
								setResult( null );
							} }
							placeholder={ __(
								'Paste the link here',
								'flyaffiliate'
							) }
							aria-invalid={ Boolean( result?.error ) }
							className="min-w-0 flex-1"
						/>
						<Button type="submit">
							{ __( 'Generate', 'flyaffiliate' ) }
						</Button>
					</div>
					{ result?.error && (
						<FieldError>{ result.error }</FieldError>
					) }
				</Field>
				{ result?.url && (
					<CopyField
						value={ result.url }
						className="bg-muted/40"
						aria-label={ __(
							'Your generated referral link',
							'flyaffiliate'
						) }
					/>
				) }
			</form>
		</Card>
	);
}
