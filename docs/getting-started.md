# Getting started

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

Easy Apple Icon answers those requests with your own Site Icon (**Appearance > Customize > Site
Identity**), converted to a PNG at the right size. The first time each address is asked for, it
also saves the PNG in your site's root directory, so from then on the web server sends the file
directly and WordPress isn't involved at all.

## Before you start

- Set a Site Icon under **Appearance > Customize > Site Identity**. Without one, the plugin has
  nothing to send
- Use a Site Icon with a solid background. iPhones show transparent areas as black corners on the
  home screen

## Installing

Download
[`easy-apple-icon.zip`](https://github.com/headwalluk/easy-apple-icon/releases/latest/download/easy-apple-icon.zip),
the latest release. Inside is a folder, `easy-apple-icon`, holding the whole plugin: one file,
`easy-apple-icon.php`, and its licence.

Install it in **one** of these places. It works the same from each.

### As a must-use plugin (easiest)

Unzip the download, then upload `easy-apple-icon.php` (the file, not the folder) into
`wp-content/mu-plugins/` with FileZilla or your hosting control panel's file manager. If there's
no `mu-plugins` folder inside `wp-content`, create it.

That's all. Must-use plugins are always on, with nothing to activate, and can't be switched off
from the dashboard. That makes this a good choice when you look after a site for a client. They
appear under **Plugins > Must-Use**.

### As a normal plugin

Go to **Plugins > Add New Plugin > Upload Plugin**, choose `easy-apple-icon.zip`, then
**Install Now** and **Activate**.

### Inside your theme

Unzip the download, then upload `easy-apple-icon.php` into your child theme's folder, for example
`wp-content/themes/my-theme/easy-apple-icon.php`, and add this line to the theme's
`functions.php`:

```php
require_once __DIR__ . '/easy-apple-icon.php';
```

Use a child theme rather than editing a theme you downloaded: a theme update would remove the
file and the line.

### More than one copy

It's safe to have more than one copy installed, for example a must-use copy and a theme copy. The
first one WordPress loads does the work, and the others do nothing.

## Checking it works

Visit `https://your-site.example/apple-touch-icon.png`. You should see your Site Icon.

Then look in your site's root directory, the folder that holds `wp-config.php`. There should now
be a file called `apple-touch-icon.png`. More files appear over the following days, one for each
icon size Apple devices ask for.

## Changing your Site Icon

Nothing to do. When you change or remove the Site Icon, the plugin deletes the icon files it
saved, and makes new ones from the new icon as Apple devices ask for them.

Apple devices and browsers keep a copy of the icon for a while, so the old one can take a few
days to disappear from a home screen that already had it.

## Removing it

The plugin saves its icons as ordinary files in your site's root directory. How you remove them
depends on how you installed it.

**A normal plugin:** deactivate it under **Plugins**. It deletes its icon files for you. Then
delete the plugin as usual.

**A must-use plugin or a theme copy:** these can't clean up after themselves, so it takes two
steps.

1. Remove the code. For a must-use plugin, delete `wp-content/mu-plugins/easy-apple-icon.php`.
   For a theme copy, **first** delete the `require_once` line from `functions.php`, **then**
   delete `easy-apple-icon.php`. In the other order, your site shows an error until the line is
   gone
2. Delete the icon files. Connect with FileZilla (or open your hosting control panel's file
   manager), go to the folder that holds `wp-config.php`, and delete every file whose name starts
   with `apple-touch-icon` and ends with `.png`:

   ```
   apple-touch-icon.png
   apple-touch-icon-precomposed.png
   apple-touch-icon-120x120.png
   apple-touch-icon-152x152.png
   ...
   ```

   If you had your own `apple-touch-icon.png` before you installed the plugin, keep that one. The
   plugin never changes or deletes a file it didn't make

If you skip step 2, nothing breaks. Apple devices go on getting the saved icons, but they no
longer change when you change your Site Icon.

Hosting providers can run `wp easy-apple-icon remove` in place of step 2: see [WP-CLI](wp-cli.md).

If the plugin is bundled in something you can't remove, you can switch it off instead: see
[Hooks and filters](hooks-and-filters.md#switching-it-off).

## Things to know

- **Your own icon files are safe.** If you already have an `apple-touch-icon.png`, the plugin
  leaves it alone and doesn't replace it
- **Multisite:** icons are served, but no files are saved, because every site in the network
  shares one root directory and one site's file would answer for all of them
- **Home URL with a path** (`https://example.com/blog/`): the plugin does nothing. Apple devices
  ask at the domain root, which this WordPress doesn't serve. A WordPress whose *files* are in a
  subdirectory but whose home URL is the domain root ("WordPress in its own directory") works
  normally, as long as the folder name matches the address (files in `/wp/`, Site Address
  ending `/wp`). If it doesn't, icons are served but no files are saved
- **Site Icon changed directly in the database:** the plugin learns about Site Icon changes from
  WordPress, so a change made with SQL isn't noticed. Delete the old icon files as in step 2 of
  [Removing it](#removing-it), and new ones are made from the new icon
- **No Site Icon:** the plugin answers with an empty "not found" (no theme page) rather than
  falling back to the WordPress logo, which would look wrong on a home screen
