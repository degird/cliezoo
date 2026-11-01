/**
 * What a custom field's settings mean for showing and typing its value.
 *
 * Kept apart from the controls so the list, the record page and exports can
 * format a value the same way the form does, and so the rules are tested
 * without rendering anything.
 */
import { __ } from '@wordpress/i18n';

/** A choice of a list or multi-list field. */
export interface CustomChoice {
	value: string;
	label: string;
	/** A hex colour (#rrggbb), or empty for none. */
	color?: string;
}

/** The options a custom field carries (by type). */
export interface CustomOptions {
	choices?: CustomChoice[];
	/** Number fields: how the number is shown. */
	format?: 'plain' | 'thousands' | 'percentage';
	/** Number fields: decimal places shown (null = as stored). */
	decimals?: number | null;
	/** Currency fields: the ISO 4217 code amounts are counted in. */
	currency?: string;
	/** Rating fields: the most stars. */
	max?: number;
}

/**
 * The options of a field, whatever shape the metadata carried them in (a
 * built-in enum sends a list of strings; a custom field sends an object).
 *
 * @param options The field's `options`.
 */
export function customOptions( options: unknown ): CustomOptions {
	if (
		! options ||
		'object' !== typeof options ||
		Array.isArray( options )
	) {
		return {};
	}
	return options as CustomOptions;
}

/**
 * The choices of a list field, each with its colour.
 *
 * @param options The field's `options`.
 */
export function choicesOf( options: unknown ): CustomChoice[] {
	return ( customOptions( options ).choices ?? [] ).filter(
		( choice ) => choice && '' !== String( choice.value ?? '' )
	);
}

/**
 * How many decimal places a currency has (yen none, dinar three).
 *
 * @param code Currency code.
 */
export function currencyDecimals( code: string ): number {
	try {
		return (
			new Intl.NumberFormat( 'en', {
				style: 'currency',
				currency: code,
			} ).resolvedOptions().maximumFractionDigits ?? 2
		);
	} catch {
		return 2;
	}
}

/**
 * A number as the field says to show it.
 *
 * A percentage is stored as the number people typed (12.5 means 12.5 %),
 * not as a fraction: that is how it is typed in and how it is filtered.
 *
 * @param value   The number.
 * @param options The field's options.
 * @param locale  The locale to write it in.
 */
export function formatNumber(
	value: number | null | undefined,
	options: CustomOptions,
	locale = 'en'
): string {
	if ( null === value || undefined === value || Number.isNaN( value ) ) {
		return '';
	}
	const decimals =
		'number' === typeof options.decimals ? options.decimals : undefined;
	const digits =
		undefined === decimals
			? { maximumFractionDigits: 6 }
			: {
					minimumFractionDigits: decimals,
					maximumFractionDigits: decimals,
				};
	switch ( options.format ) {
		case 'thousands':
			return new Intl.NumberFormat( locale, {
				useGrouping: true,
				...digits,
			} ).format( value );
		case 'percentage':
			return new Intl.NumberFormat( locale, {
				style: 'percent',
				...digits,
			} ).format( value / 100 );
		default:
			return new Intl.NumberFormat( locale, {
				useGrouping: false,
				...digits,
			} ).format( value );
	}
}

/**
 * An amount in minor units, written as money.
 *
 * @param minor    Amount in minor units (cents).
 * @param currency Currency code.
 * @param locale   The locale to write it in.
 */
export function formatMoney(
	minor: number | null | undefined,
	currency: string,
	locale = 'en'
): string {
	if ( null === minor || undefined === minor || Number.isNaN( minor ) ) {
		return '';
	}
	const decimals = currencyDecimals( currency );
	try {
		return new Intl.NumberFormat( locale, {
			style: 'currency',
			currency,
		} ).format( minor / 10 ** decimals );
	} catch {
		return `${ ( minor / 10 ** decimals ).toFixed( decimals ) } ${ currency }`;
	}
}

/**
 * What was typed in major units (12.50), as minor units (1250). Null when
 * it is not a number.
 *
 * @param typed    What was typed.
 * @param currency Currency code.
 */
export function toMinor( typed: string, currency: string ): number | null {
	const clean = typed.replace( /[\s,]/g, '' );
	if ( '' === clean || Number.isNaN( Number( clean ) ) ) {
		return null;
	}
	return Math.round( Number( clean ) * 10 ** currencyDecimals( currency ) );
}

/**
 * A custom value as words, for a cell, an export or a screen reader.
 *
 * @param type    The field's type.
 * @param value   The value.
 * @param options The field's options.
 * @param locale  The locale.
 */
export function formatCustom(
	type: string,
	value: unknown,
	options: unknown,
	locale = 'en'
): string {
	if ( null === value || undefined === value || '' === value ) {
		return '';
	}
	const settings = customOptions( options );
	switch ( type ) {
		case 'number':
			return formatNumber( Number( value ), settings, locale );
		case 'currency': {
			const money = value as { amount?: number; currency?: string };
			return formatMoney(
				'object' === typeof value ? money.amount : Number( value ),
				( 'object' === typeof value && money.currency ) ||
					settings.currency ||
					'USD',
				locale
			);
		}
		case 'list': {
			const found = choicesOf( options ).find(
				( choice ) => choice.value === String( value )
			);
			return found ? found.label : String( value );
		}
		case 'multilist': {
			const all = choicesOf( options );
			return ( Array.isArray( value ) ? value : [ value ] )
				.map(
					( one ) =>
						all.find( ( choice ) => choice.value === String( one ) )
							?.label ?? String( one )
				)
				.join( ', ' );
		}
		case 'checkbox':
			return value ? __( 'Yes', 'cliezoo' ) : __( 'No', 'cliezoo' );
		case 'person':
			return String(
				( value as { label?: string } )?.label ?? String( value )
			);
		default:
			return String( value );
	}
}
