/**
 * Controls for custom fields whose type needs more than a text box: a list
 * with its coloured choices, a multi-list, a number in its format, money in
 * the field's currency, a rating, a date and time.
 */
import { useState, type ReactElement } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { getBoot } from '@cliezoo/data';
import { Checkbox, Select, Switch, TextField } from '@cliezoo/ui';
import type { FieldMeta } from '../list/meta';
import { fromLocalInput, toLocalInput } from './datetime';
import {
	choicesOf,
	currencyDecimals,
	customOptions,
	formatNumber,
	toMinor,
} from './customField';

/** Label, help and state, as FieldInput hands them to every control. */
export interface Common {
	label?: string;
	'aria-label'?: string;
	description?: string;
	errorMessage?: string;
	isRequired?: boolean;
	isDisabled?: boolean;
}

/**
 * The control a custom field's own type needs, or null when the ordinary
 * control for its type will do (text, URL, email, phone, date, long text,
 * a person).
 *
 * @param field    The field.
 * @param value    Its value.
 * @param onChange Called with the new value.
 * @param common   Label, help and state.
 */
export function customControl(
	field: FieldMeta,
	value: unknown,
	onChange: ( value: unknown ) => void,
	common: Common
): ReactElement | null {
	const options = customOptions( ( field as { options?: unknown } ).options );
	switch ( field.type ) {
		case 'list':
			return <ChoiceInput { ...{ field, value, onChange, common } } />;
		case 'multilist':
			return <ChoicesInput { ...{ field, value, onChange, common } } />;
		case 'checkbox':
			return (
				<div className="flex items-center">
					<Switch
						isSelected={ !! value }
						onChange={ onChange }
						isDisabled={ common.isDisabled }
					>
						{ field.label }
					</Switch>
				</div>
			);
		case 'currency':
			return (
				<CustomMoneyInput
					{ ...{ value, onChange, common } }
					currency={
						options.currency || getBoot().workspace.currency
					}
				/>
			);
		case 'number':
			return (
				<NumberInput
					{ ...{ value, onChange, common } }
					format={ options.format ?? 'plain' }
					decimals={ options.decimals ?? null }
				/>
			);
		case 'rating': {
			const max = options.max ?? 5;
			return (
				<Select
					{ ...common }
					items={ Array.from( { length: max + 1 }, ( _, n ) => ( {
						id: String( n ),
						label: sprintf(
							/* translators: 1: how many so far, 2: how many there are. */
							__( '%1$d of %2$d', 'cliezoo' ),
							n,
							max
						),
					} ) ) }
					selectedKey={
						null === value || undefined === value
							? null
							: String( value )
					}
					onSelectionChange={ ( key ) =>
						onChange( null === key ? null : Number( key ) )
					}
				/>
			);
		}
		case 'datetime':
			return (
				<TextField
					{ ...common }
					type="datetime-local"
					value={ toLocalInput( value ) }
					onChange={ ( next: string ) =>
						onChange( fromLocalInput( next ) )
					}
				/>
			);
	}
	return null;
}

/**
 * The dot a choice's colour is shown with, beside its name.
 *
 * @param root0       Props.
 * @param root0.color The colour.
 */
export function Swatch( { color }: { color?: string } ) {
	if ( ! color ) {
		return null;
	}
	return (
		<span
			aria-hidden="true"
			className="inline-block size-2.5 shrink-0 rounded-full"
			// A colour the workspace chose for one choice is data, so it
			// cannot be a class.
			style={ { backgroundColor: color } }
		/>
	);
}

interface ChoiceProps {
	field: FieldMeta;
	value: unknown;
	onChange: ( value: unknown ) => void;
	common: Common;
}

/**
 * One choice from a list field, with the colour of the one chosen.
 *
 * @param root0          Props.
 * @param root0.field    The field.
 * @param root0.value    The value.
 * @param root0.onChange Called with the new value.
 * @param root0.common   Label, help and state.
 */
function ChoiceInput( { field, value, onChange, common }: ChoiceProps ) {
	const choices = choicesOf( ( field as { options?: unknown } ).options );
	const chosen = choices.find( ( one ) => one.value === String( value ) );
	return (
		<div className="flex items-end gap-2">
			<Select
				{ ...common }
				className="min-w-0 flex-1"
				items={ choices.map( ( one ) => ( {
					id: one.value,
					label: one.label,
				} ) ) }
				selectedKey={ chosen ? chosen.value : null }
				onSelectionChange={ ( key ) =>
					onChange( null === key ? null : String( key ) )
				}
			/>
			{ chosen?.color && (
				<span className="mb-3">
					<Swatch color={ chosen.color } />
				</span>
			) }
		</div>
	);
}

