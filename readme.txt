=== WM Warp ===
Contributors: webmaniak
Tags: migration, push, pull, database, sync
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0-dev
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Data migration and synchronization between WordPress sites: database, media, themes and plugins.

== Description ==

WM Warp moves data between two WordPress sites that both have the plugin installed.

* Pull and push the database, with find & replace of addresses and paths, also inside serialized data and JSON.
* Pull and push media, themes and plugins; only changed files are transferred.
* Undo: the previous tables and files are kept aside until the migration is confirmed.
* Sites connect with a key; every request is signed and encrypted. HTTP authentication and the IP address of the server are supported.

The plugin is made for the needs of WebManiak. It is provided as is, without support. See README.md for details and credits.

== Installation ==

1. Download `wm-warp.zip` from the latest release on GitHub.
2. In WordPress go to Plugins → Add New → Upload Plugin and upload the ZIP.
3. Activate the plugin on both sites and connect them in Tools → Warp.

Updates then appear among the usual WordPress updates.

== Changelog ==

The changes of each version are in the release notes on GitHub and in "View details" in the plugin list.
