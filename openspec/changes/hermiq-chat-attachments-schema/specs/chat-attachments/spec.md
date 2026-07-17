# chat-attachments Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- hermiq-chat-attachments-schema (this change — schema)
- hermiq-chat-attachments (dependent — controller + storage + turn assembly)

## Purpose

Let a user attach a file to a single chat turn so the agent can use it in that turn. Per
ADR-024's concept model, an attachment is **not** a fourth concept beside Skill/Context/Memory:
it is Context-kind material (a pointer at a Nextcloud file, read as the acting user, rendered
into the one budgeted preamble) with a per-**Message** lifecycle rather than a per-Agent one. The
reference shape therefore mirrors the shipped `Context.files` `{path, description}` precedent, so
the dependent code change can read it through the same permission-respecting `IRootFolder` path
`ContextAssembler::resolveFiles()` already uses — preserving the sovereignty posture documented
in `docs/concepts/safe-setup.md` (the file stays in Nextcloud; no parallel attachment store; the
model container gets text only). This change delivers only the schema field and its
version-gated re-import; upload handling, limits, and turn assembly are specified for the
dependent code change.

## ADDED Requirements

### Requirement: Message schema declares a per-turn attachments reference field

The `Message` schema MUST declare an `attachments` field, so that a single chat turn can carry a
reference to a file in the acting user's Nextcloud without that file becoming part of the
agent's durable definition.

The `Message` schema (slug `message`, in `lib/Settings/hermiq_register.json`) MUST declare an
`attachments` property of `type: array` with `default: []`, whose items are objects with the
fields `path`, `name`, and `description`. `path` MUST hold a file path relative to the acting
user's Nextcloud folder, carrying identical semantics to `Context.files[].path`. The property
MUST NOT be added to the schema's `required` list.

#### Scenario: The widened schema is imported

- GIVEN the `Message` schema at register `info.version` 0.15.0 with fields `conversationId, role, content, sources, context`
- WHEN the register is re-imported at the bumped version
- THEN the `Message` schema exposes an `attachments` array property whose items are `{ path, name, description }`
- AND `attachments` declares `default: []`
- AND `attachments` is absent from the schema's `required` list

#### Scenario: A Message object persists an attachments entry

- GIVEN the widened `Message` schema is imported
- WHEN a Message object is saved with an `attachments` value of one entry `{ path: "Hermiq/uploads/report.txt", name: "report.txt", description: "…" }`
- THEN the object persists with that `attachments` array intact
- AND a Message object saved WITHOUT any `attachments` value still validates (the field is optional and defaults to `[]`)

#### Scenario: Existing messages are unaffected

- GIVEN Message objects stored before the widened schema was imported
- WHEN they are read after the re-import
- THEN they remain valid and readable
- AND their absent `attachments` value is treated as the empty default

### Requirement: The attachment reference carries a path, never an inline body

The `attachments` field MUST model a **reference** to a Nextcloud file, not a copy of its
content. Its items MUST NOT declare a `body`, `content`, `data`, or `base64` field, and MUST NOT
carry the uploaded bytes in any form. The file content SHALL live only in the user's Nextcloud,
so that reading it is governed by Nextcloud's permission model when resolved through the acting
user's folder, and so that no parallel attachment store is created.

This deliberately follows the `Context.files` `{path, description}` precedent rather than the
`Context.documents` `{name, body, format, description}` precedent (ADR-024 Rule 2): `documents`
is inline because it is curated content authored on the object, whereas an attachment points at
a file that already exists in Files.

#### Scenario: Reference shape, not body shape

- GIVEN the widened `Message` schema
- WHEN the `attachments.items` sub-properties are inspected
- THEN they are exactly `path`, `name`, and `description`
- AND no `body`, `content`, `data`, `base64`, or `encoding` sub-property is present

#### Scenario: Shape parity with the files precedent

- GIVEN the `attachments` field and the `Context` schema's `files` field
- WHEN the two are compared
- THEN both are `type: array` with `default: []` and array-of-typed-objects items
- AND both declare a `path` sub-property meaning a path relative to the acting user's Nextcloud folder
- AND each `attachments.items` sub-property (`path`, `name`, `description`) carries its own `title` and `description`

### Requirement: The attachment shape declares no media-type or vision affordance

