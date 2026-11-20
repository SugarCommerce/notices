# Notices

Declare a plugin's admin notices once, including the ones that have to
survive a redirect.

## What it does

An admin notice is easy until it needs to appear after a save. Then it is a
query argument, or a transient, or a flag in user meta -- and a dismissal
that has to be remembered per user, and a condition that decides whether it
is shown at all.

This is the declaration. You describe the notices once; showing, dismissing
and remembering are handled. Each notice is printed by core's
`wp_admin_notice()`, so it has core's markup and escaping and another plugin's
`wp_admin_notice_args` / `_markup` filters reach it; remembering a dismissal
and flashing a message across a redirect are what this adds.

## Features

* Declare a notice with its message, type and when it should appear
* Show one after a redirect, without inventing a transient for it
* Tell the next page something only known now -- "3 orders refunded." -- once
* Let a user dismiss a notice and have it stay dismissed, for that user
* Show a notice only while a condition holds -- no API key set, say
* Bring a dismissed notice back, when whatever it warned about returns
* Keep notices out of screens they have nothing to do with

## Installation

```bash
composer require sugarcommerce/notices
```

## Quick start

```php
use SugarCommerce\Notices\Notices;

Notices::boot(); // the dismiss route, on every request

add_action( 'admin_init', function () {
	Notices::register( 'myplugin', [
		'settings_saved' => [
			'message' => __( 'Settings saved.', 'my-plugin' ),
			'type'    => 'success',
		],
		'needs_api_key'  => [
			'message'    => __( 'Add an API key to start syncing.', 'my-plugin' ),
			'type'       => 'warning',
			'persistent' => true,
			'condition'  => fn(): bool => ! get_option( 'myplugin_api_key' ),
		],
	] );
} );
```

Showing one after a save is the part that is usually fiddly:

```php
wp_safe_redirect( Notices::url( 'myplugin', 'settings_saved' ) );
exit;
```

## Two kinds of notice

A **triggered** notice (the default) is the message about something that
just happened. It waits for `?myplugin_notice=settings_saved`, which
`Notices::url()` adds, and the argument is taken back out of the address bar
once the page has loaded, so a refresh does not announce the save twice. Its
X hides it for now; it is not remembered, so the next save confirms itself
again.

A **persistent** notice is a condition that is true until it is not. It
shows whenever its `capability`, `screens` and `condition` all allow, until
the user dismisses it -- and that is remembered, per user, in user meta
`_dismissed_notice_{context}_{key}`. The X sends the dismissal to a REST
route with core's apiFetch; notices declared on `admin_init` are never
declared on a REST request, so call `Notices::boot()` once where the plugin
loads for the route to answer there.

| Key | Default | |
|---|---|---|
| `message` | (required) | HTML, through `wp_kses_post()` |
| `type` | `info` | `success`, `warning`, `error` or `info`; anything else is `info`, never guessed from the key |
| `dismissible` | `true` | whether it has an X |
| `persistent` | `false` | shown unprompted, and remembered as dismissed |
| `capability` | `manage_options` | who sees it |
| `screens` | everywhere | screen ids it is limited to |
| `condition` | none | a callable that must return true |

```php
Notices::dismissed( 'myplugin', 'needs_api_key' );            // has this user closed it?
Notices::undismiss( 'myplugin', 'needs_api_key' );            // show it to them again
Notices::undismiss( 'myplugin', 'needs_api_key', $user_id );  // or to someone else
Notices::all( 'myplugin' );                                   // what is registered
```

## A message only known now

Some messages carry what happened -- a count, a name -- which a declared
notice cannot. Put those words in the URL and anybody can link to a page that
says "Payment refunded". So they wait in a transient for that user instead,
for a minute at most, and show on their next admin page, once:

```php
Notices::flash( 'myplugin', sprintf( __( '%d orders refunded.', 'my-plugin' ), $count ) );
Notices::flash( 'myplugin', __( 'Two orders could not be refunded.', 'my-plugin' ), 'error' );

wp_safe_redirect( $back );
exit;
```

Flashed messages show above the declared notices, in the order they were
left, ten at most.

## Two plugins on one site

Everything is named from the context: the trigger argument
(`{context}_notice`), the user meta and the transient; the dismiss route is
named from the copy's own namespace (`sugarcommerce-notices/v1/dismiss`, with
a prefix in a bundled build). Two plugins that each bundle this library and each
have a `settings_saved` never show each other's.

## Security

* Forged dismissals: dismissing goes through a REST route for signed-in
  users, sent with core's `wp_rest` nonce, and only with the token the
  notice was drawn with for that user, checked with `hash_equals()` -- so no
  request writes meta for a notice it was never shown.
* Script in a message: every message goes through `wp_admin_notice()`, which
  runs it through `wp_kses_post()`; the dismiss script's context is a key.
* Showing a notice by address: the trigger argument can only pick a notice
  that was declared for it.
* Another user's flash: a flashed message is kept per user, a minute at most
  and ten at once.

## Layout

* `src/Notices.php`: registering notices and the entry points, with its jobs
  in `src/Traits/Notices/` (flashing, dismissing, which apply and printing).

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
