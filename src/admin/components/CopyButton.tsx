/**
 * Copies a value to the clipboard and says so: the icon flips to a check.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Check, Copy } from 'lucide-react';
import { Button, cn } from '@wedevs/plugin-ui';
import { copyText } from '@/lib/clipboard';

type Props = {
	value: string;
	label?: string;
	className?: string;
	size?: 'sm' | 'icon-sm' | 'icon-xs';
};

export default function CopyButton( {
	value,
	label,
	className,
	size = 'icon-sm',
}: Props ) {
	const [ copied, setCopied ] = useState( false );
	const timer = useRef< number | null >( null );

	useEffect( () => {
		return () => {
			if ( timer.current ) {
				window.clearTimeout( timer.current );
			}
		};
	}, [] );

	const copy = async () => {
		if ( ! ( await copyText( value ) ) ) {
			return;
		}

		setCopied( true );

		if ( timer.current ) {
			window.clearTimeout( timer.current );
		}

		timer.current = window.setTimeout( () => setCopied( false ), 1800 );
	};

	const Icon = copied ? Check : Copy;

	return (
		<Button
			type="button"
			variant="ghost"
			size={ label ? 'sm' : size }
			onClick={ copy }
			aria-label={ label ?? __( 'Copy', 'flyaffiliate' ) }
			title={ copied ? __( 'Copied', 'flyaffiliate' ) : value }
			className={ cn(
				'text-muted-foreground hover:text-foreground',
				copied && 'text-success hover:text-success',
				className
			) }
			data-copied={ copied ? 'true' : undefined }
		>
			<Icon
				key={ copied ? 'check' : 'copy' }
				className="size-4 animate-in zoom-in-50 fade-in-0 duration-200"
			/>
			{ label && (
				<span>{ copied ? __( 'Copied', 'flyaffiliate' ) : label }</span>
			) }
		</Button>
	);
}
