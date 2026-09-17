/**
 * The shell of every form dialog: a bordered title bar, the fields, and a
 * bordered footer holding the buttons, the way Dokan's modals are cut.
 */
import type { ReactNode } from 'react';
import {
	Dialog,
	DialogContent,
	DialogDescription,
	DialogFooter,
	DialogHeader,
	DialogTitle,
	cn,
} from '@wedevs/plugin-ui';

type Props = {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	title: string;
	description?: string;
	footer: ReactNode;
	children: ReactNode;
	onSubmit: ( event: React.FormEvent ) => void;
	testId?: string;
	className?: string;
};

export default function FormDialog( {
	open,
	onOpenChange,
	title,
	description,
	footer,
	children,
	onSubmit,
	testId,
	className,
}: Props ) {
	return (
		<Dialog open={ open } onOpenChange={ onOpenChange }>
			<DialogContent
				className={ cn( 'gap-0 p-0 sm:max-w-lg', className ) }
			>
				<form onSubmit={ onSubmit } data-testid={ testId }>
					<DialogHeader className="border-b border-border px-6 py-4 pr-14">
						<DialogTitle className="text-lg font-semibold leading-6 text-foreground">
							{ title }
						</DialogTitle>
						{ description && (
							<DialogDescription className="text-sm text-muted-foreground">
								{ description }
							</DialogDescription>
						) }
					</DialogHeader>

					{ /* The fields scroll on a short window; the title bar and the buttons stay put. */ }
					<div className="grid max-h-[calc(100vh-14rem)] gap-5 overflow-y-auto px-6 py-5">
						{ children }
					</div>

					<DialogFooter className="border-t border-border px-6 py-4 sm:gap-3">
						{ footer }
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}
