# Artifacts for WordPress

Agents write self-contained HTML. This plugin makes that HTML a first-class WordPress
content type: stored like a post, served **byte-identical**, with revisions, statuses,
permalinks, redirects, an archive, an author and provenance — and an Abilities/MCP
surface so an agent can publish, update, roll back and learn what the site looks like.

- **Requires:** WordPress 6.9+ (the Abilities API is in core), PHP 8.1+
- **License:** GPL-2.0-or-later
- **Slug / text domain:** `wp-artifacts`

## What "byte-identical" means here

The bytes you send are the bytes a visitor downloads. No theme, no `wp_head`, no
`wp_footer`, no admin bar, no emoji script, no `the_content` filters, no `wpautop`.
Requests are intercepted at `parse_request` and `template_redirect` priority 1, before
WordPress builds a page at all.

The one exception is opt-in: `wrap: true` serves the artifact inside the site header and
footer. Nothing else touches the payload.

## Install

The plugin lives in `wp-artifacts/` inside the repository, alongside the build spec, so
symlink or copy that directory into your plugins folder rather than cloning over it:

```bash
git clone https://github.com/draganescu/wp-artefact
ln -s "$PWD/wp-artefact/wp-artifacts" wp-content/plugins/wp-artifacts
wp plugin activate wp-artifacts
```

Set a permalink structure other than "Plain" — artifact URLs need rewrite rules.
Site Health tells you if you forgot.

## URLs

| What | URL |
|---|---|
| Entry document | `/a/{slug}/` |
| Bundle asset | `/a/{slug}/css/app.css` |
| A specific revision's asset | `/a/{slug}/~r{revision_id}/css/app.css` |
| Archive | `/artifacts/` |

The `a` prefix and the archive slug are settings, and are filterable with
`wp_artifacts_url_prefix` and `wp_artifacts_archive_slug`.

## Publishing from an agent

With the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) active, point
your client at `https://example.com/wp-json/wp-artifacts/mcp`. Authentication is the
adapter's business: application passwords out of the box, or OAuth through whichever
plugin you install. This plugin stores no credentials and has no auth UI.

**Claude / Claude Code**: add the endpoint as a remote MCP server and authenticate with an
application password (Users → Profile → Application Passwords).

**ChatGPT**: add it as a connector with the same URL.

Whatever the client, the flow is the same:

1. `wp-artifacts/guide` — read how this site wants artifacts built.
2. `wp-artifacts/site-style` — palette, fonts, spacing, logo, rendered header and footer.
3. `wp-artifacts/publish` with `status: "private"` — you get a `share_url` to show someone.
4. `wp-artifacts/update` with `status: "publish"` when it is approved.

Without the adapter the plugin degrades quietly: the abilities are still registered, so
they are callable over the core abilities REST route and over WP-CLI, and artifacts serve
exactly the same.

### Abilities

| Ability | What it does |
|---|---|
| `wp-artifacts/publish` | Create an artifact from content plus optional bundle files |
| `wp-artifacts/update` | Replace content and/or assets; always creates a revision |
| `wp-artifacts/get` | Read one artifact, with content and manifest |
| `wp-artifacts/list` | List artifacts with URLs, sizes and provenance |
| `wp-artifacts/revisions` | List stored revisions |
| `wp-artifacts/rollback` | Restore a revision, assets included |
| `wp-artifacts/delete` | Trash, or delete for good with `force` |
| `wp-artifacts/share` | Get or rotate the unlisted preview link |
| `wp-artifacts/screenshot` | Render to PNG and set the thumbnail |
| `wp-artifacts/set-front-page` | Put an artifact at `/` |
| `wp-artifacts/upload-url` | One-time signed endpoint that accepts a zip bundle |
| `wp-artifacts/site-style` | How this site looks (also the `wp://site/style` resource) |
| `wp-artifacts/guide` | Markdown instructions for the agent |

### WP-CLI

```bash
wp artifact publish ./dashboard.html --title="Q3 dashboard" --status=publish
wp artifact publish ./build --title="Landing page" --status=private   # a directory becomes a bundle
wp artifact list
wp artifact revisions 42
wp artifact rollback 42 41
wp artifact style
wp artifact guide
```

