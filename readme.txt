=== TK Fields ===
Contributors: iodine
Tags: custom fields, field groups, gutenberg blocks, elementor, post meta
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.20.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Native WordPress custom fields — 35 field types, clean storage, built on core APIs. Free forever, no lock-in.

== Description ==

TK Fields adds structured custom fields to WordPress the native way: field groups built with WordPress components, values stored as plain post meta (no proprietary tables), and display handled through Gutenberg blocks, Elementor dynamic tags, or the classic editor.

**Field groups, not shortcodes**

Create a field group, attach location rules (post type, taxonomy, page template — including AND/OR rule groups), and add fields from a searchable picker: text, number, email, URL, date pickers, WYSIWYG, image/file/gallery, select/radio/checkboxes, post object, taxonomy, user, relationship, map with address search, flexible content layouts, groups, repeaters, and clone fields, plus layout helpers (message, separator, tab).

**Display your data**

* Gutenberg: Field Value and Field Group blocks with a block-bindings source, so field values can feed any block that supports bindings.
* Elementor: typed dynamic tags for every field type, plus a repeater widget.
* Classic editor: meta boxes render your fields on the post edit screen.
* Developers: a canonical `tk_*()` PHP API, a REST API (`tk/v1`), WP-CLI commands, AI context export, and read-only Abilities.

**Interoperability**

A one-click importer detects field groups from the Advanced Custom Fields plugin and converts them natively. An opt-in compatibility shim provides `get_field()`-style functions for themes written against other field plugins.

**Privacy**

TK Fields collects no telemetry and phones home nowhere. Two features make remote requests only when you use them: the Map field queries the Photon geocoding API (photon.komoot.io) from your browser as you type an address, and oEmbed fields fetch embeds from the provider you paste a URL from. The Map field's editor preview also loads OpenStreetMap map tiles (tile.openstreetmap.org, with the required attribution shown in the map corner) whenever a map field renders in the post editor — tiles only, no address or user data is sent. Nothing else fires on its own.

**Icon credits**

Field-type and UI icons are WordPress core Dashicons, which ship with WordPress under the GPL — no third-party icon assets are bundled with the plugin.

== Installation ==

1. Upload the `tk-fields` folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to TK Fields → Field Groups and create your first group.

== Build from source ==

The field-group builder UI is compiled from included source with `@wordpress/scripts` (webpack). The compiled bundle in `assets/admin/build/` is generated, not hand-written; the full source lives in `src/admin/src/`.

To rebuild it yourself:

1. `cd src/admin && npm install`
2. `npm run build` (outputs to `assets/admin/build/`)
3. `npm test` runs the JS smoke tests in `src/admin/tests/`

PHP unit tests live in `tests/` and run with PHPUnit (see `tests/bootstrap.php`).

== Frequently Asked Questions ==

= Do I need the block editor to use TK Fields? =

No. Field groups can be edited in the block editor or the classic editor (meta boxes). A few composite field types (Relationship, Flexible Content, Clone) are block-editor-only in the current version; the classic editor shows them in a collapsed notice rather than failing silently.

= Where are field values stored? =

As plain post meta (also term meta and user meta where applicable), one row per field. There are no custom database tables, so your data stays readable by any tool and survives deactivation — uninstalling never deletes field values.

= Can I import my existing field groups? =

Yes — the built-in importer detects field groups from the Advanced Custom Fields plugin and converts field names, types, choices, location rules, and layouts. Anything that cannot be converted is listed in the import report.

= Is there a paid version? =

No. TK Fields is free software, licensed GPLv2 or later, with no premium tier, no upsells, and no locked features.

== External services ==

