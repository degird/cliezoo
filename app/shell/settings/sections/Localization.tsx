/**
 * Settings → Localization: time zone, currency, number and date formats,
 * week and fiscal year, working days and holidays.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Plus, Trash2 } from 'lucide-react';
import { getBoot } from '@cliezoo/data';
import { Button, Checkbox, IconButton, Select, TextField } from '@cliezoo/ui';
import { Fieldset, SettingsForm, useSettings } from '../useSettings';

interface Holiday {
	date: string;
	name: string;
	recurring?: boolean;
}

const locale = () => getBoot().locale.replace( '_', '-' );

function supported( kind: 'timeZone' | 'currency' ): string[] {
	try {
		return (
			Intl as unknown as { supportedValuesOf: ( k: string ) => string[] }
		 ).supportedValuesOf( kind );
	} catch {
		return kind === 'timeZone' ? [ 'UTC' ] : [ 'USD', 'EUR', 'GBP' ];
	}
}

function currencyName( code: string ): string {
	try {
		return `${ code } — ${ new Intl.DisplayNames( [ locale() ], { type: 'currency' } ).of( code ) }`;
	} catch {
		return code;
	}
}

function weekday( index: number ): string {
	// 2023-01-01 was a Sunday.
	return new Intl.DateTimeFormat( locale(), {
		weekday: 'long',
		timeZone: 'UTC',
	} ).format( new Date( Date.UTC( 2023, 0, 1 + index ) ) );
}

function month( index: number ): string {
	return new Intl.DateTimeFormat( locale(), {
		month: 'long',
		timeZone: 'UTC',
	} ).format( new Date( Date.UTC( 2023, index - 1, 1 ) ) );
}

function preview( v: Record< string, unknown > ): string {
	const decimal = ( v.decimal_separator as string ) ?? '.';
	const thousands = ( v.thousands_separator as string ) ?? ',';
	const grouping = v.digit_grouping === 'indian' ? '12,34,567' : '1,234,567';
	const number =
		grouping.replace( /,/g, '' ).replace( //g, thousands ) + decimal + '89';
	const symbol = ( v.currency as string ) || 'USD';
	const position = ( v.currency_symbol_position as string ) ?? 'before';
	const money =
		{
			before: `${ symbol }${ number }`,
			before_space: `${ symbol } ${ number }`,
			after: `${ number }${ symbol }`,
			after_space: `${ number } ${ symbol }`,
		}[ position ] ?? number;
	// Half past two, written the way the choice being previewed would
	// write it — in this language, not in American English.
	const time = new Intl.DateTimeFormat(
		getBoot().locale.replace( '_', '-' ),
		{
			hour: 'numeric',
			minute: '2-digit',
			hour12: v.time_format !== '24',
			timeZone: 'UTC',
		}
	).format( new Date( Date.UTC( 2000, 0, 1, 14, 30 ) ) );
	return `${ money } · ${ v.date_format as string } · ${ time }`;
}

export default function Localization() {
	const s = useSettings( 'workspace' );
	const v = s.values;
	const days = ( v.working_days as number[] ) ?? [];
	const holidays = ( v.holidays as Holiday[] ) ?? [];
	const select = (
		key: string,
		label: string,
		items: Array< { id: string; label: string } >
	) => (
		<Select
			label={ label }
			items={ items }
			selectedKey={ String( v[ key ] ?? '' ) }
			onSelectionChange={ ( k ) =>
				s.set(
					key,
					typeof v[ key ] === 'number' ? Number( k ) : String( k )
				)
			}
			errorMessage={ s.errors[ key ] }
		/>
	);
	return (
		<SettingsForm state={ s }>
			<Fieldset title={ __( 'Time zone and currency', 'cliezoo' ) }>
				{ select(
					'timezone',
					__( 'Time zone', 'cliezoo' ),
					supported( 'timeZone' ).map( ( zone ) => ( {
						id: zone,
						label: zone.replace( /_/g, ' ' ),
					} ) )
				) }
				{ select(
					'currency',
					__( 'Default currency', 'cliezoo' ),
					supported( 'currency' ).map( ( code ) => ( {
						id: code,
						label: currencyName( code ),
					} ) )
				) }
				{ select(
					'currency_symbol_position',
					__( 'Currency symbol', 'cliezoo' ),
					[
						{
							id: 'before',
							label: __( 'Before the amount ($100)', 'cliezoo' ),
						},
						{
							id: 'before_space',
							label: __(
								'Before, with a space ($ 100)',
								'cliezoo'
							),
						},
						{
							id: 'after',
							label: __( 'After the amount (100$)', 'cliezoo' ),
						},
						{
							id: 'after_space',
							label: __(
								'After, with a space (100 $)',
								'cliezoo'
							),
						},
					]
				) }
			</Fieldset>
			<Fieldset
				title={ __( 'Numbers and dates', 'cliezoo' ) }
				description={ sprintf(
					/* translators: %s: formatting example. */
					__( 'Preview: %s', 'cliezoo' ),
					preview( v )
				) }
			>
				{ select(
					'decimal_separator',
					__( 'Decimal separator', 'cliezoo' ),
					[
						{ id: '.', label: __( 'Point (1.5)', 'cliezoo' ) },
						{ id: ',', label: __( 'Comma (1,5)', 'cliezoo' ) },
					]
				) }
				{ select(
					'thousands_separator',
					__( 'Thousands separator', 'cliezoo' ),
					[
						{ id: ',', label: __( 'Comma (1,000)', 'cliezoo' ) },
						{ id: '.', label: __( 'Point (1.000)', 'cliezoo' ) },
						{ id: ' ', label: __( 'Space (1 000)', 'cliezoo' ) },
						{
							id: "'",
							label: __( "Apostrophe (1'000)", 'cliezoo' ),
						},
						{ id: '', label: __( 'None (1000)', 'cliezoo' ) },
					]
				) }
				{ select( 'digit_grouping', __( 'Digit grouping', 'cliezoo' ), [
					{
						id: 'standard',
						label: __( 'Thousands (1,234,567)', 'cliezoo' ),
					},
					{
						id: 'indian',
						label: __( 'Lakh and crore (12,34,567)', 'cliezoo' ),
					},
				] ) }
				{ select(
					'date_format',
					__( 'Date format', 'cliezoo' ),
					[
						'MMM D, YYYY',
						'D MMM YYYY',
						'MM/DD/YYYY',
						'DD/MM/YYYY',
						'YYYY-MM-DD',
					].map( ( f ) => ( { id: f, label: f } ) )
				) }
				{ select( 'time_format', __( 'Time format', 'cliezoo' ), [
					{ id: '12', label: __( '12-hour (2:30 PM)', 'cliezoo' ) },
					{ id: '24', label: __( '24-hour (14:30)', 'cliezoo' ) },
				] ) }
			</Fieldset>
			<Fieldset title={ __( 'Calendar', 'cliezoo' ) }>
				{ select(
					'week_start',
					__( 'Week starts on', 'cliezoo' ),
					[ 0, 1, 2, 3, 4, 5, 6 ].map( ( d ) => ( {
						id: String( d ),
						label: weekday( d ),
					} ) )
				) }
				{ select(
					'fiscal_year_start',
					__( 'Fiscal year starts in', 'cliezoo' ),
					[ 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12 ].map( ( m ) => ( {
						id: String( m ),
						label: month( m ),
					} ) )
				) }
				<fieldset className="sm:col-span-2">
					<legend className="mb-2 text-sm font-medium">
						{ __( 'Working days', 'cliezoo' ) }
					</legend>
					<div className="flex flex-wrap gap-4">
						{ [ 1, 2, 3, 4, 5, 6, 0 ].map( ( d ) => (
							<Checkbox
								key={ d }
								isSelected={ days.includes( d ) }
								onChange={ ( on ) =>
									s.set(
										'working_days',
										on
											? [ ...days, d ].sort()
											: days.filter( ( x ) => x !== d )
									)
								}
							>
								{ weekday( d ) }
							</Checkbox>
						) ) }
					</div>
				</fieldset>
			</Fieldset>
			<section className="rounded-lg border border-line bg-raised p-5">
				<h2 className="text-base font-semibold">
					{ __( 'Holidays', 'cliezoo' ) }
				</h2>
				<p className="mt-1 text-sm text-muted">
					{ __(
						'Due dates and reminders skip these days.',
						'cliezoo'
					) }
				</p>
				<ul className="mt-4 space-y-2">
					{ holidays.map( ( holiday, index ) => {
						const update = ( change: Partial< Holiday > ) =>
							s.set(
								'holidays',
								holidays.map( ( h, i ) =>
									i === index ? { ...h, ...change } : h
								)
							);
						return (
							<li
								key={ index }
								className="grid items-end gap-2 sm:grid-cols-[10rem_1fr_auto_auto]"
							>
								<TextField
									label={ __( 'Date', 'cliezoo' ) }
									type="date"
									value={ holiday.date }
									onChange={ ( date ) => update( { date } ) }
								/>
								<TextField
									label={ __( 'Name', 'cliezoo' ) }
									value={ holiday.name }
									onChange={ ( name ) => update( { name } ) }
								/>
								<Checkbox
									className="pb-2"
									isSelected={ !! holiday.recurring }
									onChange={ ( recurring ) =>
										update( { recurring } )
									}
								>
									{ __( 'Every year', 'cliezoo' ) }
								</Checkbox>
								<IconButton
									label={ __( 'Remove holiday', 'cliezoo' ) }
									tooltip={ false }
									icon={
										<Trash2
											size={ 15 }
											aria-hidden="true"
										/>
									}
									onPress={ () =>
										s.set(
											'holidays',
											holidays.filter(
												( _, i ) => i !== index
											)
										)
									}
								/>
							</li>
						);
					} ) }
				</ul>
				<Button
					className="mt-3"
					size="sm"
					icon={ <Plus size={ 14 } aria-hidden="true" /> }
					onPress={ () =>
						s.set( 'holidays', [
							...holidays,
							{ date: '', name: '', recurring: false },
						] )
					}
				>
					{ __( 'Add holiday', 'cliezoo' ) }
				</Button>
			</section>
		</SettingsForm>
	);
}
