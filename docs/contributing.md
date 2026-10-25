# Contributing

Bug reports, ideas and pull requests are welcome, on
[GitHub](https://github.com/headwalluk/easy-apple-icon/issues).

## Reporting a bug

Open an issue with:

- What you expected, and what happened instead
- Your WordPress and PHP versions, and your web server (Apache, nginx, LiteSpeed)
- How the plugin is installed: must-use plugin, normal plugin, or in a theme
- The address requested, and the response headers if you can get them
  (`curl -I https://your-site.example/apple-touch-icon.png`)
- Any lines in the PHP error log that start with `easy-apple-icon:`
- If you use WP-CLI, the output of `wp easy-apple-icon status`

Remove anything you'd rather not publish, such as your domain or server paths: the issue is public.

If you think you've found a security problem, such as a way to make the plugin write a file it
shouldn't, don't open a public issue. Email the maintainer through
[Headwall Hosting](https://headwall-hosting.com/) instead.

## Suggesting an improvement

Open an issue describing the problem you'd like solved before writing code, so we can agree on
the approach first.

The plugin deliberately does one thing. These are outside its scope:

- `/favicon.ico` (WordPress handles it), web app manifests and Android icons
- Adding `<link>` tags to pages (WordPress does it when a Site Icon is set)
- Writing `.htaccess` or nginx rules
- A settings page or admin notices

Filling transparent areas with a background colour (iPhones show them as black) is a candidate for
a future version.

## Sending a pull request

The rules that matter most:

- **One file.** `easy-apple-icon.php` is the whole plugin, and must work as a must-use plugin, a
  normal plugin, or included from a theme. No `includes/` folder, no Composer, no build step
- **PHP 8.2 and WordPress 6.2.** Don't use syntax from PHP 8.3 or later, and don't add
  `declare(strict_types=1)`
- **Coding standards.** `phpcs` must report no errors and no warnings, using the repository's
  `phpcs.xml` (WordPress Coding Standards 3)
- **Don't break existing sites.** The filter names, the `EASY_APPLE_ICON_STATIC_FILES` constant,
  the `easy_apple_icon_state` option and its format, and the WP-CLI commands are public. Add new
  ones; don't rename or change the existing ones
- **Never touch a file the plugin didn't write.** See [How it works](#how-it-works)

Code style, beyond what `phpcs` checks:

- One `return`, at the end of a method, except for an early guard at the top. Never `return`
  from inside a loop
- `if` / `elseif` chains end in a plain `else`. A branch that does nothing still gets a one-line
  comment saying why
- Hook callbacks are named methods, never closures, so anyone can unhook them with
  `remove_action()`
- Every error is logged with `error_log()` and recorded for `wp easy-apple-icon status`. Never an
  empty `catch`, and never an error that only shows up with `WP_DEBUG` on
- Check what every filter returns, and fall back to the default when it's unusable

There's no unit-test suite. Test against a real WordPress site with `curl` and WP-CLI, and test
what happens when things go wrong (no Site Icon, an unwritable site root, a file the plugin didn't
write) as well as when they go right. Say in the pull request what you tested and how.

Commit messages start with a type: `feat:`, `fix:`, `docs:`, `refactor:`, `chore:` or `test:`.

## How it works

Notes for anyone changing the code.

**Requests.** Every request passes one string comparison on the request path, when the file
loads. Only a match registers the `parse_request` callback, so ordinary requests cost nothing
else: no option reads, no filters. The full match is a strict allow-list, and the filename written
is rebuilt from the parsed size, never copied from the request.

**Writing files.** Each PNG is written to a temporary file in the site root, then moved into
place, so the web server never sends half a file. A new file is placed with `link()`, which fails
if another file has appeared there in the meantime.

**Ownership.** The plugin records the SHA-256 of every file it writes. A file is the plugin's only
if its hash matches the record. A symlink, or any file that doesn't match, is someone else's, and
is never overwritten or deleted.

**State.** The records live in the `easy_apple_icon_state` option. Apple devices ask for several
icons at once, so the option is read and written with direct database queries under a file lock.
That way an object cache can't hand back a stale copy and lose a record. A counter in the state
goes up each time the files are deleted, which stops a request still rendering the old Site Icon
from writing it back.

**The site root.** This is the directory that serves the home URL. It's `ABSPATH`, less the
WordPress subdirectory for "WordPress in its own directory" installs. WordPress's
`get_home_path()` isn't used, because it reads `SCRIPT_FILENAME` and so is only right in wp-admin:
on the front end it can return `/`, and under WP-CLI it returns WP-CLI's own directory. When the
layout doesn't match, the root is unknown and the plugin writes nothing.

**Paths across a chroot.** The source image path is recorded relative to the site root, so a
PHP-FPM pool jailed in a chroot and WP-CLI outside it record the same value.
