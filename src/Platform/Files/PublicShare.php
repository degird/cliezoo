<?php
/**
 * What somebody with no account sees at /cz/s/{token}.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Files;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\RateLimiter;
use Cliezoo\Core\Request;
use Cliezoo\Core\Rest\RouteRegistry;
use Cliezoo\Infrastructure\Storage\Downloads;
use Cliezoo\Infrastructure\Storage\Uploads;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;
use Cliezoo\Platform\Records\PublicLinks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * A public link is only worth making if somebody can open it, so this is the
 * other half of `PublicLinks`: the one endpoint the public bundle calls, and
 * the rules about what a token is allowed to reach.
 *
 * The token stands in for the person who made the link, and never for more
 * than them: everything here is filtered through their own Policy, because a
 * folder you may share can contain one that is private to people you are not
 * among, and a link must not be a way round that. If they have since left,
 * the link stops working rather than quietly showing less.
 *
 * The rest of the work is at the edges: nothing outside the shared branch is
 * reachable, nothing trashed or archived or half-uploaded is listed, no
 * internal id leaves, and a link with downloads turned off is given no
 * addresses at all rather than addresses it politely asks you not to use.
 */
final class PublicShare {

	/**
	 * How long an address handed to a public visitor lasts. Short, because
	 * an address that outlives the link it came from is a link that cannot
	 * really be taken back.
	 */
	private const TTL = 300;

