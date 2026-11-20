# WM Warp

Data migration and synchronization between WordPress sites, by [WebManiak](https://webmaniak.cz).

> Early planning stage. Features and requirements are not settled yet.

## Goals

- Push and pull the database between two WordPress sites.
- Find and replace URLs and paths, including serialized data.
- Transfer media, themes and plugins.

## Requirements

- WordPress and PHP versions: to be decided.
- The plugin must be installed on both the source and the target site.

## Development

The plugin is developed locally and tested in LocalWP: the project folder is linked into each test site's `wp-content/plugins/wm-warp` as a junction.
