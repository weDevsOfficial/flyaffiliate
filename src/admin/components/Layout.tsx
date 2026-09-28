/**
 * The shell every page renders inside: a branded header bar, then the page.
 */
import type { ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { BookOpen, CircleStar, LifeBuoy } from 'lucide-react';
import { Button, TopBar } from '@wedevs/plugin-ui';
import { getGlobals } from '@/lib/globals';
import { BRAND_OUTLINE } from '@/lib/ui';

export default function Layout( { children }: { children: ReactNode } ) {
	const { version, urls } = getGlobals();

	return (
		<div
			className="flyaffiliate-admin-layout"
			data-testid="flyaffiliate-admin-app"
		>
			<TopBar
				className="flyaffiliate-topbar mb-6 items-center rounded-md shadow"
				logo={
					<a
						href={ urls.app }
						className="flex h-full items-center gap-2.5 text-foreground"
						data-testid="flyaffiliate-brand"
					>
						<span
							className="flex size-7 items-center justify-center rounded-md bg-primary text-white"
							aria-hidden="true"
						>
							<CircleStar className="size-5" />
						</span>
						<span className="text-lg font-bold leading-none">
							{ __( 'FlyAffiliate', 'flyaffiliate' ) }
						</span>
					</a>
				}
				versions={
					version
						? [
								{
									version: sprintf(
										/* translators: %s: plugin version */
										__( 'Version %s', 'flyaffiliate' ),
										version
									),
									isPro: false,
									className:
										'h-6 rounded-full border-border px-2.5 text-xs font-medium text-muted-foreground',
								},
						  ]
						: []
				}
				rightSideComponents={
					<>
						<Button
							variant="ghost"
							size="sm"
							className="text-muted-foreground hover:text-primary"
							render={
								// eslint-disable-next-line jsx-a11y/anchor-has-content
								<a
									href={ urls.docs }
									target="_blank"
									rel="noopener noreferrer"
								/>
							}
						>
							<BookOpen className="size-4" />
							{ __( 'Documentation', 'flyaffiliate' ) }
						</Button>
						<Button
							variant="outline"
							size="sm"
							className={ BRAND_OUTLINE }
							render={
								// eslint-disable-next-line jsx-a11y/anchor-has-content
								<a
									href={ urls.support }
									target="_blank"
									rel="noopener noreferrer"
								/>
							}
						>
							<LifeBuoy className="size-4" />
							{ __( 'Get support', 'flyaffiliate' ) }
						</Button>
					</>
				}
			/>
			{ children }
		</div>
	);
}
