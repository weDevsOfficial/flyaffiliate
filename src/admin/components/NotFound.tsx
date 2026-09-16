/**
 * The page a bad hash lands on.
 */
import { __ } from '@wordpress/i18n';
import { Link } from 'react-router-dom';
import { FileQuestion } from 'lucide-react';
import { Button, Card } from '@wedevs/plugin-ui';
import EmptyState from './EmptyState';

export default function NotFound() {
	return (
		<Card className="rounded-md py-0 shadow ring-0">
			<EmptyState
				icon={ FileQuestion }
				titleAs="h1"
				title={ __(
					'Sorry, that page cannot be found',
					'flyaffiliate'
				) }
				description={ __(
					'The page you were looking for has moved or does not exist.',
					'flyaffiliate'
				) }
				action={
					<Button render={ <Link to="/affiliates" /> }>
						{ __( 'Back to Affiliates', 'flyaffiliate' ) }
					</Button>
				}
			/>
		</Card>
	);
}
