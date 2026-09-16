/**
 * A read-only value with a copy button at its end: the referral link.
 */
import {
	InputGroup,
	InputGroupAddon,
	InputGroupInput,
} from '@wedevs/plugin-ui';
import CopyButton from './CopyButton';

type Props = {
	value: string;
	id?: string;
	className?: string;
	'aria-label'?: string;
};

export default function CopyField( { value, id, className, ...rest }: Props ) {
	return (
		<InputGroup className={ className }>
			<InputGroupInput
				id={ id }
				value={ value }
				readOnly
				aria-label={ rest[ 'aria-label' ] }
				className="font-mono text-xs"
				onFocus={ ( event ) => event.currentTarget.select() }
			/>
			<InputGroupAddon align="inline-end">
				<CopyButton value={ value } size="icon-xs" />
			</InputGroupAddon>
		</InputGroup>
	);
}
