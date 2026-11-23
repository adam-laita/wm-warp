# WM Warp

Data migration and synchronization between WordPress sites, by [WebManiak](https://webmaniak.cz).

> Early development. Not ready for use yet.

## Goals

- Push and pull the database between two WordPress sites.
- Find and replace URLs and paths, including serialized data.
- Transfer media, themes and plugins.

## Requirements

- PHP 8.3 or newer.
- WordPress 6.6 or newer.
- MySQL or MariaDB.
- The plugin must be installed on both the source and the target site.

## Development

The plugin is developed locally and tested in LocalWP: the project folder is linked into each test site's `wp-content/plugins/wm-warp` as a junction.

The plugin has no runtime dependencies; Composer is used for development tools only.

```
composer install
composer check   # coding standards, static analysis, tests and translations
composer fix     # fix coding standard violations automatically
composer i18n    # regenerate languages/*.pot, merge into .po files, compile .mo and .l10n.php
```

Every user-facing text is written in English and translated to Czech in `languages/wm-warp-cs_CZ.po` (edit `msgstr`, then run `composer i18n`).
