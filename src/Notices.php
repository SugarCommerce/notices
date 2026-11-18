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
 *   plugin passes -- the query argument, the AJAX action, the user meta,
 *   the transient -- so two plugins that bundle this library, each with a
 *   `settings_saved`, never show each other's.
 *
 * What this deliberately does not do: guess a notice's type from its key.
 * `licence_expired` once inferred "success" because it did not end in
 * `_error`, which is the sort of magic that is right often enough to be
 * trusted and wrong exactly when it matters.
 */
final class Notices {

	/**
	 * Core's four types. Anything else renders as a notice with no colour,
	 * which reads as a notice that failed rather than one of a kind nobody
	 * has heard of.
	 *
	 * @var string[]
	 */
	public const TYPES = [ 'success', 'warning', 'error', 'info' ];

	/**
	 * How long a flashed message waits for the next page, in seconds.
	 *
	 * Long enough to follow a redirect, and no longer.
	 */
	public const FLASH_LIFETIME = 60;

	/**
	 * Most flashed messages kept waiting for one user, the newest kept.
	 */
	public const FLASH_LIMIT = 10;

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
	 * Leave a message for this user's next admin page.
	 *
	 * For a message whose words are only known now -- "3 orders refunded."
	 * -- which a declared notice and a query argument cannot carry without
	 * letting anybody link to a page that says it. Shown once, then gone;
	 * it waits FLASH_LIMIT deep and FLASH_LIFETIME seconds at most.
	 *
	 *     Notices::flash( 'myplugin', sprintf( __( '%d orders refunded.', 'my-plugin' ), $count ) );
	 *     wp_safe_redirect( $back );
	 *     exit;
	 *
	 * @param string $context The context.
	 * @param string $message HTML, run through wp_kses_post() when shown.
	 * @param string $type    success (the default), warning, error or info.
	 *
	 * @return bool False when there is no message or nobody is signed in.
	 */
	public static function flash( string $context, string $message, string $type = 'success' ): bool {
		$context = sanitize_key( $context );
		$user_id = get_current_user_id();

		if ( '' === $context || '' === trim( $message ) || 0 === $user_id ) {
			return false;
		}

		$waiting   = self::flashed( $context, $user_id );
		$waiting[] = [
			'message' => $message,
			'type'    => self::type( $type ),
		];

		set_transient( self::flash_key( $context, $user_id ), array_slice( $waiting, -self::FLASH_LIMIT ), self::FLASH_LIFETIME );

		self::hook( $context );

		return true;
	}

	/**
	 * Whether a notice should be shown on this request.
	 *
	 * @param string               $context The context.
	 * @param string               $key     The notice's key.
	 * @param array<string, mixed> $config  Its configuration.
	 *
	 * @return bool
	 */
	public static function applies( string $context, string $key, array $config ): bool {
		if ( ! current_user_can( (string) $config['capability'] ) ) {
			return false;
		}

		if ( [] !== (array) $config['screens'] && ! self::on_screen( (array) $config['screens'] ) ) {
			return false;
		}

		if ( is_callable( $config['condition'] ) && ! call_user_func( $config['condition'] ) ) {
			return false;
		}

		// Only a persistent notice is remembered as dismissed. A triggered
		// one is the message about something that just happened: the X
		// hides it for now, and the next save is a new message.
		// Remembering it meant one click on "Settings saved." and no save
		// ever confirmed itself to that user again.
		if ( $config['persistent'] && $config['dismissible'] && self::dismissed( $context, $key ) ) {
			return false;
		}

		// A persistent notice shows whenever everything above is true. Any
		// other kind waits to be triggered, which is what makes it the
		// message about something that just happened.
		return $config['persistent'] || self::triggered( $context, $key );
	}

	/**
	 * Print whatever applies: flashed messages first, then notices.
	 *
	 * @param string $context The context.
	 *
	 * @return void
	 */
	public static function render( string $context ): void {
		$user_id = get_current_user_id();

		if ( 0 !== $user_id ) {
			$flashed = self::flashed( $context, $user_id );

			// Taken whether or not anything prints, so a message shows once.
			if ( [] !== $flashed ) {
				delete_transient( self::flash_key( $context, $user_id ) );
			}

			foreach ( $flashed as $flash ) {
				self::print( $context, '', (string) $flash['type'], true, (string) $flash['message'] );
			}
		}

		$remember = false;

		foreach ( self::$notices[ $context ] ?? [] as $key => $config ) {
			if ( ! self::applies( $context, $key, $config ) ) {
				continue;
			}

			self::print( $context, $key, (string) $config['type'], (bool) $config['dismissible'], (string) $config['message'] );

			$remember = $remember || ( (bool) $config['persistent'] && (bool) $config['dismissible'] );
		}

		// Only when there is something to remember: a persistent notice
		// with an X. A script on every admin page for a notice that is not
		// there, or one that core's own X already handles, is a script
		// nobody asked for.
		if ( $remember ) {
			self::script( $context );
		}
	}

