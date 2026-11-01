<?php
/**
 * ISO 4217 currency data.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Active ISO 4217 currencies with their minor-unit decimals (0 for JPY,
 * 3 for KWD/BHD…) so money math is exact (Blueprint §31.1 "Rounding").
 * Names are English fallbacks; the app shows localised names through Intl.
 */
final class Currencies {

	/**
	 * Code => [name, symbol, decimals].
	 */
	private const DATA = array(
		'AED' => array( 'UAE Dirham', 'د.إ', 2 ),
		'AFN' => array( 'Afghani', '؋', 2 ),
		'ALL' => array( 'Lek', 'L', 2 ),
		'AMD' => array( 'Armenian Dram', '֏', 2 ),
		'ANG' => array( 'Netherlands Antillean Guilder', 'ƒ', 2 ),
		'AOA' => array( 'Kwanza', 'Kz', 2 ),
		'ARS' => array( 'Argentine Peso', '$', 2 ),
		'AUD' => array( 'Australian Dollar', 'A$', 2 ),
		'AWG' => array( 'Aruban Florin', 'ƒ', 2 ),
		'AZN' => array( 'Azerbaijan Manat', '₼', 2 ),
		'BAM' => array( 'Convertible Mark', 'KM', 2 ),
		'BBD' => array( 'Barbados Dollar', '$', 2 ),
		'BDT' => array( 'Taka', '৳', 2 ),
		'BGN' => array( 'Bulgarian Lev', 'лв', 2 ),
		'BHD' => array( 'Bahraini Dinar', '.د.ب', 3 ),
		'BIF' => array( 'Burundi Franc', 'FBu', 0 ),
		'BMD' => array( 'Bermudian Dollar', '$', 2 ),
		'BND' => array( 'Brunei Dollar', '$', 2 ),
		'BOB' => array( 'Boliviano', 'Bs.', 2 ),
		'BRL' => array( 'Brazilian Real', 'R$', 2 ),
		'BSD' => array( 'Bahamian Dollar', '$', 2 ),
		'BTN' => array( 'Ngultrum', 'Nu.', 2 ),
		'BWP' => array( 'Pula', 'P', 2 ),
		'BYN' => array( 'Belarusian Ruble', 'Br', 2 ),
		'BZD' => array( 'Belize Dollar', '$', 2 ),
		'CAD' => array( 'Canadian Dollar', 'CA$', 2 ),
		'CDF' => array( 'Congolese Franc', 'FC', 2 ),
		'CHF' => array( 'Swiss Franc', 'CHF', 2 ),
		'CLP' => array( 'Chilean Peso', '$', 0 ),
		'CNY' => array( 'Yuan Renminbi', '¥', 2 ),
		'COP' => array( 'Colombian Peso', '$', 2 ),
		'CRC' => array( 'Costa Rican Colon', '₡', 2 ),
		'CUP' => array( 'Cuban Peso', '$', 2 ),
		'CVE' => array( 'Cabo Verde Escudo', '$', 2 ),
		'CZK' => array( 'Czech Koruna', 'Kč', 2 ),
		'DJF' => array( 'Djibouti Franc', 'Fdj', 0 ),
		'DKK' => array( 'Danish Krone', 'kr', 2 ),
		'DOP' => array( 'Dominican Peso', 'RD$', 2 ),
		'DZD' => array( 'Algerian Dinar', 'د.ج', 2 ),
		'EGP' => array( 'Egyptian Pound', 'E£', 2 ),
		'ERN' => array( 'Nakfa', 'Nfk', 2 ),
		'ETB' => array( 'Ethiopian Birr', 'Br', 2 ),
		'EUR' => array( 'Euro', '€', 2 ),
		'FJD' => array( 'Fiji Dollar', '$', 2 ),
		'FKP' => array( 'Falkland Islands Pound', '£', 2 ),
		'GBP' => array( 'Pound Sterling', '£', 2 ),
		'GEL' => array( 'Lari', '₾', 2 ),
		'GHS' => array( 'Ghana Cedi', '₵', 2 ),
		'GIP' => array( 'Gibraltar Pound', '£', 2 ),
		'GMD' => array( 'Dalasi', 'D', 2 ),
		'GNF' => array( 'Guinean Franc', 'FG', 0 ),
		'GTQ' => array( 'Quetzal', 'Q', 2 ),
		'GYD' => array( 'Guyana Dollar', '$', 2 ),
		'HKD' => array( 'Hong Kong Dollar', 'HK$', 2 ),
		'HNL' => array( 'Lempira', 'L', 2 ),
		'HTG' => array( 'Gourde', 'G', 2 ),
		'HUF' => array( 'Forint', 'Ft', 2 ),
		'IDR' => array( 'Rupiah', 'Rp', 2 ),
		'ILS' => array( 'New Israeli Sheqel', '₪', 2 ),
		'INR' => array( 'Indian Rupee', '₹', 2 ),
		'IQD' => array( 'Iraqi Dinar', 'ع.د', 3 ),
		'IRR' => array( 'Iranian Rial', '﷼', 2 ),
		'ISK' => array( 'Iceland Krona', 'kr', 0 ),
		'JMD' => array( 'Jamaican Dollar', '$', 2 ),
		'JOD' => array( 'Jordanian Dinar', 'د.ا', 3 ),
		'JPY' => array( 'Yen', '¥', 0 ),
		'KES' => array( 'Kenyan Shilling', 'KSh', 2 ),
		'KGS' => array( 'Som', 'с', 2 ),
		'KHR' => array( 'Riel', '៛', 2 ),
		'KMF' => array( 'Comorian Franc', 'CF', 0 ),
		'KPW' => array( 'North Korean Won', '₩', 2 ),
		'KRW' => array( 'Won', '₩', 0 ),
		'KWD' => array( 'Kuwaiti Dinar', 'د.ك', 3 ),
		'KYD' => array( 'Cayman Islands Dollar', '$', 2 ),
		'KZT' => array( 'Tenge', '₸', 2 ),
		'LAK' => array( 'Lao Kip', '₭', 2 ),
		'LBP' => array( 'Lebanese Pound', 'ل.ل', 2 ),
		'LKR' => array( 'Sri Lanka Rupee', 'Rs', 2 ),
		'LRD' => array( 'Liberian Dollar', '$', 2 ),
		'LSL' => array( 'Loti', 'L', 2 ),
		'LYD' => array( 'Libyan Dinar', 'ل.د', 3 ),
		'MAD' => array( 'Moroccan Dirham', 'د.م.', 2 ),
		'MDL' => array( 'Moldovan Leu', 'L', 2 ),
		'MGA' => array( 'Malagasy Ariary', 'Ar', 2 ),
		'MKD' => array( 'Denar', 'ден', 2 ),
		'MMK' => array( 'Kyat', 'K', 2 ),
		'MNT' => array( 'Tugrik', '₮', 2 ),
		'MOP' => array( 'Pataca', 'MOP$', 2 ),
		'MRU' => array( 'Ouguiya', 'UM', 2 ),
		'MUR' => array( 'Mauritius Rupee', '₨', 2 ),
		'MVR' => array( 'Rufiyaa', 'Rf', 2 ),
		'MWK' => array( 'Malawi Kwacha', 'MK', 2 ),
		'MXN' => array( 'Mexican Peso', 'MX$', 2 ),
		'MYR' => array( 'Malaysian Ringgit', 'RM', 2 ),
		'MZN' => array( 'Mozambique Metical', 'MT', 2 ),
		'NAD' => array( 'Namibia Dollar', '$', 2 ),
		'NGN' => array( 'Naira', '₦', 2 ),
		'NIO' => array( 'Cordoba Oro', 'C$', 2 ),
		'NOK' => array( 'Norwegian Krone', 'kr', 2 ),
		'NPR' => array( 'Nepalese Rupee', 'रू', 2 ),
		'NZD' => array( 'New Zealand Dollar', 'NZ$', 2 ),
		'OMR' => array( 'Rial Omani', 'ر.ع.', 3 ),
		'PAB' => array( 'Balboa', 'B/.', 2 ),
		'PEN' => array( 'Sol', 'S/', 2 ),
		'PGK' => array( 'Kina', 'K', 2 ),
		'PHP' => array( 'Philippine Peso', '₱', 2 ),
		'PKR' => array( 'Pakistan Rupee', '₨', 2 ),
		'PLN' => array( 'Zloty', 'zł', 2 ),
		'PYG' => array( 'Guarani', '₲', 0 ),
		'QAR' => array( 'Qatari Rial', 'ر.ق', 2 ),
		'RON' => array( 'Romanian Leu', 'lei', 2 ),
		'RSD' => array( 'Serbian Dinar', 'дин.', 2 ),
		'RUB' => array( 'Russian Ruble', '₽', 2 ),
		'RWF' => array( 'Rwanda Franc', 'FRw', 0 ),
		'SAR' => array( 'Saudi Riyal', 'ر.س', 2 ),
		'SBD' => array( 'Solomon Islands Dollar', '$', 2 ),
		'SCR' => array( 'Seychelles Rupee', '₨', 2 ),
		'SDG' => array( 'Sudanese Pound', 'ج.س.', 2 ),
		'SEK' => array( 'Swedish Krona', 'kr', 2 ),
		'SGD' => array( 'Singapore Dollar', 'S$', 2 ),
		'SHP' => array( 'Saint Helena Pound', '£', 2 ),
		'SLE' => array( 'Leone', 'Le', 2 ),
		'SOS' => array( 'Somali Shilling', 'Sh', 2 ),
		'SRD' => array( 'Surinam Dollar', '$', 2 ),
		'SSP' => array( 'South Sudanese Pound', '£', 2 ),
		'STN' => array( 'Dobra', 'Db', 2 ),
		'SVC' => array( 'El Salvador Colon', '₡', 2 ),
		'SYP' => array( 'Syrian Pound', '£', 2 ),
		'SZL' => array( 'Lilangeni', 'L', 2 ),
		'THB' => array( 'Baht', '฿', 2 ),
		'TJS' => array( 'Somoni', 'SM', 2 ),
		'TMT' => array( 'Turkmenistan New Manat', 'm', 2 ),
		'TND' => array( 'Tunisian Dinar', 'د.ت', 3 ),
		'TOP' => array( 'Pa’anga', 'T$', 2 ),
		'TRY' => array( 'Turkish Lira', '₺', 2 ),
		'TTD' => array( 'Trinidad and Tobago Dollar', '$', 2 ),
		'TWD' => array( 'New Taiwan Dollar', 'NT$', 2 ),
		'TZS' => array( 'Tanzanian Shilling', 'TSh', 2 ),
		'UAH' => array( 'Hryvnia', '₴', 2 ),
		'UGX' => array( 'Uganda Shilling', 'USh', 0 ),
		'USD' => array( 'US Dollar', '$', 2 ),
		'UYU' => array( 'Peso Uruguayo', '$U', 2 ),
		'UZS' => array( 'Uzbekistan Sum', 'soʻm', 2 ),
		'VES' => array( 'Bolívar Soberano', 'Bs.', 2 ),
		'VND' => array( 'Dong', '₫', 0 ),
		'VUV' => array( 'Vatu', 'VT', 0 ),
		'WST' => array( 'Tala', 'T', 2 ),
		'XAF' => array( 'CFA Franc BEAC', 'FCFA', 0 ),
		'XCD' => array( 'East Caribbean Dollar', '$', 2 ),
		'XOF' => array( 'CFA Franc BCEAO', 'CFA', 0 ),
		'XPF' => array( 'CFP Franc', '₣', 0 ),
		'YER' => array( 'Yemeni Rial', '﷼', 2 ),
		'ZAR' => array( 'Rand', 'R', 2 ),
		'ZMW' => array( 'Zambian Kwacha', 'ZK', 2 ),
		'ZWG' => array( 'Zimbabwe Gold', 'ZiG', 2 ),
	);

