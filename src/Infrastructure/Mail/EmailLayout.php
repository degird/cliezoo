<?php
/**
 * Responsive branded email layout.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ClientManager's email layout (hero image, greeting, body, details card,
 * call-to-action button, closing, signature) with Kitchen's branding
 * (logo, accent, text colour, signature). Email clients need inline styles;
 * this markup is email content, not a web page.
 */
final class EmailLayout {

	/**
	 * Renders the HTML email.
	 *
	 * @param array<string, mixed> $parts    Rendered parts (greeting/body/closing already escaped HTML).
	 * @param array<string, mixed> $branding Branding.
	 * @param string               $title    Document title.
	 * @return string
	 */
	public static function render( array $parts, array $branding, string $title ): string {
		$accent = sanitize_hex_color( (string) ( $branding['accent'] ?? '' ) );
		$accent = $accent ? $accent : '#0458FB';
		$text   = sanitize_hex_color( (string) ( $branding['text_color'] ?? '' ) );
		$text   = $text ? $text : '#0F172A';
		$radius = ! empty( $branding['rounded'] ) ? '8px' : '0';
		$logo   = (string) ( $branding['logo'] ?? '' );
		$name   = (string) ( $branding['workspace_name'] ?? '' );

		$html  = '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $title ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:#F7F8FA;">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F8FA;"><tr><td align="center" style="padding:24px 12px;">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:' . esc_attr( $radius ) . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:' . esc_attr( $text ) . ';font-size:15px;line-height:1.6;">';
		$html .= '<tr><td style="padding:24px 32px 8px;">';
		$html .= '' !== $logo ? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '" style="max-height:40px;max-width:200px;">' : '<strong style="font-size:18px;">' . esc_html( $name ) . '</strong>';
		$html .= '</td></tr>';
		if ( ! empty( $parts['hero_image'] ) ) {
			$html .= '<tr><td style="padding:8px 32px;"><img src="' . esc_url( (string) $parts['hero_image'] ) . '" alt="" style="width:100%;border-radius:' . esc_attr( $radius ) . ';"></td></tr>';
		}
		$html .= '<tr><td style="padding:16px 32px;">';
		if ( ! empty( $parts['greeting'] ) ) {
			$html .= '<p style="margin:0 0 12px;">' . $parts['greeting'] . '</p>';
		}
		$rows = array_filter(
			(array) ( $parts['card'] ?? array() ),
			static fn( $row ): bool => '' !== trim( (string) ( $row['value'] ?? '' ) )
		);
		$card = '';
		if ( $rows ) {
			$card = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;background:#F7F8FA;border-radius:' . esc_attr( $radius ) . ';">';
			foreach ( $rows as $row ) {
				$card .= '<tr><td style="padding:8px 16px;color:#475569;">' . esc_html( (string) $row['label'] ) . '</td><td align="right" style="padding:8px 16px;font-weight:600;">' . esc_html( (string) $row['value'] ) . '</td></tr>';
			}
			$card .= '</table>';
		}
		$button = ! empty( $parts['button_label'] ) && ! empty( $parts['button_url'] )
			? '<p style="margin:24px 0;"><a href="' . esc_url( (string) $parts['button_url'] ) . '" style="display:inline-block;background:' . esc_attr( $accent ) . ';color:#FFFFFF;text-decoration:none;padding:12px 24px;border-radius:' . esc_attr( $radius ) . ';font-weight:600;">' . esc_html( (string) $parts['button_label'] ) . '</a></p>'
			: '';
		// Where the person writing put {{card}} or {{cta}} in their message,
		// the card and the button go there, once, instead of after it. The
		// paragraph around the mark is closed and opened again, so a table is
		// never inside a paragraph.
		$body = (string) $parts['body'];
		foreach ( array(
			'{{card}}' => &$card,
			'{{cta}}'  => &$button,
		) as $mark => &$piece ) {
			$at = strpos( $body, $mark );
			if ( false === $at ) {
				continue;
			}
			$body  = substr( $body, 0, $at ) . '</p>' . $piece . '<p>' . str_replace( $mark, '', substr( $body, $at + strlen( $mark ) ) );
			$piece = '';
		}
		unset( $piece );
		$html .= '<div>' . str_replace( '<p></p>', '', $body ) . '</div>';
		$html .= $card . $button;
		if ( ! empty( $parts['closing'] ) ) {
			$html .= '<p style="margin:16px 0 0;">' . $parts['closing'] . '</p>';
		}
		if ( ! empty( $branding['signature_enabled'] ) && ! empty( $branding['signature'] ) ) {
			$html .= '<p style="margin:16px 0 0;color:#475569;">' . nl2br( esc_html( (string) $branding['signature'] ) ) . '</p>';
		}
		$html .= '</td></tr>';
		if ( ! empty( $branding['powered_by'] ) ) {
			$html .= '<tr><td align="center" style="padding:16px;color:#94A3B8;font-size:12px;">' . esc_html__( 'Powered by Cliezoo', 'cliezoo' ) . '</td></tr>';
		}
		$html .= '</table></td></tr></table></body></html>';
		return $html;
	}

	/**
	 * Plain-text version.
	 *
	 * @param array<string, mixed> $parts Parts.
	 * @return string
	 */
	public static function text( array $parts ): string {
		$lines = array();
		foreach ( array( 'greeting', 'body' ) as $key ) {
			if ( ! empty( $parts[ $key ] ) ) {
				// The marks for the card and the button are the HTML's
				// business; in plain text both come after the words.
				$said    = str_replace( array( '{{card}}', '{{cta}}' ), '', (string) $parts[ $key ] );
				$lines[] = trim( html_entity_decode( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br />', '</li>' ), "\n", $said ) ), ENT_QUOTES, 'UTF-8' ) );
			}
		}
		foreach ( (array) ( $parts['card'] ?? array() ) as $row ) {
			if ( '' !== trim( (string) ( $row['value'] ?? '' ) ) ) {
				$lines[] = $row['label'] . ': ' . $row['value'];
			}
		}
		if ( ! empty( $parts['button_label'] ) && ! empty( $parts['button_url'] ) ) {
			$lines[] = $parts['button_label'] . ': ' . $parts['button_url'];
		}
		if ( ! empty( $parts['closing'] ) ) {
			$lines[] = trim( html_entity_decode( wp_strip_all_tags( (string) $parts['closing'] ), ENT_QUOTES, 'UTF-8' ) );
		}
		return implode( "\n\n", array_filter( $lines ) );
	}
}
