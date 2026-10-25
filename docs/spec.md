# Easy Apple Icon: requirements specification

Draft 2, 2026-10-25.

## Problem

iOS and macOS clients request `/apple-touch-icon.png` and `/apple-touch-icon-precomposed.png`,
plus sized variants such as `/apple-touch-icon-120x120.png`, at the site root. They do this
**whether or not the page declares `<link rel="apple-touch-icon">`**. The main clients are
`NetworkingExtension` (link previews in Messages, the share sheet) and
`com.apple.WebKit.Networking`, which fetch the root path without reading the page's HTML.

WordPress has no file at those paths, so every probe falls through to WordPress and renders the
theme's full 404 page, often several hundred kilobytes, uncompressed, with a full PHP bootstrap.
On a busy site that is hundreds of wasted page renders a day. The common advice, "add the
`<link>` tag", doesn't help: WordPress core already outputs it whenever a Site Icon is set.

Core solves the equivalent problem for `/favicon.ico` (`do_favicon()`, WordPress 5.4). This
plugin does the same for Apple touch icons.

## Goal

Answer every Apple touch-icon probe with the site's own Site Icon as a PNG, then get PHP out of
the way: after the first request for each filename, the web server serves a static file.

## Requirements

### R1. Matching requests

Handle a request only when its path (query string ignored) matches exactly:

    ^/apple-touch-icon(-(SIZE)x(SIZE))?(-precomposed)?\.png$

- Both SIZE values must be equal, and in the allow-list: 57, 60, 72, 76, 114, 120, 144, 152, 167, 180
- The bare names (no size) produce 180×180
- Anything else, such as `-99999x99999` or `-120x152`, is not handled and falls through to
  WordPress as normal
- The allow-list is what stops arbitrary filenames being written to the site root. The filename
  written is rebuilt from the parsed size, never copied from the request

Every other request costs one cheap string check and nothing else (see CLAUDE.md, Performance).
The check runs once, when the file loads, on `$_SERVER['REQUEST_URI']`; the `parse_request`
callback is registered only when it matches.

Sizes returned by `easy_apple_icon_sizes` must be integers from 16 to 1024. If the filter
returns anything else, or an empty list, the default list is used.

### R2. Serving (dynamic path)

On a match, early in the request (`parse_request`, priority 0: after every load context has
registered its hooks, before canonical redirects and template loading):

- Source: the full-size file of the Site Icon attachment (`site_icon` option, via
  `get_attached_file()`), unless `easy_apple_icon_source_file` supplies another path
- Produce a PNG of the requested size with `wp_get_image_editor()`: resize, then save as
  `image/png`. The source may be JPEG, PNG or WebP. Never pass the source file through unconverted
- Respond `200`, `Content-Type: image/png`, `Content-Length`,
  `Cache-Control: public, max-age=<easy_apple_icon_cache_max_age, default 604800>`
- Support `HEAD` (headers only)
- `exit` without loading a template
- When R4 is about to write a file, send the response first (`fastcgi_finish_request()` where it
  exists), so the first visitor doesn't wait for the disk write

### R3. No Site Icon, or conversion failure

- **No Site Icon set** (and no filtered source): respond `404` with an empty body and
  `Cache-Control: public, max-age=3600`, then exit. No theme render. Do **not** fall back to the
  WordPress logo, as core's favicon handler does: it is wrong on a home screen
- **Image editor failure** (missing GD/Imagick, no WebP support, unreadable source): the same
  empty `404`, plus `log_error()`. Recorded once per distinct failure, not on every request

### R4. Static files (written lazily)

After R2 has served a request successfully, write the PNG to `get_home_path()` plus the filename,
so the web server serves it from then on. `get_home_path()` handles "WordPress in its own
directory" installs; load `wp-admin/includes/file.php` first if it isn't loaded.

Write rules (see also CLAUDE.md, Filesystem writes):

- Only allow-listed filenames (R1)
- Temporary file in the same directory, then `rename()`. Mode `0644`
- If the target exists, overwrite only when its sha256 matches the hash recorded in state. A
  symlink, or any other existing file, is foreign: leave it, record it once
- Directory not writable: keep serving dynamically, record it once

Do not write, and serve dynamically only, when:

- **Multisite.** Sites share a docroot, so one site's file would answer for all of them
- **`EASY_APPLE_ICON_STATIC_FILES` is `false`**

Do nothing at all (fall through to WordPress) when:

- **The home URL has a path component** (`https://example.com/blog/`). The probes go to the
  domain root, which this WordPress doesn't own

### R5. Keeping the files in step with the Site Icon

- Site Icon changed (`update_option_site_icon`, `add_option_site_icon`): delete every file the
  plugin wrote (hash-verified). The next probe regenerates it. These hooks also fire for
  `wp option update site_icon`
- Site Icon removed (`delete_option_site_icon`, or updated to `0`): delete the plugin's files
- `easy_apple_icon_source_file` filter in use: the plugin can't see when that file changes.
  Record the source path and its mtime with each written file. When `status` or `regenerate` runs
  and they differ, treat the files as stale
