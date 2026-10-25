# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
