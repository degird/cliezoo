<?php
/**
 * First run: the setup wizard, sample data and the onboarding checklist.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Setup;

use Cliezoo\Core\Actions\ActionError;
use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\Op;
use Cliezoo\Core\App\AppRouter;
use Cliezoo\Core\App\WidgetRegistry;
use Cliezoo\Core\BaseModule;
use Cliezoo\Core\Plugin;
use Cliezoo\Core\Seeder;
use Cliezoo\Infrastructure\Crypto\Vault;
use Cliezoo\Infrastructure\Mail\Mailer;
use Cliezoo\Platform\Permissions\Actor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as JSON by the REST layer and never printed as HTML.

/**
 * What happens between installing the plugin and having a workspace that
 * works: a short wizard, an optional set of example records to look at, and
 * a checklist on the home screen that disappears once it is done
 * (Blueprint §13). Nothing here blocks the app: every step can be skipped.
 */
final class SetupModule extends BaseModule {

	private const OPTION = 'cliezoo_setup';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'setup';
	}

	/**
	 * {@inheritDoc}
	 */
	public function switchable(): bool {
		return false;
	}

	/**
	 * Registers hooks.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		add_action(
			'cliezoo_register_widgets',
			static function ( WidgetRegistry $widgets, Actor $actor ) use ( $plugin ): void {
				$state  = self::progress( $plugin, $actor );
				$badges = (array) ( $state['achievements'] ?? array() );
				$earned = array_filter( $badges, static fn( array $badge ): bool => ! empty( $badge['done'] ) );
				if ( $actor->is_admin() && $earned ) {
					$widgets->add(
						'achievements',
						array(
							'title' => __( 'What you have got done', 'cliezoo' ),
							'size'  => 'md',
							'order' => 2,
							'allow' => static fn( Actor $a ): bool => $a->is_admin(),
						)
					);
				}
				if ( $actor->is_admin() && ! $state['dismissed'] && $state['remaining'] > 0 ) {
					$widgets->add(
						'setup-checklist',
						array(
							'title' => __( 'Finish setting up', 'cliezoo' ),
							'size'  => 'md',
							'order' => 1,
							'allow' => static fn( Actor $a ): bool => $a->is_admin(),
						)
					);
				}
			},
			10,
			2
		);
		add_filter(
			'cliezoo_boot_data',
			static function ( array $data, Actor $actor ) use ( $plugin ): array {
				if ( ! $actor->is_guest() && $actor->is_admin() ) {
					$data['setup'] = self::progress( $plugin, $actor, true );
				}
				return $data;
			},
			10,
			2
		);
		add_action(
			'cliezoo_register_actions',
			static function ( ActionRegistry $registry ) use ( $plugin ): void {
				self::actions( $registry, $plugin );
			}
		);
	}

	/**
	 * Registers the actions.
	 *
	 * @param ActionRegistry $registry Registry.
	 * @param Plugin         $plugin   Plugin.
	 * @return void
	 */
	private static function actions( ActionRegistry $registry, Plugin $plugin ): void {
		$admin = static fn( Actor $a ): bool => $a->is_admin();
		$registry->add(
			Op::make( 'setup.get', __( 'Setup progress', 'cliezoo' ) )
				->allow( $admin )
				->run( static fn( Actor $a ): array => self::progress( $plugin, $a ) )
				->rest( 'GET', '/setup' )
				->readonly()
		);
		$registry->add(
			Op::make( 'setup.finish', __( 'Finish setup', 'cliezoo' ) )
				->describe( __( 'Marks the wizard as done, or puts the checklist away.', 'cliezoo' ) )
				->input(
					array(
						'dismiss' => array( 'type' => 'boolean' ),
					)
				)
				->allow( $admin )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin ): array {
						$saved              = self::saved();
						$saved['completed'] = true;
						if ( ! empty( $in['dismiss'] ) ) {
							$saved['dismissed'] = true;
						}
						update_option( self::OPTION, $saved, false );
						return self::state( $plugin, $a );
					}
				)
				->rest( 'POST', '/setup/finish' )
		);
		$registry->add(
			Op::make( 'setup.industry', __( 'Set up for an industry', 'cliezoo' ) )
				->describe( __( 'Saves the workspace\'s industry and adds the project categories and folders that suit it. Nothing already there is changed or removed.', 'cliezoo' ) )
				->input(
					array(
						'industry' => array(
							'type'     => 'string',
							'enum'     => IndustryPresets::INDUSTRIES,
							'required' => true,
						),
					)
				)
				->allow( $admin )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin ): array {
						$industry = (string) $in['industry'];
						$plugin->settings()->update( 'workspace', array( 'industry' => $industry ) );
						$added             = ( new IndustryPresets( $plugin ) )->apply( $industry );
						$saved             = self::saved();
						$saved['industry'] = $industry;
						update_option( self::OPTION, $saved, false );
						return array(
							'industry' => $industry,
							'added'    => $added,
						);
					}
				)
				->rest( 'POST', '/setup/industry' )
		);
		$registry->add(
			Op::make( 'setup.test_email', __( 'Send a test email', 'cliezoo' ) )
				->describe( __( 'Sends a short message to the address you are signed in with, to prove this site can send email.', 'cliezoo' ) )
				->allow( $admin )
				->run( static fn( Actor $a ): array => self::test_email( $plugin, $a ) )
				->rest( 'POST', '/setup/test-email' )
		);
		$registry->add(
			Op::make( 'setup.pretty_permalinks', __( 'Use tidy addresses', 'cliezoo' ) )
				->describe( __( 'Turns WordPress\'s plain permalinks into post-name ones, so the app lives at a tidy address rather than a query string. A site-wide WordPress setting, so only somebody who manages the site may change it.', 'cliezoo' ) )
				->allow( $admin )
				->run(
					static function () use ( $plugin ): array {
						if ( ! current_user_can( 'manage_options' ) ) {
							throw ActionError::forbidden();
						}
						// Only from plain: a site that chose its own structure
						// keeps it.
						if ( '' === (string) get_option( 'permalink_structure' ) ) {
							update_option( 'permalink_structure', '/%postname%/' );
							( new AppRouter( $plugin ) )->rewrites();
							flush_rewrite_rules( false );
						}
						return array(
							'pretty'  => AppRouter::pretty(),
							'app_url' => AppRouter::app_url(),
						);
					}
				)
				->rest( 'POST', '/setup/pretty-permalinks' )
		);
		$registry->add(
			Op::make( 'setup.sample_data', __( 'Add example records', 'cliezoo' ) )
				->describe( __( 'Adds a small set of example records so the app is not empty while you look around. Remove them at any time.', 'cliezoo' ) )
				->input( array( 'remove' => array( 'type' => 'boolean' ) ) )
				->allow( $admin )
				->run(
					static function ( Actor $a, array $in ) use ( $plugin ): array {
						return empty( $in['remove'] ) ? self::add_sample_data( $plugin, $a ) : self::remove_sample_data( $plugin, $a );
					}
				)
				->rest( 'POST', '/setup/sample-data' )
		);
	}

	/**
	 * The saved setup state.
	 *
	 * @return array<string, mixed>
	 */
	private static function saved(): array {
		$saved = get_option( self::OPTION );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Where this workspace stands: the wizard, and the checklist steps that
	 * are already true. Each step is derived from the workspace itself, so
	 * it cannot claim something is done when it is not.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param Actor  $actor  Actor.
	 * @return array<string, mixed>
	 */
	public static function state( Plugin $plugin, Actor $actor ): array {
		$saved    = self::saved();
		$settings = $plugin->settings();
		$members  = $plugin->service( 'members' )->repository();
		$branding = in_array( 'branding', $settings->modules(), true ) ? (array) $settings->all( 'branding' ) : array();
		$steps    = array(
			array(
				'id'    => 'workspace',
				'label' => __( 'Name your workspace', 'cliezoo' ),
				'done'  => '' !== (string) $settings->get( 'workspace', 'name', '' ) && get_bloginfo( 'name' ) !== (string) $settings->get( 'workspace', 'name', '' ),
				'to'    => '/settings/general',
			),
			array(
				'id'    => 'localization',
				'label' => __( 'Set your time zone and currency', 'cliezoo' ),
				'done'  => '' !== (string) $settings->get( 'workspace', 'timezone', '' ),
				'to'    => '/settings/localization',
			),
			array(
				'id'    => 'branding',
				'label' => __( 'Add your logo', 'cliezoo' ),
				'done'  => '' !== (string) ( $branding['logo'] ?? '' ),
				'to'    => '/settings/branding',
			),
			array(
				'id'    => 'team',
				'label' => __( 'Invite someone', 'cliezoo' ),
				'done'  => count( $members->where( array( 'kind' => 'staff' ), 'id', 5 ) ) > 1,
				'to'    => '/settings/team',
			),
			array(
				'id'    => 'health',
				'label' => __( 'Check that email and jobs work', 'cliezoo' ),
				'done'  => is_array( get_option( \Cliezoo\Platform\Health\Health::OPTION ) ),
				'to'    => '/settings/health',
			),
		);
		/**
		 * Filters the onboarding checklist (modules add their own steps).
		 *
		 * @param array $steps Steps.
		 * @param Actor $actor Actor.
		 */
		$steps = (array) apply_filters( 'cliezoo_setup_steps', $steps, $actor );
		return array(
			'completed'   => ! empty( $saved['completed'] ),
			'dismissed'   => ! empty( $saved['dismissed'] ),
			'sample_data' => ! empty( $saved['sample_data'] ),
			'app_url'     => AppRouter::app_url(),
			'key_source'  => Vault::key_source(),
			'steps'       => array_values( $steps ),
			'remaining'   => count( array_filter( $steps, static fn( array $step ): bool => empty( $step['done'] ) ) ),
		);
	}

	/**
	 * Where this workspace stands, with the badges it has earned.
	 *
	 * The badges are settled here rather than inside state() because
	 * settling writes down the day each was earned, and the badge for
	 * finishing setup asks state() what is left.
	 *
	 * The day earned is written when somebody opens the app, never by a
	 * read of the API: a GET that writes is a GET nobody can cache or
	 * trust.
	 *
	 * @param Plugin $plugin  Plugin.
	 * @param Actor  $actor   Actor.
	 * @param bool   $persist Whether to write down what has just been earned.
	 * @return array<string, mixed>
	 */
	public static function progress( Plugin $plugin, Actor $actor, bool $persist = false ): array {
		$state = self::state( $plugin, $actor );
		$saved = self::saved();
		$badge = Achievements::settle( $plugin, $actor, $saved, $state );
		if ( $persist && $badge['fresh'] ) {
			$saved[ Achievements::KEY ] = $badge['earned'];
			update_option( self::OPTION, $saved, false );
		}
		$state['achievements'] = $badge['badges'];
		// Which were earned since the last time somebody looked, so the
		// screen can say so once.
		$state['new_achievements'] = $badge['fresh'];
		return $state;
	}

	/**
	 * Sends a test message to the person running setup.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param Actor  $actor  Actor.
	 * @return array<string, mixed>
	 * @throws ActionError When there is nowhere to send it.
	 */
	private static function test_email( Plugin $plugin, Actor $actor ): array {
		$member = $plugin->service( 'members' )->repository()->find( $actor->member_id );
		$email  = (string) ( $member['email'] ?? '' );
		if ( '' === $email ) {
			throw ActionError::validation( __( 'Your account has no email address.', 'cliezoo' ) );
		}
		$name = (string) $plugin->settings()->get( 'workspace', 'name', get_bloginfo( 'name' ) );
		$sent = $plugin->get( Mailer::class )->send(
			$email,
			/* translators: %s: workspace name. */
			sprintf( __( '%s can send email', 'cliezoo' ), $name ),
			'<p>' . esc_html__( 'This is the test message from your setup checklist. Email is working.', 'cliezoo' ) . '</p>',
			__( 'This is the test message from your setup checklist. Email is working.', 'cliezoo' ),
			array( 'category' => 'test' )
		);
		return array(
			'sent' => (bool) $sent,
			'to'   => $email,
		);
	}

	/**
	 * Adds example records: the Seeder's clients, contacts, projects, boards
	 * and tasks, then whatever modules add for their own record types.
	 * Everything created is written down so it can be removed in one go.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param Actor  $actor  Actor.
	 * @return array<string, mixed>
	 */
	private static function add_sample_data( Plugin $plugin, Actor $actor ): array {
		// The same small workspace `wp cliezoo seed` makes, created through
		// the app's own actions as the person pressing the button, so the
		// wizard and the command line cannot drift apart. Types with nothing
		// made are left out, so the summary only names what is there.
		$created = array_filter( ( new Seeder( $plugin, $actor ) )->add() );
		/**
		 * Creates example records. Add yours and return how many you made,
		 * keyed by record type.
		 *
		 * @param array  $created Type => count.
		 * @param Plugin $plugin  Plugin.
		 * @param Actor  $actor   Actor.
		 */
		$created              = (array) apply_filters( 'cliezoo_sample_data', $created, $plugin, $actor );
		$saved                = self::saved();
		$saved['sample_data'] = true;
		update_option( self::OPTION, $saved, false );
		return array(
			'created' => $created,
			'total'   => array_sum( array_map( 'intval', $created ) ),
		);
	}

	/**
	 * Removes what the example data added.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param Actor  $actor  Actor.
	 * @return array<string, mixed>
	 */
	private static function remove_sample_data( Plugin $plugin, Actor $actor ): array {
		// The Seeder removes only what it wrote down it had made, so records
		// people added or changed alongside the examples are never touched.
		$removed = array_filter( ( new Seeder( $plugin, $actor ) )->remove() );
		/**
		 * Removes example records.
		 *
		 * @param array  $removed Type => count.
		 * @param Plugin $plugin  Plugin.
		 * @param Actor  $actor   Actor.
		 */
		$removed              = (array) apply_filters( 'cliezoo_sample_data_remove', $removed, $plugin, $actor );
		$saved                = self::saved();
		$saved['sample_data'] = false;
		update_option( self::OPTION, $saved, false );
		return array(
			'removed' => $removed,
			'total'   => array_sum( array_map( 'intval', $removed ) ),
		);
	}
}
