---
kind: config
depends_on: []
---

# Proposal: hermiq-chat-attachments-schema

## Summary

Add an `attachments` array to the `Message` schema in `lib/Settings/hermiq_register.json` so a
chat turn can carry a **reference** to a file in the acting user's Nextcloud, and bump the
register `info.version` (0.15.0 → 0.16.0) plus the app `<version>` in `appinfo/info.xml`
(0.1.80 → 0.1.81) — both gate the re-import. This is the config head of a two-change chain: it
ships the field only. No code reads or writes it until `hermiq-chat-attachments` lands.

## Motivation

Users want to upload a file in the AI chat so the agent can use it in the turn. Today that is
impossible: hermiq's chat API is JSON-only. Verified at HEAD — there is no `getUploadedFile`,
multipart, or attachment handling in `lib/Controller/ChatController.php` (whose
`extractMessageRequestParams()` reads only `conversation`, `agentUuid`, `message`, `views`,
`tools`, the four `ragSettings` keys, and `context`) or in `lib/Controller/ChatStreamController.php`
(a JSON body: `message`, `agentUuid`, `conversationUuid`, `context`). The `Message` schema
(slug `message`, version `0.1.0`) has exactly five properties — `conversationId`, `role`,
`content`, `sources`, `context` — and none of them can carry a file reference.

The workaround a user has today is to attach the file to the **Agent** via a `Context` object's
`files` array. That is the wrong lifecycle: `Context` is attached to an agent
(`Agent.contextRefs`) and is durable, curated, shared across every run. "Here, look at *this*
file, *this* turn" is per-turn material that belongs to the message, not to the agent's
definition. Carrying it on the agent would leak one turn's file into every subsequent run.

Why now: `Context.documents` (ADR-024) just shipped, which settles the concept model this
change must slot into rather than contradict. The schema field is separated from the code so the
version-gated register re-import lands as its own reviewable step.

## Chain Arc

This proposal is the head of a config→code chain that mirrors the ADR-024 context-documents
build:

1. **`hermiq-chat-attachments-schema`** (this change, config) — add the `attachments` reference
   field to the `Message` schema; bump the register `info.version` and the app version so the
   change re-imports. No code.
2. **`hermiq-chat-attachments`** (code, `depends_on` this) — the chat controller accepts an
   upload, the file lands in the acting user's Nextcloud, the Message persists the reference,
   and turn assembly reads the content through the SAME permission-respecting `IRootFolder`
   path `ContextAssembler::resolveFiles()` already uses, under the same budget.

The two are split so the version-gated register change can land and re-import independently of
the code that consumes it. The schema can ship first with no runtime breakage: the field is
additive and defaulted to `[]`, and nothing reads it until change 2 — which is *also* why change
2 must actually land. A config head whose consumer is abandoned leaves a phantom field, so the
rollback plan below is not decorative.

## Affected Projects

- [ ] Project: `hermiq` — `Message` schema gains an `attachments` array; register `info.version`
      0.15.0 → 0.16.0; app `<version>` in `appinfo/info.xml` 0.1.80 → 0.1.81. No PHP, no Vue.

## Scope

### In Scope

- Add `attachments` to `components.schemas.Message.properties` in
  `lib/Settings/hermiq_register.json`: an array of `{path, name, description}` objects,
  `default: []`, deliberately mirroring the `Context.files` `{path, description}` precedent.
- Bump `Message.version` (`0.1.0` → `0.1.1`), matching how `Context.version` went to `0.1.1`
  when ADR-024 added `documents`.
- Bump register `info.version` 0.15.0 → 0.16.0.
- Bump `appinfo/info.xml` `<version>` 0.1.80 → 0.1.81.

### Out of Scope

- **All code.** No controller, no service, no Vue. That is `hermiq-chat-attachments`.
- **Any `Conversation` schema change.** Considered and rejected — see design.md. An attachment
  is per-turn; hanging it on the conversation would give it the same wrong (too-durable)
  lifecycle as putting it on the agent.
- **A binary/image attachment shape.** No `mediaType`, no base64 field, no vision affordance.
  The chain is scoped to text-decodable files; see the design's scope call.
- **Backfill/migration of existing `Message` objects.** An additive, defaulted array needs none.

## Approach

One additive property on one schema, shaped to an existing, shipped precedent rather than a new
invention. `Context.files` already models "a Nextcloud file included as model input" as
`{path, description}`, and `ContextAssembler::resolveFiles()` already reads exactly that shape
via `IRootFolder` scoped to the acting user. `Message.attachments` reuses that shape so the
follow-on change can reuse that read path, adding only `name` (the display filename, which can
diverge from `path` once Nextcloud deduplicates an upload to `report (2).txt`).

Both version bumps are mandatory and neither is optional: the register re-import is gated on
`info.version`, and the app upgrade that triggers the import is gated on the `info.xml`
`<version>`. Bumping one without the other silently ships a field that never reaches the
database.

## New Dependencies

None.

## Impact

- `lib/Settings/hermiq_register.json` — one new property on `Message`; two version bumps.
- `appinfo/info.xml` — one version bump.
- Existing `Message` objects — unaffected. The property is additive with `default: []`; nothing
  reads it at this revision.
- Consumers of the chat API — unaffected. No endpoint changes shape in this change.

## Cross-Project Dependencies

None. `Message` is hermiq-owned (register slug `hermiq`, schema slug `message`). No other
Conduction app reads it.

## Risks

### Risk 1: Version bump lands on only one of the two gates

**Severity:** Medium — **Mitigation:** The re-import requires *both* `info.version` and the
`info.xml` `<version>` to move; bumping one alone produces a green build whose schema never
reaches the database — the "spec-says-done ≠ feature runs" failure mode. tasks.md carries both
bumps as explicit, separately-verified steps, and the acceptance criteria require confirming the
field on the **imported** schema, not just in the JSON file.

### Risk 2: The field ships and nothing ever reads it

**Severity:** Low — **Mitigation:** This is an orphaned-capability risk by construction: a
config-only chain head is *intentionally* unread until change 2 lands. It is bounded by the
declared chain (`hermiq-chat-attachments` has `depends_on: [hermiq-chat-attachments-schema]`)
and by the field being inert and defaulted. If change 2 is abandoned, this field must be
reverted rather than left as a phantom.

## Rollback Strategy

Revert the commit (remove the `attachments` property, restore `info.version` to 0.15.0,
`Message.version` to 0.1.0, and `appinfo/info.xml` to 0.1.80) and re-import the register.
Because the field is additive, defaulted, and read by nothing at this revision, no stored
`Message` object depends on it and no data is lost. Any object that somehow persisted an
`attachments` value simply carries an unread key.

## Open Questions

None blocking. The shape question (`{path, name, description}` vs. an inline-body shape vs. a
`Conversation`-level field) is decided and justified in design.md.
