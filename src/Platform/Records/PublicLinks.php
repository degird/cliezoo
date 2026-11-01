<?php
/**
 * Public links: unguessable, expiring, revocable links to one record.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Records;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\App\AppRouter;
use Cliezoo\Core\Data\Serializer;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\RateLimiter;
use Cliezoo\Core\Request;
use Cliezoo\Infrastructure\Crypto\Vault;
use Cliezoo\Platform\Members\Members;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * Links for documents, files, invoices, forms and bookings (cliezoo.md §6.41):
 * 256-bit tokens stored only as HMAC hashes, optional expiry and passcode,
 * revoke and regenerate, view counts and an access log. Creating a link
 * needs "share" permission on the record. Resolving is rate-limited per IP
 * and per link; passcode failures lock the link for a while.
 */
final class PublicLinks {

	/**
	 * URL prefix per purpose (below the public base, e.g. /cz/s/{token}).
	 */
	private const PREFIX = array(
		'share'    => 's',
		'document' => 'd',
		'invoice'  => 'pay',
		'form'     => 'f',
		'booking'  => 'book',
		'answers'  => 'q',
		// One link per signer, because the link is what the evidence rests on:
		// "this address was sent this link, and this link was used to sign".
		'sign'     => 'sign',
		// A calendar feed. Its address goes into somebody's phone and is out
		// of anybody's hands afterwards, so it is read-only and revocable
		// like every other link here.
		'calendar' => 'ics',
	);

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The parent record and entity, checked for a permission.
	 *
	 * @param Actor  $actor  Actor.
	 * @param string $type   Entity name.
	 * @param string $uid    Record uid.
	 * @param string $action Policy action.
	 * @return array{0: \Cliezoo\Core\Data\Entity, 1: array<string, mixed>}
	 * @throws ActionError When missing or not allowed.
	 */
	private function parent( Actor $actor, string $type, string $uid, string $action ): array {
		if ( ! $this->plugin->entities()->has( $type ) ) {
			throw ActionError::not_found();
		}
		$entity = $this->plugin->entities()->get( $type );
		$row    = $entity->public ? $this->plugin->service( $type )->repository()->find_by_uid( $uid ) : null;
		if ( ! $row || ! $this->plugin->policy()->can( $actor, Policy::VIEW, $entity, $row ) ) {
			throw ActionError::not_found();
		}
		if ( Policy::VIEW !== $action && ! $this->plugin->policy()->can( $actor, $action, $entity, $row ) ) {
			throw ActionError::forbidden();
		}
		return array( $entity, $row );
	}

	/**
	 * A short address of the studio's choosing — /cz/f/work-with-us —
	 * checked, or null for none.
	 *
	 * Opt-in, and only for the pages meant for anybody (a form, a booking
	 * page): such a page is found by its name the way a website is, and the
	 * same robot checks and limits stand behind it as behind its link. At
	 * most forty characters, so it can never be read as a link's token,
	 * which is always longer.
	 *
	 * @param Plugin                    $plugin   The plugin.
	 * @param string                    $entity   forms or meeting_types.
	 * @param mixed                     $given    What was asked for.
	 * @param array<string, mixed>|null $existing The record as it stands.
	 * @return string|null
	 * @throws ActionError When it is not a usable address, or is taken.
	 */
	public static function clean_slug( Plugin $plugin, string $entity, $given, ?array $existing ): ?string {
		$slug = strtolower( trim( (string) $given ) );
		if ( '' === $slug ) {
			return null;
		}
		if ( strlen( $slug ) < 3 || strlen( $slug ) > 40 || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			throw ActionError::validation( __( 'A short address is three to forty letters, numbers and single dashes, such as work-with-us.', 'cliezoo' ) );
		}
		global $wpdb;
		$table = esc_sql( $plugin->entities()->get( $entity )->table() );
		// Deleted ones count too: their address stays theirs until they are
		// gone for good, so a restored page does not lose it.
		$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d LIMIT 1", $slug, (int) ( $existing['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from code.
		if ( $taken > 0 ) {
			throw ActionError::validation( __( 'That short address is already in use.', 'cliezoo' ) );
		}
		return $slug;
	}