## Security

### Scripts need `unfiltered_html`

Publishing an artifact whose content or assets contain script — a `<script>` tag, an
`on*=` attribute, a `javascript:` URL, or a `.js`/`.svg`/`.wasm` asset — requires the
`unfiltered_html` capability, exactly as core requires it for raw HTML in a post.
Detection is deliberately conservative: it would rather refuse a harmless file than let an
executable one through. Script-free artifacts can be published by anyone with
`publish_artifacts`; that can be turned off in the settings.

On multisite only super admins have `unfiltered_html` by default, and
`DISALLOW_UNFILTERED_HTML` removes it for everyone. The settings screen and the artifact
screens say so when the current user lacks it.

### Same-origin risk

An artifact's JavaScript runs on the site's origin, so it can obtain a REST nonce for
whoever is viewing. For a single-owner site that is fine — the owner wrote the artifact.
For a site with several authors, set **Serve artifacts from a separate host** in the
settings: point a second host name (say `assets.example.com`) at the same install, enter
it, and artifact URLs move to that host while requests on the main host are redirected.
Setting up the DNS and the virtual host is your job; the plugin only routes.

### Response headers

Every artifact response carries `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin`, a `Content-Type` that is never
sniffed, and `X-Robots-Tag: noindex, nofollow` unless the artifact is marked indexable.
`Content-Security-Policy` follows the per-artifact setting, falling back to the site
default (`strict` sends `default-src 'self' 'unsafe-inline' data: blob:; frame-ancestors 'self'`).
Nothing sets a cookie. Non-public artifacts get `nocache_headers()` instead of cache headers.

## Web server notes

Bundle assets live in `wp-content/uploads/artifacts/{post_id}/{revision_id}/`. PHP serves
them through the manifest, which is the point: a file on disk that the manifest does not
list is a 404. The plugin writes an `index.php` and an `.htaccess` into the storage root
that turn off directory indexes and refuse to execute PHP there.

On nginx, add the equivalent — there is no `.htaccess`:

```nginx
location ^~ /wp-content/uploads/artifacts/ {
    autoindex off;
    location ~ \.(php|phtml|phar)$ { deny all; }
}
```

If you want the web server to serve assets directly rather than through PHP, map the
revision-pinned URLs and leave the plain ones to WordPress:

```nginx
location ~ ^/a/([^/]+)/~r(\d+)/(.+)$ {
    # Resolve {slug} → {post_id} yourself, e.g. with a map, then:
    # alias /var/www/wp-content/uploads/artifacts/$post_id/$2/$3;
    add_header Cache-Control "public, max-age=31536000, immutable";
}
```

Behind a WAF or a page cache, exclude `/wp-json/wp-artifacts/` (the upload endpoint and,
if you use it, the MCP route) and any OAuth routes your auth plugin adds. Caching layers
that inject markup — HTML minifiers, lazy-load rewriters, cookie banners — will break the
byte-identity guarantee; the "Artifacts are served byte-identical" Site Health test
catches that by fetching a published artifact and comparing sha256.

## Limits

| Limit | Default |
|---|---|
| Entry document | 2 MB |
| Single asset | 10 MB |
| Whole bundle | 50 MB |
| Files per bundle | 200 |

Allowed asset MIME types: `text/css`, `text/html`, `text/plain`, `text/javascript`,
`application/javascript`, `application/json`, `application/wasm`,
`application/manifest+json`, `image/*`, `font/*`, `video/mp4`, `audio/mpeg`. Extend with
the `wp_artifacts_allowed_mimes` filter.

## Data model

`post_content` holds the entry document, so core revisions carry the primary payload.
Assets are revision-addressed on disk. The manifest is revisioned meta, so rolling back
restores the file list along with the content.

| Meta | Revisioned | Purpose |
|---|---|---|
| `_artifact_manifest` | yes | `{ entry, files: [{ path, mime, bytes, sha256 }], total_bytes }` |
| `_artifact_content_type` | yes | MIME of `post_content` |
| `_artifact_provenance` | yes | `{ tool, model, agent, source_url, generated_at }` |
| `_artifact_wrap` | yes | Serve inside the site chrome |
| `_artifact_indexable` | no | Controls `X-Robots-Tag` and sitemap inclusion |
| `_artifact_csp` | no | `inherit`, `strict`, `off`, or a literal header value |
| `_artifact_share_token` | no | 32 random bytes, hex; never exposed over REST |
| `_artifact_deliver_for_parent` | no | On the **parent** post: which artifact serves it |

