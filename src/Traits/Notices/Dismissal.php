<?php
/**
 * A plugin's admin notices, declared once and shown when they apply.: Dismissal
 *
 * @package   SugarCommerce\Notices
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Notices\Traits\Notices;

/**
 * Dismissing a notice for good, per user, and the script that sends it.
 *
 * Part of Notices: only the class uses it.
 */
trait Dismissal {

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
