/**
 * A searchable WordPress user select, for turning a user into an affiliate.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { SmartSelect, type SmartSelectOption } from '@wedevs/plugin-ui';

type WpUser = { id: number; name: string; slug: string };

type Props = {
	value: number | null;
	onChange: ( userId: number | null ) => void;
};

export default function UserPicker( { value, onChange }: Props ) {
	const [ options, setOptions ] = useState< SmartSelectOption[] >( [] );
	const [ loading, setLoading ] = useState( false );

	const search = async ( query: string ) => {
		setLoading( true );

		try {
			const users = await apiFetch< WpUser[] >( {
				path: addQueryArgs( '/wp/v2/users', {
					search: query,
					per_page: 20,
					context: 'edit',
				} ),
			} );

			setOptions(
				users.map( ( user ) => ( {
					value: String( user.id ),
					label: `${ user.name } (${ user.slug })`,
				} ) )
			);
		} catch ( error ) {
			setOptions( [] );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		search( '' );
	}, [] );

	return (
		<SmartSelect
			options={ options }
			loading={ loading }
			onSearch={ search }
			value={ value ? String( value ) : '' }
			onValueChange={ ( next ) =>
				onChange( next ? Number( next ) : null )
			}
			placeholder={ __( 'Choose a user', 'flyaffiliate' ) }
			searchPlaceholder={ __( 'Search users…', 'flyaffiliate' ) }
			emptyMessage={ __( 'No users match.', 'flyaffiliate' ) }
			showClear
		/>
	);
}
