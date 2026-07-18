# Design: hermiq-agent-files

## Architecture Overview

The per-agent Files surface is a **UI + one controller behaviour change over
existing engine paths** — it introduces no new assembly, no new schema (the
`Agent.uploadFolder` field arrives from `hermiq-agent-files-schema`), and no new
concept. It has two independent halves that share the ADR-024 concept model:

1. **Upload folder** — where a chat attachment is written. Today
   `ChatAttachmentController` writes to the constant `Hermiq/Attachments` under
   `IRootFolder::getUserFolder($actingUserId)`. This change makes that
   destination the agent's `uploadFolder` (default = the same constant).
2. **Related files** — files the agent reads every run. These are
   `Context.files[]` entries on an **agent-owned Context bundle** referenced by
   `agent.contextRefs`, read into the budgeted preamble by the existing
   `ContextAssembler::assembleForAgent()` → `resolveFiles()` path. The Files
   widget on `AgentDetail` is a CRUD surface over that bundle's `files[]`.

```
Chat composer ──POST /api/chat/attachments {agentId, file}──▶ ChatAttachmentController
                                                                 │ resolve agent.uploadFolder
                                                                 ▼ (acting user's folder, path-checked)
                                                        Nextcloud Files: <uploadFolder>/<name>

AgentDetail ▸ Files widget ──CRUD Context.files[]──▶ agent-owned Context bundle (contextRefs)
                                                                 │  (next run)
Engine.processTurn() ─assembleForAgent()▶ resolveFiles() ─▶ budgeted preamble ─▶ guardrail filter ─▶ system prompt
```

## ADR-024 fit — this is Context, not a fourth concept

ADR-024 fixes three concepts (Skill / Context / Memory) converging in one
budgeted preamble via one seam (`ContextAssembler`). This change adds **no new
concept**:

- "Related files the agent can scan" is exactly `Context.files[]` — "Nextcloud
  files (a `{path, description}` per entry) read from the acting user's folder"
  (ADR-024 Context row). We surface a UI over them; we do not model a new
  `Agent.relatedFiles[]` field (the schema change explicitly forbids it).
- The upload folder is not context material at all — it is a *destination
  setting*, one scalar on the Agent. It participates in the concept model only
  indirectly: a file written there can later be added as a related file, but that
  is a user action, not an automatic promotion.

So the "Files surface" the user asked for is presented as one UI, but is backed by
two existing mechanisms (a per-Agent setting and Context.files), keeping the
concept count at three and the assembly seam singular.

## API Design

### `POST /api/chat/attachments` (MODIFIED)

Adds one optional field to the existing multipart request; response shape
unchanged.

**Request (multipart/form-data):**
```
file:    <the uploaded file>            (unchanged)
agentId: <uuid of the active agent>     (new, optional)
```
**Response (200):**
```json
{ "path": "Hermiq/ProjectX/report.txt", "name": "report.txt" }
```
`path` now reflects the resolved agent folder. Absent/unknown `agentId`, or an
agent with no `uploadFolder`, yields the `Hermiq/Attachments` default — identical
to today. 400/401/500 conditions are unchanged from `hermiq-chat-attachments`.

## Database Changes

None. No schema is modified (the field came from the schema change). The
agent-owned Context bundle is an ordinary `Context` OpenRegister object created
through the existing objects API; no relational migration.

## Nextcloud Integration

