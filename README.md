# Easy Apple Icon

Stops the flood of `apple-touch-icon.png` 404s in your WordPress access log, by serving your
site's own Site Icon at the paths Apple devices ask for.

## Why you see so many apple-touch-icon 404s

iPhones, iPads and Macs ask every website for an icon at a fixed address:
`/apple-touch-icon.png`, `/apple-touch-icon-precomposed.png`, and sized versions such as
`/apple-touch-icon-120x120.png`. They ask whenever someone shares a link in Messages, opens the
share sheet or saves the site to their home screen, and they ask **even when your pages already
say where the icon is**. The `<link rel="apple-touch-icon">` tag doesn't stop them; WordPress
already outputs it whenever a Site Icon is set.

WordPress has no file at those addresses, so each request runs the whole of WordPress and renders
your theme's full "page not found" page, usually a few hundred kilobytes, for an icon nobody will
see. On a busy site that adds up to hundreds of wasted page renders a day.

This plugin answers those requests with your own Site Icon (**Appearance > Customize > Site
Identity**), converted to a PNG at the right size. The first time each address is asked for, it
also saves the PNG in your site's root directory, so from then on the web server sends the file
directly and WordPress isn't involved at all.

There's nothing to configure. Set a Site Icon and install the plugin.

## Installing

The plugin is one file, `easy-apple-icon.php`. Put it in **one** of these places. It works the
same from each.

**As a normal plugin.** Create the folder `wp-content/plugins/easy-apple-icon/`, put the file in
it, then activate **Easy Apple Icon** under **Plugins**. Deactivating it later deletes the icon
files it made.