	/**
	 * Whether a code is a known currency.
	 *
	 * @param string $code ISO code.
	 * @return bool
	 */
	public static function exists( string $code ): bool {
		return isset( self::DATA[ strtoupper( $code ) ] );
	}

	/**
	 * Minor-unit decimals (2 when unknown).
	 *
	 * @param string $code ISO code.
	 * @return int
	 */
	public static function decimals( string $code ): int {
		return self::DATA[ strtoupper( $code ) ][2] ?? 2;
	}

	/**
	 * Symbol.
	 *
	 * @param string $code ISO code.
	 * @return string
	 */
	public static function symbol( string $code ): string {
		return self::DATA[ strtoupper( $code ) ][1] ?? strtoupper( $code );
	}

	/**
	 * All currencies for pickers.
	 *
	 * @return array<int, array{code: string, name: string, symbol: string, decimals: int}>
	 */
	public static function all(): array {
		$out = array();
		foreach ( self::DATA as $code => $row ) {
			$out[] = array(
				'code'     => $code,
				'name'     => $row[0],
				'symbol'   => $row[1],
				'decimals' => $row[2],
			);
		}
		return $out;
	}

	/**
	 * Formats minor units for plain-text output (emails, PDFs), honouring the
	 * workspace separators and Indian lakh/crore grouping.
	 *
	 * @param int                  $minor    Amount in minor units.
	 * @param string               $currency ISO code.
	 * @param array<string, mixed> $format   Workspace settings (separators, grouping, symbol position).
	 * @return string
	 */
	public static function format( int $minor, string $currency, array $format = array() ): string {
		$decimals  = self::decimals( $currency );
		$negative  = $minor < 0;
		$minor     = abs( $minor );
		$divisor   = 10 ** $decimals;
		$whole     = intdiv( $minor, $divisor );
		$fraction  = $decimals > 0 ? str_pad( (string) ( $minor % $divisor ), $decimals, '0', STR_PAD_LEFT ) : '';
		$thousands = (string) ( $format['thousands_separator'] ?? ',' );
		$decimal   = (string) ( $format['decimal_separator'] ?? '.' );
		$digits    = (string) $whole;
		if ( 'indian' === ( $format['digit_grouping'] ?? 'standard' ) && strlen( $digits ) > 3 ) {
			$last   = substr( $digits, -3 );
			$rest   = substr( $digits, 0, -3 );
			$rest   = (string) preg_replace( '/\B(?=(\d{2})+(?!\d))/', $thousands, $rest );
			$digits = $rest . $thousands . $last;
		} else {
			$digits = (string) preg_replace( '/\B(?=(\d{3})+(?!\d))/', $thousands, $digits );
		}
		$number = $digits . ( '' !== $fraction ? $decimal . $fraction : '' );
		$symbol = self::symbol( $currency );
		switch ( $format['currency_symbol_position'] ?? 'before' ) {
			case 'after':
				$text = $number . $symbol;
				break;
			case 'after_space':
				$text = $number . ' ' . $symbol;
				break;
			case 'before_space':
				$text = $symbol . ' ' . $number;
				break;
			default:
				$text = $symbol . $number;
		}
		return ( $negative ? '-' : '' ) . $text;
	}
}
