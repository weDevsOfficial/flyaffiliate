/**
 * The page a bad hash or an unknown record lands on, drawn as Dokan's admin
 * 404 is: the question-file icon in a tinted circle, a large 404, a title,
 * a line under it and a way back, centred in a card that fills the screen.
 * The sizes are Dokan's; the colours are the brand tokens. A detail page
 * passes its own words and its own way back.
 */
import { __ } from '@wordpress/i18n';
import { Link } from 'react-router-dom';
import { FileQuestion } from 'lucide-react';
import { Button, Card } from '@wedevs/plugin-ui';

type Props = {
	title?: string;
	description?: string;
	/** The way back; the Dashboard unless the page knows a better one. */
	backTo?: { to: string; label: string };
};

export default function NotFound( { title, description, backTo }: Props ) {
	const back = backTo ?? {
		to: '/',
		label: __( 'Back to Dashboard', 'flyaffiliate' ),
	};

	return (
		<Card
			className="flex h-[calc(100vh-8.5rem)] min-h-[30rem] flex-col items-center justify-center rounded-md py-0 text-center shadow ring-0"
			data-testid="flyaffiliate-not-found"
		>
			<div className="flex flex-col items-center justify-center gap-[22px]">
				<div className="flex size-[127px] shrink-0 items-center justify-center rounded-full bg-primary/10">
					<FileQuestion
						className="size-[52px] text-primary"
						strokeWidth={ 2 }
						aria-hidden="true"
					/>
				</div>
				<div className="max-w-[270px]">
					<div className="text-[64px] font-bold leading-[130%] text-foreground">
						404
					</div>
					<div className="flex flex-col gap-2">
						<h1 className="text-lg font-bold leading-[130%] text-foreground">
							{ title ??
								__(
									'Sorry, the page can’t be found',
									'flyaffiliate'
								) }
						</h1>
						<p className="text-xs leading-[140%] text-muted-foreground">
							{ description ??
								__(
									'The page you were looking for appears to have been moved, deleted or does not exist.',
									'flyaffiliate'
								) }
						</p>
					</div>
				</div>
				<Button
					render={ <Link to={ back.to } /> }
					className="mt-2 h-[38px] rounded-[5px] px-4 text-sm font-medium"
				>
					{ back.label }
				</Button>
			</div>
		</Card>
	);
}
