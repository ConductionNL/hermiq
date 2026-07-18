# Design: hermiq-agent-files-schema

## Architecture Overview

Hermiq owns no database tables (thin client); every domain object is an
OpenRegister object validated against a JSON schema in
`lib/Settings/hermiq_register.json`. This change widens exactly one schema object
— `Agent` (slug `agent`) — by adding one scalar property, `uploadFolder`, and
bumps the versions that gate the re-import.

The field exists to make the chat-attachment destination per-agent. Today
`ChatAttachmentController` writes every upload into a private constant
`ATTACHMENTS_FOLDER = 'Hermiq/Attachments'`, resolved under
`IRootFolder::getUserFolder($actingUserId)` — i.e. relative to the acting user's
own Nextcloud folder. `uploadFolder` is the agent-scoped override of that
constant; its default is that exact constant value, so nothing changes until an
owner sets it.

This is the head of a two-change chain. The dependent code change
(`hermiq-agent-files`) reads `agent.uploadFolder`, resolves + path-checks it, and
builds the Files surface (widget + form field) — including the **related-files**
half, which reuses the already-present `Agent.contextRefs` → `Context.files`
seam and therefore needs **no schema field here at all**.

## API Design

No API endpoints are introduced or modified by this change. (The code change adds
an `agentId` to the existing `POST /api/chat/attachments`; that is specified
there.)

## Database Changes

No relational tables. The schema delta is a single JSON property on the `Agent`
schema, plus two version bumps:

- `Agent.properties.uploadFolder`:
  ```json
  {
    "type": "string",
    "default": "Hermiq/Attachments",
    "title": "Upload folder",
    "description": "Folder (relative to the acting user's Nextcloud folder) where this agent's chat attachments are stored, created on demand. Defaults to Hermiq/Attachments — today's hard-coded destination. Same path semantics as Context.files[].path."
  }
  ```
- `info.version`: `0.16.0` → `0.17.0`.
- `appinfo/info.xml` `<version>`: `0.1.82` → `0.1.83`.

The re-import is applied by `lib/Repair/InitializeSettings.php` →
`SettingsService::loadConfiguration()` →
`OCA\OpenRegister\Service\ConfigurationService::importFromApp(appId, data, version)`
on app upgrade. See migration.md.

## Nextcloud Integration

- Controllers: none changed by this config change.
- Services: `OCA\Hermiq\Service\SettingsService` (existing) performs the import;
  unchanged — it reads `info.version` from the JSON.
- Mappers/Entities: none (OpenRegister-backed).
- Events/Hooks: `OCA\Hermiq\Repair\InitializeSettings` (existing Repair step)
  runs the import on upgrade.

## Security Considerations

No security impact from the schema field itself: it is a plain optional string
with a default. The trust-relevant behaviour — that `uploadFolder` must be
resolved under the acting user's own folder and path-checked against traversal
before any write — is a **runtime** concern owned by the code change, not the
schema. Storing a per-agent string does not by itself let a caller write outside
their storage; the resolver in the code change is where that guarantee lives.

## Decisions

### Decision 1: One `uploadFolder` string, not a folder object or an array

**Choice:** A single scalar `string`.
**Why:** The requirement is one destination folder per agent. A string mirrors
the existing constant it replaces and the shape of sibling agent config fields
(`model`, `type`). Alternatives considered: (a) an object `{path, shared}` to
carry a per-user-vs-shared flag — rejected, uploads are always resolved under the
acting user's folder (ADR-023 acting-user identity; the current controller
resolves via `getUserFolder` and never from a request parameter), so there is no
shared-folder mode to model; (b) an array of folders — rejected, an upload has
exactly one destination.

### Decision 2: Path is relative to the acting user's Nextcloud folder

**Choice:** `uploadFolder` is interpreted relative to
`IRootFolder::getUserFolder($actingUserId)`, identical to `Context.files[].path`
and the current `ATTACHMENTS_FOLDER` constant.
**Why:** Consistency with the one existing file-path convention in this app and
with the sovereignty posture (the file stays in the user's own Nextcloud; no
shared or absolute paths). An absolute or shared-storage path would introduce a
new trust surface the acting-user model deliberately avoids.

### Decision 3: Default equals today's constant — behaviour-preserving

**Choice:** `default: "Hermiq/Attachments"`.
**Why:** Every existing agent (which has no `uploadFolder`) must behave exactly as
today. Reading the absent field as this default guarantees a byte-for-byte
identical destination until an owner explicitly overrides it.

### Decision 4: No new Agent field for related files

**Choice:** Do not add `relatedFiles[]` (or any related-files field) to `Agent`.
**Why:** ADR-024 already models "Nextcloud files read into the budgeted preamble"
as `Context.files[]`, attached via `Agent.contextRefs` and resolved by
`ContextAssembler::resolveFiles()`. Adding a parallel field would create a fourth
concept the ADR explicitly rejects and a second assembly seam. The code change
uses an agent-owned Context bundle instead. This change therefore adds only
`uploadFolder`.

## Risks / Trade-offs

- [Re-import silently skipped if only one version is bumped] → Bump both
  `info.version` and app `<version>`; migration.md's verification step confirms
  the field is present post-upgrade against Postgres (SQLite breaks OR magic
  tables).
- [Default folder shared with per-turn attachments and any other feature writing
  there] → Intentional; the default is the pre-existing shared destination, so no
  regression. An override simply diverges one agent's uploads.

## Migration Plan

See migration.md. Summary: edit schema + two versions, deploy, the Repair step
re-imports at 0.17.0, verify the `Agent` schema exposes `uploadFolder`. Rollback:
revert the edits; optional field + default means no stored-object migration and
no destructive undo.

## Trade-offs

The main alternative was to model the whole Files surface as new bespoke Agent
fields (`uploadFolder` + `relatedFiles[]`). That was rejected in favour of
reusing Context for related files, keeping this change to the single field that
genuinely has no existing home. This keeps the concept count at three
(Skill/Context/Memory) per ADR-024 and the assembly seam singular.

## Open Questions

None blocking. The per-user-vs-shared question is resolved (per-user, Decision 2);
the auto-join-uploads-to-related-files question is a **code-change** decision and
is carried in that change's deferred questions.
