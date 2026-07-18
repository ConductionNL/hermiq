# agent-files Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- hermiq-agent-files-schema (this change — schema)
- hermiq-agent-files (dependent — controller + folder resolution + Files widget + form field)

## Purpose

Give each agent a per-agent **Files** surface — "like a Claude project" —
combining a configurable upload folder for chat attachments and a list of related
files the agent scans. Per ADR-024's concept model this is **not** a fourth
concept beside Skill/Context/Memory: related files are `Context.files[]` material
attached via `Agent.contextRefs` and read into the one budgeted preamble by
`ContextAssembler::resolveFiles()`, and an upload is a file written into the acting
user's own Nextcloud. The only piece of durable agent state with no existing home
is *where uploads land*. This change delivers only that schema field —
`Agent.uploadFolder` — and its version-gated re-import; folder resolution, the
Files widget, the agent-owned Context bundle, and the form field are specified for
the dependent code change.

## ADDED Requirements

### Requirement: The Agent schema declares a per-agent upload folder

The `Agent` schema MUST declare an `uploadFolder` property, so that an agent's
chat-attachment destination can be configured per agent instead of being a single
hard-coded folder shared by every agent and user.

The `Agent` schema (slug `agent`, in `lib/Settings/hermiq_register.json`) MUST
declare an `uploadFolder` property of `type: string` with `default:
"Hermiq/Attachments"`. Its value MUST be interpreted as a folder path relative to
the acting user's Nextcloud folder, carrying identical semantics to
`Context.files[].path` and to the current
`ChatAttachmentController::ATTACHMENTS_FOLDER` constant. The property MUST NOT be
added to the schema's `required` list.

#### Scenario: The widened schema is imported

- GIVEN the `Agent` schema at register `info.version` 0.16.0 without an `uploadFolder` field
- WHEN the register is re-imported at the bumped `info.version` 0.17.0
- THEN the `Agent` schema exposes an `uploadFolder` property of `type: string`
- AND `uploadFolder` declares `default: "Hermiq/Attachments"`
- AND `uploadFolder` is absent from the schema's `required` list

#### Scenario: An agent persists an upload-folder override

- GIVEN the widened `Agent` schema is imported
- WHEN an Agent object is saved with `uploadFolder` set to `"Hermiq/ProjectX"`
- THEN the object persists with `uploadFolder` equal to `"Hermiq/ProjectX"`
- AND an Agent object saved WITHOUT any `uploadFolder` value still validates (the field is optional)

#### Scenario: Existing agents are unaffected and read the default

- GIVEN Agent objects stored before the widened schema was imported
- WHEN they are read after the re-import
- THEN they remain valid and readable
- AND their absent `uploadFolder` is treated as the default `"Hermiq/Attachments"`, so their attachment destination is unchanged

### Requirement: Related files remain modeled as Context

The `Agent` schema MUST NOT gain any related-files field (such as `relatedFiles[]`
or a schema-level `files` array). Related files MUST continue to be modeled as
`Context.files[]` entries on a Context bundle referenced by the existing
`Agent.contextRefs` property, preserving ADR-024's three-concept model
(Skill/Context/Memory) and its single assembly seam.

#### Scenario: No related-files field is introduced on Agent

- GIVEN the widened `Agent` schema at `info.version` 0.17.0
- WHEN its properties are inspected
- THEN the only property added relative to 0.16.0 is `uploadFolder`
- AND no `relatedFiles`, `files`, or equivalent related-files array is present on the `Agent` schema
- AND the existing `contextRefs` property (Context uuids) is unchanged

#### Scenario: contextRefs stays the seam for related files

- GIVEN an agent whose `contextRefs` references a Context bundle with `files[]` entries
- WHEN `ContextAssembler::assembleForAgent()` runs for that agent
- THEN those `files[]` are read into the budgeted preamble via the existing `resolveFiles()` path
- AND no new assembly path is introduced by adding `uploadFolder`
