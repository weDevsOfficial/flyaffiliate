/**
 * The shell every page renders inside: a branded header bar across the top
 * of the screen, flush with wp-admin's chrome as Dokan's is, then the page.
 */
import type { ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { MessageCircleQuestionMark } from 'lucide-react';
import { TopBar } from '@wedevs/plugin-ui';
import { getGlobals } from '@/lib/globals';
import HelpMenu from './HelpMenu';

export default function Layout( { children }: { children: ReactNode } ) {
	const { version, urls } = getGlobals();

	return (
		<div
			className="flyaffiliate-admin-layout"
			data-testid="flyaffiliate-admin-app"
		>
			<TopBar
				className="flyaffiliate-topbar items-center border-b border-border shadow-xs"
				logo={
					<a
						href={ urls.app }
						className="flex h-full items-center gap-2.5 text-foreground"
						data-testid="flyaffiliate-brand"
					>
						<img
							src={ urls.logo }
							className="size-7 shrink-0"
							alt=""
						/>
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
					<div className="flex items-center gap-6">
						{ /* Dokan's Get Support button, measure for measure. */ }
						<a
							href={ urls.support }
							target="_blank"
							rel="noopener noreferrer"
							className="flex h-10 items-center gap-1 rounded-md border border-primary bg-card px-3 whitespace-nowrap text-primary transition-colors duration-200 hover:bg-primary hover:text-primary-foreground"
						>
							<MessageCircleQuestionMark
								className="size-5"
								aria-hidden="true"
							/>
							<span className="min-w-20 text-xs">
								{ __( 'Get Support', 'flyaffiliate' ) }
							</span>
						</a>
						<HelpMenu />
					</div>
				}
			/>
			<div className="flyaffiliate-admin-content">{ children }</div>
		</div>
	);
}