	/**
	 * The token behind a short address, or '' when there is none.
	 *
	 * @param string $entity  forms or meeting_types.
	 * @param string $slug    The short address.
	 * @param string $purpose The link's purpose.
	 * @return string
	 */
	public function token_for_slug( string $entity, string $slug, string $purpose ): string {
		if ( 1 !== preg_match( '/^[a-z0-9-]{3,40}$/', $slug ) ) {
			return '';
		}
		$row = $this->plugin->service( $entity )->repository()->first( array( 'slug' => $slug ) );
		if ( ! is_array( $row ) || ! empty( $row['deleted_at'] ) ) {
			return '';
		}
		return $this->published_token( $entity, (string) $row['uid'], $purpose );
	}

	/**
	 * A link's address, or the record's short address in its place when it
	 * has one: the same page, under the same prefix, by its name.
	 *
	 * @param string               $url The link's address.
	 * @param array<string, mixed> $row The form or booking page.
	 * @return string
	 */
	public static function shortened( string $url, array $row ): string {
		$slug = (string) ( $row['slug'] ?? '' );
		if ( '' === $slug || '' === $url ) {
			return $url;
		}
		return (string) preg_replace( '#/[A-Za-z0-9_-]{20,100}/?$#', '/' . $slug, $url );
	}

	/**
	 * Public URL for a token.
	 *
	 * @param string $purpose Purpose.
	 * @param string $token   Token.
	 * @return string
	 */
	public static function url( string $purpose, string $token ): string {
		/**
		 * URL prefixes per public-link purpose (modules add theirs).
		 *
		 * @param array<string, string> $prefixes Purpose => path segment.
		 */
		$prefixes = (array) apply_filters( 'cliezoo_public_link_prefixes', self::PREFIX );
		return AppRouter::public_url( ( $prefixes[ $purpose ] ?? 's' ) . '/' . $token );
	}

	/**
	 * Creates a link. The token is returned once and never stored in plain text.
	 *
	 * @param Actor                $actor Actor.
	 * @param array<string, mixed> $in    object_type, uid, purpose, expires_at, passcode, allow_download, require_email.
	 * @return array<string, mixed>
	 */
	public function create( Actor $actor, array $in ): array {
		list( $entity, $row ) = $this->parent( $actor, (string) $in['object_type'], (string) $in['uid'], Policy::SHARE );
		$token                = $this->plugin->get( Vault::class )->token( 32 );
		$passcode             = (string) ( $in['passcode'] ?? '' );
		$link                 = $this->plugin->service( 'public_links' )->repository()->insert(
			array(
				'object_type'    => $entity->name,
				'object_id'      => (int) $row['id'],
				'token_hash'     => $token['hash'],
				'purpose'        => (string) ( $in['purpose'] ?? 'share' ),
				'passcode_hash'  => '' !== $passcode ? wp_hash_password( $passcode ) : null,
				'expires_at'     => ! empty( $in['expires_at'] ) ? Serializer::to_mysql( (string) $in['expires_at'] ) : null,
				'allow_download' => array_key_exists( 'allow_download', $in ) ? (int) (bool) $in['allow_download'] : 1,
				'require_email'  => (int) ! empty( $in['require_email'] ),
			),
			$actor->member_id ? $actor->member_id : null
		);
		$this->plugin->service( 'public_links' )->repository()->update(
			(int) $link['id'],
			array( 'token_cipher' => $this->keep( (int) $link['id'], $token['token'] ) )
		);
		do_action(
			'cliezoo_audit',
			'public_link_created',
			array(
				'object_type' => $entity->name,
				'object_id'   => (int) $row['id'],
				'purpose'     => $link['purpose'],
			)
		);
		return $this->present( $link ) + array(
			'token' => $token['token'],
			'url'   => self::url( (string) $link['purpose'], $token['token'] ),
		);
	}

	/**
	 * Whose authority a link carries: the person who made it.
	 *
	 * A public link is not a way to show somebody more than you can see
	 * yourself. Sharing a folder checks that you may share *that folder*,
	 * and a folder below it can be private to people you are not among — so
	 * whatever the link shows is filtered against its maker, exactly as the
	 * app filters it for them.
	 *
	 * @param string $uid Link uid.
	 * @return Actor The maker, or a guest when they are gone or suspended.
	 */
	public function sharer( string $uid ): Actor {
		$link = $this->plugin->service( 'public_links' )->repository()->find_by_uid( $uid );
		if ( ! $link || empty( $link['created_by'] ) ) {
			return Actor::guest();
		}
		$member = $this->plugin->service( 'members' )->repository()->find( (int) $link['created_by'] );
		if ( ! $member || empty( $member['user_id'] ) ) {
			return Actor::guest();
		}
		return $this->plugin->get( Members::class )->actor_for( (int) $member['user_id'] );
	}

