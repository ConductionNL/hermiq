---
kind: code
depends_on: []
---

# Proposal: hermiq-skill-markdown-authoring

## Summary

Give the Skills catalog a real skill-authoring form. Today the SkillsCatalog page
(`type: "index"`, route `/skills`) is a generic OpenRegister index whose built-in "Add"
CTA opens nc-vue's schema-driven create/edit dialog — so a user authoring an
agentskills.io skill types the SKILL.md `body` and the YAML `frontmatter` into raw
textareas with no markdown affordances and no preview. This change replaces that generic
dialog, for both create AND edit, with a purpose-built `SkillFormModal` that uses the
lib's existing `CnMarkdownEditor` for the SKILL.md `body` alongside plain fields for
`name`, `description`, and `frontmatter`. It mirrors the just-shipped `AgentFormModal`
wiring exactly: SkillsCatalog's TOP-LEVEL `slots: { form-dialog: "SkillFormModal" }` +
registration in `src/customComponents.js`. The modal lets a user WRITE markdown directly
or PASTE a full agentskills.io package, and saves through the existing skill create/import
path — no new backend, no new endpoint.

## Motivation

Skills are the agentskills.io-format capability objects an agent can be given (see the
`skills-catalog` spec). Hermiq can already import a pasted package, export, install-onto-agent,
and browse — but it has no first-class way to *author* a skill. The generic schema form
renders `frontmatter` and `body` as undifferentiated `<textarea>`s: no markdown toolbar, no
live preview, no guidance that `body` is SKILL.md and `frontmatter` is YAML. Authoring a
skill by hand is the natural on-ramp before the conversational authoring flow (the dependent
change `hermiq-skill-conversational-authoring`), which reuses this very modal to turn a
chat-produced SKILL.md into a reviewable Skill. Making direct authoring good first is the
prerequisite for that seam and an immediately useful capability on its own.

## Affected Projects

- [ ] Project: `hermiq` — new `src/modals/SkillFormModal.vue`; register it in
  `src/customComponents.js`; add `slots.form-dialog: "SkillFormModal"` to the SkillsCatalog
  page in `src/manifest.json`. No backend change.

## Scope

### In Scope

- A new `src/modals/SkillFormModal.vue` (ADR-004 modal-isolation, own file under
  `src/modals/`) that accepts the `#form-dialog` scoped-slot bindings `{ show, item, schema, close }`
  exactly like `AgentFormModal` — `item` is the Skill being edited (null in create mode),
  `close` hides the host dialog.
- Fields: `name` (NcTextField, required), `description` (NcTextField), `frontmatter`
  (a plain text field for the YAML block), and `body` authored via the lib's
  `CnMarkdownEditor` (SKILL.md markdown, `value`-in / `@input`-out contract).
- A "paste a full package" affordance: when the pasted text is a fenced agentskills.io
  package, it is parsed client-side/server-side so `frontmatter` + `body` populate the form
  rather than being stored as one opaque blob (reuses the existing import path, which calls
  `SkillSerializer::fromPackage`).
- Save through the existing skill persistence path (`SkillService` via
  `src/api/skills.js`) for both create and edit; on edit, the existing Skill payload is
  merged so schema fields the form does not surface (`state`, `source`, `installedOn`,
  provenance) survive the write — the `AgentFormModal` pattern.
- Wiring: SkillsCatalog page top-level `slots.form-dialog` + `src/customComponents.js`
  registration, so the built-in Add CTA and row edit mount this modal in place of the
  generic dialog.
- Auxiliary `files` editing: the modal surfaces the Skill's `files` array (each an
  `{ name, content }` object per the schema) with add / rename / edit-content / remove,
  so multi-file agentskills.io skills can be hand-authored. File content uses a plain text
  field (Markdown files may use `CnMarkdownEditor`); the array round-trips through the
  existing persistence path unchanged.

### Out of Scope

- No new backend service, controller, endpoint, or route — persistence reuses the
  existing `SkillController`/`SkillService` create/import + update paths.
- No schema change — `Skill` (slug `agentskill`) already declares `frontmatter` (string)
  and `body` (string); nothing is added or removed.
- No quarantine/marketplace change — a locally-authored skill follows the existing
  create path; cross-org/hub install and its quarantine gate are untouched (owned by
  `skills-marketplace`).
- No conversational "Save as skill" seam — that is the dependent change
  `hermiq-skill-conversational-authoring`.
- No richer YAML-aware `frontmatter` editor — `frontmatter` stays a plain text field
  (a structured YAML editor is deferred).

## Approach

Port the `AgentFormModal` slot pattern to skills. `CnPageRenderer` forwards a page's
top-level `slots` map as scoped slots to its page host; `CnIndexPage` exposes a
`#form-dialog` scoped slot with scope `{ show, item, schema, close }` that, when provided,
replaces its built-in create/edit dialog. So: add `slots.form-dialog: "SkillFormModal"` to
the SkillsCatalog page, register `SkillFormModal` in `customComponents.js`, and build the
modal to accept those bindings. The `body` field is a `CnMarkdownEditor` (already exported
from `@conduction/nextcloud-vue`); `frontmatter` and the two identity fields are plain
NC inputs. Save routes through `src/api/skills.js` (create/import for new, update for edit).
A pasted full package is detected (leading `---` fence) and split via the existing import
path so it lands as structured `frontmatter` + `body`. Details in design.md.

## New Dependencies

None. `CnMarkdownEditor` already ships in the pinned `@conduction/nextcloud-vue` and is
exported from its index. No new npm or PHP dependency.

## Impact

- Frontend only: one new modal component, one `customComponents.js` entry, one
  manifest slot line. The SkillsCatalog page keeps its columns, row-actions widget, and
  route unchanged.
- The generic schema-driven create/edit dialog is no longer reached for skills — its
  behaviour is superseded, not deleted (it remains the fallback for any page without a
  `form-dialog` slot).
- No API surface change; existing `src/api/skills.js` calls are reused.

## Cross-Project Dependencies

None at runtime. This change consumes `CnMarkdownEditor` and the `#form-dialog`
scoped-slot contract from the already-pinned `@conduction/nextcloud-vue` (nc-vue), the
same lib `AgentFormModal` consumes — no version bump is required.

## Risks

### Risk 1: The `#form-dialog` slot contract could differ from what AgentFormModal assumes

**Severity:** Medium — **Mitigation:** Mirror `AgentFormModal` exactly (verified: it
accepts `{ show, item, schema, close }`, folds `item` into an `effectiveAgent`, and calls
both `$emit('close')` and `this.close?.()`). Accept `schema` as an unused prop to avoid a
Vue extraneous-attribute warning, exactly as AgentFormModal does. A Playwright check that
Add and row-edit open the new modal covers regressions.

### Risk 2: A pasted full package could be stored double-fenced or as an opaque blob

**Severity:** Low — **Mitigation:** Route a detected package (leading `---` fence)
through the existing import path so `SkillSerializer::fromPackage` splits it; store the
raw frontmatter block and body separately, never re-fenced. The serializer is
byte-for-byte lossless (verified in `SkillSerializer`), so a subsequent export reproduces
the original.

## Rollback Strategy

Revert the three edits: delete `src/modals/SkillFormModal.vue`, remove its
`customComponents.js` entry, and drop the `slots.form-dialog` line from the SkillsCatalog
page in `src/manifest.json`, then rebuild the frontend. The SkillsCatalog page falls back
to the built-in generic create/edit dialog immediately; no data migration or backend
change is involved, so rollback is a pure frontend revert with zero data impact.
