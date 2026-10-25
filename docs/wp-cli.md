# WP-CLI

Nobody needs WP-CLI to use this plugin: everything in [Getting started](getting-started.md) works
through the dashboard and FileZilla. These commands are for hosting providers and developers who
manage sites from the command line.

| Command | What it does |
|---------|--------------|
| `wp easy-apple-icon status` | Shows whether the plugin is on, the source image, each icon file and the last error. Changes nothing |
| `wp easy-apple-icon regenerate` | Deletes the icon files the plugin made, then makes `apple-touch-icon.png` and `apple-touch-icon-precomposed.png` straight away |
| `wp easy-apple-icon remove` | Deletes the icon files the plugin made and forgets everything it recorded |

Each command exits non-zero when it fails.

## status

```
$ wp easy-apple-icon status
Enabled:      yes
Source:       /var/www/example/web/wp-content/uploads/cropped-icon.png (Site Icon, attachment 42)
Site root:    /var/www/example/web/
Static files: enabled
+----------------------------------+--------+---------+-------+-------------------------+
| file                             | status | size    | bytes | written                 |
+----------------------------------+--------+---------+-------+-------------------------+
| apple-touch-icon.png             | ours   | 180x180 | 11447 | 2026-10-25 08:54:12 UTC |
| apple-touch-icon-precomposed.png | ours   | 180x180 | 11447 | 2026-10-25 08:54:12 UTC |
+----------------------------------+--------+---------+-------+-------------------------+
Last error:   none
```

Each file's status is one of:

| Status | Meaning |
|--------|---------|
| `ours` | The plugin made it, from the current source image |
| `ours (stale)` | The plugin made it, but the source image has changed since. Run `regenerate` |
| `foreign` | The plugin didn't make it. It is never changed or deleted |
| `missing` | No file yet. It's made the first time an Apple device asks for it |

The last error is the most recent thing that went wrong, such as an image that couldn't be
converted or a file that couldn't be written. The same errors go to the PHP error log, prefixed
`easy-apple-icon:`.

## regenerate

A head start, not a requirement: without it, each file is made the first time an Apple device asks
for it. Run it after changing the image supplied by the `easy_apple_icon_source_file` filter
([Hooks and filters](hooks-and-filters.md#using-a-different-image)), or to fix files that
`status` shows as `ours (stale)`.

It refuses, changing nothing, when the plugin is switched off, when static files are turned off,
on multisite, and when the site root can't be written.

## remove

Deletes the plugin's icon files and its record of them. Run it before deleting a must-use or theme
copy of the plugin, in place of deleting the files by hand
([Removing it](getting-started.md#removing-it)). It works when the plugin is switched off.

A normal plugin doesn't need it: deactivating does the same.

## Running WP-CLI commands: use the right user

The plugin writes small PNG files into your site's root directory, the one your home page is
served from (usually the one containing `wp-config.php`). Normally the website itself does this
the first time an Apple device asks for an icon, and you never need to think about it.

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