	/**
	 * Links of a record (without tokens).
	 *
	 * @param Actor  $actor Actor.
	 * @param string $type  Entity name.
	 * @param string $uid   Record uid.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_record( Actor $actor, string $type, string $uid ): array {
		list( $entity, $row ) = $this->parent( $actor, $type, $uid, Policy::VIEW );
		$rows                 = $this->plugin->service( 'public_links' )->repository()->where(
			array(
				'object_type' => $entity->name,
				'object_id'   => (int) $row['id'],
			),
			'-id'
		);
		// The address itself only to somebody who may share the record.
		// Seeing that a link exists is not the same as holding it.
		$may = $this->plugin->policy()->can( $actor, Policy::SHARE, $entity, $row );
		return array_map(
			function ( array $link ) use ( $may ): array {
				$out = $this->present( $link );
				$url = $may ? $this->address( $link ) : '';
				if ( '' !== $url ) {
					$out['url'] = $url;
				}
				return $out;
			},
			$rows
		);
	}

	/**
	 * The token of a record's own published link, for the server's own use.
	 *
	 * This exists so a block or a shortcode can carry a record's uid rather
	 * than a token. A token printed into a page is a token in every cache and
	 * every copy of that page's HTML, and revoking the link would not take it
	 * back; the uid is no secret — it is already in the page — and everything
	 * that makes the link a credential stays where it is, because whatever
	 * embeds a record hands this back to `resolve()` immediately and nothing
	 * else. The rate limiting, the expiry, the revocation, the view count and
	 * the access log are all still the link's.
	 *
	 * It refuses a link with a passcode, because a passcode on something
	 * embedded in a public page protects nobody: whoever put it there either
	 * meant it to be answered by anybody, in which case the passcode is in the
	 * way, or did not, in which case the page is the mistake.
	 *
	 * No actor: an embed is read by whoever is reading the page. What decides
	 * whether it renders is that somebody on the team published the record —
	 * this link is what publishing means — not who is looking.
	 *
	 * @param string $type    Entity name.
	 * @param string $uid     Record uid.
	 * @param string $purpose Link purpose.
	 * @return string The token, or '' when there is no link to embed.
	 */
	public function published_token( string $type, string $uid, string $purpose ): string {
		if ( ! $this->plugin->entities()->has( $type ) ) {
			return '';
		}
		$row = $this->plugin->service( $type )->repository()->first( array( 'uid' => $uid ) );
		if ( ! is_array( $row ) || ! empty( $row['deleted_at'] ) ) {
			return '';
		}
		$links = $this->plugin->service( 'public_links' )->repository()->where(
			array(
				'object_type' => $type,
				'object_id'   => (int) $row['id'],
				'purpose'     => $purpose,
			),
			'-id'
		);
		foreach ( $links as $link ) {
			if ( ! empty( $link['passcode_hash'] ) ) {
				continue;
			}
			if ( null !== ( $link['revoked_at'] ?? null ) ) {
				continue;
			}
			// Read the way address() reads it — the token itself rather than
			// the address it sits in, because an address can be written more
			// than one way and the token cannot.
			$token = $this->plugin->get( Vault::class )->decrypt(
				isset( $link['token_cipher'] ) ? (string) $link['token_cipher'] : null,
				'link:' . (int) $link['id'] . ':token'
			);
			if ( null === $token || '' === $token ) {
				continue;
			}
			return (string) $token;
		}
		return '';
	}

	/**
	 * Encrypts a token against the row that holds it.
	 *
	 * Bound to the row id, so a ciphertext moved to another link will not
	 * open — the same rule the gateway keys follow.
	 *
	 * @param int    $id    The link's row id.
	 * @param string $token The token in the clear.
	 * @return string
	 */
	private function keep( int $id, string $token ): string {
		return $this->plugin->get( Vault::class )->encrypt( $token, 'link:' . $id . ':token' );
	}

	/**
	 * The address of a link, or '' when it was made before this was kept.
	 *
	 * @param array<string, mixed> $link The link row.
	 * @return string
	 */
	public function address( array $link ): string {
		if ( null !== ( $link['revoked_at'] ?? null ) ) {
			return '';
		}
		$token = $this->plugin->get( Vault::class )->decrypt(
			isset( $link['token_cipher'] ) ? (string) $link['token_cipher'] : null,
			'link:' . (int) $link['id'] . ':token'
		);
		return null === $token || '' === $token
			? ''
			: self::url( (string) $link['purpose'], $token );
	}

