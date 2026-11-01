<?php
/**
 * Safe variable engine for emails, documents and templates.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Infrastructure\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handlebars-style variables without code execution (cliezoo.md §6.9, §6.33):
 *
 * - `{{client.name}}` — escaped value (HTML mode) or raw (text mode)
 * - `{{{html}}}` — trusted HTML from the system (never user input)
 * - `{{ isClient ? 'Hi' : 'Team' }}`, `{{ status == 'paid' ? 'Paid' : 'Due' }}` — ternaries
 * - `{{#if path}}…{{else}}…{{/if}}`, `{{#unless path}}…{{/unless}}`
 * - `{{#each items}}{{title}}{{/each}}` — loops (the item is the context, `@index` available)
 * - `{{contact.first_name|fallback}}` — fallback text when empty
 *
 * Templates are parsed into a small tree and rendered; nothing is evaluated.
 */
final class TemplateEngine {

	private const MAX_DEPTH = 10;

	/**
	 * Renders a template.
	 *
	 * @param string               $template Template.
	 * @param array<string, mixed> $context  Variables.
	 * @param bool                 $html     Escape for HTML.
	 * @return string
	 */
	public static function render( string $template, array $context, bool $html = true ): string {
		$tokens = preg_split( '/(\{\{\{\s*[\w.@]+\s*\}\}\}|\{\{[^{}]*\}\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$pos    = 0;
		$tree   = self::parse( is_array( $tokens ) ? $tokens : array(), $pos, '', 0 );
		return self::render_nodes( $tree['children'], $context, $html, 0 );
	}

	/**
	 * Parses tokens into nodes until the matching close tag.
	 *
	 * @param string[] $tokens Tokens.
	 * @param int      $pos    Position (by reference).
	 * @param string   $close  Block type being closed ('' = root).
	 * @param int      $depth  Depth.
	 * @return array{children: array<int, array<string, mixed>>, else: array<int, array<string, mixed>>}
	 */
	private static function parse( array $tokens, int &$pos, string $close, int $depth ): array {
		$children = array();
		$else     = array();
		$target   = &$children;
		$total    = count( $tokens );
		while ( $pos < $total ) {
			$token = $tokens[ $pos++ ];
			if ( preg_match( '/^\{\{\{\s*([\w.@]+)\s*\}\}\}$/', $token, $m ) ) {
				$target[] = array(
					'type' => 'raw',
					'path' => $m[1],
				);
			} elseif ( preg_match( '/^\{\{#(if|unless|each)\s+([\w.@]+)\s*\}\}$/', $token, $m ) && $depth < self::MAX_DEPTH ) {
				$block    = self::parse( $tokens, $pos, $m[1], $depth + 1 );
				$target[] = array(
					'type'     => $m[1],
					'path'     => $m[2],
					'children' => $block['children'],
					'else'     => $block['else'],
				);
			} elseif ( '{{else}}' === preg_replace( '/\s+/', '', $token ) && '' !== $close ) {
				$target = &$else;
			} elseif ( preg_match( '/^\{\{\/(if|unless|each)\s*\}\}$/', $token, $m ) ) {
				if ( $m[1] === $close ) {
					break;
				}
			} elseif ( preg_match( '/^\{\{\s*(.+?)\s*\}\}$/s', $token, $m ) ) {
				$target[] = array(
					'type'       => 'expr',
					'expression' => $m[1],
				);
			} else {
				$target[] = array(
					'type' => 'text',
					'text' => $token,
				);
			}
		}
		unset( $target );
		return array(
			'children' => $children,
			'else'     => $else,
		);
	}

	/**
	 * Renders nodes.
	 *
	 * @param array<int, array<string, mixed>> $nodes   Nodes.
	 * @param array<string, mixed>             $context Context.
	 * @param bool                             $html    HTML mode.
	 * @param int                              $depth   Depth.
	 * @return string
	 */
	private static function render_nodes( array $nodes, array $context, bool $html, int $depth ): string {
		$out = '';
		foreach ( $nodes as $node ) {
			switch ( $node['type'] ) {
				case 'text':
					$out .= $node['text'];
					break;
				case 'raw':
					$out .= self::scalar( self::lookup( $context, $node['path'] ) );
					break;
				case 'expr':
					$value = self::expression( $node['expression'], $context );
					$out  .= $html ? esc_html( $value ) : $value;
					break;
				case 'if':
				case 'unless':
					$pass = self::truthy( self::lookup( $context, $node['path'] ) );
					$pass = 'if' === $node['type'] ? $pass : ! $pass;
					$out .= self::render_nodes( $pass ? $node['children'] : $node['else'], $context, $html, $depth + 1 );
					break;
				case 'each':
					$items = self::lookup( $context, $node['path'] );
					if ( ! is_array( $items ) || ! $items ) {
						$out .= self::render_nodes( $node['else'], $context, $html, $depth + 1 );
						break;
					}
					$i = 0;
					foreach ( $items as $item ) {
						$item_context           = is_array( $item ) ? $item + $context : array( 'this' => $item ) + $context;
						$item_context['@index'] = $i++;
						$out                   .= self::render_nodes( $node['children'], $item_context, $html, $depth + 1 );
					}
					break;
			}
		}
		return $out;
	}

	/**
	 * Evaluates a variable, fallback or ternary expression.
	 *
	 * @param string               $expression Expression.
	 * @param array<string, mixed> $context    Context.
	 * @return string
	 */
	private static function expression( string $expression, array $context ): string {
		$ternary = '/^([\w.@]+)\s*(?:(==|!=|>|<)\s*(?:\'([^\']*)\'|([\d.]+)))?\s*\?\s*\'([^\']*)\'\s*:\s*\'([^\']*)\'$/';
		if ( preg_match( $ternary, $expression, $m ) ) {
			$value = self::lookup( $context, $m[1] );
			if ( '' === $m[2] ) {
				$pass = self::truthy( $value );
			} else {
				$compare = '' !== $m[3] ? $m[3] : $m[4];
				switch ( $m[2] ) {
					case '==':
						$pass = self::scalar( $value ) === $compare;
						break;
					case '!=':
						$pass = self::scalar( $value ) !== $compare;
						break;
					case '>':
						$pass = (float) self::scalar( $value ) > (float) $compare;
						break;
					default:
						$pass = (float) self::scalar( $value ) < (float) $compare;
				}
			}
			return $pass ? $m[5] : $m[6];
		}
		$parts    = explode( '|', $expression, 2 );
		$value    = self::scalar( self::lookup( $context, trim( $parts[0] ) ) );
		$fallback = isset( $parts[1] ) ? trim( trim( $parts[1] ), "'\"" ) : '';
		return '' !== $value ? $value : $fallback;
	}

	/**
	 * Dot-path lookup.
	 *
	 * @param array<string, mixed> $context Context.
	 * @param string               $path    Path.
	 * @return mixed
	 */
	public static function lookup( array $context, string $path ) {
		$value = $context;
		foreach ( explode( '.', $path ) as $key ) {
			if ( is_array( $value ) && array_key_exists( $key, $value ) ) {
				$value = $value[ $key ];
			} else {
				return null;
			}
		}
		return $value;
	}

	/**
	 * Truthiness like Handlebars.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function truthy( $value ): bool {
		if ( is_array( $value ) ) {
			return (bool) $value;
		}
		return ! ( null === $value || false === $value || '' === $value || 0 === $value || '0' === $value );
	}

	/**
	 * Converts a value to printable text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Variable names used in a template (for the picker and validation).
	 *
	 * @param string $template Template.
	 * @return string[]
	 */
	public static function variables( string $template ): array {
		preg_match_all( '/\{\{\{?\s*(?:#(?:if|unless|each)\s+)?([a-z_@][\w.]*)/i', $template, $m );
		return array_values( array_unique( array_filter( $m[1], static fn( string $v ): bool => ! in_array( $v, array( 'else', 'this' ), true ) ) ) );
	}
}
