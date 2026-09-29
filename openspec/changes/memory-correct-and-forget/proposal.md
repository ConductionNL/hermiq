---
kind: code
depends_on: []
---

# Proposal: memory-correct-and-forget

## Summary

The owner of an agent can correct a fact the agent remembers, or make it forget one, from the memory list on the agent page and on the Memory page. Forgetting keeps the old fact in the history, like the agent's own `forgetMemory` tool does; correcting replaces the fact with the new wording.

## Why

One row of hermiq's capability matrix that is `building`, decided in the build-all pass of 2026-09-28.

| row | own rating | decision |
|---|---|---|
| `hermiq:me-view-edit` | partial, building | build: two competitors rate yes for the missing half, correcting and deleting |

Competitor cells rated yes, quoted from the matrix evidence:

- Open WebUI v0.11.4: "src/lib/components/chat/Settings/Personalization.svelte:53 lists memories, :61 delete, MemoryModal.svelte:59 Edit Memory; backend/open_webui/routers/memories.py:548 update, :610 delete".
- Nextcloud Assistant v4.0.0: "remembered conversations are listed in personal settings (assistant:src/components/PersonalSettings.vue:61-65)".

The matrix note on the built half: "A human can see what the agent remembers and add a new fact, but cannot correct or delete an existing one; only the agent itself can soft-delete via hermiq.forgetMemory, which the panel does not expose."

## What hermiq already has

- `MemoryController::memory()` and `addMemory()` (`lib/Controller/MemoryController.php:88`, `:124`), routes `appinfo/routes.php:201-202`; adding is owner-only through `requireAgentOwnership()` (`:324`).
- `MemoryService::forgetEntry()` (`lib/Service/MemoryService.php:464`): soft delete by entry id, sets `deletedAt`, keeps the entry in the stored array, excluded from recall and from the character budget.
- `MemoryService::appendMemoryEntry()` (`:214`) gives new entries an id.
- `src/components/AgentMemoryPanel.vue`: budget bar, consolidate, add-a-fact and the entry list, rendered on the agent detail page and the Memory page.

## What this change builds

1. `DELETE /api/agents/{agentId}/memory/entries/{entryId}`: the owner forgets one entry, through `forgetEntry()`.
2. `PUT /api/agents/{agentId}/memory/entries/{entryId}` with `text`: the owner corrects one entry. The service soft-deletes the old entry and appends the new text as a new entry in one save, so the history shows both.
3. "Correct" and "Forget" actions on every entry in `AgentMemoryPanel.vue`, with a confirmation before forgetting.
4. Entries stored before entry ids existed get an id when the memory is next written, so every entry shown can be addressed.

## Out of scope

- Editing a user profile the agent keeps about a person. That is the person's own data and follows its own rules.
- Hard deletion. Forgetting stays a soft delete, as it is for the agent.