	/**
	 * How many folders and how many files one page lists.
	 */
	private const PAGE = 200;

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
	 * `POST /public/share` — open a shared file or folder by its token.
	 *
	 * @param RouteRegistry $routes Routes.
	 * @return void
	 */
	public function routes( RouteRegistry $routes ): void {
		$routes->add(
			'/public/share',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // The link token is the credential; resolve() rate-limits.
				'callback'            => function ( \WP_REST_Request $request ) {
					try {
						return new \WP_REST_Response( array( 'data' => $this->open( $request ) ), 200 );
					} catch ( ActionError $error ) {
						return $error->to_wp_error();
					}
				},
			)
		);
	}

	/**
	 * Opens a token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 * @throws ActionError When the link is gone, or is not a file or folder.
	 */
	public function open( \WP_REST_Request $request ): array {
		$in       = (array) $request->get_json_params() + (array) $request->get_body_params();
		$token    = (string) ( $in['token'] ?? '' );
		$passcode = (string) ( $in['passcode'] ?? '' );
		$inside   = (string) ( $in['folder'] ?? '' );
		$email    = sanitize_email( (string) ( $in['email'] ?? '' ) );

		$links = $this->plugin->get( PublicLinks::class );
		$link  = $links->resolve( $token, $passcode, 'share' );
		if ( ! empty( $link['needs_passcode'] ) ) {
			return array( 'needs_passcode' => true );
		}
		if ( ! empty( $link['require_email'] ) && ! is_email( $email ) ) {
			return array( 'needs_email' => true );
		}
		if ( ! empty( $link['require_email'] ) ) {
			do_action(
				'cliezoo_audit',
				'public_link_email_given',
				array(
					'object_type' => (string) $link['object_type'],
					'link'        => (string) $link['link'],
					'email'       => $email,
				)
			);
		}

		// Whose eyes this link sees with. A link whose maker has gone is a
		// link with nobody behind it.
		$sharer = $links->sharer( (string) $link['link'] );
		if ( $sharer->is_guest() ) {
			throw ActionError::not_found();
		}

		$download = ! empty( $link['allow_download'] );
		if ( 'files' === $link['object_type'] ) {
			return $this->one_file( $sharer, (int) $link['object_id'], $download, (string) $link['link'] ) + array( 'can_download' => $download );
		}
		if ( 'folders' === $link['object_type'] ) {
			return $this->one_folder( $sharer, (int) $link['object_id'], $inside, $download, (string) $link['link'] ) + array( 'can_download' => $download );
		}
		/**
		 * What another module shows behind a `share` link of its own.
		 *
		 * The token has already been resolved, its passcode checked and its
		 * maker found, so a module answering this only has to decide what to
		 * show — and it is handed the maker's Actor, because a link must
		 * never show more than the person who made it can see.
		 *
		 * @param array<string, mixed>|null $page   What to show, or null.
		 * @param array<string, mixed>      $link   The resolved link.
		 * @param Actor                     $sharer Who made it.
		 */
		$page = apply_filters( 'cliezoo_public_share', null, $link, $sharer );
		if ( is_array( $page ) ) {
			return $page + array( 'can_download' => $download );
		}
		throw ActionError::not_found();
	}

	/**
	 * A single shared file.
	 *
	 * @param Actor  $sharer   Who made the link.
	 * @param int    $id       File id.
	 * @param bool   $download Whether the link allows downloading.
	 * @param string $link    Link uid, which the address is signed against.
	 * @return array<string, mixed>
	 * @throws ActionError When the file has gone, or the sharer may not see it.
	 */
	private function one_file( Actor $sharer, int $id, bool $download, string $link ): array {
		$row = $this->plugin->service( 'files' )->repository()->find( $id );
		if ( ! $row || ! $this->alive( $row ) || ! $this->plugin->policy()->can( $sharer, Policy::VIEW, $this->plugin->entities()->get( 'files' ), $row ) ) {
			throw ActionError::not_found();
		}
		return array(
			'kind' => 'file',
			'name' => (string) $row['name'],
			'file' => $this->file( $sharer, $row, $download, $link ),
		);
	}

	/**
	 * A shared folder, or one folder inside the branch it shared.
	 *
	 * @param Actor  $sharer   Who made the link.
	 * @param int    $id       The shared folder's id.
	 * @param string $inside   Uid of a folder to look inside ('' for the shared one).
	 * @param bool   $download Whether the link allows downloading.
	 * @param string $link     Link uid, which signs the zip address.
	 * @return array<string, mixed>
	 * @throws ActionError When the folder has gone, or is not in the branch.
	 */
	private function one_folder( Actor $sharer, int $id, string $inside, bool $download, string $link ): array {
		$repo   = $this->plugin->service( 'folders' )->repository();
		$entity = $this->plugin->entities()->get( 'folders' );
		$policy = $this->plugin->policy();
		$root   = $repo->find( $id );
		if ( ! $root || ! empty( $root['deleted_at'] ) || ! $policy->can( $sharer, Policy::VIEW, $entity, $root ) ) {
			throw ActionError::not_found();
		}

		$folder = $root;
		$trail  = array();
		if ( '' !== $inside ) {
			$wanted = $repo->find_by_uid( $inside );
			if ( ! $wanted
				|| ! empty( $wanted['deleted_at'] )
				|| ! $this->plugin->get( Folders::class )->is_inside( (int) $root['id'], $wanted )
				|| ! $policy->can( $sharer, Policy::VIEW, $entity, $wanted )
			) {
				throw ActionError::not_found();
			}
			$folder = $wanted;
			$trail  = $this->trail_to( $root, $wanted );
		}

		$folders = array();
		$more    = false;
		foreach ( $repo->where( array( 'parent_id' => (int) $folder['id'] ), 'name', self::PAGE + 1 ) as $child ) {
			if ( ! empty( $child['archived_at'] ) || ! $policy->can( $sharer, Policy::VIEW, $entity, $child ) ) {
				continue;
			}
			if ( count( $folders ) >= self::PAGE ) {
				$more = true;
				break;
			}
			$folders[] = array(
				'uid'  => (string) $child['uid'],
				'name' => (string) $child['name'],
			);
		}

		$files = array();
		$size  = 0;
		foreach ( $this->plugin->get( Files::class )->branch_files( (int) $folder['id'], false, $sharer ) as $row ) {
			$size += (int) $row['size'];
			if ( count( $files ) >= self::PAGE ) {
				$more = true;
				continue;
			}
			$files[] = $this->file( $sharer, $row, $download, $link );
		}

		return array(
			'kind'    => 'folder',
			'name'    => (string) $folder['name'],
			'shared'  => (string) $root['name'],
			'trail'   => $trail,
			'folders' => $folders,
			'files'   => $files,
			'more'    => $more,
			'zip'     => $download && Zip::available() && $files
				? $this->plugin->get( Zip::class )->public_link( (string) $folder['uid'], $link, self::TTL )
				: null,
			'size'    => $size,
		);
	}

	/**
	 * One file, as a visitor should see it: a name, a size, and an address
	 * only when the sender allowed one and may give one.
	 *
	 * @param Actor                $sharer   Who made the link.
	 * @param array<string, mixed> $row      File row.
	 * @param bool                 $download Whether the link allows downloading.
	 * @param string               $link     Link uid, which the address is signed against.
	 * @return array<string, mixed>
	 */
	private function file( Actor $sharer, array $row, bool $download, string $link ): array {
		// Downloading is its own right: a file the sharer may only look at
		// is listed without an address.
		$may = $download && $this->plugin->get( Uploads::class )->can_download( $sharer, $row );
		return array(
			'uid'      => (string) $row['uid'],
			'name'     => (string) $row['name'],
			'size'     => (int) $row['size'],
			'mime'     => (string) $row['mime'],
			'is_image' => Downloads::is_image( (string) $row['mime'] ),
			'url'      => $may ? Downloads::shared_url( (string) $row['uid'], $link, self::TTL ) : null,
		);
	}

	/**
	 * The way back up, but only as far as the folder that was shared: a
	 * visitor is not told the names of folders above it.
	 *
	 * @param array<string, mixed> $root   The shared folder.
	 * @param array<string, mixed> $folder Where the visitor is.
	 * @return array<int, array<string, string>>
	 */
	private function trail_to( array $root, array $folder ): array {
		$repo  = $this->plugin->service( 'folders' )->repository();
		$trail = array();
		$at    = $folder;
		for ( $step = 0; $step < Folders::MAX_DEPTH; $step++ ) {
			if ( (int) $at['id'] === (int) $root['id'] || empty( $at['parent_id'] ) ) {
				break;
			}
			$parent = $repo->find( (int) $at['parent_id'] );
			if ( ! $parent ) {
				break;
			}
			array_unshift(
				$trail,
				array(
					'uid'  => (string) $parent['uid'],
					'name' => (string) $parent['name'],
				)
			);
			$at = $parent;
		}
		return $trail;
	}

	/**
	 * Serves one file of a share: `/cz/sf/{uid}?e=…&s=…&l=…`.
	 *
	 * The signature is a receipt for having opened the link — passcode and
	 * all — a few minutes ago, not a second credential. So everything is
	 * checked again here, because between the page and the click the link
	 * may have been taken back, the file may have moved out of the shared
	 * branch, or the person who shared it may have lost the right to.
	 *
	 * @param string $uid File uid.
	 * @return void
	 */
	public function serve_shared( string $uid ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A signed URL is the credential.
		$expires = isset( $_GET['e'] ) ? (int) $_GET['e'] : 0;
		$sig     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$link    = isset( $_GET['l'] ) ? sanitize_text_field( wp_unslash( $_GET['l'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $link || ! Downloads::verify_shared( $uid, $link, $expires, $sig ) ) {
			status_header( 403 );
			exit;
		}
		if ( RateLimiter::hit( 'share-file:' . Request::ip(), 300, 300 ) ) {
			status_header( 429 );
			exit;
		}

		$row = $this->plugin->service( 'files' )->repository()->find_by_uid( $uid );
		$now = $this->live_link( $link );
		if ( ! $row || ! $now || ! $this->alive( $row ) || empty( $now['allow_download'] ) ) {
			status_header( 404 );
			exit;
		}

		$sharer = $this->plugin->get( PublicLinks::class )->sharer( $link );
		if ( $sharer->is_guest() || ! $this->plugin->get( Uploads::class )->can_download( $sharer, $row ) ) {
			status_header( 404 );
			exit;
		}
		if ( ! $this->reaches( $now, $row ) ) {
			status_header( 404 );
			exit;
		}

		do_action(
			'cliezoo_audit',
			'public_link_downloaded',
			array(
				'object_type' => 'files',
				'object_id'   => (int) $row['id'],
				'link'        => $link,
				'ip'          => Request::ip(),
			)
		);
		Downloads::output(
			array(
				'driver' => (string) $row['storage_driver'],
				'key'    => (string) $row['object_key'],
				'mime'   => (string) $row['mime'],
				'name'   => (string) $row['name'],
				'inline' => false,
				'cache'  => 'private, no-store',
			)
		);
		exit;
	}

	/**
	 * A share link that is still worth honouring, or null.
	 *
	 * @param string $uid Link uid.
	 * @return array<string, mixed>|null
	 */
	public function live_link( string $uid ): ?array {
		$row = $this->plugin->service( 'public_links' )->repository()->find_by_uid( $uid );
		if ( ! $row
			|| null !== $row['revoked_at']
			|| 'share' !== (string) $row['purpose']
			|| ( ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] . ' UTC' ) < time() )
		) {
			return null;
		}
		return $row;
	}

	/**
	 * Whether a link reaches a file: it is that file, or it is in the branch
	 * of that folder.
	 *
	 * @param array<string, mixed> $link Link row.
	 * @param array<string, mixed> $file File row.
	 * @return bool
	 */
	public function reaches( array $link, array $file ): bool {
		if ( 'files' === (string) $link['object_type'] ) {
			return (int) $link['object_id'] === (int) $file['id'];
		}
		if ( 'folders' !== (string) $link['object_type'] || empty( $file['folder_id'] ) ) {
			return false;
		}
		$folder = $this->plugin->service( 'folders' )->repository()->find( (int) $file['folder_id'] );
		return $folder && $this->plugin->get( Folders::class )->is_inside( (int) $link['object_id'], $folder );
	}

	/**
	 * Whether a file row is something a visitor may be shown at all.
	 *
	 * @param array<string, mixed> $row File row.
	 * @return bool
	 */
	private function alive( array $row ): bool {
		return empty( $row['deleted_at'] )
			&& empty( $row['archived_at'] )
			&& 'ready' === (string) $row['status']
			&& 'upload' === (string) $row['kind'];
	}
}
