/**
 * A searchable affiliate select backed by the REST API.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SmartSelect, type SmartSelectOption } from '@wedevs/plugin-ui';
import { fetchList } from '@/lib/api';
import type { Affiliate } from '@/lib/types';

type Props = {
	value: number | null;
	onChange: ( affiliateId: number | null, affiliate?: Affiliate ) => void;
	placeholder?: string;
	status?: string;
	className?: string;
};

export default function AffiliatePicker( {
	value,
	onChange,
	placeholder,
	status,
	className,
}: Props ) {
	const [ options, setOptions ] = useState< SmartSelectOption[] >( [] );
	const [ loading, setLoading ] = useState( false );
	const [ known, setKnown ] = useState< Record< number, Affiliate > >( {} );

	const search = async ( query: string ) => {
		setLoading( true );

		try {
			const { items } = await fetchList< Affiliate >( '/affiliates', {
				search: query,
				per_page: 20,
				...( status ? { status } : {} ),
			} );

			setKnown( ( previous ) => ( {
				...previous,
				...Object.fromEntries( items.map( ( a ) => [ a.id, a ] ) ),
			} ) );
			setOptions(
				items.map( ( affiliate ) => ( {
					value: String( affiliate.id ),
					label: `${ affiliate.name } (#${ affiliate.id })`,
				} ) )
			);
		} finally {
			setLoading( false );
		}
	};

	// Load the first page so the list is not empty before a search.
	useEffect( () => {
		search( '' );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ status ] );

	return (
		<SmartSelect
			className={ className }
			options={ options }
			loading={ loading }
			onSearch={ search }
			value={ value ? String( value ) : '' }
			onValueChange={ ( next ) => {
				const id = next ? Number( next ) : null;
				onChange( id, id ? known[ id ] : undefined );
			} }
			placeholder={
				placeholder ?? __( 'Choose an affiliate', 'flyaffiliate' )
			}
			searchPlaceholder={ __( 'Search affiliates…', 'flyaffiliate' ) }
			emptyMessage={ __( 'No affiliates match.', 'flyaffiliate' ) }
			showClear
		/>
	);
}