TK Fields sends no data anywhere on its own. The only remote requests happen inside the WordPress admin (never on your site's public pages), and only when you use the Map field:

= Photon geocoder (photon.komoot.io) =
Used by the Map field's optional address search. As the editor types an address, the typed text is sent to the Photon geocoding API so matching addresses can be suggested and converted to coordinates. This happens only in the post editor, only when the field's "Enable address search" setting is turned on (it is off by default), and each keystroke is debounced — nothing is sent until you use the search box. The request goes directly from the editor's browser to photon.komoot.io; Photon is a public demo server provided by Komoot and needs no API key.
Usage terms: https://github.com/komoot/photon#demo-server (fair-use limits; heavy use is throttled or banned)
Privacy policy: https://www.komoot.com/privacy

= OpenStreetMap tiles (tile.openstreetmap.org) =
Used by the Map field's editor preview. The map canvas in the post editor loads map tiles from tile.openstreetmap.org so the editor can see and click a location. This happens whenever a map field input renders in the post editor. Tile requests carry only the standard technical data of any image request (IP address, browser user agent); no address, field value, or other user data is sent to the tile server. The required "© OpenStreetMap contributors" attribution is shown in the map corner.
Tile usage policy: https://operations.osmfoundation.org/policies/tiles/
Privacy policy: https://wiki.osmfoundation.org/wiki/Privacy_Policy

= oEmbed providers (your choice) =
oEmbed fields use WordPress core's oEmbed discovery. When an editor pastes a URL from a supported provider (for example YouTube or X), WordPress fetches the embed HTML from that provider's oEmbed endpoint. Data is sent only to the provider of the URL you pasted, and only when you paste it. This is core WordPress behavior; each provider's own terms and privacy policy apply.

== Changelog ==

= 0.20.7 =
* Compliance: the admin build's `.npmrc` no longer ships in the release zip — the release builder now fails the build if any hidden file lands in the staged tree (the wordpress.org upload scan rejected 0.20.6 for it).

= 0.20.6 =
* Compliance: added an "External services" readme section documenting the Map field's Photon geocoder address search (opt-in, editor-side only) and the OpenStreetMap tile preview, with terms and privacy links; corrected the main file header, which wrongly claimed there is no external service.
* Compliance: the public GitHub repository the Plugin URI points to (https://github.com/iodine-53/tk-fields) now exists and holds the plugin source.
* Housekeeping: the TK_FIELDS_VERSION constant and the admin app's package.json version now track the plugin version.

= 0.20.5 =
* Compliance: finishes the Plugin Check pass — the remaining 5 errors from the 0.20.4 re-check are resolved (translators comments placed directly above their calls, documented ignore for the int-cast user ID, direct-access guard moved to the top of class-blocks.php).
-
= 0.20.4 =
* Compliance: addresses all 209 Plugin Check errors — i18n placeholders ordered with translators comments throughout the admin, renderers and Elementor widgets hardened with proper output escaping, `suppress_filters => true` removed, `Tested up to` corrected to the major version, and `wp_register_ability_category()` guarded so WordPress 6.8 stays supported. The admin build's `.npmrc` no longer ships in the release zip.

= 0.20.3 =
* Fix: groups last saved by 0.20.1 whose conditional logic used the old `not_empty` operator now validate again — the group store translates the legacy operator to `!empty` on save instead of rejecting the group.
* Release hygiene: the plugin zip no longer ships the PHP/JS test suites or the internal FIXES-*.md build notes (they stay in the GitHub repo); the admin app's package.json version now tracks the plugin version.

= 0.20.2 =
* Compliance: the bundled Flaticon UIcons icon-font subset is gone — field-type and UI icons are now WordPress core Dashicons (GPL, shipped with WordPress; no third-party icon assets bundled). Content types created before this change keep their icons: legacy `fi-sr-*` values are translated to their Dashicons successors automatically.
* Compliance: added a root `package.json` and a "Build from source" readme section documenting how the admin UI bundle is rebuilt from `src/admin/src/`; the main file header no longer claims there is no build step.
* Fix: the builder's "is not empty" conditional-logic operator now saves as `!empty`, matching the canonical operator set used by the AI importer and server-side validation — builder-created rules and imported rules now agree.
* Polish: replaced a joke placeholder URL in the AI context export's oEmbed sample value with a neutral example.com URL.

= 0.20.1 =
* New: field-group renderer — opening a post in the block editor now automatically inserts the `tk/field-repeater` wrapper for every top-level repeater field in the post's assigned field groups (new `tk/v1/post-repeaters` endpoint, `edit_posts`-gated like the sibling field-def route; schema only, no values). Insertion is idempotent: exactly one wrapper per field, matched by the canonical fieldKey, and a wrapper you remove mid-session is never re-inserted until the next editor load. Repeater fields are now fully usable through the editor UI — no console calls needed.
* Compliance: the Elementor repeater dynamic tag now escapes its output — one escaped text line per row — closing a stored-XSS path when a sub-field carries the "allow unfiltered HTML" opt-in. The tag's typed data layer (`get_value()`) is unchanged; the escaping lives at the rendering boundary, the same role `render()` plays in the sibling tags.
* Compliance: password sub-fields inside repeaters, groups, and flexible-content layouts are now unconditionally excluded from AI context export, matching the long-standing top-level password exclusion. The export is schema-only (values were never exposed); this closes the shape disclosure gap.
* Compliance: added the missing `tk-fields` text domain to a validation message in the repeater block editor.
* Branding: plugin name finalized as "TK Fields"; Author set to "Iodine" and readme Contributors to "iodine" per the pre-submission decisions.
* Polish: main plugin file header rewritten — the internal phase-by-phase build log is replaced with a clean description of what the plugin is, its architecture, and highlights.
* ACF import UX: the import dialog now explains how the direct import works (reads field groups straight from the active ACF plugin — no export file needed; skips are reported with reasons). When ACF is not active, the dialog offers an "Import with AI" fallback: a copy-to-clipboard transformer prompt (generated server-side from the live FIELD_SHAPE, so it can't drift) to paste into ChatGPT/Claude/Gemini together with the ACF JSON export, with the next step spelled out — paste the AI's JSON into "Import from AI".

= 0.20.0 =
* New: repeater field type — repeatable rows of sub-fields, nested two levels deep (e.g. course modules → lessons). Rows are stored as native `tk/field-repeater` blocks with stable row IDs (never array indexes); context is a full path stack end-to-end, so nested saves can never overwrite a sibling row's data.
* Repeater settings: min/max row counts, add-row button label, list/grid row layout, and collapsed (which sub-field drives the row summary). Builder UI with sub-field editing, tooltips on every control, and a block-editor-only badge — no classic-editor UI in v1, same as flexible content.
* Repeater reads return the typed CQ1 shape (`list<array<string,mixed>>`, recursive — an image sub-field returns the identical DTO as a top-level image field); `tk_get_sub_field()` resolves against the nested row-context stack.
* "Import from AI" accepts repeaters: `sub_fields` (recursive), `min`/`max`, `button_label`, `layout` (`list`|`grid`, ACF table/row/block layouts mapped), `collapsed` — all carried with zero dropped settings; depth 3+ nesting is a hard error, never a silent drop. The ACF importer re-maps ACF `repeater` fields to TK repeaters (no auto-migration of existing groups).
* New Elementor dynamic tag (tk-fields-repeater): resolves a repeater field + dot-notation sub-field path through the Fields service typed read, one entry per row in stored order; the existing repeater widget stays the render primitive.
* New `tk/field-repeater` block: dedicated wrapper with stable fieldKey identity (no editable field name), composed from the existing row machinery; nested wrappers resolve recursively; copy/paste across posts regenerates row IDs.
* Note: `tk/field-repeater` blocks are inserted automatically by the field-group renderer — the block stays out of the inserter by design, and `window.tkFieldRepeater.createFromFieldDef()` remains the programmatic entry point.

= 0.19.0 =
* Feature: "Import from AI" is now full-fidelity — the importer carries all ~35 field settings, not just the basic seven. Conditional logic, defaults, placeholders, min/max/step/maxlength, choices + multiple, toolbar presets, map settings (search, default lat/lng/zoom), relationship/post-object settings (post types, allow external), taxonomy settings (taxonomy, field type, save/load terms), user roles, file MIME types and image size, allow_unfiltered, message text, clone targets, group sub-fields, and flexible-content layouts (keys, labels, min/max, button label) all survive the round trip. See FIXES-0.19.0.md.
* The import preview now shows a Settings column summarising each field's extra settings, so nothing is imported blind.
* Unknown field or group keys are reported as warnings instead of being silently dropped; conditional logic is structurally validated and must reference another top-level field in the same group.

= 0.18.0 =
* Security: the tk/field-value block's wysiwyg renderer now resolves the field through the field registry and sanitizes the value with that field's own sanitizer — a hand-crafted block (block-comment JSON is editable by contributors/authors) can no longer smuggle raw HTML/script onto the frontend. Unknown fields, or a type mismatch between the block attributes and the registered field, refuse to render. See FIXES-0.18.0.md.
* Compliance: the Leaflet map library is now vendored locally (assets/leaflet/, BSD-2-Clause) instead of loading from the unpkg CDN — all editor JavaScript and CSS ships inside the plugin.
* Improved: uninstall now removes field-group definition posts and the custom `manage_tk_fields` capability (field values on posts/terms/users are still left untouched).
* Improved: readme Privacy section now discloses that the map field's editor preview loads OpenStreetMap tiles; the search help text says the same.
* Improved: release zip no longer ships development files (tests, package manifests, internal dev notes, consultation scratch files).
* Fixed: ABSPATH direct-access guards added to the three generated asset manifests that lacked them; duplicate `= 0.16.0 =` changelog heading merged into `= 0.15.1 =`.

= 0.17.1 =
* Fixed: bound-block editing could silently corrupt field values. Three defects in the editor bindings script (`assets/bindings/bindings.js`): (1) every keystroke fired its own save request and each response overwrote the editor cache with its own request's value, so responses yanked the text backward past newer optimistic edits — typing "Dune: Part Two" could store "Dune: Part TwoDune: Part Two"; (2) the first keystroke of an empty bound paragraph arrived as a non-primitive value object that was saved as empty, wiping the character; (3) server whitespace trimming applied mid-typing ate spaces in values like "Slow and steady". Writes are now serialized through a one-in-flight pump (coalescing keystrokes, every payload carries the full value, a response is applied only when no newer value is queued), first-edit values are coerced to their text, and server normalizations are deferred until the user moves to another block. Stored values now equal exactly what was typed, fast or slow, keystroke or paste. See FIXES-0.17.1.md.

= 0.17.0 =
* New: AI round trip — "Import from AI" on the field groups list. Copy the prompt (schema + closed field-type list + worked example, generated from the live registry), paste it into an AI assistant, paste the AI's JSON back, review the preview, and confirm. Strict validation (unknown types rejected, aggressive sanitization, sane limits), conflict handling for duplicate titles, capability-gated REST routes.
* Improved: AI Context export now emits type-aware Block Bindings snippets (paragraph for text-ish fields, image block for images, buttons block for links, guidance comments for array-valued fields instead of misleading bindings), real location rules, and a `sample_value` per field for better AI code generation.

= 0.16.2 =
* New: TK Post Listing block for Gutenberg — the Elementor widget's twin. Same query engine, same card markup, same design tokens, so both builders render sibling output. Query, Card, TK Fields, Pagination and Style panels in the inspector, help text on every setting, live preview in the canvas.
* New: shared post-listing renderer — the Elementor TK Post Listing widget and the new Gutenberg block now delegate to one engine (`Post_Listing_Renderer`), so a fix or improvement lands in both builders at once.

= 0.16.1 =
* New: TK Post Listing widget gains card-uniformity settings — "Equalize Card Heights" (on by default) makes every card in a row the same height with the Read More link pinned to the bottom, and "Excerpt Lines" (default 3, 0 hides) controls the excerpt line-clamp. Both in the Card section with help text on every setting.
* Improved: excerpt line-clamp is now driven by a CSS variable so the Excerpt Lines setting applies without markup changes.

= 0.16.0 =
* New: TK Studio v1 design language — shared design tokens (spacing, radii, motion, theme-adaptive surfaces) consumed by Gutenberg blocks and Elementor widgets alike, so both builders render sibling output.
* New Elementor widgets: TK Accordion, TK Slider, TK Parallax and TK Countdown, each with full Content/Style controls (typography, colors, spacing, borders, motion) and help text on every setting.
* New Elementor listing widgets: TK Post Listing (any post type incl. Content Types, TK field display, grid/list, pagination), TK Taxonomy Terms (pill cloud/list), TK Related Posts (driven by TK Relationship fields with same-term fallback).
* Improved: Gutenberg accordion, slider, parallax, countdown and scroll-reveal blocks restyled with premium defaults — card accordions, pill slider dots, parallax scrims, countdown unit cards.
* Fixed: Elementor widgets no longer hand-write image loading attributes (WordPress core owns lazy-loading); slider only emits data-autoplay when autoplay is enabled.

= 0.15.1 =
* Fixed: the import file-picker now shows the pre-import preview before importing (a state reset was wiping the freshly computed preview).
* Fixed: per-row Export no longer 404s on plain-permalink installs (export URL now built with the URL API).
* Fixed: the internal content-type post type no longer inherits the field-group capability mapping — dedicated capability keeps Authors from touching definitions.

= 0.15.0 =
* New: Content Types — a custom post type builder. Create post types (Movies, Portfolio, Events…) with labels, slugs, visibility, URLs, features, taxonomies and menu icons, no code. Includes JSON export/import, live slug validation with a reserved-name blocklist, explicit slug migration that moves existing posts, and autoloaded definition snapshots for zero per-request queries.
* Security: the internal field-group post type now maps every capability to manage_options (previously Authors could inject group definitions via XML-RPC custom fields).
* AI context export now includes content-type schemas alongside field groups.

= 0.14.0 =
* New field-type icons: the type picker, field rows and empty states now use a bundled subset of Flaticon UIcons (Solid Rounded) instead of Dashicons — see readme for attribution.
* Subtle per-category colour tints on the type picker tiles (pastel backgrounds, category-coloured icons).

= 0.13.0 =
* WordPress.org submission readiness: GPL license headers on every source file, LICENSE file, and readme.txt.
* Removed comparison language from the plugin description.

= 0.12.2 =
* Fixed: field groups created programmatically (wp-cli / direct `wp_insert_post`) now get a valid definition on save and appear in the list table and REST API.

= 0.12.1 =
* Fixed: sub-field labels no longer truncate in the collapsed group-options row.
* Fixed: hand-written `tk/field-value` block markup now validates in the block editor.

= 0.12.0 =
* Rebuilt field-group builder UI: searchable type picker with category tabs and icon tiles, one recursive field-row component for nested fields, AND/OR location rule groups, adaptive settings tabs, Flexible Content layouts screen, block-editor-only badges in the picker, and a one-click importer for other field plugins.
* Block Inspector: field selection is now a dropdown instead of manual slug entry.
* Classic editor: block-only field types collapse into a single notice accordion.
* Baseline frontend CSS for group fieldsets.

= 0.11.0 =
* New field type: Group (fieldset wrapper with namespaced sub-fields).

= 0.10.0 =
* Classic editor support: field groups render as meta boxes via a shared PHP field renderer.

= 0.9.0 =
* New field types: WYSIWYG, Map (Photon geocoding), Flexible Content, Clone. 33 field types total.

= 0.8.0 =
* New field types: Post Object, Page Link, Taxonomy, User, Relationship, Gallery. 29 field types total.

== Upgrade Notice ==

= 0.13.0 =
License headers and readme added for directory submission. No functional changes; safe to update.
