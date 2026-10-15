/**
 * The dialog a locked extension opens, as Dokan Lite's Modules page opens
 * its "Upgrade the Plan & Unlock" dialog: what FlyAffiliate Pro adds today,
 * Upgrade to Pro, and a way to the Plugins screen for a site that has Pro
 * already but not active.
 */
import { __ } from '@wordpress/i18n';
import { CircleCheck, Crown, ExternalLink } from 'lucide-react';
import {
	Button,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogTitle,
} from '@wedevs/plugin-ui';
import type { ProExtensionsData } from '@/lib/globals';

type Props = {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	data: ProExtensionsData;
};

export default function UpgradeDialog( { open, onOpenChange, data }: Props ) {
	return (
		<Dialog open={ open } onOpenChange={ onOpenChange }>
			<DialogContent
				className="gap-0 overflow-hidden p-0 sm:max-w-xl"
				data-testid="flyaffiliate-extensions-upgrade"
			>
				<div className="relative overflow-hidden bg-linear-to-b from-primary/15 via-primary/5 to-card px-8 pt-10 pb-6 text-center">
					<div
						aria-hidden="true"
						className="pointer-events-none absolute -top-20 left-1/2 size-64 -translate-x-1/2 rounded-full bg-primary/15 blur-3xl"
					/>
					<span className="relative mx-auto flex size-16 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg ring-8 ring-primary/15">
						<Crown className="size-7" aria-hidden="true" />
					</span>
					<DialogTitle className="relative mt-5 text-2xl font-bold text-foreground">
						{ __( 'Upgrade to FlyAffiliate Pro', 'flyaffiliate' ) }
					</DialogTitle>
					<DialogDescription className="relative mx-auto mt-2 max-w-sm text-sm leading-relaxed text-muted-foreground">
						{ __(
							'Extensions come with FlyAffiliate Pro. Upgrade to switch them on and grow your affiliate program.',
							'flyaffiliate'
						) }
					</DialogDescription>
				</div>

				<div className="px-8 pb-8">
					<ul className="grid gap-x-6 gap-y-3 rounded-xl border border-border bg-muted/40 p-5 text-left text-sm text-foreground sm:grid-cols-2">
						{ data.features.map( ( feature ) => (
							<li
								key={ feature }
								className="flex items-start gap-2"
							>
								<CircleCheck
									className="mt-px size-4 shrink-0 text-primary"
									aria-hidden="true"
								/>
								<span>{ feature }</span>
							</li>
						) ) }
					</ul>

					<div className="mt-6 flex flex-col items-center gap-3">
						<Button
							size="lg"
							className="gap-2 px-6"
							render={
								// eslint-disable-next-line jsx-a11y/anchor-has-content
								<a
									href={ data.upgradeUrl }
									target="_blank"
									rel="noopener noreferrer"
								/>
							}
							data-testid="flyaffiliate-extensions-upgrade-link"
						>
							<Crown className="size-4" aria-hidden="true" />
							{ __( 'Upgrade to Pro', 'flyaffiliate' ) }
							<ExternalLink
								className="size-3.5 opacity-80"
								aria-hidden="true"
							/>
						</Button>
						<a
							href={ data.pluginsUrl }
							className="text-sm font-medium text-muted-foreground underline-offset-4 hover:text-primary hover:underline"
						>
							{ __(
								'Already upgraded? Activate FlyAffiliate Pro',
								'flyaffiliate'
							) }
						</a>
					</div>
				</div>
			</DialogContent>
		</Dialog>
	);
}
