# Design: memory-correct-and-forget

Read at hermiq development c91e637a.

## Where it lives

- `lib/Controller/MemoryController.php`: two new methods `forgetEntry()` and `correctEntry()`, both `#[NoAdminRequired]`, both guarded by the existing `requireAgentOwnership()` before any read, answering 404 when the caller does not own the agent (the same answer `addMemory()` gives, so existence does not leak).
- `appinfo/routes.php`: two routes beside `memory#addMemory` (`:202`).
- `lib/Service/MemoryService.php`: `correctEntry(agentId, entryId, text)` built on the private `softDeleteEntry()` (`:551`) and the append path, saved once through ObjectService. `normaliseEntries()` (`:651`, with `generateEntryId()` at `:701`) gains id assignment for entries without one, applied on write.
- `src/api/memory.js`: `forgetMemoryEntry()` and `correctMemoryEntry()`.
- `src/components/AgentMemoryPanel.vue`: actions per entry; the correct form is an inline text field in the list row; the forget confirmation is a dialog in `src/dialogs/ForgetMemoryDialog.vue` (ADR-004 modal isolation).

## The payload written into OpenRegister

The Memory object keeps its schema (`lib/Settings/hermiq_register.json` Memory, `entries[]` with `id`, `text`, `createdAt`, `deletedAt`). No new property. A PHPUnit test validates the exact object `correctEntry()` saves against the real Memory schema fragment from the register file with OpenRegister's validator, so a correction that writes a field the schema refuses fails the test.

## Decisions

- D1. Correct is forget plus append, not an in-place edit. The audit trail then holds the old and the new wording, and recall already skips soft-deleted entries.
- D2. Owner only, like `addMemory()`. An organisation admin who is not the owner uses the existing agent ownership rules.
- D3. An empty correction is refused with 400 "A non-empty text is required", the wording `addMemory()` uses.
