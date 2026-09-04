=== Artifacts ===
Contributors: draganescu
Tags: mcp, agents, html, ai, artifacts
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agent-authored HTML as a first-class content type: stored like a post, served byte-identical, with an MCP surface.

== Description ==

Agents produce self-contained HTML, CSS and JavaScript — landing pages, one-off tools,
visualizations, small sites. Today those get parked on throwaway static hosts behind
random URLs. This plugin makes such an artifact a normal WordPress object.

* Stored like a post: statuses, revisions with rollback, slugs, redirects, an archive, an author, a featured image, search.
* Served byte-identical: no theme, no wp_head, no admin bar, no injected scripts, no content filters.
* Bundles: an entry document plus relative assets, versioned together.
* An artifact can stand in for an existing post or page, or be the site front page.
* Everything is exposed as Abilities, so it is reachable over MCP, REST and WP-CLI.
* A site style resource tells agents the palette, fonts and spacing so artifacts match the theme.
* Executable content is gated by unfiltered_html, exactly like core gates raw HTML.

== Installation ==

1. Upload the plugin to `wp-content/plugins/wp-artifacts` and activate it.
2. Set a permalink structure other than "Plain".
3. Optional: install the WordPress MCP Adapter to expose the tools over MCP.

== Frequently Asked Questions ==

= Do I need the MCP Adapter? =

No. Without it the abilities are still registered and work over REST and WP-CLI, and
artifacts serve exactly the same. The adapter only adds the MCP transport.

= Why can't I publish an artifact with a script? =

Storing executable content requires the `unfiltered_html` capability, the same rule core
applies to raw HTML in posts. On multisite only super admins have it by default.

== Changelog ==

= 1.0.0 =
* First release.