- Plugin context only: `register_deactivation_hook()` deletes the plugin's files and the state
  option
- **Delete fails** (most often a Site Icon change made by `wp` running as a user who can't write
  the site root): `log_error()`, keep the file records, and `status` reports those files as
  stale. `regenerate` run as the right user fixes them. An option update can't be refused
- **Known gap, document it in README:** a Site Icon changed by direct SQL is not noticed until
  `wp easy-apple-icon regenerate` runs

### R6. State

One option, `easy_apple_icon_state`, autoload **off** (only the probe path and the Site Icon hooks
read it):

```
files:      { "<filename>": { sha256, size, bytes, source_path, source_mtime, written_at } }
last_error: { message, at } | null
notices:    { foreign: [filenames], unwritable: bool }
```

`size` is the icon's width in pixels (e.g. `180`). `bytes` is the length of the file.

Write it only when something changes, never per request.

### R7. WP-CLI

`wp easy-apple-icon <subcommand>`:

- `status`: source file, each allow-listed bare name (ours / foreign / missing), every recorded
  file, last error
- `regenerate`: remove the plugin's files, then write the two bare names now (a warm start, so the
  first real probe doesn't hit PHP)
- `remove`: delete the plugin's files and clear state. This is the uninstall route for mu-plugin
  and theme installs

Each exits non-zero on failure. `status` is read-only: it reports stale files, and only
`regenerate` rewrites them.

**Wrong-user failures must be self-explanatory.** Running `wp` as a user who can't write to the
site root is the most likely mistake, so when `regenerate` or `remove` finds the site root
unwritable, the error names:

- the directory
- the user running the command (`posix_geteuid()` + `posix_getpwuid()`, when the POSIX extension
  is loaded; otherwise say it is unknown)
- the directory's owner (`fileowner()` + `posix_getpwuid()`)
- the command to run instead, e.g.
  `sudo -u www-example wp --path=/var/www/example/web easy-apple-icon regenerate`, using
  `ABSPATH` as the running process sees it, followed by one line: if that user is jailed
  (chroot), use the site path as that user sees it

Make no changes in that case. README.md, "Running WP-CLI commands", documents this for users;
keep the two in step.

## Plugin header

Author: Paul Faulkner. License: GPL-2.0-or-later. Plugin URI:
`https://headwall-hosting.com/plugins/easy-apple-icon/`. Requires PHP: 8.2. Requires at least: 6.2.
First version: 1.0.0.

## Configuration

| Name | Type | Default | Purpose |
|------|------|---------|---------|
| `EASY_APPLE_ICON_STATIC_FILES` | constant, bool | `true` | `false` disables R4 (serve dynamically only) |
| `easy_apple_icon_source_file` | filter, string | Site Icon full-size path | Use a different source, e.g. an icon the theme ships |
| `easy_apple_icon_sizes` | filter, int[] | R1 list | The size allow-list |
| `easy_apple_icon_cache_max_age` | filter, int | `604800` | `Cache-Control` max-age for a served icon |

No settings page, no admin notices, no admin menu entry.

## Out of scope (v1)

- `/favicon.ico` (core handles it), web manifests, Android icons
- Outputting `<link>` tags (core's `wp_site_icon()` does it)
- `.htaccess` or nginx rules
- Flattening transparency onto a background colour (iOS shows transparent areas as black).
  Candidate for v2
- Static files on multisite
- A built-in updater

## Acceptance tests

Run on a workbench site (see CLAUDE.md, Testing). Each test resets what it changed.

1. Site Icon set (JPEG): the first `curl -I /apple-touch-icon.png` returns `200 image/png` from
   PHP, and the file appears in the site root. The second request is served statically
2. Same for `-precomposed.png` and `-152x152.png`. `file` reports PNG at the right dimensions
3. `/apple-touch-icon-99999x99999.png` and `-120x152.png`: normal WordPress 404, nothing written
4. `HEAD` returns the same headers as `GET`, with no body
5. WebP Site Icon: valid PNG output
6. Change the Site Icon: the plugin's files are deleted, and the next probe serves the new icon
7. Remove the Site Icon: files deleted, then an empty `404` with no theme render
8. A pre-existing `apple-touch-icon.png` that the plugin didn't write: untouched after probes,
   `regenerate` and `remove`; `status` reports it as foreign
9. Unwritable site root: still `200` dynamically, with one recorded error, not one per request
10. `EASY_APPLE_ICON_STATIC_FILES = false`: `200` dynamically, nothing written
11. Loads and works from `plugins/`, `mu-plugins/` and a child theme. Included twice (mu-plugin
    plus plugin): no fatal, and hooks registered once
12. Plugin deactivation removes only the plugin's own files, and the state option
13. Home URL with a path component: probes fall through to WordPress, nothing written
14. `regenerate` and `remove` behave as R7, and exit non-zero on failure. Run as a user who can't
    write the site root: the error names the directory, both users and the corrected command,
    and nothing changes
15. Unrelated requests: no option reads before the path check (inspect with Query Monitor, or
    count `get_option` calls in a `WP_DEBUG` trace)