**As a must-use plugin.** Put the file straight into `wp-content/mu-plugins/` (create the folder
if it isn't there). Must-use plugins are always on and can't be deactivated from the dashboard,
which makes this a good choice when you look after a site for a client.

**Inside your theme.** Put the file in your (child) theme's folder, for example
`wp-content/themes/my-theme/easy-apple-icon.php`, and add this line to the theme's
`functions.php`:

```php
require_once __DIR__ . '/easy-apple-icon.php';
```

It's safe to have more than one copy installed, for example a must-use copy and a theme copy. The
first one WordPress loads does the work, and the others do nothing.

To uninstall a must-use or theme copy, first run `wp easy-apple-icon remove` (see below) so the
icon files it made are deleted, then delete the file.

### Checking it works

Visit `https://your-site.example/apple-touch-icon.png`. You should see your Site Icon. Then look
in your site's root directory: there should now be a file called `apple-touch-icon.png`.

With WP-CLI, `wp easy-apple-icon status` shows which source image is being used, each icon file
and whether the plugin made it, and the last error, if there was one.

| Command | What it does |
|---------|--------------|
| `wp easy-apple-icon status` | Shows the source image, each icon file (`ours`, `ours (stale)`, `foreign` or `missing`) and the last error |
| `wp easy-apple-icon regenerate` | Deletes the icon files the plugin made, then makes `apple-touch-icon.png` and `apple-touch-icon-precomposed.png` straight away |
| `wp easy-apple-icon remove` | Deletes the icon files the plugin made and forgets everything it recorded |

The plugin never touches an icon file it didn't make. If you already had your own
`apple-touch-icon.png`, it's left alone and `status` lists it as `foreign`.

## Running WP-CLI commands: use the right user

The plugin writes small PNG files into your site's root directory, the one your home page is
served from (usually the one containing `wp-config.php`). Normally the website itself does this the first time an Apple device asks for
an icon, and you never need to think about it.

The WP-CLI commands (`wp easy-apple-icon regenerate` and `remove`) write and delete the same
files. **They only work when you run them as the user that owns your site's files.** On many
servers, that user isn't the one you log in as.

### How to tell

Look at who owns `wp-config.php`:

```bash
ls -l /path/to/your/site/wp-config.php
```

```
-rw-r----- 1 www-example www-data 3247 Oct 20 09:14 wp-config.php
             ^^^^^^^^^^^
             this is the user to run WP-CLI as
```

If that's the user you're logged in as, you're fine: run `wp` as normal. This is usual on shared
hosting and most control panels.

If it's someone else (`www-data`, `apache`, `nginx`, or a per-site user like `www-example`), run
`wp` as that user.

### Running as the site's user

Either run a single command as that user:

```bash
sudo -u www-example wp --path=/path/to/your/site easy-apple-icon regenerate
```

Or switch to that user first, then work as normal:

```bash
sudo -iu www-example
cd /path/to/your/site
wp easy-apple-icon regenerate
```

Both need `sudo` rights on the server. If you don't have them, ask your host to run the command,
or skip it: the plugin creates the files itself the first time an Apple device visits, so
`regenerate` is only ever a head start.

### What goes wrong if you don't

- **As your own user:** the command can't write to the site root and stops with an error that
  names the directory, the user you ran as, and the user that owns it, and gives the command to
  run instead. Nothing is changed. The website keeps serving icons, because it does have
  permission:

  ```
  Error: Cannot write to the site root, /var/www/example/web/
  You are running as alice; the directory is owned by www-example.
  Run the command as the owner instead:
    sudo -u www-example wp --path=/var/www/example/web easy-apple-icon regenerate
  If that user is jailed (chroot), use the site path as that user sees it.
  Nothing was changed.
  ```

  On some servers each site's user is "jailed" and sees the site at a different path, such as
  `/web` instead of `/var/www/example/web`. The plugin can't tell, so if the suggested command
  says the path doesn't exist, ask your host which path that user sees
- **Changing the Site Icon with `wp option update site_icon` as your own user:** the change
  works, but the old icon files can't be deleted, so the web server keeps sending the old icon.
  `status` shows them as `ours (stale)`. Run `regenerate` as the right user to fix it
- **As `root`, or with `--allow-root`:** don't. WP-CLI runs every plugin's code with full root
  rights, and any files created can end up owned by root, which the website may not be able to
  manage later

## Configuration

None is needed. For developers, there is one constant and three filters.

| Name | Type | Default | What it does |
|------|------|---------|--------------|
| `EASY_APPLE_ICON_STATIC_FILES` | constant | `true` | Set to `false` to stop the plugin saving icon files. Every request is then answered by PHP |
| `easy_apple_icon_source_file` | filter | the Site Icon's file | Use a different image, such as an icon your theme ships |
| `easy_apple_icon_sizes` | filter | `57, 60, 72, 76, 114, 120, 144, 152, 167, 180` | The sizes answered at `-NNxNN` addresses |
| `easy_apple_icon_cache_max_age` | filter | `604800` (a week) | How long browsers may cache an icon, in seconds |

Turn off static files in `wp-config.php`:

```php
define( 'EASY_APPLE_ICON_STATIC_FILES', false );
```

Use an icon from your theme instead of the Site Icon. The source can be a JPEG, PNG or WebP of
any size; it's cropped to a square from the centre and resized:

```php
add_filter( 'easy_apple_icon_source_file', 'my_theme_apple_icon_source' );

function my_theme_apple_icon_source( string $path ): string {
	return get_stylesheet_directory() . '/images/apple-icon.png';
}
```

The filter's second argument is the Site Icon's attachment ID (`0` when none is set). If a filter
returns something unusable (a file that doesn't exist, a size outside 16 to 1024, a negative
max-age), the plugin uses its default instead.

After changing the source filter, run `wp easy-apple-icon regenerate`. The plugin can't see when a
filtered file changes, but `status` reports the icon files as `ours (stale)` when it has.

## Limitations

- **Multisite:** icons are served, but no files are saved, because every site in the network
  shares one root directory and one site's file would answer for all of them
- **Home URL with a path** (`https://example.com/blog/`): the plugin does nothing. Apple devices
  ask at the domain root, which this WordPress doesn't serve. A WordPress whose *files* are in a
  subdirectory but whose home URL is the domain root ("WordPress in its own directory") works
  normally, as long as the folder name matches the address (files in `/wp/`, Site Address
  ending `/wp`). If it doesn't, icons are served but no files are saved, and `status` says why
- **Site Icon changed directly in the database:** the plugin learns about Site Icon changes from
  WordPress, so a change made with SQL isn't noticed, and the old icon files stay until you run
  `wp easy-apple-icon regenerate`
- **No Site Icon:** the plugin answers with an empty "not found" (no theme page) rather than
  falling back to the WordPress logo, which would look wrong on a home screen
- **Transparent icons** show black corners on iOS home screens. Use a Site Icon with a solid
  background