## Filters

| Filter | What it changes |
|---|---|
| `wp_artifacts_settings` | The effective settings array |
| `wp_artifacts_url_prefix` / `wp_artifacts_archive_slug` | URLs |
| `wp_artifacts_post_type_args` | `register_post_type` arguments |
| `wp_artifacts_allowed_mimes` | Which asset types are accepted |
| `wp_artifacts_csp` | The policy sent with an artifact |
| `wp_artifacts_asset_cache_control` | The `Cache-Control` sent with assets |
| `wp_artifacts_site_style` / `wp_artifacts_chrome` | The style document and captured chrome |
| `wp_artifacts_guide` | The agent guide |
| `wp_artifacts_ability_args` | One ability definition before registration |
| `wp_artifacts_register_mcp_server` | Set false to configure the MCP server yourself |
| `wp_artifacts_parent_post_types` | Which post types an artifact may represent |
| `wp_artifacts_allow_front_page` | Whether artifacts appear in the front page dropdown |
| `wp_artifacts_enable_convert` | Shows the (v2) "Convert to blocks" box |

## Development

The whole development environment is [WordPress Playground](https://wordpress.github.io/wordpress-playground/):
no Docker, no database, no local WordPress to keep up to date.

```bash
npm install
npm start              # http://127.0.0.1:9400, logged in as admin
```

`blueprint.json` sets the permalink structure, activates the plugin and drops you on
the artifact list. The plugin directory is mounted live, so editing a PHP file and
reloading is the whole feedback loop.

```bash
npm run test:e2e:server   # a second Playground on :9411 for the tests
npm run test:e2e          # Playwright: serving, delivery, abilities, admin
composer lint             # PHPCS: WordPress-Extra + WordPress-Docs
composer analyse          # PHPStan level 6
```

The PHPUnit suite loads WordPress in-process, so it needs a checkout of the WordPress
test library rather than Playground:

```bash
export WP_TESTS_DIR=/path/to/wordpress-tests-lib
composer test
```

## Notes on the build spec

Deliberate departures from `SPEC.md`, each one narrowing what is possible or matching
what the shipped APIs actually require:

- **`set-front-page` requires `manage_options`**, not just `publish_artifacts`. It writes
  `show_on_front` and `page_on_front`, which are site-wide settings; letting anyone who
  can publish an artifact take over `/` is a bigger grant than the rest of the tool set.
  It also records the previous setting, so `restore: true` puts it back.
- **The manifest always lists its own entry document.** The spec rejects a manifest whose
  `files` omits the entry; since the plugin builds the manifest itself, it derives that
  record from `post_content` instead of refusing the call.
- **Annotations use the core vocabulary** — `readonly`, `destructive`, `idempotent` —
  rather than the spec's `readOnlyHint` / `destructiveHint` / `idempotentHint`. The MCP
  Adapter maps them to the MCP hint names on its way out.
- **`screenshot` is not annotated read-only**, though the spec lists it that way. It
  stores the render in the media library and sets the artifact thumbnail, and core routes
  read-only abilities to GET, so calling it read-only would mean a GET that writes.
- **`set-front-page` is not annotated destructive**, for the same reason: core routes
  destructive-and-idempotent abilities to DELETE, which is the wrong verb for a setter
  that records what it replaced.
- **`guide` and `site-style-resource` declare no input schema at all.** That is how the
  Abilities API says "this takes nothing", and it keeps the MCP prompt from advertising a
  phantom required argument.

"Convert to blocks" is a v2 item, per the spec. The metabox exists behind
`wp_artifacts_enable_convert` and does nothing yet.

The predecessor is [draganescu/immersive-delivery](https://github.com/draganescu/immersive-delivery):
same idea about raw HTML per post and a theme analyzer, none of its LLM pipeline —
generation happens outside WordPress now.
