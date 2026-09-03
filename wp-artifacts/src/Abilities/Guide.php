<?php
/**
 * The `wp-artifacts/guide` prompt/resource.
 *
 * @package WPArtifacts
 */

declare( strict_types = 1 );

namespace WPArtifacts\Abilities;

use WPArtifacts\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * How to build an artifact for this particular site.
 */
final class Guide implements Ability {

	/**
	 * Ability definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'How to build an artifact for this site', 'wp-artifacts' ),
			'description'         => __( 'Read this before publishing: how artifacts are served on this site, the size and MIME limits, how to reference bundle files, and how to preview work before it goes live.', 'wp-artifacts' ),
			// No input schema: this takes no arguments, and that is how the Abilities API
			// says so. It also keeps the MCP prompt free of a phantom required argument.
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'content' => array( 'type' => 'string' ),
					'mime'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( self::class, 'execute' ),
			'permission_callback' => array( Registrar::class, 'can_read' ),
			'category'            => Registrar::CATEGORY,
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array(
					'type'      => 'prompt',
					'uri'       => 'wp://site/artifacts-guide',
					'mime_type' => 'text/markdown',
					'mimeType'  => 'text/markdown',
					'name'      => 'artifacts-guide',
				),
				'uri'          => 'wp://site/artifacts-guide',
				'annotations'  => array(
					'title'      => __( 'How to build an artifact for this site', 'wp-artifacts' ),
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		);
	}

	/**
	 * Returns the guide.
	 *
	 * @return array<string,string>
	 */
	public static function execute() {
		return array(
			'content' => self::markdown(),
			'mime'    => 'text/markdown',
		);
	}

	/**
	 * The guide itself.
	 *
	 * @return string
	 */
	public static function markdown(): string {
		$prefix      = Settings::prefix();
		$max_entry   = size_format( (int) Settings::get( 'max_entry_bytes', 2097152 ) );
		$max_asset   = size_format( (int) Settings::get( 'max_asset_bytes', 10485760 ) );
		$max_bundle  = size_format( (int) Settings::get( 'max_bundle_bytes', 52428800 ) );
		$max_files   = (int) Settings::get( 'max_files', 200 );
		$mimes       = implode( ', ', Settings::allowed_mimes() );
		$site        = home_url( '/' );
		$example_url = home_url( '/' . $prefix . '/my-artifact/' );

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- example markup in documentation.
		$guide = <<<MD
# Publishing artifacts to {$site}

An **artifact** is a self-contained document — usually one HTML file, sometimes with a few
relative assets — stored in WordPress and served back byte for byte. No theme wraps it, no
scripts are injected, nothing is filtered. What you send is what a visitor downloads.

## The short version

1. Call `wp-artifacts/site-style` first. It returns the palette, fonts, spacing scale, logo and
   the rendered site header and footer. Use them so the artifact looks like it belongs here.
2. Call `wp-artifacts/publish` with `status: "private"`. You get back a `share_url`; anyone with
   that link can see the artifact while it is still unlisted.
3. Show the `share_url` to the person you are working with. When they approve, call
   `wp-artifacts/update` with `status: "publish"`.

## Writing the document

- Send one complete HTML document in `content`, starting with `<!doctype html>`.
- Inline your CSS and JS when the whole thing is small. Reach for `files` when assets are large
  or shared between artifacts.
- Reference bundle files by **relative path only**: `<link rel="stylesheet" href="css/app.css">`,
  never an absolute URL and never a path starting with `/`.
- Paths must match `[A-Za-z0-9._-/]+`, with no `..` segments. PHP files are rejected.
- The artifact is public HTML. Never inline API keys, tokens or anything else secret.
- Make it responsive and give it a `<title>`; the artifact owns its whole `<head>`.

## Limits on this site

| Limit | Value |
|---|---|
| Entry document | {$max_entry} |
| Single asset | {$max_asset} |
| Whole bundle | {$max_bundle} |
| File count | {$max_files} |

Allowed asset MIME types: {$mimes}.

## URLs

- Entry document: `{$example_url}`
- Assets: `{$example_url}css/app.css`
- A specific revision's assets: `{$example_url}~r123/css/app.css`

## Scripts need `unfiltered_html`

Publishing an artifact that contains a `<script>` tag, an `on*=` attribute, a `javascript:` URL or
a `.js` asset requires the `unfiltered_html` capability — the same rule WordPress applies to raw
HTML in posts. If you get `artifact_requires_unfiltered_html`, either publish a script-free version
or ask a site administrator to grant the capability. On multisite, only super admins have it by
default.

## Things worth knowing

- `wp-artifacts/update` always creates a revision. `wp-artifacts/revisions` lists them and
  `wp-artifacts/rollback` restores one, assets included.
- Sending `files` replaces the entire asset set. Omit `files` to keep the current assets.
- Artifacts are `noindex` by default. Pass `indexable: true` when the page is meant to be found.
- An artifact can stand in for an existing post or page: pass `parent_id` and
  `deliver_for_parent: true`, and the parent's URL serves the artifact while keeping its own
  canonical URL and indexing rules. Add `?artifact=0` to any such URL to see the original.
- `wrap: true` serves the artifact inside the site header and footer. It is best effort and it is
  the only mode that touches your bytes.
MD;
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet

		/**
		 * Filters the agent guide.
		 *
		 * @param string $guide Markdown document.
		 */
		return (string) apply_filters( 'wp_artifacts_guide', $guide );
	}
}
