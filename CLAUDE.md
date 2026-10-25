# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Easy Apple Icon is a single-file WordPress plugin that answers Apple touch-icon probes
(`/apple-touch-icon.png`, `/apple-touch-icon-precomposed.png` and sized variants) with the
site's own Site Icon as a PNG. Without it, each probe renders the theme's full 404 page. After
the first request for a given filename, the plugin writes that PNG into the site root so the web
server serves it statically, with no PHP.

**`docs/spec.md` is the requirements specification.** Read it before writing code. Where this
file and the spec disagree, stop and ask.

- **Namespace:** `Easy_Apple_Icon`
- **Constants:** `EASY_APPLE_ICON_*`. **Filters, options, CLI:** `easy_apple_icon_` / `easy-apple-icon`
- **PHP:** 8.2+, the oldest PHP branch still receiving security fixes. This is a policy, not a
  technical floor: a site on an end-of-life PHP needs its hosting fixed, not a compatibility
  shim. Use 8.2 syntax freely (`true` type, readonly classes, DNF types); never use 8.3+ syntax
  (typed class constants, `#[\Override]`, `json_validate()`). Do NOT use `declare(strict_types=1)`,
  which breaks WordPress interop
- **WordPress:** 6.2+
- **No build system:** no Composer, no npm, no dependencies, no assets

This plugin is published publicly on GitHub. Tracked files must contain no client names,
client URLs, fleet measurements or client data of any kind, in code, comments, docs, fixtures
or commit messages. `dev-notes/` is **private and untracked** (`.gitignore`) and is the home for
that material. Never copy from it into a tracked file without scrubbing it.

## The deliverable is one file

`easy-apple-icon.php` is the whole plugin. It must work, with zero configuration, from any of:

1. `wp-content/plugins/easy-apple-icon/easy-apple-icon.php` (a normal plugin)
2. `wp-content/mu-plugins/easy-apple-icon.php`
3. A theme directory, loaded with `require_once __DIR__ . '/easy-apple-icon.php';` from `functions.php`

It is also bundled inside another must-use plugin, so it must tolerate being **included twice**
(the bundled copy plus a standalone one). Never split it into an `includes/` tree or add a
`constants.php`. Constants and helpers live in the class.

### Repository layout

| Path | Purpose |
|------|---------|
| `easy-apple-icon.php` | The plugin: header, guard, one class |
| `docs/spec.md` | Requirements specification (tracked) |
| `docs/` | Operator and developer docs, one audience per file |
| `README.md` | GitHub landing page: the problem, install in the three contexts, configuration |
| `CHANGELOG.md` | Per-version release notes |
| `phpcs.xml` | WordPress Coding Standards config |
| `dev-notes/` | Private, untracked: evidence, measurements, scratch notes |

### Load contexts, and what differs between them

- **Load time.** mu-plugins load before plugins, and themes load after both (`functions.php`
  runs after `plugins_loaded`, before `init`). Register every hook from the file body at load
  time. Never assume `plugins_loaded` is still to come: in theme context it has already fired
- **Deactivation** exists only in plugin context. Register `register_deactivation_hook()` only
  when `__FILE__` is under `WP_PLUGIN_DIR` (compare with `wp_normalize_path()`). In mu-plugin and
  theme context, `wp easy-apple-icon remove` is the clean-up path
- **WP-CLI** loads all three contexts, so the command registers the same way everywhere, behind
  `defined( 'WP_CLI' ) && WP_CLI`

### Double-include guard: get this right

The pattern below is verified in wp-nag-cleanup on PHP 8.5. The obvious alternatives fail:

- An **unconditional top-level `class`** followed by a `return` guard does not work. PHP binds the
  class early when the file is compiled, so `class_exists()` is already true the *first* time the
  guard runs, the file returns, and the plugin never boots, silently
- An **unconditional top-level `function`** is fatal on the second include (`Cannot redeclare`)
  before any guard runs

So the guard **wraps** the declarations, and there are no top-level functions:

```php
namespace Easy_Apple_Icon;

defined( 'ABSPATH' ) || die();

if ( ! class_exists( __NAMESPACE__ . '\\Plugin' ) ) {
	class Plugin { /* ... */ }

	global $easy_apple_icon;

	$easy_apple_icon = new Plugin();
	$easy_apple_icon->run();
}
```

