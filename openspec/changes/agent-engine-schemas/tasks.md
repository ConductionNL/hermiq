# Tasks: agent-engine-schemas

## 1. Declare the Agent schema

- [x] 1.1 Add an `Agent` entry under `components.schemas` in `lib/Settings/hermiq_register.json`
  (title, slug `agent`, icon, version, `x-openregister`), fields per OR's `lib/Db/Agent.php`
  verified at HEAD: `name` (required), `description`, `type`, `provider`, `model`, `prompt`,
  `temperature`, `maxTokens`, `configuration` (object).
- [x] 1.2 Add the RAG/quota/visibility fields: `active` (default true), `enableRag` (default
  false), `ragSearchMode`, `ragNumSources`, `ragIncludeFiles`, `ragIncludeObjects`, `requestQuota`,
  `tokenQuota`, `views` (array), `searchFiles`, `searchObjects`, `isPrivate` (default true),
  `invitedUsers` (array), `groups` (array), `user` (string, for cron/background runs).
- [x] 1.3 Add `tools` (array of `{appId}.{toolName}` strings, default empty) as the ADR-035
  `toolWhitelist` field — empty means all discovered tools allowed (design.md Decisions).
- [x] 1.4 Do NOT declare `owner`/`organisation` — inherited from `ObjectEntity`.

## 2. Declare the Conversation schema

- [x] 2.1 Add a `Conversation` entry (slug `conversation`) with `title`, `userId` (string),
  `agentId` (**string, format uuid, required** — not an integer FK), `metadata` (object).
- [x] 2.2 Do NOT declare `owner`/`organisation`/`deletedAt`/`created`/`updated` — all inherited
  from `ObjectEntity` (soft-delete + timestamps are native).

## 3. Declare the Message schema

- [x] 3.1 Add a `Message` entry (slug `message`) with `conversationId` (**string, format uuid,
  required**), `role` (enum `system`|`user`|`assistant`|`tool`, required), `content` (string),
  `sources` (array).
- [x] 3.2 Add `context` (object, optional) — the AI Chat Companion `CnAiContext` snapshot (hydra
  ADR-034 Decision 5); preserve the shape (`appId`, `pageKind`, `objectUuid`, `registerSlug`,
  `schemaSlug`, `route`, `capturedAt`) as free-form JSON, not individually typed properties.

## 4. Declare the Feedback schema

- [x] 4.1 Add a `Feedback` entry (slug `feedback`) with `messageId`, `conversationId`, `agentId`
  (all **string, format uuid, required**), `userId` (string, required), `type` (string, required),
  `comment` (string, optional). `type` declared as enum `positive`|`negative` per the
  `openregister_feedback.type` column comment ("positive or negative") at HEAD
  (`Version1Date20251107150000.php`) and design.md's own "plain enums" call-out.
- [x] 4.2 Do NOT declare `organisation` — inherited from `ObjectEntity`.

## 5. Validate import and persistence

- [x] 5.1 Re-validate `hermiq_register.json` as well-formed JSON; confirm `Example`, `Schedule`,
  `Approval`, `Tenant control`, `AI feature`, `Agent memory`, `User profile`, `Session`,
  `Session turn`, `Skill`, `Skill source` are unchanged (union import, no regression). Verified:
  `jq empty` passes; `git diff` vs origin/development is purely additive (88 insertions, 0
  deletions) so every pre-existing schema is byte-identical; `tests/validate-register.js` and
  `tests/validate-json-strict.js` (hermiq's structural + duplicate-key/merge-safety checks — the
  closest equivalents to a RegisterFragmentMergeTest; hermiq has no register.d fragment-merge or
  PHP-level merge test) both PASS with 0 warnings/errors.
- [ ] 5.2 Import the register via the repair step (`ConfigurationService::importFromApp()`)
  against live OpenRegister and confirm all four new schemas create cleanly. **Not performed in
  this session** — no isolated OpenRegister instance was stood up (the shared dev `nextcloud`
  container is off-limits per the no-deploy-to-shared-instance safety rule, and openregister's
  working copy is mid-WIP on `ci/composer-auth`, marked do-not-touch). Deferred to Hydra's live
  gate / CI pipeline, which already exercises this exact repair-step import path.
- [ ] 5.3 Persist one valid object per new schema (per design.md Seed Data) and confirm an
  invalid `Message.role` / `Feedback` missing a required uuid field is rejected. **Not performed
  in this session** for the same reason as 5.2 (requires a live OR instance to persist against).
  Static validation stands in for this: `required` arrays and `enum` constraints for `Message.role`
  and `Feedback`'s required uuid fields are declared exactly per spec.md's scenarios, and
  `hermiq_register.json` parses/validates cleanly under every check that doesn't need a live
  ObjectService.

## Acceptance criteria

- `Agent`, `Conversation`, `Message`, `Feedback` schemas exist in the `hermiq` register with the
  fields listed above; `Conversation.agentId` and `Message.conversationId` are uuid strings, not
  integers.
- No schema declares `owner`/`organisation` as a property — tenancy comes from `ObjectEntity`.
- The change adds no PHP, controller, service, or API surface.
- Existing schemas in the register are unchanged after import (no regression).

## Quality reminders

- Config-only change — do NOT add a Service class, controller, or write path; the engine port is
  the downstream `agent-engine-port` change.
- Use the Edit tool (not sed/awk/scripts) to modify `hermiq_register.json`; re-parse the JSON after
  editing.
- Keep schemas flat — no `if`/`then`/`allOf` conditionals; the importer rejects them.
- Keep i18n keys (schema titles/descriptions) in English source; use NIL UUID / `<angle-bracket>`
  placeholders in any seed/example data.
