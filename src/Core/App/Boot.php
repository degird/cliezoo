<?php
/**
 * Boot data for the app and public shells.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core\App;

use Cliezoo\Core\Plugin;
use Cliezoo\Infrastructure\Jobs\SafeMode;
use Cliezoo\Infrastructure\Storage\Downloads;
use Cliezoo\Platform\Members\Members;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;
use Cliezoo\Platform\Permissions\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the first-paint payload (current user, permission matrix, workspace
 * formats, branding tokens, active modules) so the
 * app needs no extra round-trip on load (Blueprint §11.2). The same payload
 * backs `GET /session`. Permissions here only shape the UI; the server
 * re-checks every call.
 */
final class Boot {

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
	 * The payload.
	 *
	 * @param string $context app|public.
	 * @param string $path    Current path inside the base.
	 * @return array<string, mixed>
	 */
	public function data( string $context = 'app', string $path = '' ): array {
		$actor = $this->plugin->actor();
		$bases = AppRouter::bases();
		$data  = array(
			'version'   => CLIEZOO_VERSION,
			'context'   => $context,
			'rest'      => array(
				'root'  => esc_url_raw( rest_url( 'cliezoo/v1/' ) ),
				'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			),
			'routes'    => array(
				'app'       => $bases['app'],
				'public'    => $bases['public'],
				'appUrl'    => AppRouter::app_url(),
				'publicUrl' => AppRouter::public_url(),
				'pretty'    => AppRouter::pretty(),
				// A token page gets the kind of page it is and not the rest:
				// the token is already in the address bar, and printing a
				// second copy of the credential into the markup adds a place
				// it can leak from and nothing else.
				'path'      => 'public' === $context ? (string) strtok( $path, '/' ) : $path,
				'adminUrl'  => $actor->is_admin() ? admin_url() : '',
			),
			'locale'    => determine_locale(),
			'security'  => array(
				'passwordMin' => (int) $this->plugin->settings()->get( 'security', 'password_min_length', 10 ),
				'magicLinks'  => (bool) $this->plugin->settings()->get( 'security', 'magic_links', true ),
			),
			'rtl'       => is_rtl(),
			'workspace' => $this->workspace(),
			'branding'  => $this->branding(),
			'user'      => $this->user( $actor ),
			'theme'     => $this->theme( $actor->user_id ),
			'prefs'     => $actor->is_guest() ? null : Preferences::get( $actor->user_id ),
			'modules'   => $actor->is_guest() ? array() : $this->modules( $actor ),
			'widgets'   => $actor->is_guest() ? array() : $this->widgets( $actor ),
			'safeMode'  => 'app' === $context && $actor->is_admin() ? $this->plugin->get( SafeMode::class )->status() : null,
		);
		/**
		 * Filters the app boot data. Never add secrets: this is printed into the page.
		 *
		 * @param array  $data    Boot data.
		 * @param Actor  $actor   Current actor.
		 * @param string $context app|public.
		 */
		return (array) apply_filters( 'cliezoo_boot_data', $data, $actor, $context );
	}

	/**
	 * Home cards this person may have. Modules declare them here; the app
	 * bundle supplies what each one looks like.
	 *
	 * @param Actor $actor Actor.
	 * @return array<int, array<string, mixed>>
	 */
	private function widgets( Actor $actor ): array {
		$registry = new WidgetRegistry();
		/**
		 * Declares dashboard widgets.
		 *
		 * @param WidgetRegistry $registry Registry.
		 * @param Actor          $actor    Actor.
		 */
		do_action( 'cliezoo_register_widgets', $registry, $actor );
		return $registry->for_actor( $actor );
	}

	/**
	 * The avatar file, if the person may actually see it.
	 *
	 * A signed URL bypasses every later permission check, so the file is
	 * re-checked against the policy here rather than trusted because its id
	 * sits on the member row.
	 *
	 * @param Actor $actor Actor.
	 * @param int   $id    File id (0 for none).
	 * @return array<string, mixed>|null
	 */
	private function avatar( Actor $actor, int $id ): ?array {
		if ( ! $id ) {
			return null;
		}
		$files = $this->plugin->entities()->get( 'files' );
		$file  = $this->plugin->service( 'files' )->repository()->find( $id );
		if ( ! $file || ! empty( $file['deleted_at'] ) || 'ready' !== $file['status'] ) {
			return null;
		}
		return $this->plugin->policy()->can( $actor, Policy::VIEW, $files, $file ) ? $file : null;
	}

	/**
	 * Current user (null for guests).
	 *
	 * @param Actor $actor Actor.
	 * @return array<string, mixed>|null
	 */
	/**
	 * The modules this person sees.
	 *
	 * @param Actor $actor Actor.
	 * @return array<int, string>
	 */
	private function modules( Actor $actor ): array {
		$modules = array_keys( $this->plugin->active_modules() );
		/**
		 * Narrows the modules one person sees (the portal, per client).
		 *
		 * @param array $modules Active module ids.
		 * @param Actor $actor   Actor.
		 */
		return array_values( (array) apply_filters( 'cliezoo_boot_modules', $modules, $actor ) );
	}

	/**
	 * The signed-in person, as the app needs them.
	 *
	 * @param Actor $actor Actor.
	 * @return array<string, mixed>|null
	 */
	private function user( Actor $actor ): ?array {
		if ( $actor->is_guest() ) {
			return null;
		}
		$member = $this->plugin->get( Members::class )->member_for( $actor->user_id );
		if ( ! $member ) {
			return null;
		}
		$labels = Roles::labels();
		$file   = $this->avatar( $actor, (int) ( $member['avatar_file_id'] ?? 0 ) );
		return array(
			'uid'          => (string) $member['uid'],
			'name'         => (string) $member['name'],
			'email'        => (string) $member['email'],
			'kind'         => (string) $member['kind'],
			'role'         => $actor->role,
			'roleLabel'    => (string) ( $labels[ $actor->role ] ?? $actor->role ),
			// No picture of their own means initials, drawn by the app. Free
			// never falls back to Gravatar: that would ask an outside
			// service about everybody who opens the app (Blueprint §6.4).
			'avatar'       => $file ? Downloads::signed_url( (string) $file['uid'], DAY_IN_SECONDS, 'avatar' ) : '',
			'color'        => (string) ( $member['color'] ?? '' ),
			'timezone'     => (string) ( $member['timezone'] ?? '' ),
			'locale'       => (string) ( $member['locale'] ?? '' ),
			'dateFormat'   => (string) ( $member['date_format'] ?? '' ),
			'timeFormat'   => (string) ( $member['time_format'] ?? '' ),
			'weekStart'    => isset( $member['week_start'] ) && '' !== (string) $member['week_start'] ? (int) $member['week_start'] : null,
			'owner'        => $actor->is_owner(),
			'admin'        => $actor->is_admin(),
			'client'       => $actor->is_client(),
			'permissions'  => $actor->permissions(),
		);
	}

	/**
	 * Workspace formats every screen needs.
	 *
	 * @return array<string, mixed>
	 */
	private function workspace(): array {
		$all  = $this->plugin->settings()->all( 'workspace' );
		$keep = array( 'name', 'country', 'language', 'timezone', 'currency', 'currency_symbol_position', 'decimal_separator', 'thousands_separator', 'digit_grouping', 'date_format', 'time_format', 'week_start', 'fiscal_year_start', 'working_days' );
		// The languages this site has, which are the ones anybody can pick.
		return array_intersect_key( $all, array_flip( $keep ) ) + array( 'languages' => array_values( array_unique( array_merge( array( 'en_US' ), get_available_languages() ) ) ) );
	}

	/**
	 * Branding tokens (the branding designer writes these; defaults are the Cliezoo brand).
	 *
	 * @return array<string, mixed>
	 */
	private function branding(): array {
		$values = in_array( 'branding', $this->plugin->settings()->modules(), true ) ? $this->plugin->settings()->all( 'branding' ) : array();
		return array_merge(
			array(
				'accent'  => '#0458FB',
				'logo'    => '',
				'logoAlt' => (string) $this->plugin->settings()->get( 'workspace', 'name', get_bloginfo( 'name' ) ),
				'font'    => 'inter',
				'radius'  => 'md',
				'density' => 'comfortable',
			),
			array_filter(
				array_intersect_key(
					$values,
					array_flip(
						array(
							'accent',
							'accent_dark',
							'logo',
							'logo_dark',
							'favicon',
							'logoAlt',
							'tagline',
							'font',
							'radius',
							'density',
							'sidebar',
							'background',
							'sign_in_image',
							'sign_in_title',
							'sign_in_text',
							'sign_in_side',
							'sign_in_position',
							'sign_in_bg',
							'sign_in_accent',
							'sign_in_text_color',
							'nav_text',
							'nav_accent',
							'sidebar_bg',
							'sidebar_text',
							'sidebar_accent',
							'nav_text_dark',
							'nav_accent_dark',
							'sidebar_bg_dark',
							'sidebar_text_dark',
							'sidebar_accent_dark',
						)
					)
				),
				static fn( $value ): bool => '' !== $value && null !== $value
			),
			// The one global credit switch (off unless the workspace opts
			// in, WordPress.org guideline 10). Every client-facing screen
			// that could show the credit reads it from here; emails read the
			// same setting.
			array( 'powered_by' => (bool) $this->plugin->settings()->get( 'workspace', 'powered_by', false ) )
		);
	}

	/**
	 * Theme preference: light | dark | system.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public function theme( int $user_id ): string {
		$theme = (string) Preferences::get( $user_id )['theme'];
		return in_array( $theme, array( 'light', 'dark', 'system' ), true ) ? $theme : 'system';
	}
}
