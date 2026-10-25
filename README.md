# Easy Apple Icon

Stops the flood of `apple-touch-icon.png` 404s in your WordPress access log, by serving your
site's own Site Icon at the paths Apple devices ask for.

> **Status:** in development. Sections marked *TODO* are filled in as the plugin is built.

## Why you see so many apple-touch-icon 404s

*TODO: the problem, in plain words. See `docs/spec.md`, Problem.*

## Installing

*TODO: the three ways to install (plugin, must-use plugin, theme include).*

## Running WP-CLI commands: use the right user

The plugin writes small PNG files into your site's root directory, the one containing
`wp-config.php`. Normally the website itself does this the first time an Apple device asks for
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
  names the directory, the user you ran as, and the user that owns it. Nothing is changed. The
  website keeps serving icons, because it does have permission
- **As `root`, or with `--allow-root`:** don't. WP-CLI runs every plugin's code with full root
  rights, and any files created can end up owned by root, which the website may not be able to
  manage later

## Configuration

*TODO: constant and filters. See `docs/spec.md`, Configuration.*

## Limitations

*TODO: multisite, WordPress in a subdirectory, the direct-SQL Site Icon gap.*
