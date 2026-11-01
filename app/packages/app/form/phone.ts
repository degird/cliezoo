/**
 * Phone numbers with a country dialling code, kept in E.164 (+4930123456).
 *
 * The table is ISO 3166 country → ITU calling code. Country names come from
 * the browser (Intl.DisplayNames) in the reader's language, so nothing here
 * needs translating and no service is asked. Where several countries share
 * a code (+1, +7, +44…), the first match wins when reading a number back;
 * the country picked is remembered only while it is being typed.
 */

/* eslint-disable prettier/prettier -- one country per entry reads better as a table. */
export const DIALLING_CODES: Record< string, string > = {
	AF: '93', AL: '355', DZ: '213', AS: '1', AD: '376', AO: '244', AI: '1', AG: '1', AR: '54', AM: '374',
	AW: '297', AU: '61', AT: '43', AZ: '994', BS: '1', BH: '973', BD: '880', BB: '1', BY: '375', BE: '32',
	BZ: '501', BJ: '229', BM: '1', BT: '975', BO: '591', BA: '387', BW: '267', BR: '55', BN: '673', BG: '359',
	BF: '226', BI: '257', KH: '855', CM: '237', CA: '1', CV: '238', KY: '1', CF: '236', TD: '235', CL: '56',
	CN: '86', CO: '57', KM: '269', CG: '242', CD: '243', CK: '682', CR: '506', CI: '225', HR: '385', CU: '53',
	CW: '599', CY: '357', CZ: '420', DK: '45', DJ: '253', DM: '1', DO: '1', EC: '593', EG: '20', SV: '503',
	GQ: '240', ER: '291', EE: '372', SZ: '268', ET: '251', FK: '500', FO: '298', FJ: '679', FI: '358', FR: '33',
	GF: '594', PF: '689', GA: '241', GM: '220', GE: '995', DE: '49', GH: '233', GI: '350', GR: '30', GL: '299',
	GD: '1', GP: '590', GU: '1', GT: '502', GN: '224', GW: '245', GY: '592', HT: '509', HN: '504', HK: '852',
	HU: '36', IS: '354', IN: '91', ID: '62', IR: '98', IQ: '964', IE: '353', IL: '972', IT: '39', JM: '1',
	JP: '81', JO: '962', KZ: '7', KE: '254', KI: '686', KP: '850', KR: '82', XK: '383', KW: '965', KG: '996',
	LA: '856', LV: '371', LB: '961', LS: '266', LR: '231', LY: '218', LI: '423', LT: '370', LU: '352', MO: '853',
	MG: '261', MW: '265', MY: '60', MV: '960', ML: '223', MT: '356', MH: '692', MQ: '596', MR: '222', MU: '230',
	YT: '262', MX: '52', FM: '691', MD: '373', MC: '377', MN: '976', ME: '382', MS: '1', MA: '212', MZ: '258',
	MM: '95', NA: '264', NR: '674', NP: '977', NL: '31', NC: '687', NZ: '64', NI: '505', NE: '227', NG: '234',
	MK: '389', MP: '1', NO: '47', OM: '968', PK: '92', PW: '680', PS: '970', PA: '507', PG: '675', PY: '595',
	PE: '51', PH: '63', PL: '48', PT: '351', PR: '1', QA: '974', RE: '262', RO: '40', RU: '7', RW: '250',
	KN: '1', LC: '1', VC: '1', WS: '685', SM: '378', ST: '239', SA: '966', SN: '221', RS: '381', SC: '248',
	SL: '232', SG: '65', SX: '1', SK: '421', SI: '386', SB: '677', SO: '252', ZA: '27', SS: '211', ES: '34',
	LK: '94', SD: '249', SR: '597', SE: '46', CH: '41', SY: '963', TW: '886', TJ: '992', TZ: '255', TH: '66',
	TL: '670', TG: '228', TO: '676', TT: '1', TN: '216', TR: '90', TM: '993', TC: '1', TV: '688', UG: '256',
	UA: '380', AE: '971', GB: '44', US: '1', UY: '598', UZ: '998', VU: '678', VA: '39', VE: '58', VN: '84',
	VG: '1', VI: '1', YE: '967', ZM: '260', ZW: '263',
};
/* eslint-enable prettier/prettier */

