<?php
/**
 * A plugin's admin notices, declared once and shown when they apply.: Flash
 *
 * @package   SugarCommerce\Notices
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Notices\Traits\Notices;

/**
 * One-time notices for the user who caused them, shown on their next screen.
 *
 * Part of Notices: only the class uses it.
 */
trait Flash {

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
}
