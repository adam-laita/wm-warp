# WM Warp

Data migration and synchronization between WordPress sites, by [WebManiak](https://webmaniak.cz).

## Features

- Pull and push the database between two WordPress sites.
- Find and replace addresses and paths, including serialized data and JSON.
- Pull and push media, themes and plugins; only changed files are transferred.
- Undo until the migration is confirmed.
- Sites connect with a key; requests are signed and encrypted. HTTP authentication and the server IP address are supported.

## Requirements

- PHP 8.3 or newer.
- WordPress 6.6 or newer.
- MySQL or MariaDB.
- The plugin must be installed on both the source and the target site.

## Installation and updates

Download `wm-warp.zip` from the [latest release](https://github.com/adam-laita/wm-warp/releases/latest) and upload it in Plugins → Add New → Upload Plugin. New versions then appear among the usual WordPress updates (the plugin checks the GitHub releases twice a day and verifies the SHA-256 of the downloaded package).

## Support

WM Warp is made for the needs of WebManiak and is provided as is, without any warranty. Issues, questions and feature requests are not handled and pull requests are not accepted. If you need something different, you are welcome to fork the plugin.

## Credits

Big thanks to **WP Migrate** (Delicious Brains / WP Engine) and **WP Synchro**. For years they were the tools of choice here – the author even helped extend WP Synchro with feedback – and WM Warp takes a lot of inspiration from both. In the end the needs grew different enough to start a plugin of its own. Thanks also to **DevKit Pro** (dPlugins), whose Sync module was another source of inspiration.

WM Warp is original code; no code was copied from these plugins.

## License

[GPL-2.0-or-later](LICENSE).

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

### Releasing

1. Set the same version in the plugin header (`Version`), the `VERSION` constant in `wm-warp.php` and `Stable tag` in `readme.txt`.
2. Create an annotated tag with the release notes as its message and push it: `git tag -a v0.1.0 -F notes.md && git push origin v0.1.0`.
3. GitHub Actions checks the versions and the release notes, runs `composer check`, builds `wm-warp.zip` with `git archive` (development files are left out by `.gitattributes`) and publishes the release with the ZIP and its SHA-256.

The release notes are shown in WordPress under “View details” → Changelog, so they describe what changed in the plugin for its users, briefly, in English. The first line of the tag message is only a title (`WM Warp 0.2.0`) and is not published; after an empty line follows a list with one change per line and the same labels as commits, in this order:

```
WM Warp 0.2.0

- [ADD] New feature, in one sentence.
- [UPDATE] Changed behaviour or look.
- [FIX] Fixed bug, as the user saw it.
- [REMOVE] Removed feature.
```

An optional `Note:` paragraph at the end may warn about compatibility. Never put commit messages, commit trailers (`Co-Authored-By`) or internal details in the notes; the workflow refuses notes without labelled items or with trailers.
