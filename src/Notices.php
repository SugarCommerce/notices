<?php
/**
 * A plugin's admin notices, declared once and shown when they apply.
 *
 * @package   SugarCommerce\Notices
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Notices;

use SugarCommerce\Notices\Traits\Notices\Flash;
use SugarCommerce\Notices\Traits\Notices\Dismissal;
use SugarCommerce\Notices\Traits\Notices\Rendering;

/**
 * Admin notices, declared once and shown when they apply.
 *
 * Printing a notice is three lines and needs no library. Three things
 * around it are not, and are what everybody reimplements badly:
 *
 * - **Showing one after a redirect.** An action that saves something has
 *   to redirect before it renders, so the message about what happened
 *   belongs to a different request from the work. A declared notice
 *   travels as a query argument naming it (url()); a message whose words
 *   are only known at the time (flash()) travels in a short-lived
 *   transient for that user, because words in a URL are words anybody can
 *   put there.
 *
 * - **Dismissal that sticks.** Core draws the X and hides the notice; it
 *   does not remember. A notice that comes back on the next page load is
 *   one people learn to ignore.
 *
 * - **Two plugins on one site.** Everything is named from the context the
 *   plugin passes -- the query argument, the user meta,
 *   the transient -- so two plugins that bundle this library, each with a
 *   `settings_saved`, never show each other's.
 *
 * What this deliberately does not do: guess a notice's type from its key.
 * `licence_expired` once inferred "success" because it did not end in
 * `_error`, which is the sort of magic that is right often enough to be
 * trusted and wrong exactly when it matters.
 */
final class Notices {

	use Flash;
	use Dismissal;
	use Rendering;

	/**
	 * Core's four types. Anything else renders as a notice with no colour,
	 * which reads as a notice that failed rather than one of a kind nobody
	 * has heard of.
	 *
	 * @var string[]
	 */
	public const array TYPES = [ 'success', 'warning', 'error', 'info' ];

	/**
	 * How long a flashed message waits for the next page, in seconds.
	 *
	 * Long enough to follow a redirect, and no longer.
	 */
	public const int FLASH_LIFETIME = 60;

	/**
	 * Most flashed messages kept waiting for one user, the newest kept.
	 */
	public const int FLASH_LIMIT = 10;

	/**
	 * Registered notices, by context and key.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private static array $notices = [];

	/**
	 * Contexts that have had their hooks attached.
	 *
	 * @var array<string, bool>
	 */
	private static array $hooked = [];

	/**
	 * Whether the dismiss route is hooked.
	 *
	 * @var bool
	 */
	private static bool $routed = false;

	/**
	 * Register a plugin's notices.
	 *
	 *     Notices::register( 'myplugin', [
	 *         'settings_saved' => [
	 *             'message' => __( 'Settings saved.', 'my-plugin' ),
	 *             'type'    => 'success',
	 *         ],
	 *         'needs_api_key' => [
	 *             'message'    => __( 'Add an API key to start syncing.', 'my-plugin' ),
	 *             'type'       => 'warning',
	 *             'persistent' => true,
	 *             'condition'  => fn(): bool => ! get_option( 'myplugin_api_key' ),
	 *         ],
	 *     ] );
	 *
	 * @param string                              $context The plugin's namespace for these.
	 * @param array<string, array<string, mixed>> $notices Notices, by key.
	 *
	 * @return int How many registered.
	 */
	public static function register( string $context, array $notices ): int {
		$registered = 0;

		foreach ( $notices as $key => $config ) {
			if ( self::add( $context, (string) $key, (array) $config ) ) {
				++$registered;
			}
		}

		return $registered;
	}

	/**
	 * Register one notice.
	 *
	 * Configuration:
	 *
	 * - `message`     HTML, run through wp_kses_post(). Required.
	 * - `type`        success, warning, error or info (the default).
	 * - `dismissible` Whether it has an X. Default true.
	 * - `persistent`  Shown whenever it applies, rather than when triggered,
	 *                 and remembered as dismissed. Default false.
	 * - `capability`  Who sees it. Default `manage_options`.
	 * - `screens`     Screen ids it is limited to. Default everywhere.
	 * - `condition`   A callable that must return true for it to show.
	 *
	 * @param string               $context The plugin's namespace for these.
	 * @param string               $key     How the notice is triggered and remembered.
	 * @param array<string, mixed> $config  Its configuration.
	 *
	 * @return bool False when the context, key or message is empty.
	 */
	public static function add( string $context, string $key, array $config ): bool {
		$context = sanitize_key( $context );
		$key     = sanitize_key( $key );

		if ( '' === $context || '' === $key ) {
			return false;
		}

		$config = array_merge(
			[
				'message'     => '',
				'type'        => 'info',
				'dismissible' => true,
				'persistent'  => false,
				'capability'  => 'manage_options',
				'screens'     => [],
				'condition'   => null,
			],
			$config
		);

		if ( '' === (string) $config['message'] ) {
			return false;
		}

		$config['type'] = self::type( $config['type'] );

		self::$notices[ $context ][ $key ] = $config;

		self::hook( $context );

		return true;
	}

	/**
	 * The URL that shows a notice.
	 *
	 * What an action redirects to when it has finished:
	 *
	 *     wp_safe_redirect( Notices::url( 'myplugin', 'settings_saved' ) );
	 *     exit;
	 *
	 * @param string $context The context.
	 * @param string $key     The notice's key.
	 * @param string $url     Where to go. The current screen by default.
	 *
	 * @return string
	 */
	public static function url( string $context, string $key, string $url = '' ): string {
		return add_query_arg( self::trigger( $context ), sanitize_key( $key ), $url );
	}

	/**
	 * The query argument a context's notices are triggered by.
	 *
	 * `{context}_notice`, so one plugin's `?myplugin_notice=settings_saved`
	 * is not another plugin's too.
	 *
	 * @param string $context The context.
	 *
	 * @return string
	 */
	public static function trigger( string $context ): string {
		return sanitize_key( $context ) . '_notice';
	}

	/**
	 * Everything registered for a context.
	 *
	 * @param string $context The context.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all( string $context ): array {
		return self::$notices[ $context ] ?? [];
	}

	/**
	 * Attach a context's hooks, once.
	 *
	 * @param string $context The context.
	 *
	 * @return void
	 */
	private static function hook( string $context ): void {
		if ( isset( self::$hooked[ $context ] ) ) {
			return;
		}

		self::$hooked[ $context ] = true;

		add_action( 'admin_notices', static fn() => self::render( $context ) );

		// One route for every context, registered once per copy.
		self::boot();

		// The trigger is taken back out of the URL once the page has
		// loaded, the way core does for its own `message` and `updated`, so
		// a refresh does not announce the save a second time.
		add_filter(
			'removable_query_args',
			static function ( $args ) use ( $context ): array {
				$args    = (array) $args;
				$trigger = self::trigger( $context );

				if ( ! in_array( $trigger, $args, true ) ) {
					$args[] = $trigger;
				}

				return $args;
			}
		);
	}
}