/**
 * Any of the choices of a multi-list field, as checkboxes.
 *
 * @param root0          Props.
 * @param root0.field    The field.
 * @param root0.value    The values.
 * @param root0.onChange Called with the new values.
 * @param root0.common   Label, help and state.
 */
function ChoicesInput( { field, value, onChange, common }: ChoiceProps ) {
	const choices = choicesOf( ( field as { options?: unknown } ).options );
	const picked = Array.isArray( value ) ? value.map( String ) : [];
	const name = common.label ?? common[ 'aria-label' ];
	return (
		<fieldset className="flex flex-col gap-1.5">
			<legend className="text-sm font-medium text-fg">
				{ name }
				{ common.isRequired && (
					<span className="ms-1 text-danger" aria-hidden="true">
						*
					</span>
				) }
			</legend>
			<div className="flex flex-wrap gap-x-4 gap-y-2">
				{ choices.map( ( one ) => (
					<Checkbox
						key={ one.value }
						isSelected={ picked.includes( one.value ) }
						isDisabled={ common.isDisabled }
						onChange={ ( on: boolean ) => {
							const next = on
								? [ ...picked, one.value ]
								: picked.filter( ( v ) => v !== one.value );
							onChange( next.length ? next : null );
						} }
					>
						<span className="inline-flex items-center gap-1.5">
							<Swatch color={ one.color } />
							{ one.label }
						</span>
					</Checkbox>
				) ) }
			</div>
			{ common.description && (
				<p className="text-xs text-muted">{ common.description }</p>
			) }
			{ common.errorMessage && (
				<p className="text-xs text-danger" role="alert">
					{ common.errorMessage }
				</p>
			) }
		</fieldset>
	);
}

/**
 * A number, typed plainly and shown back in the field's format.
 *
 * @param root0          Props.
 * @param root0.value    The number.
 * @param root0.onChange Called with the new number.
 * @param root0.common   Label, help and state.
 * @param root0.format   How it is shown.
 * @param root0.decimals Decimal places shown.
 */
function NumberInput( {
	value,
	onChange,
	common,
	format,
	decimals,
}: {
	value: unknown;
	onChange: ( value: unknown ) => void;
	common: Common;
	format: 'plain' | 'thousands' | 'percentage';
	decimals: number | null;
} ) {
	const number =
		null === value || undefined === value || '' === value
			? null
			: Number( value );
	const shown =
		null === number || 'plain' === format
			? ''
			: formatNumber(
					number,
					{ format, decimals },
					getBoot().locale.replace( '_', '-' )
				);
	return (
		<TextField
			{ ...common }
			type="number"
			inputMode="decimal"
			value={ null === number ? '' : String( number ) }
			description={
				[ common.description, shown ].filter( Boolean ).join( ' · ' ) ||
				undefined
			}
			onChange={ ( next: string ) =>
				onChange( '' === next ? null : Number( next ) )
			}
		/>
	);
}

/**
 * An amount in the field's currency: typed in major units (12.50), kept in
 * minor units (1250) with the currency, as all money in the API is.
 *
 * @param root0          Props.
 * @param root0.value    The amount ({ amount, currency }).
 * @param root0.onChange Called with the new amount.
 * @param root0.common   Label, help and state.
 * @param root0.currency The field's currency.
 */
function CustomMoneyInput( {
	value,
	onChange,
	common,
	currency,
}: {
	value: unknown;
	onChange: ( value: unknown ) => void;
	common: Common;
	currency: string;
} ) {
	const money = ( value ?? {} ) as { amount?: number };
	const decimals = currencyDecimals( currency );
	const [ typed, setTyped ] = useState(
		'number' === typeof money.amount
			? ( money.amount / 10 ** decimals ).toFixed( decimals )
			: ''
	);
	return (
		<TextField
			{ ...common }
			inputMode="decimal"
			value={ typed }
			description={ [ common.description, currency ]
				.filter( Boolean )
				.join( ' · ' ) }
			onChange={ ( next: string ) => {
				setTyped( next );
				const minor = toMinor( next, currency );
				onChange( null === minor ? null : { amount: minor, currency } );
			} }
		/>
	);
}
