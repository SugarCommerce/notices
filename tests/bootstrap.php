<?php
/**
 * PHPUnit bootstrap.
 *
 * @package SugarCommerce\Notices
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['notices_hooks'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['notices_hooks'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'wp_print_inline_script_tag' ) ) {
	function wp_print_inline_script_tag( $data, $attributes = [] ) {
		echo '<script>' . $data . '</script>';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) {
		return strip_tags( (string) $text, '<div><p><a><strong><em><code><br>' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value = '', $url = '' ) {
		$url = (string) $url;

		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . rawurlencode( (string) $value );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'nonce-' . $action;
	}
}

/*
 * Who the current user is and what they may do. Null caps means everything,
 * so a test that is not about permissions does not have to care.
 */
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['notices_user'] ?? 1 );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		$allowed = $GLOBALS['notices_caps'] ?? null;

		return null === $allowed || in_array( $capability, (array) $allowed, true );
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key = '', $single = false ) {
		return $GLOBALS['notices_meta'][ $user_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value, $prev = '' ) {
		$GLOBALS['notices_meta'][ $user_id ][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $user_id, $key, $value = '' ) {
		$existed = isset( $GLOBALS['notices_meta'][ $user_id ][ $key ] );

		unset( $GLOBALS['notices_meta'][ $user_id ][ $key ] );

		return $existed;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['notices_transients'][ $key ][0] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['notices_transients'][ $key ] = [ $value, $expiration ];

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		$existed = isset( $GLOBALS['notices_transients'][ $key ] );

		unset( $GLOBALS['notices_transients'][ $key ] );

		return $existed;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $route_namespace, $route, $args = [] ) {
		$GLOBALS['notices_routes'][ $route_namespace . $route ] = $args;

		return true;
	}
}

if ( ! function_exists( 'wp_hash' ) ) {
	function wp_hash( $data, $scheme = 'auth' ) {
		return hash_hmac( 'md5', (string) $data, 'test-salt-' . $scheme );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return 0 !== (int) ( $GLOBALS['notices_user'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle ) {
		$GLOBALS['notices_scripts'][ $handle ] = $GLOBALS['notices_scripts'][ $handle ] ?? [];
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		$GLOBALS['notices_scripts'][ $handle ][] = $data;

		return true;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * The parameters of a request, which is all the route reads.
	 */
	class WP_REST_Request implements ArrayAccess {

		public function __construct( private array $params = [] ) {}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		public function offsetGet( $offset ): mixed {
			return $this->params[ $offset ] ?? null;
		}

		public function offsetSet( $offset, $value ): void {
			$this->params[ $offset ] = $value;
		}

		public function offsetUnset( $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {

		public function __construct( public mixed $data = null, public int $status = 200 ) {}

		public function get_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		public function __construct( public string $code = '', public string $message = '', public mixed $data = '' ) {}

		public function get_error_code() {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		return $GLOBALS['notices_screen'] ?? null;
	}
}

/**
 * Forget everything a previous test set up.
 *
 * @return void
 */
function notices_reset_globals(): void {
	$GLOBALS['notices_hooks']  = [];
	$GLOBALS['notices_meta']   = [];
	$GLOBALS['notices_screen'] = null;
	$GLOBALS['notices_user']   = 1;
	$GLOBALS['notices_caps']   = null;

	$GLOBALS['notices_transients'] = [];
	$GLOBALS['notices_routes']     = [];
	$GLOBALS['notices_scripts']    = [];

	$_GET  = [];
	$_POST = [];

	foreach ( [ 'notices', 'hooked' ] as $property ) {
		( new ReflectionProperty( 'SugarCommerce\Notices\Notices', $property ) )->setValue( null, [] );
	}

	( new ReflectionProperty( 'SugarCommerce\Notices\Notices', 'routed' ) )->setValue( null, false );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['notices_hooks'][ $hook ] ?? [] as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['notices_hooks'][ $hook ] ?? [] as $callback ) {
			$callback( ...$args );
		}
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, is_array( $args ) ? $args : array() );
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function_name, $message, $version ) {
		$GLOBALS['notices_doing_it_wrong'][] = $function_name;
	}
}

/*
 * Core's admin notice functions, verbatim from WordPress 7.1 (wp-includes/functions.php),
 * so a notice is tested against the markup core really prints.
 */
if ( ! function_exists( 'wp_get_admin_notice' ) ) {
function wp_get_admin_notice( $message, $args = array() ) {
	$defaults = array(
		'type'               => '',
		'dismissible'        => false,
		'id'                 => '',
		'additional_classes' => array(),
		'attributes'         => array(),
		'paragraph_wrap'     => true,
	);

	$args = wp_parse_args( $args, $defaults );

	/**
	 * Filters the arguments for an admin notice.
	 *
	 * @since 6.4.0
	 *
	 * @param array  $args    The arguments for the admin notice.
	 * @param string $message The message for the admin notice.
	 */
	$args       = apply_filters( 'wp_admin_notice_args', $args, $message );
	$id         = '';
	$classes    = 'notice';
	$attributes = '';

	if ( is_string( $args['id'] ) ) {
		$trimmed_id = trim( $args['id'] );

		if ( '' !== $trimmed_id ) {
			$id = 'id="' . $trimmed_id . '" ';
		}
	}

	if ( is_string( $args['type'] ) ) {
		$type = trim( $args['type'] );

		if ( str_contains( $type, ' ' ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				sprintf(
					/* translators: %s: The "type" key. */
					__( 'The %s key must be a string without spaces.' ),
					'<code>type</code>'
				),
				'6.4.0'
			);
		}

		if ( '' !== $type ) {
			$classes .= ' notice-' . $type;
		}
	}

	if ( true === $args['dismissible'] ) {
		$classes .= ' is-dismissible';
	}

	if ( is_array( $args['additional_classes'] ) && ! empty( $args['additional_classes'] ) ) {
		$classes .= ' ' . implode( ' ', $args['additional_classes'] );
	}

	if ( is_array( $args['attributes'] ) && ! empty( $args['attributes'] ) ) {
		$attributes = '';
		foreach ( $args['attributes'] as $attr => $val ) {
			if ( is_bool( $val ) ) {
				$attributes .= $val ? ' ' . $attr : '';
			} elseif ( is_int( $attr ) ) {
				$attributes .= ' ' . esc_attr( trim( $val ) );
			} elseif ( $val ) {
				$attributes .= ' ' . $attr . '="' . esc_attr( trim( $val ) ) . '"';
			}
		}
	}

	if ( false !== $args['paragraph_wrap'] ) {
		$message = "<p>$message</p>";
	}

	$markup = sprintf( '<div %1$sclass="%2$s"%3$s>%4$s</div>', $id, $classes, $attributes, $message );

	/**
	 * Filters the markup for an admin notice.
	 *
	 * @since 6.4.0
	 *
	 * @param string $markup  The HTML markup for the admin notice.
	 * @param string $message The message for the admin notice.
	 * @param array  $args    The arguments for the admin notice.
	 */
	return apply_filters( 'wp_admin_notice_markup', $markup, $message, $args );
}

function wp_admin_notice( $message, $args = array() ) {
	/**
	 * Fires before an admin notice is output.
	 *
	 * @since 6.4.0
	 *
	 * @param string $message The message for the admin notice.
	 * @param array  $args    The arguments for the admin notice.
	 */
	do_action( 'wp_admin_notice', $message, $args );

	echo wp_kses_post( wp_get_admin_notice( $message, $args ) );
}
}