	/**
	 * Revokes a link.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Link uid.
	 * @return array<string, mixed>
	 */
	public function revoke( Actor $actor, string $uid ): array {
		$link = $this->owned( $actor, $uid );
		$this->plugin->service( 'public_links' )->repository()->update( (int) $link['id'], array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		do_action(
			'cliezoo_audit',
			'public_link_revoked',
			array(
				'object_type' => $link['object_type'],
				'object_id'   => (int) $link['object_id'],
			)
		);
		return $this->present( (array) $this->plugin->service( 'public_links' )->repository()->find( (int) $link['id'] ) );
	}

	/**
	 * Issues a new token for a link (the old one stops working).
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Link uid.
	 * @return array<string, mixed>
	 */
	public function regenerate( Actor $actor, string $uid ): array {
		$link  = $this->owned( $actor, $uid );
		$token = $this->plugin->get( Vault::class )->token( 32 );
		$this->plugin->service( 'public_links' )->repository()->update(
			(int) $link['id'],
			array(
				'token_hash'   => $token['hash'],
				'token_cipher' => $this->keep( (int) $link['id'], $token['token'] ),
				'revoked_at'   => null,
			)
		);
		return $this->present( (array) $this->plugin->service( 'public_links' )->repository()->find( (int) $link['id'] ) ) + array(
			'token' => $token['token'],
			'url'   => self::url( (string) $link['purpose'], $token['token'] ),
		);
	}

	/**
	 * Resolves a token for a public page. Returns the link row with the parent
	 * reference, or asks for the passcode.
	 *
	 * @param string          $token    Token from the URL.
	 * @param string          $passcode Passcode (when the link has one).
	 * @param string|string[] $purpose  Expected purpose, or the purposes any
	 *                                  one of which will do ('' = any).
	 * @return array<string, mixed>
	 * @throws ActionError When invalid, expired, revoked or locked.
	 */
	public function resolve( string $token, string $passcode = '', string|array $purpose = '' ): array {
		$purposes = array_values( array_filter( (array) $purpose, static fn( $one ): bool => '' !== $one ) );
		$ip       = Request::ip();
		if ( RateLimiter::hit( 'link-ip:' . $ip, 60, 300 ) ) {
			throw ActionError::rate_limited( __( 'Too many requests. Please wait a few minutes.', 'cliezoo' ), 300 );
		}
		$gone = new ActionError( 'cliezoo_link_unavailable', __( 'This link is not available. It may have expired or been turned off.', 'cliezoo' ), 404 );
		if ( ! preg_match( '/^[A-Za-z0-9_\-]{20,100}$/', $token ) ) {
			throw $gone;
		}
		$repo = $this->plugin->service( 'public_links' )->repository();
		$link = $repo->first( array( 'token_hash' => $this->plugin->get( Vault::class )->hash_token( $token ) ) );
		if ( ! $link || null !== $link['revoked_at'] || ( $purposes && ! in_array( (string) $link['purpose'], $purposes, true ) ) ) {
			throw $gone;
		}
		if ( ! empty( $link['expires_at'] ) && strtotime( (string) $link['expires_at'] . ' UTC' ) < time() ) {
			throw $gone;
		}
		if ( ! empty( $link['passcode_hash'] ) ) {
			// Per link and per address: a lockout counted only per link lets
			// anyone the token was forwarded to shut out everybody else, for
			// as long as they care to keep guessing.
			$key  = 'link-pass:' . $link['id'] . ':' . $ip;
			$wide = 'link-pass-all:' . $link['id'];
			if ( RateLimiter::blocked( $key, 5 ) || RateLimiter::blocked( $wide, 50 ) ) {
				throw ActionError::rate_limited( __( 'Too many wrong passcodes. Please try again later.', 'cliezoo' ), RateLimiter::retry_after( $key ) );
			}
			if ( '' === $passcode ) {
				return array(
					'needs_passcode' => true,
					'purpose'        => (string) $link['purpose'],
				);
			}
			if ( ! wp_check_password( $passcode, (string) $link['passcode_hash'] ) ) {
				RateLimiter::hit( $key, 5, 900 );
				RateLimiter::hit( $wide, 50, 900 );
				throw ActionError::validation( __( 'That passcode is not correct.', 'cliezoo' ), array( 'passcode' => __( 'That passcode is not correct.', 'cliezoo' ) ) );
			}
			RateLimiter::clear( $key );
		}
		$repo->update(
			(int) $link['id'],
			array(
				'view_count'     => (int) $link['view_count'] + 1,
				'last_viewed_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		do_action(
			'cliezoo_audit',
			'public_link_viewed',
			array(
				'object_type' => $link['object_type'],
				'object_id'   => (int) $link['object_id'],
				'link'        => (string) $link['uid'],
				'ip'          => $ip,
			)
		);
		/**
		 * A public link was opened (modules render the page for their purpose).
		 *
		 * @param array $link Link row.
		 */
		// Without the hashes: a listener on an unauthenticated path has no
		// business holding the credential or the passcode.
		$told = $link;
		unset( $told['token_hash'], $told['passcode_hash'] );
		do_action( 'cliezoo_public_link_opened', $told );
		return array(
			'needs_passcode' => false,
			'purpose'        => (string) $link['purpose'],
			'object_type'    => (string) $link['object_type'],
			'object_id'      => (int) $link['object_id'],
			'allow_download' => (bool) $link['allow_download'],
			'require_email'  => (bool) $link['require_email'],
			'link'           => (string) $link['uid'],
		);
	}

	/**
	 * What a live link points at, without opening it.
	 *
	 * For the shell, which has to pick a page's language before the page asks
	 * for anything: no view is counted and nothing is audited, and a link with
	 * a passcode says nothing at all until the passcode has been given.
	 *
	 * @param string $token   Token from the URL.
	 * @param string $purpose The purpose it must have.
	 * @return array{object_type: string, object_id: int}|null
	 */
	public function peek( string $token, string $purpose ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9_\-]{20,100}$/', $token ) ) {
			return null;
		}
		$link = $this->plugin->service( 'public_links' )->repository()->first(
			array( 'token_hash' => $this->plugin->get( Vault::class )->hash_token( $token ) )
		);
		if ( ! $link || null !== $link['revoked_at'] || $purpose !== (string) $link['purpose'] || ! empty( $link['passcode_hash'] ) ) {
			return null;
		}
		if ( ! empty( $link['expires_at'] ) && strtotime( (string) $link['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}
		return array(
			'object_type' => (string) $link['object_type'],
			'object_id'   => (int) $link['object_id'],
		);
	}

	/**
	 * A link the actor may manage.
	 *
	 * @param Actor  $actor Actor.
	 * @param string $uid   Link uid.
	 * @return array<string, mixed>
	 * @throws ActionError When missing or not allowed.
	 */
	private function owned( Actor $actor, string $uid ): array {
		$link = $this->plugin->service( 'public_links' )->repository()->find_by_uid( $uid );
		if ( ! $link || ! $this->plugin->entities()->has( (string) $link['object_type'] ) ) {
			throw ActionError::not_found();
		}
		$entity = $this->plugin->entities()->get( (string) $link['object_type'] );
		$row    = $this->plugin->service( $entity->name )->repository()->find( (int) $link['object_id'] );
		if ( ! $row || ! $this->plugin->policy()->can( $actor, Policy::SHARE, $entity, $row ) ) {
			throw ActionError::not_found();
		}
		return $link;
	}

	/**
	 * Safe output (never the hashes).
	 *
	 * @param array<string, mixed> $link Row.
	 * @return array<string, mixed>
	 */
	public function present( array $link ): array {
		$expired = ! empty( $link['expires_at'] ) && strtotime( (string) $link['expires_at'] . ' UTC' ) < time();
		return array(
			'uid'            => (string) $link['uid'],
			'purpose'        => (string) $link['purpose'],
			'expires_at'     => Serializer::iso( $link['expires_at'] ?? null ),
			'has_passcode'   => ! empty( $link['passcode_hash'] ),
			'allow_download' => (bool) $link['allow_download'],
			'require_email'  => (bool) ( $link['require_email'] ?? false ),
			'view_count'     => (int) ( $link['view_count'] ?? 0 ),
			'last_viewed_at' => Serializer::iso( $link['last_viewed_at'] ?? null ),
			'status'         => null !== ( $link['revoked_at'] ?? null ) ? 'revoked' : ( $expired ? 'expired' : 'active' ),
			'created_at'     => Serializer::iso( $link['created_at'] ?? null ),
		);
	}
}
