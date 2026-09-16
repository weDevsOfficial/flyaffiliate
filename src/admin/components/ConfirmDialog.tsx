/**
 * A yes/no question before an action that is hard to undo: a plugin-ui alert
 * dialog with one confirming button, used by the page-level actions that
 * DataViews' row confirmations do not cover.
 */
import { __ } from '@wordpress/i18n';
import {
	AlertDialog,
	AlertDialogAction,
	AlertDialogCancel,
	AlertDialogContent,
	AlertDialogDescription,
	AlertDialogFooter,
	AlertDialogHeader,
	AlertDialogTitle,
	Spinner,
} from '@wedevs/plugin-ui';

type Props = {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	title: string;
	description: string;
	confirmLabel: string;
	onConfirm: () => void | Promise< void >;
	destructive?: boolean;
	busy?: boolean;
};

export default function ConfirmDialog( {
	open,
	onOpenChange,
	title,
	description,
	confirmLabel,
	onConfirm,
	destructive = false,
	busy = false,
}: Props ) {
	return (
		<AlertDialog open={ open } onOpenChange={ onOpenChange }>
			<AlertDialogContent>
				<AlertDialogHeader>
					<AlertDialogTitle>{ title }</AlertDialogTitle>
					<AlertDialogDescription>
						{ description }
					</AlertDialogDescription>
				</AlertDialogHeader>
				<AlertDialogFooter>
					<AlertDialogCancel disabled={ busy }>
						{ __( 'Cancel', 'flyaffiliate' ) }
					</AlertDialogCancel>
					<AlertDialogAction
						variant={ destructive ? 'destructive' : 'default' }
						disabled={ busy }
						onClick={ ( event ) => {
							// Stays open while the request runs; the caller closes it.
							event.preventDefault();
							onConfirm();
						} }
						data-testid="flyaffiliate-confirm"
					>
						{ busy && <Spinner className="size-4" /> }
						{ confirmLabel }
					</AlertDialogAction>
				</AlertDialogFooter>
			</AlertDialogContent>
		</AlertDialog>
	);
}
