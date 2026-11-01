<?php
/**
 * Plugin bootstrap.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Core;

use Cliezoo\Core\Actions\ActionRegistry;
use Cliezoo\Core\Actions\EntityAction;
use Cliezoo\Core\Data\Entity;
use Cliezoo\Core\Data\EntityRegistry;
use Cliezoo\Core\Data\EntityService;
use Cliezoo\Core\Data\Migrator;
use Cliezoo\Core\Data\Serializer;
use Cliezoo\Core\Rest\RestServer;
use Cliezoo\Core\Rest\RouteRegistry;
use Cliezoo\Platform\Permissions\Actor;
use Cliezoo\Platform\Permissions\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots Cliezoo: container, registries, modules and the public extension API.
 *
 * Only cheap hook registration runs on every request (Blueprint §18.2).
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether boot() ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Active modules after switches and dependencies.
	 *
	 * @var array<string, Module>
	 */
	private array $active = array();

	/**
	 * Whether generic and custom actions were registered.
	 *
	 * @var bool
	 */
	private bool $actions_ready = false;

	/**
	 * Entity services cache.
	 *
	 * @var array<string, EntityService>
	 */
	private array $services = array();

	/**
	 * Current actor override (tests, jobs, impersonation).
	 *
	 * @var Actor|null
	 */
	private ?Actor $actor = null;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->register_services();
	}

	/**
	 * The instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Core services.
	 *
	 * @return void
	 */
	private function register_services(): void {
		$c = $this->container;
		$c->set( ModuleRegistry::class, static fn(): ModuleRegistry => new ModuleRegistry() );
		$c->set( EntityRegistry::class, static fn(): EntityRegistry => new EntityRegistry() );
		$c->set( ActionRegistry::class, static fn(): ActionRegistry => new ActionRegistry() );
		$c->set( RouteRegistry::class, static fn(): RouteRegistry => new RouteRegistry() );
		$c->set( Policy::class, static fn( Container $c ): Policy => new Policy( $c->get( EntityRegistry::class ) ) );
		$c->set( Serializer::class, static fn( Container $c ): Serializer => new Serializer( $c->get( EntityRegistry::class ) ) );
		$c->set( Migrator::class, static fn(): Migrator => new Migrator() );
	}

	/**
	 * Boots modules and hooks. Runs on plugins_loaded (priority 0).
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$modules = $this->modules();
		foreach ( Modules::free() as $module ) {
			$modules->add( $module );
		}
		/**
		 * Register additional modules (add-ons register here).
		 *
		 * @param ModuleRegistry $modules Registry.
		 * @param Plugin         $plugin  Plugin.
		 */
		do_action( 'cliezoo_register_modules', $modules, $this );

		$this->active = $modules->active( $this->switched_off() );
		foreach ( $modules->all() as $module ) {
			$this->entities()->provide( $module );
		}
		foreach ( $this->active as $id => $module ) {
			try {
				$module->register( $this );
			} catch ( \Throwable $error ) {
				$modules->fail( $id, $error->getMessage() );
				unset( $this->active[ $id ] );
				Logger::error(
					'Module failed to boot',
					array(
						'module' => $id,
						'error'  => $error->getMessage(),
					)
				);
			}
		}

		( new RestServer( $this ) )->hooks();
		( new App\AppRouter( $this ) )->hooks();
		// One page in wp-admin: a way into the app and how the site is
		// doing. Admin code loads in wp-admin and nowhere else.
		if ( is_admin() ) {
			( new Admin\AdminPage( $this ) )->register();
		}
		Actions\Abilities::hooks( $this );
		add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );

		/**
		 * Fires when Cliezoo has loaded (the "parent loaded" signal for add-ons).
		 *
		 * @param Plugin $plugin Plugin.
		 */
		do_action( 'cliezoo_loaded', $this );
	}

	/**
	 * Runs migrations when the code version changed (updates without re-activation).
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		// While the plugin is being built, the tables change between
		// releases without the version changing. On a local or development
		// site the schema is compared at most once a minute, which costs
		// only what the migrator does anyway: tables whose definition did
		// not change are left alone.
		$developing = in_array( wp_get_environment_type(), array( 'local', 'development' ), true )
			&& false === get_transient( 'cliezoo_schema_checked' );
		if ( $developing ) {
			set_transient( 'cliezoo_schema_checked', 1, MINUTE_IN_SECONDS );
		}
		if ( ! $developing && get_option( 'cliezoo_version' ) === CLIEZOO_VERSION && ! apply_filters( 'cliezoo_force_migrations', false ) ) {
			return;
		}
		// One request migrates; the rest carry on. Without this every request
		// that arrived after an update and before the first one finished ran
		// the whole thing too — a dozen ALTER TABLEs queued behind each other,
		// requests stalled for minutes, and data migrations run twice at once.
		// add_option is the lock because it cannot succeed twice for one name.
		if ( ! self::take_migration_lock() ) {
			return;
		}
		try {
			$this->migrate();
			update_option( 'cliezoo_version', CLIEZOO_VERSION );
			/**
			 * Every module's tables are those of the running code. An add-on
			 * that asked for this migration through `cliezoo_force_migrations`
			 * records its own version here.
			 */
			do_action( 'cliezoo_migrated' );
		} finally {
			delete_option( self::MIGRATION_LOCK );
		}
	}

	/**
	 * The option that says a migration is under way.
	 */
	public const MIGRATION_LOCK = 'cliezoo_migrating';

	/**
	 * Takes the migration lock, or says somebody else has it.
	 *
	 * A lock older than ten minutes belongs to a request that died half way
	 * (a timeout, a fatal error); it is taken over rather than left to stop
	 * every later upgrade.
	 *
	 * @return bool Whether this request may migrate.
	 */
	public static function take_migration_lock(): bool {
		if ( add_option( self::MIGRATION_LOCK, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( self::MIGRATION_LOCK, 0 );
		if ( $since > 0 && time() - $since < 10 * MINUTE_IN_SECONDS ) {
			return false;
		}
		delete_option( self::MIGRATION_LOCK );
		return (bool) add_option( self::MIGRATION_LOCK, time(), '', false );
	}

	/**
	 * Installs/updates tables and runs data migrations for every registered module.
	 *
	 * @return array{created: string[], migrated: string[]}
	 */
	public function migrate(): array {
		$report = $this->migrator()->run( $this->modules()->all() );
		/**
		 * Tables and migrations are up to date (seed defaults here; must be idempotent).
		 *
		 * @param array  $report Created tables and applied migrations.
		 * @param Plugin $plugin Plugin.
		 */
		do_action( 'cliezoo_migrated', $report, $this );
		return $report;
	}

	/**
	 * Registers the generic record actions for an entity.
	 *
	 * @param Entity $entity Entity.
	 * @return void
	 */
	private function register_entity_actions( Entity $entity ): void {
		if ( ! $entity->public ) {
			return;
		}
		// A table with no trash still needs a way to remove a row: a
		// checklist item, a label on a card, somebody taken off a task. It
		// goes through the same DELETE route and the same permission check;
		// it simply happens at once, because there is nowhere to put it.
		$verbs = $entity->soft_delete ? EntityAction::VERBS : array( 'list', 'get', 'create', 'update', 'trash' );
		/**
		 * Filters which generic actions an entity exposes.
		 *
		 * @param string[] $verbs  Verbs.
		 * @param Entity   $entity Entity.
		 */
		$verbs = (array) apply_filters( 'cliezoo_entity_verbs', $verbs, $entity );
		$name  = $entity->name;
		foreach ( $verbs as $verb ) {
			/**
			 * Extra metadata for one of an entity's generic actions.
			 *
			 * A module says here which of its own record's verbs an external
			 * agent may use — `mcp` is `read`, `safe` or null — without
			 * writing the action itself.
			 *
			 * @param array  $extra  Metadata overrides.
			 * @param Entity $entity Entity.
			 * @param string $verb   list|get|create|update|trash|…
			 */
			$extra = (array) apply_filters( 'cliezoo_entity_action_meta', array(), $entity, $verb );
			$this->actions()->add( new EntityAction( $entity, $verb, fn(): EntityService => $this->service( $name ), $extra ) );
		}
	}

	/**
	 * Module ids switched off in Settings → Modules.
	 *
	 * @return string[]
	 */
	public function switched_off(): array {
		$off = get_option( 'cliezoo_modules_off', array() );
		return is_array( $off ) ? array_map( 'strval', $off ) : array();
	}

	/**
	 * Whether a module is active.
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public function module_active( string $id ): bool {
		return isset( $this->active[ $id ] );
	}

	/**
	 * Active modules.
	 *
	 * @return array<string, Module>
	 */
	public function active_modules(): array {
		return $this->active;
	}

	/**
	 * The container.
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Module registry.
	 *
	 * @return ModuleRegistry
	 */
	public function modules(): ModuleRegistry {
		return $this->container->get( ModuleRegistry::class );
	}

	/**
	 * Entity registry.
	 *
	 * @return EntityRegistry
	 */
	public function entities(): EntityRegistry {
		return $this->container->get( EntityRegistry::class );
	}

	/**
	 * Action registry.
	 *
	 * @return ActionRegistry
	 */
	public function actions(): ActionRegistry {
		$registry = $this->container->get( ActionRegistry::class );
		if ( ! $this->actions_ready ) {
			$this->actions_ready = true;
			foreach ( $this->active as $id => $module ) {
				foreach ( $this->entities()->for_module( $id ) as $entity ) {
					$this->register_entity_actions( $entity );
				}
			}
			/**
			 * Register custom Actions (built lazily on first use).
			 *
			 * @param ActionRegistry $registry Registry.
			 * @param Plugin         $plugin   Plugin.
			 */
			do_action( 'cliezoo_register_actions', $registry, $this );
		}
		return $registry;
	}

	/**
	 * Route registry.
	 *
	 * @return RouteRegistry
	 */
	public function routes(): RouteRegistry {
		return $this->container->get( RouteRegistry::class );
	}

	/**
	 * Policy.
	 *
	 * @return Policy
	 */
	public function policy(): Policy {
		return $this->container->get( Policy::class );
	}

	/**
	 * Serializer.
	 *
	 * @return Serializer
	 */
	public function serializer(): Serializer {
		return $this->container->get( Serializer::class );
	}

	/**
	 * Migrator.
	 *
	 * @return Migrator
	 */
	public function migrator(): Migrator {
		return $this->container->get( Migrator::class );
	}

	/**
	 * A service from the container.
	 *
	 * @param string $id Service id (class name).
	 * @return object
	 */
	public function get( string $id ): object {
		return $this->container->get( $id );
	}

	/**
	 * The record service for an entity.
	 *
	 * @param string $entity Entity name.
	 * @return EntityService
	 */
	public function service( string $entity ): EntityService {
		if ( ! isset( $this->services[ $entity ] ) ) {
			$custom                    = apply_filters( 'cliezoo_custom_filter_builder', null, $entity );
			$this->services[ $entity ] = new EntityService( $this->entities()->get( $entity ), $this->entities(), $this->policy(), $this->serializer(), is_callable( $custom ) ? $custom : null );
		}
		return $this->services[ $entity ];
	}

	/**
	 * Numbering service.
	 *
	 * @return \Cliezoo\Platform\Numbering\Numbering
	 */
	public function numbering(): \Cliezoo\Platform\Numbering\Numbering {
		return $this->container->get( \Cliezoo\Platform\Numbering\Numbering::class );
	}

	/**
	 * Settings service.
	 *
	 * @return \Cliezoo\Platform\Settings\Settings
	 */
	public function settings(): \Cliezoo\Platform\Settings\Settings {
		return $this->container->get( \Cliezoo\Platform\Settings\Settings::class );
	}

	/**
	 * The acting user for this request.
	 *
	 * @return Actor
	 */
	public function actor(): Actor {
		if ( null !== $this->actor ) {
			return $this->actor;
		}
		$actor = $this->container->get( \Cliezoo\Platform\Members\Members::class )->actor_for( get_current_user_id() );
		/**
		 * The actor this request is acting as.
		 *
		 * The portal uses it for "see this as the client": the signed-in
		 * user does not change, what they are allowed to do does.
		 *
		 * @param Actor $actor The signed-in person's actor.
		 */
		$swapped = apply_filters( 'cliezoo_current_actor', $actor );
		return $swapped instanceof Actor ? $swapped : $actor;
	}

	/**
	 * Overrides the acting user (tests, background jobs, impersonation).
	 *
	 * @param Actor|null $actor Actor or null to reset.
	 * @return void
	 */
	public function act_as( ?Actor $actor ): void {
		$this->actor = $actor;
	}
}