/**
 * Where a number is read as belonging when it starts with a shared code:
 * the country most people with that code are in.
 */
const PREFERRED: Record< string, string > = {
	'1': 'US',
	'7': 'RU',
	'39': 'IT',
	'44': 'GB',
	'61': 'AU',
	'262': 'RE',
	'590': 'GP',
};

/** A phone number taken apart. */
export interface PhoneParts {
	/** ISO 3166 country code, e.g. "DE". */
	country: string;
	/** The number without its country code, digits only. */
	national: string;
}

/**
 * A number in E.164 from a country and what was typed. A leading trunk "0"
 * (as in 030 for Berlin) is dropped, as it is when dialling from abroad;
 * a number typed with its own "+" keeps the code it was typed with.
 *
 * @param country  ISO country code.
 * @param national What was typed.
 * @return E.164, or '' when there are no digits.
 */
export function toE164( country: string, national: string ): string {
	const typed = national.trim();
	if ( typed.startsWith( '+' ) ) {
		const digits = typed.replace( /\D/g, '' );
		return digits ? `+${ digits.slice( 0, 15 ) }` : '';
	}
	let digits = typed.replace( /\D/g, '' );
	if ( typed.startsWith( '00' ) ) {
		return digits.length > 2 ? `+${ digits.slice( 2, 17 ) }` : '';
	}
	digits = digits.replace( /^0+/, '' );
	if ( ! digits ) {
		return '';
	}
	const code = DIALLING_CODES[ country.toUpperCase() ] ?? '';
	return `+${ ( code + digits ).slice( 0, 15 ) }`;
}

/**
 * Splits an E.164 number into its country and national part. A number
 * saved before dialling codes (no "+") is kept whole under the fallback.
 *
 * @param value    The stored number.
 * @param fallback The country to use when the number names none.
 * @param hint     A country the person picked, kept when its code matches.
 */
export function fromE164(
	value: string,
	fallback: string,
	hint = ''
): PhoneParts {
	const text = String( value ?? '' ).trim();
	if ( ! text.startsWith( '+' ) ) {
		return { country: fallback, national: text };
	}
	const digits = text.replace( /\D/g, '' );
	if (
		hint &&
		DIALLING_CODES[ hint ] &&
		digits.startsWith( DIALLING_CODES[ hint ] )
	) {
		return {
			country: hint,
			national: digits.slice( DIALLING_CODES[ hint ].length ),
		};
	}
	// The longest code that fits wins: +1268 is Antigua's +1, not +12.
	for ( let length = 3; length >= 1; length-- ) {
		const code = digits.slice( 0, length );
		const matches = Object.keys( DIALLING_CODES ).filter(
			( iso ) => DIALLING_CODES[ iso ] === code
		);
		if ( matches.length ) {
			const country = PREFERRED[ code ] ?? matches[ 0 ];
			return { country, national: digits.slice( length ) };
		}
	}
	return { country: fallback, national: digits };
}

/**
 * Whether a number looks like E.164: "+", then 8 to 15 digits.
 *
 * @param value The number.
 */
export function isE164( value: string ): boolean {
	return /^\+[1-9]\d{7,14}$/.test( value );
}

/**
 * The countries, named in the reader's language, sorted by name.
 *
 * @param locale The locale to name them in.
 */
export function countries(
	locale = 'en'
): Array< { id: string; label: string } > {
	let names: { of: ( code: string ) => string | undefined } | null = null;
	try {
		names = new Intl.DisplayNames( [ locale ], { type: 'region' } );
	} catch {
		names = null;
	}
	return Object.keys( DIALLING_CODES )
		.map( ( iso ) => ( {
			id: iso,
			label: `${ names?.of( iso ) ?? iso } (+${ DIALLING_CODES[ iso ] })`,
		} ) )
		.sort( ( a, b ) => a.label.localeCompare( b.label, locale ) );
}
