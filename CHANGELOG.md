# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.1] - 2026-10-25

### Added

- Each release now has `easy-apple-icon.zip` attached, ready for **Plugins > Add New Plugin >
  Upload Plugin**. The newest is always at
  `https://github.com/headwalluk/easy-apple-icon/releases/latest/download/easy-apple-icon.zip`.
  No change to the plugin's behaviour

## [1.1.0] - 2026-10-25

### Added

- `240` in the default size list, so `/apple-touch-icon-240x240.png` and its `-precomposed`
  variant are answered. iPhone Safari asks for this size and doesn't fall back to the plain name

## [1.0.1] - 2026-10-25

### Fixed

- When switched off with `easy_apple_icon_enabled`, changing or removing the Site Icon no longer
  deletes the icon files the plugin saved earlier. Those files are the site owner's from then on.
  `wp easy-apple-icon remove`, and deactivating a normal plugin, still delete them

### Changed

- The Site Icon hooks now call `handle_site_icon_change()` instead of `delete_owned_files()`.
  Code that unhooked them with `remove_action()` must use the new method name

## [1.0.0] - 2026-10-25

First release.

### Added

- Answers `/apple-touch-icon.png`, `/apple-touch-icon-precomposed.png` and the allow-listed sized
  variants with the Site Icon, converted to PNG at the requested size
- Saves each icon in the site root on its first request, so the web server serves it statically
  from then on
- Deletes its icon files when the Site Icon changes or is removed, and on deactivation
- Never overwrites or deletes an icon file it didn't write
- `wp easy-apple-icon status`, `regenerate` and `remove`, with an error naming the correct user
  when run as one who can't write the site root
- `easy_apple_icon_enabled` filter, so a theme or plugin can switch it off
- `EASY_APPLE_ICON_STATIC_FILES` constant, and the `easy_apple_icon_source_file`,
  `easy_apple_icon_sizes` and `easy_apple_icon_cache_max_age` filters

[Unreleased]: https://github.com/headwalluk/easy-apple-icon/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/headwalluk/easy-apple-icon/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/headwalluk/easy-apple-icon/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/headwalluk/easy-apple-icon/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/headwalluk/easy-apple-icon/releases/tag/v1.0.0
