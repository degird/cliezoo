/**
 * Settings → General: workspace name and ID, industry, company size,
 * contact details and the Powered-by credit.
 */
import { __ } from '@wordpress/i18n';
import { copyText } from '../../../packages/app/copy';
import { Button, Select, Switch, TextField } from '@cliezoo/ui';
import { Fieldset, SettingsForm, useSettings } from '../useSettings';
import { COUNTRIES, countryName } from '../countries';
import { COMPANY_SIZES, INDUSTRIES, industryName } from '../industries';
import { PhoneInput } from '../../../packages/app/form/PhoneInput';

const SIZES = [ '', ...COMPANY_SIZES ];

export default function General() {
	const s = useSettings( 'workspace' );
	const v = s.values;
	const text = ( key: string ) => ( v[ key ] as string ) ?? '';
	const address = ( v.address as Record< string, string > ) ?? {};
	const setAddress = ( key: string, value: string ) =>
		s.set( 'address', { ...address, [ key ]: value } );
	return (
		<SettingsForm state={ s }>
			<Fieldset
				title={ __( 'Workspace', 'cliezoo' ) }
				description={ __(
					'Shown to your team and clients in the app, emails and documents.',
					'cliezoo'
				) }
			>
				<TextField
					label={ __( 'Workspace name', 'cliezoo' ) }
					value={ text( 'name' ) }
					onChange={ ( x ) => s.set( 'name', x ) }
					isRequired
					errorMessage={ s.errors.name }
				/>
				<TextField
					label={ __( 'Workspace ID', 'cliezoo' ) }
					description={ __(
						'Fixed for this workspace. Support may ask for it.',
						'cliezoo'
					) }
					value={ s.info.id ?? '' }
					isReadOnly
				/>
				<div className="flex items-end">
					<Button
						variant="secondary"
						size="sm"
						isDisabled={ ! s.info.id }
						onPress={ () =>
							copyText(
								s.info.id ?? '',
								__( 'Copied.', 'cliezoo' )
							)
						}
					>
						{ __( 'Copy the ID', 'cliezoo' ) }
					</Button>
				</div>
				<Select
					label={ __( 'Industry', 'cliezoo' ) }
					items={ [
						{ id: 'none', label: __( 'Not set', 'cliezoo' ) },
						...INDUSTRIES.map( ( id ) => ( {
							id,
							label: industryName( id ),
						} ) ),
					] }
					selectedKey={
						( INDUSTRIES as readonly string[] ).includes(
							text( 'industry' )
						)
							? text( 'industry' )
							: 'none'
					}
					onSelectionChange={ ( key ) =>
						s.set( 'industry', key === 'none' ? '' : String( key ) )
					}
					errorMessage={ s.errors.industry }
				/>
				<Select
					label={ __( 'Company size', 'cliezoo' ) }
					items={ SIZES.map( ( size ) => ( {
						id: size || 'none',
						label: size || __( 'Not set', 'cliezoo' ),
					} ) ) }
					selectedKey={ text( 'company_size' ) || 'none' }
					onSelectionChange={ ( key ) =>
						s.set(
							'company_size',
							key === 'none' ? '' : String( key )
						)
					}
				/>
			</Fieldset>
			<Fieldset
				title={ __( 'Contact details', 'cliezoo' ) }
				description={ __(
					'Used on quotes, invoices and the client portal.',
					'cliezoo'
				) }
			>
				<TextField
					label={ __( 'Support email', 'cliezoo' ) }
					type="email"
					value={ text( 'support_email' ) }
					onChange={ ( x ) => s.set( 'support_email', x ) }
					errorMessage={ s.errors.support_email }
				/>
				<PhoneInput
					label={ __( 'Phone', 'cliezoo' ) }
					value={ text( 'phone' ) || null }
					onChange={ ( x ) => s.set( 'phone', x ?? '' ) }
					errorMessage={ s.errors.phone }
				/>
				<TextField
					label={ __( 'Website', 'cliezoo' ) }
					type="url"
					value={ text( 'website' ) }
					onChange={ ( x ) => s.set( 'website', x ) }
					errorMessage={ s.errors.website }
				/>
				<TextField
					label={ __( 'Street address', 'cliezoo' ) }
					value={ address.street ?? '' }
					onChange={ ( x ) => setAddress( 'street', x ) }
				/>
				<TextField
					label={ __( 'City', 'cliezoo' ) }
					value={ address.city ?? '' }
					onChange={ ( x ) => setAddress( 'city', x ) }
				/>
				<TextField
					label={ __( 'State or region', 'cliezoo' ) }
					value={ address.region ?? '' }
					onChange={ ( x ) => setAddress( 'region', x ) }
				/>
				<TextField
					label={ __( 'Postcode', 'cliezoo' ) }
					value={ address.postcode ?? '' }
					onChange={ ( x ) => setAddress( 'postcode', x ) }
				/>
				<Select
					label={ __( 'Country', 'cliezoo' ) }
					items={ [
						{ id: 'none', label: __( 'Not set', 'cliezoo' ) },
						...COUNTRIES.map( ( code ) => ( {
							id: code,
							label: countryName( code ),
						} ) ).sort( ( a, b ) =>
							a.label.localeCompare( b.label )
						),
					] }
					selectedKey={ text( 'country' ) || 'none' }
					onSelectionChange={ ( key ) => {
						const code = key === 'none' ? '' : String( key );
						s.set( 'country', code );
						setAddress( 'country', code );
					} }
				/>
			</Fieldset>
			<Fieldset
				title={ __( 'Our credit', 'cliezoo' ) }
				description={ __(
					'One switch for every page and email your clients see: the sign-in page, the client portal, shared links and emails. Off unless you turn it on.',
					'cliezoo'
				) }
			>
				<Switch
					isSelected={ !! v.powered_by }
					onChange={ ( on ) => s.set( 'powered_by', on ) }
				>
					{ __( 'Show "Powered by Cliezoo" to clients', 'cliezoo' ) }
				</Switch>
			</Fieldset>
		</SettingsForm>
	);
}
