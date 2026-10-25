# Hooks and filters

None of these are needed: the plugin works with no configuration. They're for developers who want
to change what it does from a theme, a plugin or `wp-config.php`.

| Name | Type | Default | What it does |
|------|------|---------|--------------|
| [`easy_apple_icon_enabled`](#switching-it-off) | filter | `true` | Return `false` to switch the plugin off |
| [`EASY_APPLE_ICON_STATIC_FILES`](#not-saving-files) | constant | `true` | Set to `false` to stop the plugin saving icon files |
| [`easy_apple_icon_source_file`](#using-a-different-image) | filter | the Site Icon's file | Use a different image |
| [`easy_apple_icon_sizes`](#choosing-the-sizes) | filter | `57, 60, 72, 76, 114, 120, 144, 152, 167, 180, 240` | The sizes answered at `-NNxNN` addresses |
| [`easy_apple_icon_cache_max_age`](#browser-caching) | filter | `604800` (a week) | How long browsers may cache an icon answered by PHP |

If a filter returns something unusable (a file that doesn't exist, a size outside 16 to 1024, a
negative max-age), the plugin uses its default instead.

The filters are read when an icon is requested, not when the plugin loads, so they work from a
theme's `functions.php` even when the plugin is a must-use plugin.

## Switching it off

```php
add_filter( 'easy_apple_icon_enabled', '__return_false' );
```

This is useful when the plugin is bundled in a must-use plugin you can't edit, such as one your
host installs. Once it's off, the plugin no longer answers icon requests or saves new files, and
WordPress handles those requests as if the plugin weren't installed.

Icon files it has already saved are still sent by the web server, because those requests never
reach WordPress. From then on they're the site owner's: the plugin no longer deletes them when the
Site Icon changes, so you can keep them, replace them with your own, or delete them as in step 2
of [Removing it](getting-started.md#removing-it).

If you switch it back on after changing the Site Icon, the saved files still show the old icon.
Delete them, or run `wp easy-apple-icon regenerate` ([WP-CLI](wp-cli.md)).
`wp easy-apple-icon status` lists them as `ours (stale)`.

## Not saving files

In `wp-config.php`:

```php
define( 'EASY_APPLE_ICON_STATIC_FILES', false );
```

The plugin still answers icon requests, but makes the PNG on every request and never writes to
the site root. Use this where nothing should write to the site's root directory, at the cost of a
PHP request for each icon.

## Using a different image

Use an icon your theme ships instead of the Site Icon. The source can be a JPEG, PNG or WebP of
any size; it's cropped to a square from the centre and resized:

```php
add_filter( 'easy_apple_icon_source_file', 'my_theme_apple_icon_source', 10, 2 );

function my_theme_apple_icon_source( string $path, int $site_icon_id ): string {
	return get_stylesheet_directory() . '/images/apple-icon.png';
}
```

The first argument is the Site Icon's full-size file (empty when none is set), and the second is
its attachment ID (`0` when none is set).

The plugin can't see when a filtered source file changes. After changing the image, delete the
saved icon files (step 2 of [Removing it](getting-started.md#removing-it)) or run
`wp easy-apple-icon regenerate` ([WP-CLI](wp-cli.md)).

## Choosing the sizes

The sizes answered at addresses like `/apple-touch-icon-120x120.png`. Each must be a whole number
from 16 to 1024; if any isn't, the default list is used. Any other address falls through to WordPress as normal, so this list is also
what limits which files the plugin can write.

```php
add_filter( 'easy_apple_icon_sizes', 'my_theme_apple_icon_sizes' );

function my_theme_apple_icon_sizes( array $sizes ): array {
	return [ 120, 152, 167, 180 ];
}
```

The plain names, `/apple-touch-icon.png` and `/apple-touch-icon-precomposed.png`, are always
answered, at 180 × 180.

## Browser caching

The `Cache-Control` max-age, in seconds, for an icon answered by PHP:

```php
add_filter( 'easy_apple_icon_cache_max_age', 'my_theme_apple_icon_max_age' );

function my_theme_apple_icon_max_age( int $max_age ): int {
	return DAY_IN_SECONDS;
}
```

Icons the plugin has saved are sent by the web server, with whatever caching headers it is set
up to add, so this applies only to the first request for each icon, and to every request when
[files aren't saved](#not-saving-files).