	/**
	 * Remember that this user dismissed a notice. The AJAX handler.
	 *
	 * @param string $context The context.
	 *
	 * @return void
	 */
	public static function dismiss( string $context ): void {
		check_ajax_referer( 'dismiss_notice_' . $context );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- defaulted; sanitize_key() is the sanitizer.
		$key     = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) );
		$user_id = get_current_user_id();

		// A registered key only. Otherwise anyone signed in can write
		// unbounded rows of user meta by asking to dismiss notices that do
		// not exist.
		if ( '' === $key || 0 === $user_id || ! isset( self::$notices[ $context ][ $key ] ) ) {
			wp_die( '0', '', [ 'response' => 400 ] );
		}

		// A triggered notice is not remembered; see applies().
		if ( ! self::$notices[ $context ][ $key ]['persistent'] ) {
			wp_die( '1' );
		}

		update_user_meta( $user_id, self::meta_key( $context, $key ), time() );

		wp_die( '1' );
	}

	/**
	 * Whether this user has dismissed a notice.
	 *
	 * @param string $context The context.
	 * @param string $key     The notice's key.
	 *
	 * @return bool
	 */
	public static function dismissed( string $context, string $key ): bool {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return false;
		}

		return '' !== (string) get_user_meta( $user_id, self::meta_key( $context, $key ), true );
	}

	/**
	 * Undo a dismissal, so the notice is shown again.
	 *
	 * For when whatever it warned about comes back.
	 *
	 * @param string   $context The context.
	 * @param string   $key     The notice's key.
	 * @param int|null $user_id Whose. The current user by default.
	 *
	 * @return bool
	 */
	public static function undismiss( string $context, string $key, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		return 0 !== $user_id && delete_user_meta( $user_id, self::meta_key( $context, $key ) );
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
		add_action( 'wp_ajax_dismiss_notice_' . $context, static fn() => self::dismiss( $context ) );

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

	/**
	 * One of core's four types, info when it is not.
	 *
	 * @param mixed $type As given.
	 *
	 * @return string
	 */
	private static function type( mixed $type ): string {
		return in_array( $type, self::TYPES, true ) ? $type : 'info';
	}

	/**
	 * Print one notice.
	 *
	 * @param string $context     The context.
	 * @param string $key         The notice's key, '' for a flashed message.
	 * @param string $type        Its type.
	 * @param bool   $dismissible Whether it has an X.
	 * @param string $message     Its HTML.
	 *
	 * @return void
	 */
	private static function print( string $context, string $key, string $type, bool $dismissible, string $message ): void {
		printf(
			'<div class="notice notice-%s%s" data-notice-context="%s"%s><p>%s</p></div>',
			esc_attr( $type ),
			$dismissible ? ' is-dismissible' : '',
			esc_attr( $context ),
			'' === $key ? '' : ' data-notice-key="' . esc_attr( $key ) . '"',
			wp_kses_post( $message )
		);
	}

	/**
	 * Whether the request asked for this notice.
	 *
	 * @param string $context The context.
	 * @param string $key     The notice's key.
	 *
	 * @return bool
	 */
	private static function triggered( string $context, string $key ): bool {
		$trigger = self::trigger( $context );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only chooses which declared message to show.
		return isset( $_GET[ $trigger ] ) && sanitize_key( wp_unslash( $_GET[ $trigger ] ) ) === $key;
	}

	/**
	 * Whether the current screen is one of a list.
	 *
	 * @param array<int, string> $screens Screen ids.
	 *
	 * @return bool
	 */
	private static function on_screen( array $screens ): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen && in_array( $screen->id, $screens, true );
	}

	/**
	 * The messages waiting for a user, oldest first.
	 *
	 * @param string $context The context.
	 * @param int    $user_id The user.
	 *
	 * @return array<int, array{message: string, type: string}>
	 */
	private static function flashed( string $context, int $user_id ): array {
		$waiting = get_transient( self::flash_key( $context, $user_id ) );

		return is_array( $waiting )
			? array_values( array_filter( $waiting, static fn( $flash ): bool => is_array( $flash ) && isset( $flash['message'], $flash['type'] ) ) )
			: [];
	}

	/**
	 * Where a user's flashed messages wait.
	 *
	 * @param string $context The context.
	 * @param int    $user_id The user.
	 *
	 * @return string
	 */
	private static function flash_key( string $context, int $user_id ): string {
		return sprintf( '%s_notice_flash_%d', $context, $user_id );
	}

	/**
	 * Where a dismissal is remembered.
	 *
	 * @param string $context The context.
	 * @param string $key     The notice's key.
	 *
	 * @return string
	 */
	private static function meta_key( string $context, string $key ): string {
		return sprintf( '_dismissed_notice_%s_%s', $context, $key );
	}

	/**
	 * The script that makes a dismissal stick.
	 *
	 * Core draws the X and hides the notice; it does not remember. This
	 * hears the click core already fires and posts it.
	 *
	 * @param string $context The context.
	 *
	 * @return void
	 */
	private static function script( string $context ): void {
		$script = sprintf(
			'(function(){document.addEventListener("click",function(e){' .
			'var b=e.target.closest(".notice-dismiss");if(!b)return;' .
			'var n=b.closest("[data-notice-key]");if(!n||n.dataset.noticeContext!==%s)return;' .
			'var d=new FormData();d.append("action","dismiss_notice_"+n.dataset.noticeContext);' .
			'd.append("key",n.dataset.noticeKey);d.append("_wpnonce",%s);' .
			'fetch(%s,{method:"POST",body:d,credentials:"same-origin"});});})();',
			wp_json_encode( $context ),
			wp_json_encode( wp_create_nonce( 'dismiss_notice_' . $context ) ),
			wp_json_encode( admin_url( 'admin-ajax.php' ) )
		);

		// Through core rather than a bare <script>, so the tag carries
		// whatever a site's CSP nonce filter adds to every other one.
		wp_print_inline_script_tag( $script );
	}
}
