# FIXES-0.19.0 — "Import from AI" full-fidelity workstream

**Scope:** make the v0.17.0 "Import from AI" feature carry every field
setting the native field shape supports, instead of the 7 it carried
(key, name, label, type, required, instructions, choices). Everything
else was silently stripped on import — a data-fidelity bug for anyone
round-tripping groups through an AI.

## Design: one schema definition, used three ways

**File:** `includes/admin/class-ai-import.php` (rewritten; `TEMPLATE_VERSION`
`'1'` → `'2'`).

New `FIELD_SHAPE` private const: 36 importable field keys, each with a
one-line LLM-facing description. It is the single source of truth —
`template()` builds the `fields[]` schema from it, `prompt()` renders its
lines, and `validate()` warns on (and drops) any key *not* in it. The
sanctioned change path for a future setting: add the key to FIELD_SHAPE
+ sanitize it in `validate_field()`; the template and prompt follow
automatically. The parent agent's standing rule ("keep template and
validator in lockstep") is now structural, not conventional.

Also new: `GROUP_KEYS` const (`title`, `fields`, `location`,
`location_match`, `exclude_from_ai`) for unknown group-level key
warnings, and `OPERATORS` mirroring `Group_Store::OPERATORS`.

New limits: `MAX_SUB_DEPTH = 3` (nesting depth), `MAX_LOGIC = 25`
(conditional rules per field); `MAX_FIELDS = 200` now counts sub-fields
and layout fields recursively.

## What the importer now carries

- `default`, `placeholder` (wp_kses_post; wysiwyg defaults get the
  field's own sanitizer unless `allow_unfiltered`)
- `message` (message-type only; elsewhere warned + dropped)
- `conditional_logic` — structural validation (each rule needs `field`,
  `operator`, `value`; operator must be a real operator) plus a deferred
  within-group reference check: a rule may only point at another
  top-level field key in the same group. Unknown references are hard
  errors. Inside sub-fields/layouts, conditional logic is stripped with
  a warning (matches the native store's v1 behaviour)
- Numerics: `min`, `max`, `step`, `maxlength` (all types; non-numeric
  rejected)
- `multiple` for checkbox/select/radio/button_group
- Relational block: `post_types`, `allow_external` (post_object),
  `taxonomy`, `save_terms`/`load_terms` (taxonomy; require a taxonomy —
  hard error otherwise), `field_type`, `roles` (intersected with real
  roles; unknown roles dropped), `filter_taxonomy`/`filter_term`,
  `mime_types`, `image_size` (against real image sizes)
- `toolbar` (against `Field_Registry::WYSIWYG_TOOLBAR_PRESETS`; unknown
  presets warned + dropped)
- `allow_unfiltered` (bool; admin opt-in, same trust model as the builder)
- Map: `enable_search`, `default_lat`, `default_lng`, `default_zoom`
- `group` → `sub_fields` (recursive; nested group/clone/flexible inside
  sub-fields rejected; missing sub-field keys get the same deterministic
  `f_` + md5 seeds Group_Store uses, so preview matches the write)
- `flexible_content` → `layouts` (key format, unique keys, labels,
  min/max, `button_label`; nested flexible/clone and layout-only types
  rejected)
- `clone` → source group ID (absint + `Group_Store::instance()->get()`
  existence check — a clone of a nonexistent group is a hard error)
- Group level: `location_match`, `exclude_from_ai`

Deliberate mirrors of `Group_Store::sanitize_field()`: `required` forced
false for non-storing types; other settings accepted regardless of type
(exactly as the native store does); taxonomy existence is left to
`Group_Store::create()` at confirm (existing light-touch pattern).

Unknown keys are never silent any more: they produce warnings
("unknown key … will be ignored") at both field and group level.
Type-scoped structural keys (`sub_fields`, `layouts`, `button_label`,
`clone`, `message`) on the wrong types warn + drop.

## Template + prompt

`template()` and `prompt()` are regenerated from FIELD_SHAPE, so they
can't drift from the validator. The template gained a worked example
group exercising placeholder, conditional_logic (github_url shows when
role == dev), a group with sub_fields, and a relationship with
post_types + multiple — the example validates with zero warnings, which
is itself a test. `prompt()` carries two mini-examples (conditional
logic; group-with-sub-fields).

## Preview UI

**File:** `src/admin/src/components/AiImportModal.js` — the preview table
gains a "Settings" column rendering the new per-field `settings` array
from `preview()` (`describe_settings()` in PHP): defaults,
placeholders, choice counts, conditional rule counts, min/max/step,
relational config, sub-field/layout counts, clone target, etc. Empty →
"—". Admin bundle rebuilt (`npm run build`, webpack exit 0).

## Bug found during testing

`describe_settings()` used `mb_strimwidth()` — the lab PHP has no
mbstring extension and the wp-cli mb polyfill in this environment
covers `mb_strlen`/`mb_substr` but *not* `mb_strimwidth`, so preview
generation fatal-errored. Fixed with a `trim_text()` helper using only
`mb_strlen`/`mb_substr`. (Note for the record: the pre-existing
`mb_strlen` calls in `validate()` were already relying on that polyfill.)

## Verified

- 64/64 assertion checks PASS: template shape (v2 schema, all new keys
  in schema, prompt documents them), template's own example validates
  with zero warnings, complex 14-field group (conditional logic ×2 rules
  incl. `!empty`, min/max/step, maxlength, message text, wysiwyg toolbar,
  map defaults, mime/image_size, roles intersected against real roles,
  taxonomy + save_terms, post_object + allow_external, group sub-fields
  incl. deterministic key fill, flexible layouts incl. min/max and
  button_label) — every setting carried through `validate()`.
- 12 hostile payloads rejected with clear errors: unknown type,
  conditional referencing unknown field, bad operator, clone of
  nonexistent group, clone without source, save_terms without taxonomy,
  nested group in group, layout-only type in layout, layouts garbage,
  non-numeric min, nested flexible in layout.
- Unknown keys (field + group) → warnings, not silent; sub-field
  conditional logic stripped with warning.
- Regression: the original v0.17.0 simple template shape (no new keys)
  still validates with zero warnings.
- End-to-end: validate → `AI_Import::create()` → read back via
  `Group_Store::get()` — all 33 checks PASS, every setting persisted
  (conditionals, defaults, toolbar, map, roles, mime, sub-fields,
  layouts, button_label, exclude_from_ai, location_match). Clone of the
  created group validates. Test groups deleted afterwards.
- Existing suites 7/7 PASS (classic, content-type, context-export,
  flexible-clone, group, map, wysiwyg). `debug.log` 0 bytes.
- Live-browser modal click-through is *not* done in this pass (no
  browser delegation available to this worker); the preview column is
  covered by the bundle build + `preview()` unit checks above. Recommend
  a quick visual pass before release.

## Not faithfully importable (by design)

- Conditional logic *inside* sub-fields/layouts: stripped with warning
  (native store doesn't support it in v1).
- Unknown keys anywhere: warned + dropped (never silent).
- `roles` values that aren't real roles: dropped (intersected).
- Unknown `toolbar` presets: warned + dropped.
- `required: true` on message/separator/tab: forced false (mirrors the
  native store).
- Taxonomy existence is not checked at validate time; a bad taxonomy
  name fails later at `Group_Store::create()` during confirm (existing
  pattern, unchanged).
