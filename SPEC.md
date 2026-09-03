# Artifacts for WordPress — Build Spec

Status: draft v1 · Owner: Andrei Draganescu · Target: a coding agent building from scratch
License: GPL-2.0-or-later · Working name: **Artifacts** (plugin slug `wp-artifacts`, text domain `wp-artifacts`)

---

## 0. One paragraph

Agents (Claude, ChatGPT, Claude Code, Codex…) produce self-contained HTML/CSS/JS things — landing pages, one-off tools, visualizations, small sites. Today those get parked on throwaway static hosts behind random URLs. This plugin makes such an **artifact a first-class content type in WordPress**: stored like a post, served **byte-identical** (no theme, no `wp_head`, nothing injected), with revisions, statuses, permalinks, redirects, an archive, an author, a thumbnail, and provenance. An MCP surface (via the core Abilities API + the WordPress MCP Adapter) lets an agent publish, update, list, roll back, screenshot, and learn the site's visual style so it can make artifacts that look like they belong. "Make it editable" (convert to blocks) is a later, optional action, never a prerequisite.

The predecessor is [draganescu/immersive-delivery](https://github.com/draganescu/immersive-delivery). Read it for the ideas (raw HTML stored per post, served via `template_include`, per-post serve-by-default flag, `ThemeAnalyzer`), then write this plugin from scratch. Do **not** port its LLM pipeline, provider adapters, jobs, prompts, or API-key settings — generation happens outside WordPress now.

---

## 1. Goals and non-goals

**Goals**

1. `artifact` is a proper WordPress post type following core conventions: registered via `register_post_type`, capabilities mapped, REST-enabled, revisions on, translatable, uninstall-clean.
2. Serving is exact: the bytes stored are the bytes sent. No theme, no `wp_head`/`wp_footer`, no admin bar, no emoji script, no filters on content.
3. Bundles (an entry HTML plus relative assets) are supported and versioned together with the HTML.
4. Full content management for free by riding core: statuses, revisions with rollback, slugs/permalinks, redirects on slug change, archive, author, dates, featured image, search.
5. An artifact may optionally stand in for an existing post or page (the "immersive version" idea) and may be set as the site's front page.
6. Everything is exposed as **Abilities** so it is reachable via MCP, REST, and WP-CLI without extra code; the MCP Adapter exposes the abilities as tools/resources.
7. A `site.style` resource tells agents how the site looks (palette, fonts, spacing, logo, rendered header/footer) so artifacts can match.
8. Security model equals core's model for raw HTML: publishing executable artifacts requires `unfiltered_html`.

**Non-goals (v1)**

- No OAuth server, no application-password flow, no auth UI. Authentication is the MCP Adapter's concern (application passwords by default; OAuth via whichever plugin the site owner installs). The plugin must work with any authenticated WordPress user that has the right capabilities.
- No LLM calls from PHP. No API keys stored.
- No HTML→blocks converter in v1 (see §11 for the v2 hook).
- No multisite provisioning. Must *work* on multisite; must not manage it.
- No CDN/edge integration beyond correct cache headers.

---

## 2. Vocabulary

- **Artifact** — a post of type `artifact`. Its `post_content` is the entry document (usually HTML).
- **Bundle** — entry document + zero or more **assets** (css, js, images, fonts, json, other files) addressed by relative path.
- **Revision** — a core post revision; each `publish`/`update` creates one. Assets are stored per revision.
- **Parent** — optional `post_parent` pointing to a post/page the artifact *represents*.
- **Chrome** — the site's rendered header and footer HTML + the CSS needed to display them.

---

## 3. Architecture

```
wp-artifacts/
  wp-artifacts.php            bootstrap, constants, autoload, activation/uninstall hooks
  uninstall.php
  src/
    Plugin.php                wires services on `plugins_loaded`
    PostType/
      ArtifactPostType.php    register_post_type, meta, caps, taxonomies (none in v1), rewrite
      Statuses.php            share-token helper for "unlisted" behaviour
    Storage/
      BundleStore.php         writes/reads assets on disk per revision, manifest handling
      Manifest.php            value object, validation
    Serving/
      Router.php              parse_request/template_redirect interception
      Responder.php           headers, ETag, streaming, CSP
      ParentDelivery.php      serve artifact in place of its parent post when flagged
    Style/
      ThemeAnalyzer.php       theme.json → palette/fonts/spacing; logo; chrome capture
    Abilities/
      Registrar.php           wp_abilities_api_init → registers all abilities below
      Publish.php Update.php Get.php List.php Rollback.php Delete.php Screenshot.php
      SiteStyle.php UploadUrl.php
    Admin/
      ListTable.php           columns: thumbnail, status, size, made-with, parent, url
      EditScreen.php          code view + live preview iframe + metadata sidebar (no block editor)
      SettingsPage.php        prefix slug, CSP mode, cookieless domain, screenshot method, size limits
      SiteHealth.php          self-tests (rewrite rules, serving headers, upload dir writable)
    Security/
      Capabilities.php        map_meta_cap glue, unfiltered_html gating
    Cli/
      Commands.php            thin `wp artifact ...` wrappers around abilities (optional; abilities already give WP-CLI in 7.0+)
  assets/admin/               editor JS/CSS (vanilla or @wordpress/scripts)
  languages/
  tests/                      PHPUnit (wp-env), Playwright for serving + admin
```

Dependencies: WordPress ≥ 6.9 (Abilities API in core), PHP ≥ 8.1. MCP exposure requires the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin to be active; the plugin must degrade gracefully (abilities still registered; REST + CLI still work) when it is not.

---

## 4. Data model

### 4.1 Post type `artifact`

```php
register_post_type( 'artifact', [
  'labels'            => …,
  'public'            => true,
  'show_in_rest'      => true,
  'rest_base'         => 'artifacts',
  'has_archive'       => 'artifacts',          // the "herd" page
  'rewrite'           => [ 'slug' => 'a', 'with_front' => false ],  // filterable via settings
  'hierarchical'      => false,
  'supports'          => [ 'title', 'editor', 'author', 'thumbnail', 'revisions', 'custom-fields', 'excerpt' ],
  'capability_type'   => [ 'artifact', 'artifacts' ],
  'map_meta_cap'      => true,
  'menu_icon'         => 'dashicons-layout',
  'show_in_nav_menus' => true,
  'can_export'        => true,
  'template_lock'     => 'all',   // we do not want the block editor; see §8.2
]);
```

`post_content` = entry document, stored **verbatim** (bypass `wp_kses` and `content_save_pre` filters for this post type; use `wp_slash` correctly; do not run `wpautop`, shortcodes, or `the_content` on it, ever).

`post_excerpt` = optional human description (used in the archive and as `og:description` on the *archive*, never injected into the artifact).

`post_parent` = optional id of a `post`/`page` this artifact represents.

### 4.2 Post meta (all registered with `register_post_meta`, `show_in_rest` where noted)

| key | type | revisioned | REST | purpose |
|---|---|---|---|---|
| `_artifact_manifest` | object | **yes** (`revisions_enabled => true`, core ≥ 6.4) | yes | `{ entry: "index.html", files: [{ path, mime, bytes, sha256 }], total_bytes }` |
| `_artifact_content_type` | string | yes | yes | MIME of `post_content`; default `text/html; charset=utf-8`. Allows SVG/JSON/plain artifacts. |
| `_artifact_indexable` | bool | no | yes | default `false`; controls `X-Robots-Tag` and sitemap inclusion |
| `_artifact_csp` | string | no | yes | `inherit` (site default), `strict`, `off`, or a literal CSP header value |
| `_artifact_provenance` | object | yes | yes | `{ tool, model, agent, source_url, generated_at }` — free-form, displayed in admin |
| `_artifact_share_token` | string | no | no (never) | random 32-byte hex; enables preview of non-public statuses via `?share=` |
| `_artifact_wrap` | bool | yes | yes | serve inside site chrome (see §6.4). default `false` |
| `_artifact_deliver_for_parent` | bool | no | yes | on the **parent** post: when true, requests for the parent serve this artifact |

### 4.3 Asset storage

Path: `{uploads}/artifacts/{post_id}/{revision_id}/{relative path}`.
On `publish`/`update`: write files for the new revision id (obtain it from `wp_save_post_revision` return, or `_wp_put_post_revision` hook), then write the manifest meta. Never mutate a previous revision's directory. On revision delete (core prunes when `WP_POST_REVISIONS` is capped), delete that directory via `wp_delete_post_revision` hooks. On artifact delete, remove `{post_id}/`.

Add a `.htaccess`/nginx note in README: the asset directory should be served by the web server directly; PHP only serves the entry document. Provide an `index.php` deny in `{uploads}/artifacts/` root and no directory listing.

Manifest is the source of truth for what may be served; a request for a path not in the manifest is 404 even if a file exists.

### 4.4 Limits (settings, with defaults)

- entry document ≤ 2 MB; single asset ≤ 10 MB; bundle ≤ 50 MB; ≤ 200 files.
- allowed asset MIME whitelist (text/css, application/javascript, image/*, font/*, application/json, text/plain, application/wasm, video/mp4, audio/mpeg). Anything else rejected unless the filter `wp_artifacts_allowed_mimes` says otherwise.

---

## 5. Capabilities and security

### 5.1 Capabilities

Custom caps from `capability_type`: `edit_artifact(s)`, `publish_artifacts`, `delete_artifact(s)`, `read_private_artifacts`, etc. On activation grant all artifact caps to `administrator` and `editor`; grant `edit_artifacts`/`delete_artifacts` (own) to `author`. Remove on uninstall.

### 5.2 The executable-content gate

Core lets a user save raw HTML/JS only if they have `unfiltered_html`. Mirror that exactly:

- Publishing or updating an artifact **whose content or assets contain script** (any `<script>`, `on*=` attribute, `javascript:` URL, or a `.js` asset) requires the current user to have `unfiltered_html`. Otherwise reject with a clear error (`artifact_requires_unfiltered_html`).
- Script-free artifacts can be published by anyone with `publish_artifacts`. Detection may be conservative (false positives acceptable, false negatives not).
- Document loudly: on multisite only super admins have `unfiltered_html` by default (`DISALLOW_UNFILTERED_HTML` also applies). The settings page must show a notice when the current user lacks it.

### 5.3 Same-origin risk and the cookieless option

An artifact's JS runs on the site's origin and can obtain a REST nonce for whoever is viewing. For single-owner sites this is acceptable (the owner authored it). For multi-author sites, offer a setting **Serve artifacts from a separate host** (e.g. `assets.example.com` pointed at the same install): when set, the canonical artifact URL uses that host and the router refuses to serve artifacts on the primary host (redirect 301). Do not attempt to implement DNS/vhost — document it.

### 5.4 Headers on every served artifact

- `Content-Type` from `_artifact_content_type` / manifest MIME (never sniffed).
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `X-Robots-Tag: noindex, nofollow` unless `_artifact_indexable`.
- `Content-Security-Policy` per `_artifact_csp` (`strict` = `default-src 'self' 'unsafe-inline' data: blob:; frame-ancestors 'self'`; `inherit` = site default from settings; `off` = none).
- `Cache-Control: public, max-age=31536000, immutable` for asset paths (they are revision-addressed); `Cache-Control: public, max-age=0, must-revalidate` + `ETag: "rev-{revision_id}"` + `Last-Modified` for the entry document. Honor `If-None-Match`.
- No `Set-Cookie`. Call `nocache_headers()` **only** for non-public statuses.

### 5.5 Validation on publish/update

Reject: path traversal (`..`, absolute paths, backslashes), duplicate paths, paths not matching `^[A-Za-z0-9._\-/]+$`, MIME outside whitelist, size over limits, entry not present in `files` when `files` non-empty, `post_content` not valid UTF-8 for text types. Do **not** "fix" HTML. Do not run it through DOMDocument. Store what was sent.

---

## 6. Serving

### 6.1 URL scheme

- Entry: `/{prefix}/{slug}/` (prefix default `a`, filterable + setting). Trailing slash canonical.
- Asset: `/{prefix}/{slug}/{relative path}` — served only if in the manifest of the **current** revision. Asset URLs are also available revision-pinned at `/{prefix}/{slug}/~r{revision_id}/{relative path}` (this is what the immutable cache header is for; the entry HTML uses relative paths so plain `/{prefix}/{slug}/style.css` must work too).
- Archive: `/artifacts/`.

### 6.2 Interception

Hook `parse_request` (priority 1): if the request path matches `^{prefix}/([^/]+)/(.+)$` resolve the artifact by slug, check status/visibility, look up the asset in the manifest, stream the file, `exit`. This runs before the main query so no theme, no widgets, no plugins' `wp` hooks.

Hook `template_redirect` (priority 1) for the singular entry: if `is_singular('artifact')` and visibility allows → `Responder::sendEntry()` then `exit`. Do not use `template_include` (too late; scripts already enqueued, `wp_head` callbacks registered — harmless but wasteful).

Also short-circuit: `show_admin_bar` false, `wp_robots` bypass (we send the header ourselves), `redirect_canonical` must still run for trailing-slash canonicalization on the entry URL.

### 6.3 Visibility

| status | who sees | notes |
|---|---|---|
| `publish` | everyone | |
| `private` | users with `read_private_artifacts` **or** valid `?share={token}` | this is the "unlisted / show my client" mode |
| `password` | core password flow | render core's password form **unwrapped**? No — serve a minimal self-contained form page (no theme). |
| `draft`/`pending` | editors + `?share=` | |
| `future` | as core | |
| `trash` | 404 | |

`?share=` token: constant-time compare; token regenerable from admin and via the `artifact.share` ability.

### 6.4 `wrap` mode

If `_artifact_wrap` is true, serve: `<!doctype html><html><head>{chrome.head_css}</head><body>{chrome.header}<main class="artifact-wrap">{artifact body}</main>{chrome.footer}</body></html>` — where chrome comes from `ThemeAnalyzer::captureChrome()` (§9) and the artifact's own `<head>` children are merged in (styles/scripts preserved in order). This is the *only* mode that touches the bytes, and it is opt-in per artifact. Accept that wrap is best-effort.

### 6.5 Parent delivery

If a `post`/`page` has meta `_artifact_deliver_for_parent = {artifact_id}` and that artifact is published, `template_redirect` on the parent serves the artifact (same headers) unless `?artifact=0`. `?artifact=1` on a parent forces it even if the flag is off (preview). Canonical URL for SEO remains the parent's; in this case `X-Robots-Tag` is **not** sent (the parent's indexing rules apply).

### 6.6 Front page

Support `show_on_front = page` where the chosen page has parent delivery on, and additionally allow choosing an artifact directly in Settings → Reading via a filter that adds artifacts to the dropdown (`wp_dropdown_pages` args filter). Serve at `/` with the same responder.

### 6.7 Sitemaps & feeds

Include `artifact` in core sitemaps only when `_artifact_indexable` is true (`wp_sitemaps_posts_query_args` filter). Exclude from the main RSS feed by default (`pre_get_posts` on feeds), setting to include.

---

## 7. Redirects

On slug change (`post_updated` where `post_name` differs), store old slug in `_artifact_old_slugs` (array) and 301 old → new in `parse_request` (mirror core's `wp_old_slug_redirect` which does not cover our asset paths). On artifact delete, if `_artifact_redirect_to` (URL) is set, 301 there; else 410.

---

## 8. Admin

### 8.1 List table (`edit.php?post_type=artifact`)

Columns: thumbnail (featured image, i.e. screenshot), title (+ view / copy URL / copy share link row actions), status, size (total bytes), files (count), made with (`provenance.tool` / `model`), parent (link), modified. Quick filters by status. Bulk: trash, regenerate screenshots.

### 8.2 Edit screen

Not the block editor. Register a classic-style edit screen with:

- Left: read-only (v1) code view of the entry document with a "Download bundle (.zip)" and "Replace bundle…" (upload zip or single html) control. Replacing creates a revision.
- Right: live preview iframe (`?share=` URL so drafts render), viewport toggles (mobile/desktop).
- Sidebar: status/visibility (core publish box), slug, parent picker (post/page search), deliver-for-parent toggle, indexable, CSP mode, wrap, provenance (read-only), share link with regenerate, revisions link (core UI works because content + manifest meta are revisioned).
- Metabox "Convert to blocks" hidden behind `wp_artifacts_enable_convert` filter (v2, §11).

### 8.3 Settings (`options-general.php?page=wp-artifacts`)

URL prefix; default CSP mode + custom header value; cookieless host; screenshot provider (`none` | `wp_cron_headless` if a Chromium binary is available | external URL template `https://…/{url}`); size limits; include artifacts in feeds; allow non-admin publishing of script-free artifacts (default on). Store as one option `wp_artifacts_settings`.

### 8.4 Site Health

Register tests: rewrite rules resolve `/{prefix}/__probe__/` (loopback request expects a 404 with our `X-Artifacts: 1` header, proving interception works, not a theme 404); uploads dir writable; asset directory not listable; loopback to a published artifact returns byte-identical body (sha256 compare) and no `Set-Cookie`.

---

## 9. ThemeAnalyzer → `site.style`

Output (JSON, cached in a transient, invalidated on `switch_theme`, `customize_save_after`, `wp_theme_json_data_*` changes, global styles post save):

```json
{
  "theme": { "name": "...", "slug": "...", "is_block_theme": true },
  "colors": { "palette": [{ "slug": "base", "name": "Base", "color": "#fff" }], "background": "#fff", "text": "#111", "accent": "#0a58ca", "link": "#0a58ca" },
  "typography": { "font_families": [{ "slug": "body", "name": "Inter", "fontFamily": "Inter, sans-serif", "src": ["https://…/inter.woff2"] }], "font_sizes": [...], "body": { "fontFamily": "...", "fontSize": "...", "lineHeight": "..." }, "heading": { ... } },
  "spacing": { "scale": [...], "content_width": "640px", "wide_width": "1200px", "padding": {...} },
  "shape": { "border_radius": "…" },
  "logo": { "url": "...", "width": 0, "height": 0 },
  "site": { "title": "...", "tagline": "...", "url": "...", "language": "en-US", "direction": "ltr" },
  "chrome": { "header_html": "...", "footer_html": "...", "css": "...", "captured_at": "..." },
  "css_variables": ":root{--wp--preset--color--base:#fff;…}",
  "guidance": "Short prose: use the palette above, load fonts from src, keep content width 640px, …"
}
```

Sources: `wp_get_global_settings()` / `wp_get_global_styles()` (block themes), `get_theme_mods` + `get_custom_logo` (classic), `wp_get_global_stylesheet()` for `css_variables`. `chrome` is captured by rendering `header.html`/`footer.html` template parts via `do_blocks( get_block_template(...) )` for block themes, or by a loopback fetch of the home page and extracting `<header>`/`<footer>` for classic themes (best effort; may be empty).

Exposed as an MCP **resource** (`meta.mcp.type = resource`, `meta.uri = wp://site/style`) *and* as a tool (`site.style`) because some clients only surface tools.

---

## 10. Abilities (the API surface)

Register on `wp_abilities_api_init`. Verify exact `wp_register_ability()` argument names against the current core docs before coding (expected: `label`, `description`, `input_schema`, `output_schema`, `execute_callback`, `permission_callback`, `meta`). Set `meta.mcp.public = true` on all; `meta.annotations` (or the adapter's equivalent) with `readOnlyHint` / `destructiveHint` / `idempotentHint` as marked. Namespace: `wp-artifacts/`.

| ability | kind | annotations | input → output |
|---|---|---|---|
| `wp-artifacts/publish` | tool | — | `{ title, content, content_type?, files?: [{path, mime?, data_base64}], status?: draft\|publish\|private, slug?, excerpt?, parent_id?, deliver_for_parent?, indexable?, csp?, wrap?, provenance? }` → `{ id, url, share_url, status, revision_id, bytes, warnings[] }` |
| `wp-artifacts/update` | tool | idempotent=false | `{ id, content?, files? (full replacement set), title?, …same optional fields }` → same as publish. Always creates a revision. Omitted `files` = keep current asset set (re-linked to new revision). |
| `wp-artifacts/get` | tool | readOnly | `{ id \| slug, include_content?: bool, include_files?: bool }` → artifact record + manifest (+ content) |
| `wp-artifacts/list` | tool | readOnly | `{ status?, parent_id?, search?, page?, per_page? }` → `[{ id, title, url, status, modified, bytes, thumbnail_url, provenance }]` |
| `wp-artifacts/revisions` | tool | readOnly | `{ id }` → `[{ revision_id, date, author, bytes }]` |
| `wp-artifacts/rollback` | tool | — | `{ id, revision_id }` → new current revision (via `wp_restore_post_revision`; manifest meta restores with it) |
| `wp-artifacts/delete` | tool | **destructive** | `{ id, force?: bool, redirect_to?: url }` → `{ deleted: true }` (trash unless force) |
| `wp-artifacts/share` | tool | — | `{ id, regenerate?: bool }` → `{ share_url }` |
| `wp-artifacts/screenshot` | tool | readOnly | `{ id, viewport?: "mobile"\|"desktop" }` → `{ image_base64, mime }` or `{ error: "screenshot_provider_unavailable" }`; also sets featured image when absent |
| `wp-artifacts/set-front-page` | tool | — | `{ id }` → `{ ok }` |
| `wp-artifacts/upload-url` | tool | — | `{ id?, expires_in? }` → `{ url, method: "POST", expires_at }` — one-time signed endpoint accepting a zip (for bundles too large for JSON). Coding agents use it; chat clients won't. |
| `wp-artifacts/site-style` | tool + resource | readOnly | `{}` → §9 JSON |
| `wp-artifacts/guide` | prompt/resource | readOnly | Markdown for the agent: how to build an artifact for this site (self-contained; relative asset paths; size limits; call `site-style` first; use `status: private` + `share_url` for review; never inline secrets). Also set as the MCP server `instructions` if the adapter exposes a hook for it. |

Permission callbacks: `publish`/`update`/`rollback`/`set-front-page` → `current_user_can('publish_artifacts')` plus §5.2 gate evaluated in `execute_callback` after content is known; `delete` → `delete_post`; `get`/`list`/`revisions`/`screenshot` → `read` (+ `read_private_artifacts` for non-public); `site-style`/`guide` → `read`; `share` → `edit_post`.

Errors: return `WP_Error` with stable codes (`artifact_invalid_path`, `artifact_too_large`, `artifact_mime_not_allowed`, `artifact_requires_unfiltered_html`, `artifact_not_found`, `artifact_forbidden`). Messages must be actionable for an agent ("File 'js/app.js' contains script; the current user lacks unfiltered_html. Ask a site administrator to grant it or publish a script-free version.").

REST: because abilities are registered with `show_in_rest`, they're callable via the core abilities REST route. Also expose the post type via `wp/v2/artifacts` for generic clients; ensure `content.raw` round-trips verbatim for users with `edit_posts`.

---

## 11. v2 hooks (design now, build later)

- `wp-artifacts/convert` — run Gutenberg's `rawHandler` (server-side via `parse_blocks`/`serialize_blocks` after a minimal HTML→blocks pass, or client-side in the edit screen) to create a **new draft page** from the artifact; never modify the artifact. Provide the filter `wp_artifacts_enable_convert`.
- Cookieless host automation, screenshot service, artifact "collections" taxonomy, per-artifact analytics, WebMCP/`agent-ready` hints.

---

## 12. Acceptance criteria (agent must verify each)

1. Publish `{title:"Hello", content:"<!doctype html><html><body><h1>Hi</h1><script>document.title='x'</script></body></html>"}` as an admin with `unfiltered_html` → 200 at `/a/hello/`, body sha256 equals input, headers per §5.4, no `Set-Cookie`, response contains no `wp-emoji`, `admin-bar`, or `wp_head` output.
2. Same as an editor **without** `unfiltered_html` → `artifact_requires_unfiltered_html`. Script-free variant → success.
3. Publish a bundle with `index.html` + `css/a.css` + `img/x.png`; `/a/slug/css/a.css` served with `text/css` and immutable cache header; `/a/slug/css/../../wp-config.php` → 404; a file on disk not in the manifest → 404.
4. `update` with new content → revisions count +1; previous revision's asset directory still present; `rollback` to it → body equals the earlier sha256 and assets resolve.
5. `status: private` → anonymous 404 (not 403 — do not leak existence); with `?share={token}` → 200; regenerating token invalidates old one.
6. Slug change → old URL 301s to new, including asset paths.
7. Parent delivery: set on a page → page URL serves artifact bytes; `?artifact=0` serves the page normally; no `X-Robots-Tag` on parent delivery.
8. Front page: set an artifact → `/` serves it byte-identical.
9. `site-style` returns valid JSON with a non-empty palette on Twenty Twenty-Five and on a classic theme (Twenty Twenty-One) without fatals; `chrome` may be empty on classic.
10. With the MCP Adapter active, `tools/list` shows all abilities with correct annotations; `resources/list` shows `wp://site/style`; a full `publish → get → update → rollback → delete` cycle works from an MCP client (use the adapter's stdio transport via WP-CLI for the test).
11. With the adapter **inactive**, plugin activates without notices and abilities work via REST and `wp ability` CLI (if present).
12. Site Health tests pass on a stock wp-env install; the rewrite probe test fails clearly if permalinks are "Plain".
13. Uninstall removes options, caps, post meta registrations, the `artifacts` uploads directory (only if setting "delete data on uninstall" is on; default off), and trashes nothing silently.
14. PHPCS (WordPress-Extra + WordPress-Docs) passes; PHPStan level 6 passes; all strings i18n'd.
15. Multisite: activates network-wide; per-site uploads paths correct; §5.2 notice shown for admins lacking `unfiltered_html`.

---

## 13. Milestones

1. **Skeleton + content model** (§3, §4, §5.1): post type, meta, caps, activation/uninstall, PHPCS/PHPStan config, wp-env, first tests.
2. **Serving** (§6, §7, §5.4, §5.5): router, responder, visibility, redirects, bundle store. Criteria 1–8.
3. **Abilities** (§10): all tools; REST + CLI verified; MCP Adapter integration; criteria 10–11.
4. **Style** (§9): ThemeAnalyzer, resource, guide prompt; criterion 9.
5. **Admin** (§8): list table, edit screen, settings, Site Health; criteria 12–15.
6. **Polish**: screenshot provider, wrap mode, README with connector setup for Claude and ChatGPT (site URL → MCP Adapter endpoint), hosting notes (WAF/cache exclusions for the MCP and OAuth routes).

---

## 14. Decisions made (change only with reason)

- Artifact is a **page-like public post type**, not a file store: it can be the front page and appears in the archive. Opaque content, normal WordPress object.
- `post_content` holds the entry document so core revisions carry the primary payload; assets are revision-addressed on disk; manifest is revisioned meta.
- Interception at `parse_request`/`template_redirect`, not `template_include`.
- Executable content gated by `unfiltered_html`, exactly like core.
- No auth code in this plugin. Auth belongs to the MCP Adapter / an OAuth plugin.
- `wrap` is v1 (small once chrome capture exists); `convert` is v2.
- Default non-indexable; owner opts in.

## 15. References

- Predecessor: https://github.com/draganescu/immersive-delivery (read `src/Repository/ImmersiveContentRepository.php`, `src/Frontend/TemplateLoader.php`, `src/Utils/ThemeAnalyzer.php`)
- Abilities API: https://developer.wordpress.org/apis/abilities-api/getting-started/ and `wp_register_ability()` reference
- MCP Adapter: https://github.com/WordPress/mcp-adapter and the Feb 2026 dev-blog introduction
- Revisioned meta: `register_post_meta( …, [ 'revisions_enabled' => true ] )` (core 6.4+)
- Old-slug redirects: `wp_old_slug_redirect()` for behaviour to mirror
- Plugin Handbook: https://developer.wordpress.org/plugins/ (coding standards, i18n, uninstall, capabilities)
