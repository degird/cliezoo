<?php
/**
 * Images for the workspace's look: logos, an invoice's header.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Settings;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Platform\Permissions\Actor;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site's media library, for the pictures a client is shown.
 *
 * A logo on an invoice is read by the client on its payment page, so it has
 * to be an address anybody can load — which the workspace's own files are
 * not, by design. The media library's are, and it is where a WordPress site
 * keeps its pictures already. Pictures only: no SVG, which is a document
 * that can carry script, and nothing over the size a logo ever needs.
 */
final class Images {

	/** The most one upload may weigh, in bytes. */
	public const MAX_BYTES = 2 * 1024 * 1024;

	/** What an upload may be. */
	public const TYPES = array(
		'image/png'  => 'png',
		'image/jpeg' => 'jpg',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	);

	/**
	 * Whether somebody may choose the workspace's pictures.
	 *
	 * @param Actor $actor Who is asking.
	 * @return bool
	 */
	public static function may( Actor $actor ): bool {
		return $actor->is_staff()
			&& ( SettingsActions::can_edit( $actor, 'invoices' ) || SettingsActions::can_edit( $actor, 'branding' ) )
			&& user_can( $actor->user_id, 'upload_files' );
	}

	/**
	 * The latest pictures in the library.
	 *
	 * @return array{images: list<array<string, mixed>>}
	 */
	public function recent(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => implode( ',', array_keys( self::TYPES ) ),
				'post_status'    => 'inherit',
				'posts_per_page' => 60,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			$url = (string) wp_get_attachment_url( (int) $post->ID );
			if ( '' === $url ) {
				continue;
			}
			$thumb = wp_get_attachment_image_src( (int) $post->ID, 'thumbnail' );
			$out[] = array(
				'id'    => (int) $post->ID,
				'url'   => $url,
				'thumb' => is_array( $thumb ) ? (string) $thumb[0] : $url,
				'title' => (string) get_the_title( $post ),
			);
		}
		return array( 'images' => $out );
	}

	/**
	 * Adds one picture to the library.
	 *
	 * @param string $name What the file was called.
	 * @param string $data The picture, as a data: address.
	 * @return array{id: int, url: string}
	 * @throws ActionError When it is not a picture, or too big.
	 */
	public function upload( string $name, string $data ): array {
		if ( ! preg_match( '#^data:(image/[a-z]+);base64,(.+)$#s', $data, $parts ) ) {
			throw ActionError::validation( __( 'That is not a picture.', 'cliezoo' ) );
		}
		$bytes = base64_decode( $parts[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The picture itself, sent as a data address.
		if ( false === $bytes || '' === $bytes ) {
			throw ActionError::validation( __( 'That is not a picture.', 'cliezoo' ) );
		}
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			throw ActionError::validation( __( 'A picture for this is at most 2 MB. Make it smaller and try again.', 'cliezoo' ) );
		}
		// What the bytes are, not what the browser said they were.
		$info = getimagesizefromstring( $bytes );
		$mime = is_array( $info ) ? (string) $info['mime'] : '';
		if ( ! isset( self::TYPES[ $mime ] ) ) {
			throw ActionError::validation( __( 'A PNG, JPEG, GIF or WebP picture, please.', 'cliezoo' ) );
		}

		$base = sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) );
		$file = ( '' !== $base ? $base : 'image' ) . '.' . self::TYPES[ $mime ];
		$made = wp_upload_bits( $file, null, $bytes );
		if ( ! empty( $made['error'] ) ) {
			throw new ActionError( 'cliezoo_upload_failed', (string) $made['error'], 500 );
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => '' !== $base ? $base : __( 'Image', 'cliezoo' ),
				'post_status'    => 'inherit',
			),
			(string) $made['file']
		);
		if ( ! is_int( $id ) || $id <= 0 ) {
			wp_delete_file( (string) $made['file'] );
			throw new ActionError( 'cliezoo_upload_failed', __( 'The picture could not be kept.', 'cliezoo' ), 500 );
		}
		// An admin file, which a REST request does not load by itself.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$meta = function_exists( 'wp_generate_attachment_metadata' ) ? wp_generate_attachment_metadata( $id, (string) $made['file'] ) : array();
		wp_update_attachment_metadata( $id, $meta );

		return array(
			'id'  => $id,
			'url' => (string) wp_get_attachment_url( $id ),
		);
	}
}
