<?php
/**
 * WordPress 7.1.4 post-event adapters; arguments are selected, never serialized.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Audit;

/** Access, user, administration and content hooks. */
final class Collectors {
	/**
	 * Context-coalesced permission changes.
	 *
	 * @var array<string,array{id:int,action:string,envelope:array<string,int|string|null>}>
	 */
	private array $permissions = array();
	/**
	 * Suppress the paired generic update after trash/restore.
	 *
	 * @var array<int,bool>
	 */
	private array $transitions = array();
	/**
	 * Registered callbacks for deterministic teardown.
	 *
	 * @var array<string,\Closure>
	 */
	private array $hooks = array();

	/**
	 * Select validated collector inputs.
	 *
	 * @param Recorder $recorder Failure-contained writer.
	 */
	public function __construct( private readonly Recorder $recorder ) {}

	/** Register once; use only post-persistence notifications. */
	public function register(): void {
		if ( array() !== $this->hooks ) {
			return;
		}
		$this->hook(
			'wp_login',
			function ( $login, $user ) {
				// No login string or user object retained.
				if ( $user instanceof \WP_User ) {
					$this->recorder->emit( 'access', 'login', 'user', $user->ID, 'success', array(), $user->ID );
				}
			},
			2
		);
		$this->hook(
			'wp_login_failed',
			function () {
				$this->recorder->emit( 'access', 'login_failed', 'none', null, 'failure', array( 'reason' => 'authentication_failed' ), 0 );
			},
			0
		);
		$this->hook(
			'wp_logout',
			function ( $id ) {
				$this->recorder->emit( 'access', 'logout', 'user', (int) $id, 'success', array(), (int) $id );
			}
		);
		foreach ( array(
			'user_register'  => 'created',
			'profile_update' => 'updated',
			'deleted_user'   => 'deleted',
		) as $hook => $action ) {
			$this->hook(
				$hook,
				function ( $id ) use ( $action ) {
					$this->recorder->emit( 'users', $action, 'user', (int) $id );
				}
			);
		}
		foreach ( array( 'set_user_role', 'add_user_role', 'remove_user_role' ) as $hook ) {
			$this->hook(
				$hook,
				function ( $id ) {
					$this->permission( (int) $id, 'role_changed' );
				}
			);
		}
		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			$this->hook(
				$hook,
				function ( $meta_id, $id, $key ) {
					global $wpdb;
					if ( $key === $wpdb->get_blog_prefix() . 'capabilities' ) {
						$this->permission( (int) $id, 'capability_changed' );
					}
				},
				3
			);
		}
		$this->hook(
			'shutdown',
			function () {
				$this->flush_permissions();
			},
			0
		);
		foreach ( array(
			'activated_plugin'   => 'activated',
			'deactivated_plugin' => 'deactivated',
		) as $hook => $action ) {
			$this->hook(
				$hook,
				function ( $file ) use ( $action ) {
					$this->technical( $action, 'plugin', $file );
				}
			);
		}
		foreach ( array(
			'deleted_plugin' => 'plugin',
			'deleted_theme'  => 'theme',
		) as $hook => $type ) {
			$this->hook(
				$hook,
				function ( $reference, $deleted ) use ( $type ) {
					$this->technical( 'deleted', $type, $reference, true === $deleted ? 'success' : 'failure' );
				},
				2
			);
		}
		$this->hook(
			'switch_theme',
			function ( $name, $theme ) {
				if ( $theme instanceof \WP_Theme ) {
					$this->technical( 'theme_switched', 'theme', $theme->get_stylesheet() );
				}
			},
			2
		);
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			$this->hook(
				$hook,
				function ( $name ) {
					$this->technical( 'option_changed', 'option', $name );
				}
			);
		}
		$this->hook(
			'transition_post_status',
			function ( $new_status, $old, $post ) {
				if ( $new_status !== $old && ( 'trash' === $new_status || 'trash' === $old ) && $this->eligible( $post ) ) {
					$this->transitions[ $post->ID ] = true;
					$this->content( 'trash' === $new_status ? 'trashed' : 'restored', $post );
				}
			},
			3
		);
		$this->hook(
			'wp_after_insert_post',
			function ( $id, $post, $update ) {
				if ( isset( $this->transitions[ $id ] ) ) {
					unset( $this->transitions[ $id ] );
					return;
				}
				if ( $post instanceof \WP_Post && 'attachment' !== $post->post_type ) {
					$this->content( $update ? 'updated' : 'created', $post );
				}
			},
			3
		);
		foreach ( array( 'add_attachment' => 'created' ) as $hook => $action ) {
			$this->hook(
				$hook,
				function ( $id ) use ( $action ) {
					$this->content( $action, get_post( $id ) );
				}
			);
		}
		// Attachments return early in wp_insert_post; their after/before hook supplies status evidence.
		$this->hook(
			'attachment_updated',
			function ( $id, $after, $before ) {
				if ( $after instanceof \WP_Post && $before instanceof \WP_Post ) {
					$action = $after->post_status !== $before->post_status && 'trash' === $after->post_status ? 'trashed' : ( $after->post_status !== $before->post_status && 'trash' === $before->post_status ? 'restored' : 'updated' );
					$this->content( $action, $after );
				}
			},
			3
		);
		$this->hook(
			'deleted_post',
			function ( $id, $post ) {
				$this->content( 'deleted', $post );
			},
			2
		);
	}

	/** Flush at shutdown; role hooks and their capability-meta write become one event per user/request. */
	public function flush_permissions(): void {
		$pending           = $this->permissions;
		$this->permissions = array();
		foreach ( $pending as $change ) {
			$this->recorder->emit( 'users', $change['action'], 'user', $change['id'], 'success', array(), null, $change['envelope'] );
		}
	}

	/** Unregister this instance, useful for isolated tests. */
	public function unregister(): void {
		foreach ( $this->hooks as $hook => $callback ) {
			remove_filter( $hook, $callback, 10 );
		}
		$this->hooks = array();
	}

	/**
	 * Select validated collector inputs.
	 *
	 * @param string   $hook WordPress hook.
	 * @param \Closure $callback Adapter.
	 * @param int      $arguments Allowed input count.
	 */
	private function hook( string $hook, \Closure $callback, int $arguments = 1 ): void {
		$this->hooks[ $hook ] = $callback;
		add_action( $hook, $callback, 10, $arguments );
	}

	/**
	 * Select validated collector inputs.
	 *
	 * @param int    $id Target user.
	 * @param string $action Permission operation.
	 */
	private function permission( int $id, string $action ): void {
		$envelope = $this->recorder->capture();
		$key      = $id . ':' . $envelope['actor_id'] . ':' . $envelope['origin'] . ':' . $envelope['ip'];
		// A new metadata write is a distinct mutation. Only following role notifications
		// are coalesced with that write (set_role emits remove/add/set for one write).
		if ( 'capability_changed' === $action && isset( $this->permissions[ $key ] ) ) {
			$previous = $this->permissions[ $key ];
			$this->recorder->emit( 'users', $previous['action'], 'user', $id, 'success', array(), null, $previous['envelope'] );
			unset( $this->permissions[ $key ] );
		}
		if ( $id > 0 && ( count( $this->permissions ) < 100 || isset( $this->permissions[ $key ] ) ) && ( 'role_changed' === $action || ! isset( $this->permissions[ $key ] ) ) ) {
			$this->permissions[ $key ] = array(
				'id'       => $id,
				'action'   => $action,
				'envelope' => $envelope,
			);
		}
	}

	/**
	 * Select validated collector inputs.
	 *
	 * @param string $action Closed action.
	 * @param string $type Technical type.
	 * @param mixed  $reference Bounded technical identity.
	 * @param string $result Outcome.
	 */
	private function technical( string $action, string $type, mixed $reference, string $result = 'success' ): void {
		if ( Reference::valid( $type, $reference ) ) {
			$this->recorder->emit( 'admin', $action, $type, null, $result, array( 'reference' => $reference ) );
		}
	}

	/**
	 * Select validated collector inputs.
	 *
	 * @param mixed $post Candidate content object.
	 */
	private function eligible( mixed $post ): bool {
		return $post instanceof \WP_Post && in_array( $post->post_type, array( 'post', 'page', 'attachment' ), true ) && 'auto-draft' !== $post->post_status && ! wp_is_post_revision( $post ) && ! wp_is_post_autosave( $post ) && ! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE );
	}

	/**
	 * Select validated collector inputs.
	 *
	 * @param string $action Closed action.
	 * @param mixed  $post Candidate content object.
	 */
	private function content( string $action, mixed $post ): void {
		if ( $this->eligible( $post ) ) {
			$this->recorder->emit( 'content', $action, $post->post_type, $post->ID );
		}
	}
}
