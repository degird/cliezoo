<?php
/**
 * Notification delivery channels.
 *
 * @package Cliezoo
 */

declare( strict_types=1 );

namespace Cliezoo\Platform\Notifications;

use Cliezoo\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The NotificationChannelRegistry (Blueprint §19.1). Free ships "inapp" and
 * "email"; add-ons may register more channels.
 * A channel is [label, deliver: fn( array $notification_row, array $member ): void].
 */
final class ChannelRegistry {

	/**
	 * Channels.
	 *
	 * @var array<string, array{label: string, deliver: callable|null}>
	 */
	private array $channels = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->channels['inapp'] = array(
			'label'   => __( 'In-app', 'cliezoo' ),
			'deliver' => null,
		);
		$this->channels['email'] = array(
			'label'   => __( 'Email', 'cliezoo' ),
			'deliver' => null,
		);
		unset( $plugin );
		/**
		 * Register extra notification channels.
		 *
		 * @param ChannelRegistry $registry Registry.
		 */
		do_action( 'cliezoo_register_notification_channels', $this );
	}

	/**
	 * Adds a channel.
	 *
	 * @param string   $id      Channel id.
	 * @param string   $label   Label.
	 * @param callable $deliver Delivery callback.
	 * @return void
	 */
	public function add( string $id, string $label, callable $deliver ): void {
		$this->channels[ $id ] = array(
			'label'   => $label,
			'deliver' => $deliver,
		);
	}

	/**
	 * Channel ids.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->channels );
	}

	/**
	 * Labels.
	 *
	 * @return array<string, string>
	 */
	public function labels(): array {
		return array_map( static fn( array $c ): string => $c['label'], $this->channels );
	}

	/**
	 * Delivery callback of an external channel.
	 *
	 * @param string $id Channel.
	 * @return callable|null
	 */
	public function deliverer( string $id ): ?callable {
		return $this->channels[ $id ]['deliver'] ?? null;
	}
}
