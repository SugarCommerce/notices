<?php
/**
 * A plugin's admin notices, declared once and shown when they apply.: Rendering
 *
 * @package   SugarCommerce\Notices
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Notices\Traits\Notices;

/**
 * Which notices apply on this screen, and printing them.
 *
 * Part of Notices: only the class uses it.
 */
trait Rendering {

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

			$remembered = (bool) $config['persistent'] && (bool) $config['dismissible'];

			self::print(
				$context,
				$key,
				(string) $config['type'],
				(bool) $config['dismissible'],
				(string) $config['message'],
				$remembered && 0 !== $user_id ? self::token( $context, $key, $user_id ) : ''
			);

			$remember = $remember || $remembered;
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
	 * @param string $token       What the dismiss route asks for, for a
	 *                            dismissal that is remembered.
	 *
	 * @return void
	 */
	private static function print( string $context, string $key, string $type, bool $dismissible, string $message, string $token = '' ): void {
		$attributes = array( 'data-notice-context' => $context );

		if ( '' !== $key ) {
			$attributes['data-notice-key'] = $key;
		}

		if ( '' !== $token ) {
			$attributes['data-notice-token'] = $token;
		}

		// Core's markup, escaping and wp_kses_post(), and its
		// wp_admin_notice_args / _markup filters. What it lacks -- a
		// dismissal remembered, a message flashed across a redirect -- is
		// what the rest of this class adds.
		wp_admin_notice(
			$message,
			array(
				'type'        => $type,
				'dismissible' => $dismissible,
				'attributes'  => $attributes,
			)
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
}