- Use `__NAMESPACE__ . '\\Plugin'`, never a hand-typed string. A typo makes the guard never match
- Check the spelling of `defined( 'ABSPATH' ) || die();`. A typo fails as a blank page on every request
- The instance is created **outside** the class. No static `boot()`, no "already booted" flag
  inside the class: the `class_exists` wrapper already makes the second include a no-op
- `global $easy_apple_icon;` is explicit because a theme or another plugin may include the file
  from inside a function, where a bare assignment would create a local. The instance has to stay
  reachable so `remove_action()` can name it
- A version constant goes inside the same wrapper, as a class constant (`Plugin::VERSION`), not a
  `define()`

## Testing

No unit-test framework, and none is wanted. Exercise behaviour against a workbench site with
`curl` and WP-CLI. The bench sites run on this host:

- `bench1.local`, `bench2.local`, `bench3.local` at `/var/www/benchN.local/web/`. Disposable:
  they can be reset at will. Use bench1 unless told otherwise; it already has a Site Icon set
- `wp-content/mu-plugins/` is writable by the `pfaulkner` user (group `www-data`). The **docroot
  is not**: PHP-FPM runs as the site user (`www-benchN`), which owns it. So a static file written
  by an HTTP request will succeed, while the same write from `wp` run as `pfaulkner` will hit the
  unwritable-docroot path. Both are real deployment cases; test both deliberately
- Never use `sudo`, and never write to any host other than this one. The maintainer runs WP-CLI
  as the site user, which is the command a real install needs. On bench1 the site user is jailed
  (chroot), so its paths differ from yours; give the commands in exactly this form:
  `sudo -u www-bench1 wp --path=/web/bench1.local easy-apple-icon <subcommand>`. Test the writable path over HTTP, and list the `sudo -u` commands
  that still need running in your report for the maintainer to run
- README.md is written for web designers who have never used a command line: everything they
  need (install, check, remove) works through the dashboard and SFTP/FTP. WP-CLI lives in its own
  section for hosting providers. Its "Running WP-CLI commands" subsection is for people who don't
  know Linux file permissions; keep it in step with the wrong-user error message (spec R7)

```bash
SITE_PATH=/var/www/bench1.local/web
curl -sI http://bench1.local/apple-touch-icon.png
wp --path="${SITE_PATH}" easy-apple-icon status
wp --path="${SITE_PATH}" option get easy_apple_icon_state --format=json
```

Two rules: reset the state you touch afterwards (delete the files and the state option, restore
the original Site Icon), and test the **failure** paths as well as the happy one. `docs/spec.md`
lists the acceptance tests; all of them must pass before a release.

Identifying a static response: anything WordPress serves carries headers the web server never
adds on its own, so compare the response headers from the first and second requests.

## Code Conventions

### Control flow

- **Single entry, single exit.** One `return`, at the bottom of the function. The only exception
  is an early `return` at the top of a function to short-circuit for **security or performance**:
  the request-path check that every request passes through is the main legitimate case
- **Never `return` from inside a loop.** Set the result, `break`, and return at the end
- **`if` / `elseif` / `else` enumerates the cases.** Test and handle each error or no-op case
  first, each in its own branch, and let the final `else` hold the success path. A branch that
  does nothing is still written out, with a one-line comment saying why. An `if` with one or more
  `elseif` branches always ends in a plain `else`, never an `elseif`. A lone `if` needs no `else`

```php
if ( ! $this->is_static_writing_enabled() ) {
	// Static files are switched off; serving dynamically is enough.
} elseif ( is_multisite() ) {
	// Sites share one docroot, so a file written here would serve every site's icon.
} elseif ( ! wp_is_writable( $directory ) ) {
	$this->record_error( 'docroot not writable' );
} else {
	$is_written = $this->write_icon_file( $file_name, $png_data );
}

return $is_written;
```

- `phpcs.xml` excludes only the `if`/`elseif`/`else` codes of `Generic.CodeAnalysis.EmptyStatement`,
  so comment-only branches pass. Empty `catch`, loop and `switch` bodies are still errors
- **Array callbacks are functions, not loops.** `array_map()` / `array_filter()` returning a value
  is fine. A `foreach` with `continue` is the loop form
- **No assignment inside a condition.** Assign on the line before
- **No unreachable `return`.** Nothing after `exit`, and no bare `return;` closing a `void` function

### PHP Style

- **Hook callbacks are named methods**, never closures. A closure can't be passed to
  `remove_action()`, so nobody debugging a site could unhook it
