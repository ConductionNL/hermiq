# Tasks: agent-engine-schemas

## 1. Declare the Agent schema

- [ ] 1.1 Add an `Agent` entry under `components.schemas` in `lib/Settings/hermiq_register.json`
  (title, slug `agent`, icon, version, `x-openregister`), fields per OR's `lib/Db/Agent.php`
  verified at HEAD: `name` (required), `description`, `type`, `provider`, `model`, `prompt`,
  `temperature`, `maxTokens`, `configuration` (object).
- [ ] 1.2 Add the RAG/quota/visibility fields: `active` (default true), `enableRag` (default
  false), `ragSearchMode`, `ragNumSources`, `ragIncludeFiles`, `ragIncludeObjects`, `requestQuota`,
  `tokenQuota`, `views` (array), `searchFiles`, `searchObjects`, `isPrivate` (default true),
  `invitedUsers` (array), `groups` (array), `user` (string, for cron/background runs).
- [ ] 1.3 Add `tools` (array of `{appId}.{toolName}` strings, default empty) as the ADR-035
  `toolWhitelist` field — empty means all discovered tools allowed (design.md Decisions).
- [ ] 1.4 Do NOT declare `owner`/`organisation` — inherited from `ObjectEntity`.

## 2. Declare the Conversation schema

- [ ] 2.1 Add a `Conversation` entry (slug `conversation`) with `title`, `userId` (string),
  `agentId` (**string, format uuid, required** — not an integer FK), `metadata` (object).
- [ ] 2.2 Do NOT declare `owner`/`organisation`/`deletedAt`/`created`/`updated` — all inherited
  from `ObjectEntity` (soft-delete + timestamps are native).

## 3. Declare the Message schema

- [ ] 3.1 Add a `Message` entry (slug `message`) with `conversationId` (**string, format uuid,
  required**), `role` (enum `system`|`user`|`assistant`|`tool`, required), `content` (string),
  `sources` (array).
- [ ] 3.2 Add `context` (object, optional) — the AI Chat Companion `CnAiContext` snapshot (hydra
  ADR-034 Decision 5); preserve the shape (`appId`, `pageKind`, `objectUuid`, `registerSlug`,
  `schemaSlug`, `route`, `capturedAt`) as free-form JSON, not individually typed properties.

## 4. Declare the Feedback schema

- [ ] 4.1 Add a `Feedback` entry (slug `feedback`) with `messageId`, `conversationId`, `agentId`
  (all **string, format uuid, required**), `userId` (string, required), `type` (string, required),
  `comment` (string, optional).
- [ ] 4.2 Do NOT declare `organisation` — inherited from `ObjectEntity`.

## 5. Validate import and persistence

- [ ] 5.1 Re-validate `hermiq_register.json` as well-formed JSON; confirm `Example`, `Schedule`,
  `Approval`, `Tenant control`, `AI feature`, `Agent memory`, `User profile`, `Session`,
  `Session turn`, `Skill`, `Skill source` are unchanged (union import, no regression).
- [ ] 5.2 Import the register via the repair step (`ConfigurationService::importFromApp()`)
  against live OpenRegister and confirm all four new schemas create cleanly.
- [ ] 5.3 Persist one valid object per new schema (per design.md Seed Data) and confirm an
  invalid `Message.role` / `Feedback` missing a required uuid field is rejected.

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