- Controllers: `ChatAttachmentController` — inject the read path to resolve an
  agent (OpenRegister `ObjectService`/the app's agent read), read `uploadFolder`.
- Services: a small `AgentContextBundleService` (name indicative) for
  resolve-or-create of the agent-owned Context bundle and `files[]` mutation;
  `ContextAssembler` unchanged.
- Mappers/Entities: none (OpenRegister-backed).
- Events/Hooks: none.
- Frontend: a new `AgentFilesWidget.vue` under `src/widgets/`, registered in the
  manifest as widget `agent-files` with a `page.slots.widget-agent-files ->
  agent-files` entry on the `AgentDetail` page (mirroring `agent-memory`); the
  Nextcloud file picker via `@nextcloud/dialogs`; `AgentFormModal.vue` field.

## Decisions

### Decision 1: Upload folder resolves under the acting user, path-checked

`uploadFolder` is resolved with `getUserFolder($actingUserId)` — the acting user
comes from `IUserSession`, never from a request parameter (ADR-023), so a caller
can only ever write into their own storage. Because `uploadFolder` is a *folder*
path (unlike the uploaded filename, it may legitimately contain `/`), it cannot be
reduced to a basename; instead it MUST be normalised and rejected if it contains
`..` segments or resolves outside the user folder, before any `newFolder`/
`newFile`. The uploaded **filename** keeps its existing basename-reduction +
`verifyPath()` (NC32+) + `getNonExistingName()` chain. Alternative considered:
allow absolute/group-folder paths — rejected, it introduces a trust surface the
acting-user model avoids.

### Decision 2: One agent-owned Context bundle, created on demand

The related-files list is backed by **exactly one** Context bundle per agent,
created lazily when the first related file is added (not provisioned up front, so
agents with no related files carry no empty bundle). Resolution:

1. Read `agent.contextRefs`; find the agent-owned bundle (identified by a marker —
   e.g. a reserved `name` such as `"Agent files: <agent name>"` or a dedicated
   description marker — decided at build; see Open Questions).
2. If found, edit its `files[]`.
3. If not found, create a new `Context` object (owner = the agent's owner /
   acting user), then append its uuid to `agent.contextRefs` and save the agent.

**Critical PUT-semantics guard:** OpenRegister `saveObject` is PUT-semantic —
omitted schema properties are nulled. When appending to `contextRefs`, the agent
save MUST carry ALL existing agent fields forward (spread the current agent
payload, mutate only `contextRefs`), or it will silently null `status`/`prompt`/
FKs. This is the same hazard `AgentFormModal.buildPayload()` already handles by
spreading the existing payload.

Alternatives considered: (a) many bundles per agent — rejected, ambiguous which
one the widget edits and needless preamble fragmentation; (b) a dedicated
`Agent.filesContextRef` scalar pointing at the bundle — rejected, it is a
redundant second pointer beside `contextRefs` and would be a schema change; the
marker-in-contextRefs approach needs no new field.

### Decision 3: Uploads and related files are distinct lifecycles

An uploaded attachment is per-**Message** (folded into that one turn's preamble by
`assembleAttachments()`, which reuses `resolveFiles()` verbatim); a related file
is per-**Agent** (read every run from `Context.files`). This change does NOT
auto-add an uploaded file to the related-files list. Rationale: auto-promotion
would (a) silently grow every future run's `charBudget`, (b) persist arbitrary
chat uploads into the agent's durable definition, and (c) blur ADR-024's
lifecycle distinction. The `uploadFolder` setting is the bridge a user can use
deliberately: point uploads at a folder, then add that file as a related file if
persistence is wanted. Alternative considered: a per-agent "auto-add uploads"
toggle — deferred as an open question, not built now.

### Decision 4: Reuse the existing assembly, budget, and guardrail path

Related files are read by the existing `assembleForAgent()` → `resolveFiles()`
with no modification, so they inherit unchanged: the per-Context `charBudget` +
`needsConsolidation` nudge; the `MAX_FILE_BYTES` (20000) per-file cap-and-log;
the skip-and-log tolerance for a missing file/folder; and the guardrail preamble
filter (`hermiq-guardrail-preamble-filter`) that `Engine` applies to the whole
assembled preamble — a related file that contains an injection string raises
`prompt_injection_in_context`, exactly like any other Context material. No new
budget contract and no new trust surface are created.

### Decision 5: Files widget uses the file picker with a path-input fallback

Adding a related file primarily opens the Nextcloud file picker (returns a path
relative to the user's folder — the exact shape `Context.files[].path` wants);
a plain text path input is offered as a fallback affordance. Each entry stores
`{path, description}`. This keeps the stored shape identical to what
`resolveFiles()` already reads.

## Security Considerations

- **Write confinement:** the upload target is always under
  `getUserFolder($actingUserId)`; `agentId` selects only *which folder name*, not
  *whose storage*. A path-traversal in `uploadFolder` is normalised/rejected
  before any write (Decision 1). CSRF stays required on the endpoint (a
  state-changing write reachable from a browser form).
- **Read confinement:** related files are read as the acting user by the existing
  `resolveFiles()`; a path the acting user cannot read is skipped-and-logged, not
  escalated.
- **Untrusted content:** related-file and uploaded-file *content* is untrusted
  input prepended to the prompt (ADR-024 Rule 3); it inherits the org's guardrail
  preamble filter and never becomes executable or an authorization.
- **IDOR on agent read:** resolving `agent.uploadFolder` from a request `agentId`
  must not leak another tenant's config; the agent read MUST respect the acting
  user's read scope (a not-found/forbidden agent falls back to the default
  folder, never errors open to another agent's folder).

## NL Design System

- Files widget uses standard NC components (`NcButton`, `NcListItem`/list,
  `NcEmptyContent` for the no-files state) and the shared file picker; no
  hardcoded colours (CSS variables only); NcSelect/inputs carry `inputLabel` for
  the accessibility gate. The `uploadFolder` field is an `NcTextField` matching
  the sibling fields in `AgentFormModal`.

## File Structure

```
lib/
  Controller/
    ChatAttachmentController.php     (modified: agentId + folder resolution + path-safety)
  Service/
    AgentContextBundleService.php    (new: resolve-or-create agent-owned Context bundle, files[] CRUD)
src/
  widgets/
    AgentFilesWidget.vue             (new: list/add/remove related files; file picker)
  modals/
    AgentFormModal.vue               (modified: uploadFolder NcTextField)
  api/
    chat.js                          (modified: upload() passes agentId)
    context.js                       (new or extended: Context bundle CRUD via the objects store)
  views/
    Chat.vue                         (modified: thread agentId into the upload call)
  manifest.json                      (modified: agent-files widget + slot on AgentDetail)
l10n/
  nl_NL.json, en_US.json             (new strings)
```

## Risks / Trade-offs

- [Crafted `uploadFolder` escapes storage] → Normalise + reject `..`/absolute
  under `getUserFolder`; PHPUnit + spec scenario.
- [Duplicate agent-owned bundles] → Resolve-or-create keyed on `contextRefs` +
  marker; reuse the first match.
- [Agent save nulls other fields when appending `contextRefs`] → Spread the full
  current agent payload; only mutate `contextRefs` (PUT-semantics guard, Decision
  2); test a non-changed field survives.
- [Two file lists confuse users] → Keep visibly separate; document distinct
  lifecycles; no auto-merge.

## Migration Plan

No data migration. Deploy after `hermiq-agent-files-schema` is applied (the field
must exist in the imported schema). Rollback: revert the code; created Context
bundles remain valid objects and simply stop being surfaced.

## Open Questions

- Bundle identity marker: reserved `name` vs a description marker vs a dedicated
  boolean on Context — pick at build (leaning reserved-name, no schema change).
- On-demand vs explicit bundle creation (proposed on-demand).
- Auto-add uploads to related files (proposed: no).
- Whether the composer upload plumbing exists yet (was scoped to
  `hermiq-chat-attachments`, frontend deferred) or must be added here.