- **Type hints and return types** on every method and property. **Callbacks on hooks the plugin
  doesn't own take `mixed`** and check the value before use (`update_option_site_icon` passes
  `$old_value` and `$value`, which can be anything a caller stored)
- **Cast at the boundary.** `get_option()` returns strings, `false` or junk; cast at read time
  (`(int) get_option( 'site_icon', 0 )`)
- **Check what a filter returns.** Any callback can return the wrong type. Fall back to the
  default when a filter hands back something unusable, such as a non-array size list, a
  non-existent source path or a negative max-age
- **Constants for magic strings and numbers**, as class constants: option key, filter names,
  default sizes, cache lifetimes. A stored key's **value** is stored data and never changes
- **Boolean config** goes through `filter_var( $value, FILTER_VALIDATE_BOOLEAN )`
- **Dates are Unix timestamps** (`time()`)
- Every `phpcs:ignore` / `phpcs:disable` names the exact sniff and ends with `-- reason`

### Filesystem writes

This plugin writes into the site root, which no other plugin should be trusted to do carelessly.
These rules are not negotiable:

- Only filenames that match the allow-list in `docs/spec.md` R1, rebuilt from the parsed size,
  never copied from the request
- Write to a temporary file in the same directory, then `rename()`. Mode `0644`
- **Never overwrite or delete a file the plugin didn't write.** Ownership is the sha256 recorded
  in the state option. A symlink or a hash mismatch means foreign: leave it alone
- No `WP_Filesystem` (it may prompt for FTP credentials). Plain PHP file functions, each
  return value checked

### Logging and errors

- `log_error()`: genuine failures (image editor failure, failed write or rename). Logs
  **unconditionally** with `error_log()`, prefixed `easy-apple-icon:`, **and** records the failure
  in the state option, so it shows up in `wp easy-apple-icon status`
- `log()`: routine tracing. Only when `WP_DEBUG` is on
- An error that only appears under `WP_DEBUG` is a silent failure in production. Never an empty
  `catch`; every `catch` rethrows, or logs **and** records
- **No per-request log spam.** A condition that will repeat on every probe (unwritable docroot,
  foreign file) is logged and recorded once, when the state changes, not on every request
- No logging library. `error_log()` with a `phpcs:ignore` is correct

### Performance

Every front-end request passes through the plugin's path check. It must be one cheap comparison
on `$_SERVER['REQUEST_URI']` before anything else: no option reads, no regex compilation beyond the
match itself, no filters applied. Everything else happens only after that check matches.

### Comments

- One-line docblock summary per method, saying what it does
- Inline comments only where the mechanism isn't obvious: a load-order trap, an API behaving
  unexpectedly, a guard whose absence would be silently wrong
- Don't restate what the names already say. Matter-of-fact, no narrative
- A comment running past three or four lines belongs in `docs/`, referenced from the code
- Enumerated no-op branches still get a comment, but a short one

## Public Contracts

Treat these as contracts, whether or not they are documented:

| Contract | Value | Breaks when |
|----------|-------|-------------|
| Filters | `easy_apple_icon_enabled`, `easy_apple_icon_source_file`, `easy_apple_icon_sizes`, `easy_apple_icon_cache_max_age` | renamed or removed, or an argument is reordered |
| Constant | `EASY_APPLE_ICON_STATIC_FILES` | renamed, or its meaning changes |
| Option key | `easy_apple_icon_state` | the string changes: files already written become "foreign" and are never cleaned up |
| State format | the per-file sha256 records | changed without reading the old shape: same failure as above |
| WP-CLI | `wp easy-apple-icon status / regenerate / remove` | a command or argument is renamed |

Add, don't change. If you can't tell whether a change touches one of these, stop and ask.

## Commands

```bash
phpcs                       # WordPress Coding Standards, configured in phpcs.xml
phpcbf                      # Auto-fix
php -l easy-apple-icon.php  # Syntax check
```

### Pre-Commit Workflow

1. `phpcs`, then `phpcbf`, then `phpcs` again: no errors **and no warnings**
2. `php -l`
3. Stage only the files you meant to change, never `git add -A`
4. Commit with `type: brief description` (`feat:`, `fix:`, `chore:`, `refactor:`, `docs:`, `test:`)

Never push without being asked.

## Release Workflow

1. Bump the version in **both** the header `Version:` field and `Plugin::VERSION`. They must match
   the git tag
2. Move `CHANGELOG.md` `[Unreleased]` entries under the new version
3. `phpcs` clean, every acceptance test in `docs/spec.md` passing
4. Tag the release
