# FIXES-0.18.0 — wordpress.org submission readiness (compliance workstream)

**Scope:** the MUST + mechanical SHOULD items from the compliance audit
(`~/workspace/tk-fields/audit/wporg-readiness-0.17.1.md`). No feature work.

## M1 — Stored XSS in `tk/field-value` wysiwyg render (BLOCKER)

**File:** `blocks/field-value/render.php` (~line 171).

The wysiwyg branch echoed `$attributes['value']` raw, justified by a comment
claiming the HTML "was sanitized at save time". That is false for this input
path: block-comment JSON (`<!-- wp:tk/field-value {"fieldType":"wysiwyg",
"value":"<script>…</script>"} /-->`) is attacker-controllable post content —
contributors and authors can hand-craft it in the code editor, and
`post_content` KSES does not sanitize inside block JSON. This re-granted
low-priv users unfiltered HTML on the frontend.

**Fix:** the branch now resolves `$field_name` through
`Field_Registry::instance()->get()` and sanitizes the value with the
*registered* field's own `sanitize()` — which switches on the registry type,
so even a type mismatch in the attributes (e.g. claiming `wysiwyg` for a
`select` field) gets the correct sanitizer. Unknown fields and type
mismatches refuse to render entirely. The per-field `allow_unfiltered`
admin opt-in is preserved (sanitize() returns raw HTML for it, same as at
save time — the admin accepts stored-XSS responsibility for that field).

**Verified:** hand-crafted block with `<script>` payload neutralized in
frontend output (unknown field → refused; type mismatch → refused);
legitimate wysiwyg HTML (links, bold, lists) still renders, injected
`<script>` stripped by wp_kses_post. 4/4 render tests PASS.

## M2 — Leaflet vendored locally (BLOCKER)

`blocks/field-value/edit.js` injected Leaflet 1.9.4 from unpkg.com (no SRI)
in two duplicated `ensureLeaflet()` loaders — violates the "all non-service
JS/CSS must be included locally" rule.

**Fix:**
- Leaflet 1.9.4 vendored at `assets/leaflet/` (`leaflet.js`, `leaflet.css`,
  `images/`, `LICENSE`) — BSD-2-Clause, GPL-compatible. The JS was
  hash-verified (unpkg download sha256 == official npm tarball).
- `includes/class-blocks.php`: registers `tk-leaflet` script + style and
  adds them as editor dependencies/handles of the `field-value` block; the
  stale `leafletVersion` inline config was removed.
- Both `ensureLeaflet()` copies simplified to resolve when `window.L` is
  ready (guaranteed by the script dependency); all CDN injection deleted.
- Zero `unpkg` references remain in plugin code.

**Verified (headless Chromium, real editor):** `window.L` 1.9.4 ready,
`.leaflet-container` renders in the canvas, local leaflet.js/css requested,
**0 unpkg requests**. OSM tiles still fire (permitted service, now disclosed
in the readme Privacy section).

## M3 — Release packaging

New `~/workspace/tk-fields/build-release.sh`:
- rebuilds the admin bundle via `npm run build` (fails the release if
  webpack exits non-zero),
- re-injects the ABSPATH guard into the regenerated
  `assets/admin/build/index.asset.php`,
- strips `tests/`, `src/admin/tests/`, `package.json`/`package-lock.json`,
  all top-level dev-notes `*.md`, `consultations/`, `node_modules/`,
- keeps `src/admin/src/` (source for the minified admin build — the
  "no obfuscated code" requirement),
- sanity-checks: no CDN references in the tree; refuses to ship without
  the admin source.

## S1 — uninstall.php

- Now deletes `tk_fields_group` definition posts (plugin configuration;
  postmeta cascades on force-delete) and removes the custom
  `manage_tk_fields` capability from every role that carries it.
- Two real bugs found while verifying the first version (fixed):
  1. `wp plugin uninstall` runs with no current user, so
     `wp_delete_post()`'s `delete_post` capability check silently refused
     every deletion — definitions survived uninstall. Fixed by mapping
     the delete primitives to `exist` via `map_meta_cap` for the cleanup
     duration.
  2. `'post_status' => 'any'` excludes trash — trashed definition posts
     survived. Statuses are now listed explicitly (incl. trash).
- The pre-existing `tk-content-type` cleanup had both flaws too and now
  shares the same hardened helper.
- Docblock extended: states exactly what is removed vs. kept (field values
  on posts/terms/users and user content in TK content types stay).

**Verified:** deactivate → uninstall → 0 `tk-field-group` posts, 0
`_tk_fields_group` meta rows, 0 `tk-content-type` posts, cap gone from
administrator, unrelated posts untouched.

## S2 — Privacy disclosures

- readme.txt Privacy: discloses that the map field's editor preview loads
  OpenStreetMap tiles (tiles only, no user data sent); the old "Nothing
  fires on its own" claim replaced with an accurate statement.
- The "Search address" tooltip in `blocks/field-value/edit.js` (both map
  input components) now mentions OSM tile loading.

## S4 — ABSPATH guards

Added to `assets/admin/build/index.asset.php` (re-injected post-build by
the release script), `blocks/accordion/view.asset.php`,
`blocks/slider/view.asset.php`. `blocks/countdown/view.asset.php` already
had one.

## S5 — Changelog

The second `= 0.16.0 =` heading (import preview fix, export 404 fix,
capability fix) renamed to `= 0.15.1 =`, matching the release it shipped in.

## Deferred (Emmanuel's call)

- **S3 Flaticon:** the bundled UIcons subset stays as-is (attribution
  present; acceptance is at reviewer discretion). Swapping to Dashicons /
  SIL-OFL icons is a design decision, not a code fix.
- **S6 "the three things":** final display name, wordpress.org username for
  `Contributors:`, `Author` + `Author URI:` — he decides before submission.
- Directory assets (icon/banner), screenshots section, `.pot` file,
  `user-search` capability tightening — all left as-is.
