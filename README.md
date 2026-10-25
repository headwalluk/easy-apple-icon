# Easy Apple Icon

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/supported-versions.php)
[![WordPress 6.2+](https://img.shields.io/badge/WordPress-6.2%2B-21759b?logo=wordpress&logoColor=white)](https://wordpress.org/download/)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version 1.1.0](https://img.shields.io/badge/version-1.1.0-green)](CHANGELOG.md)

Stops the flood of `apple-touch-icon.png` 404s in your WordPress logs.

iPhones, iPads and Macs ask every website for an icon at fixed addresses such as
`/apple-touch-icon.png`, even when the page already says where its icon is. WordPress has no file
there, so every one of those requests renders your theme's full "page not found" page.

Easy Apple Icon answers them with your own Site Icon, as a PNG at the right size, and saves a copy
in your site's root directory so the web server sends it directly from then on. There's nothing to
configure: set a Site Icon, drop in one file, and the 404s stop.

It's for web designers and site owners who have spotted these 404s in a report, and for hosting
providers who want to remove the wasted page renders across many sites.

## Documentation

- [Getting started](docs/getting-started.md): installing, checking it works, removing it, and
  what to know before you start
- [Hooks and filters](docs/hooks-and-filters.md): switching it off, using a different image,
  and other options for developers
- [WP-CLI](docs/wp-cli.md): commands for hosting providers who manage sites from the command
  line
- [Contributing](docs/contributing.md): reporting a bug, suggesting an improvement, and sending
  a pull request
- [Changelog](CHANGELOG.md)

## License

GPL-2.0-or-later. Made by [Headwall Hosting](https://headwall-hosting.com/).