The `attachments` items MUST NOT declare a `mediaType`, `mimeType`, `encoding`, or any other
sub-property whose purpose is to carry or signal binary/image content for per-provider vision
encoding. Binary and image attachments are out of scope for this capability: there is no
vision/`image_url`/base64 handling in `lib/Service/Llm/` or `lib/Service/Engine/`, and no
text-extraction library in `composer.json`. The schema MUST NOT pre-commit a shape for a
capability that has not been designed, so that no future contributor can enable vision by
populating a field that already exists.

#### Scenario: No binary affordance in the shape

- GIVEN the widened `Message` schema
- WHEN the `attachments.items` sub-properties are inspected
- THEN no `mediaType`, `mimeType`, or `encoding` sub-property is present

### Requirement: No collateral schema change

Only the single `attachments` property SHALL be added. No other `Message` field
(`conversationId`, `role`, `content`, `sources`, `context`) SHALL be changed, no `Conversation`
field SHALL be changed, and no new schema SHALL be introduced. In particular the existing
`Message.context` property — which holds the CnAiContext page snapshot — MUST NOT be overloaded
to carry attachments.

#### Scenario: Only attachments is added to Message

- GIVEN the change diff to `hermiq_register.json`
- WHEN the `Message` schema block is inspected
- THEN the only added property is `attachments`
- AND `conversationId`, `role`, `content`, `sources`, and `context` are unchanged

#### Scenario: Conversation is untouched

- GIVEN the change diff to `hermiq_register.json`
- WHEN the `Conversation` schema block is inspected
- THEN it is unchanged (`title`, `userId`, `agentId`, `metadata` as before)
- AND no attachment field is added to it

### Requirement: The register re-import is version-gated

The change MUST bump the register `info.version` from 0.15.0 to 0.16.0 AND the Hermiq app
`<version>` in `appinfo/info.xml` from 0.1.80, so OpenRegister's version-gated re-import applies
the widened schema on upgrade. `Message.version` MUST bump from 0.1.0 to 0.1.1. An unchanged
version MUST NOT be relied on to re-import, and the field's presence MUST be verified against the
**imported** schema rather than the JSON file on disk.

#### Scenario: Versions bumped so the re-import fires

- GIVEN the register `info.version` is 0.15.0 and `appinfo/info.xml` `<version>` is 0.1.80
- WHEN this change is prepared
- THEN the register `info.version` reads 0.16.0
- AND `appinfo/info.xml` `<version>` is bumped above 0.1.80
- AND the `Message` schema's own `version` reads 0.1.1

#### Scenario: Verification targets the imported schema

- GIVEN both version gates have been bumped and the app upgraded
- WHEN the `Message` schema is read back from OpenRegister
- THEN the `attachments` property is present on the imported schema
- AND a field present only in `hermiq_register.json` but absent from the imported schema is treated as a failure, not a pass

## Non-Functional Requirements

- **Performance:** No runtime cost — this change adds only a schema property and version bumps; no code path reads or writes the new field until the dependent code change lands.
- **Accessibility:** N/A for a schema-only change (no UI in this change).
- **Internationalization:** Dutch and English MUST be supported (ADR-007). Field `title`/`description` text is English in the register (the source-of-truth convention); UI-facing labels are localised in the dependent code change.

## Acceptance Criteria

- `attachments` array property exists on the **imported** `Message` schema with items `{ path, name, description }` and `default: []`.
- `attachments` is NOT in the schema's `required` list.
- No `body`/`content`/`data`/`base64`/`encoding` and no `mediaType`/`mimeType` sub-property is present.
- No other `Message` field is altered, `Conversation` is untouched, and no new schema is added.
- Register `info.version` is 0.16.0, `Message.version` is 0.1.1, and `appinfo/info.xml` `<version>` is bumped above 0.1.80.

## Notes

- Driving decisions: ADR-024 (accepted) Rule 1 (concept split) and Rule 3 (context is untrusted-by-default input). An attachment is Context-kind material with a Message lifecycle — a lifecycle variant of the `files` source kind, not a new row in ADR-024's concept table.
- ADR-031 framing: `attachments` is a plain declarative array field, not declarative business logic — no `x-openregister-*` block.
- No seed data is provided for this field, deliberately: unlike `Context.documents` (whose seed body is inline and self-contained), a seeded `attachments` entry would need a real file at a real path in a real user's Nextcloud folder. A seed carrying a dangling path would model a broken state and would be indistinguishable from the not-found case the dependent change must handle. Attachment fixtures belong to the dependent change's tests, where a file can actually be created.
- The dependent change `hermiq-chat-attachments` adds upload acceptance, storage location, size/type limits, and turn-assembly inclusion via `IRootFolder`; its scenarios live in that change's spec delta to this same capability.
