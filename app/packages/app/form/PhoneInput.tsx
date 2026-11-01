/**
 * A phone number with its country dialling code, saved in E.164.
 *
 * The country is picked from a list (named by the browser in the reader's
 * language) and the number typed as people write it locally; what is saved
 * is the one international form, so a number can be dialled from anywhere
 * and read by any other system.
 */
import { useMemo, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { getBoot } from '@cliezoo/data';
import { Select, TextField } from '@cliezoo/ui';
import { DIALLING_CODES, countries, fromE164, toE164 } from './phone';

export interface PhoneInputProps {
	label?: string;
	'aria-label'?: string;
	description?: string;
	errorMessage?: string;
	isRequired?: boolean;
	isDisabled?: boolean;
	value: string | null;
	/** Called with the number in E.164, or null when it is emptied. */
	onChange: ( value: string | null ) => void;
}

/** The workspace's country, the likeliest for a number typed without one. */
function homeCountry(): string {
	const country = String( getBoot().workspace.country ?? '' ).toUpperCase();
	return DIALLING_CODES[ country ] ? country : 'US';
}

/**
 * The control.
 *
 * @param root0              Props.
 * @param root0.label
 * @param root0.description
 * @param root0.errorMessage
 * @param root0.isRequired
 * @param root0.isDisabled
 * @param root0.value
 * @param root0.onChange
 */
export function PhoneInput( {
	label,
	description,
	errorMessage,
	isRequired,
	isDisabled,
	value,
	onChange,
	...rest
}: PhoneInputProps ) {
	const locale = (
		getBoot().user?.locale ||
		getBoot().locale ||
		'en'
	).replace( '_', '-' );
	const list = useMemo( () => countries( locale ), [ locale ] );
	const [ picked, setPicked ] = useState( '' );
	// A country chosen for an empty field has to stand: until a number is
	// typed there is nothing in the value to read a country from, and
	// falling back to the workspace's would dial the wrong code for the
	// first number somebody types.
	const parts = fromE164( value ?? '', picked || homeCountry(), picked );
	const [ typed, setTyped ] = useState( parts.national );
	const name = label ?? rest[ 'aria-label' ] ?? __( 'Phone', 'cliezoo' );

	const change = ( country: string, national: string ) => {
		const next = toE164( country, national );
		onChange( next || null );
	};

	return (
		<div className="flex flex-col gap-1.5">
			<div className="grid grid-cols-[minmax(0,11rem)_minmax(0,1fr)] items-end gap-2">
				<Select
					label={ label }
					aria-label={
						label
							? undefined
							: sprintf(
									/* translators: %s: the phone field's name. */
									__( 'Country code for %s', 'cliezoo' ),
									name
								)
					}
					items={ list }
					selectedKey={ parts.country }
					isDisabled={ isDisabled }
					onSelectionChange={ ( key ) => {
						const country = String( key ?? '' );
						setPicked( country );
						change( country, typed );
					} }
				/>
				<TextField
					aria-label={ sprintf(
						/* translators: %s: the phone field's name. */
						__( '%s number', 'cliezoo' ),
						name
					) }
					type="tel"
					inputMode="tel"
					autoComplete="tel-national"
					isRequired={ isRequired }
					isDisabled={ isDisabled }
					value={ typed }
					errorMessage={ errorMessage }
					onChange={ ( next: string ) => {
						setTyped( next );
						change( parts.country, next );
					} }
				/>
			</div>
			{ description && (
				<p className="text-xs text-muted">{ description }</p>
			) }
		</div>
	);
}
