<?php
/**
 * Notices: Dismissal
 *
 * @package   SugarCommerce\Notices
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Notices\Traits\Notices;

use SugarCommerce\Notices\Utils\Runtime;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Dismissing a notice for good, per user, and the script that sends it.
 *
 * Part of Notices: only the class uses it.
 */
trait Dismissal {

	/**
	 * Make the dismiss route answer, on every request.
	 *
	 * Registering a notice does this too, but a plugin that declares its
	 * notices on `admin_init` never does on a REST request -- so call this
	 * once where the plugin loads, or the X hides a notice that comes back.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$routed ) {
			return;
		}

		self::$routed = true;

		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	/**
	 * The dismiss route, for this copy of the library.
	 *
	 * REST rather than admin-ajax: the script sends it with core's apiFetch,
	 * which carries the `wp_rest` nonce and renews it, and the route is named
	 * from this copy's namespace, so two plugins bundling the library each
	 * answer their own.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$text = [
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_key',
		];

		register_rest_route(
			Runtime::rest_namespace(),
			'/dismiss',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'rest_dismiss' ],
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'args'                => [
					'context' => $text,
					'key'     => $text,
					'token'   => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * The dismiss route's answer.
	 *
	 * A REST request knows nothing of the notices a plugin declared for its
	 * screens, so the notice vouches for itself: it was drawn with a token
	 * for this user, context and key, and only a matching one is remembered.
	 * Nobody can fill user meta with keys that were never shown to them.
	 *
	 * @param WP_REST_Request $request The request: `context`, `key` and `token`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_dismiss( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$context = sanitize_key( (string) $request->get_param( 'context' ) );
		$key     = sanitize_key( (string) $request->get_param( 'key' ) );
		$user_id = get_current_user_id();

		if ( '' === $context || '' === $key || 0 === $user_id || ! hash_equals( self::token( $context, $key, $user_id ), (string) $request->get_param( 'token' ) ) ) {
			return new WP_Error( 'unknown_notice', 'There is no such notice to dismiss.', [ 'status' => 400 ] );
		}

		update_user_meta( $user_id, self::meta_key( $context, $key ), time() );

		return new WP_REST_Response( [ 'dismissed' => true ] );
	}

	/**
	 * What a remembered notice is drawn with, for the dismiss route to check.
	 *
	 * @param string $context The context.
	 * @param string $key     The notice's key.
	 * @param int    $user_id Who it was drawn for.
	 *
	 * @return string
	 */
	private static function token( string $context, string $key, int $user_id ): string {
		return wp_hash( 'dismiss_notice|' . $context . '|' . $key . '|' . $user_id, 'nonce' );
	}

	/**
	 * Remember that the current user dismissed a notice.
	 *
	 * @param string $context The context.
	 * @param string $key     The notice's key.
	 *
	 * @return bool False for a notice nobody registered, or nobody signed in.
	 */
	public static function dismiss( string $context, string $key ): bool {
		$context = sanitize_key( $context );
		$key     = sanitize_key( $key );
		$user_id = get_current_user_id();

		// A registered key only. Otherwise anyone signed in can write
		// unbounded rows of user meta by asking to dismiss notices that do
		// not exist.
		if ( '' === $key || 0 === $user_id || ! isset( self::$notices[ $context ][ $key ] ) ) {
			return false;
		}

		// A triggered notice is not remembered; see applies().
		if ( self::$notices[ $context ][ $key ]['persistent'] ) {
			update_user_meta( $user_id, self::meta_key( $context, $key ), time() );
		}

		return true;
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
	 * hears the click core already fires and sends it to the dismiss route
	 * with apiFetch, after core's own script.
	 *
	 * @param string $context The context.
	 *
	 * @return void
	 */
	private static function script( string $context ): void {
		$script = sprintf(
			'document.addEventListener("click",function(e){' .
			'var b=e.target.closest(".notice-dismiss");if(!b)return;' .
			'var n=b.closest("[data-notice-token]");if(!n||n.dataset.noticeContext!==%s)return;' .
			'wp.apiFetch({path:%s,method:"POST",data:{context:n.dataset.noticeContext,key:n.dataset.noticeKey,token:n.dataset.noticeToken}}).catch(function(){});});',
			wp_json_encode( $context ),
			wp_json_encode( '/' . Runtime::rest_namespace() . '/dismiss' )
		);

		wp_enqueue_script( 'wp-api-fetch' );
		wp_add_inline_script( 'wp-api-fetch', $script );
	}
}
