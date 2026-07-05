# Tasks: agent-memory

## 1. Schemas (register patch)

- [ ] 1.1 Add a `Memory` schema to `lib/Settings/hermiq_register.json` (slug `memory`): required `agentId` (uuid); `entries` (array of `{text, createdAt}`, default `[]`); `charBudget` (int, default 8000); `needsConsolidation` (bool, default false). Flat, no `if`/`then`.
- [ ] 1.2 Add a `UserProfile` schema (slug `userprofile`): required `agentId` (uuid); `subjectUid` (string); `entries` (array); `charBudget` (int, default 4000); `needsConsolidation` (bool, default false).
- [ ] 1.3 Add a `Session` schema (slug `session`): required `agentId` (uuid); `title` (string); `startedAt`, `lastActivityAt` (date-time).
- [ ] 1.4 Add a `SessionTurn` schema (slug `sessionturn`): required `sessionId` (uuid); `agentId` (uuid); `role` (enum `user|assistant|system|tool`); `content` (string); `createdAt` (date-time).
- [ ] 1.5 Bump the register `info.version`; re-validate the JSON; import via the repair step against live OR and confirm the four schemas are created cleanly (existing schemas unchanged — union import, no regression).

## 2. MemoryService

- [ ] 2.1 Create `lib/Service/MemoryService.php` (SPDX docblock) with `getMemory(agentId)` / `getUserProfile(agentId, subjectUid)` (get-or-create via `ObjectService`, owner-impersonated so `owner`/`organisation` inherit).
- [ ] 2.2 `appendMemoryEntry(agentId, text)` / `appendUserProfileEntry(agentId, subjectUid, text)`: append the entry with `createdAt`, recompute total character count, set `needsConsolidation=true` when the count exceeds `charBudget` — persist the entry regardless (never drop older entries), save via `ObjectService`.
- [ ] 2.3 `consolidate(objectUuid, newEntries)`: replace `entries` with the consolidated set and clear `needsConsolidation`; save through `ObjectService`.
- [ ] 2.4 `recordTurn(sessionId, agentId, role, content)`: append a `SessionTurn` and touch the parent `Session.lastActivityAt`; `startSession(agentId, title)` creates a `Session`.
- [ ] 2.5 `recallSessions(agentId, query)`: tenant-scoped search over `SessionTurn.content` via OR `ObjectService` search (reuse OR's search/vectorization — no bespoke index), scoped to the caller's organisation; MUST NOT return turns from another organisation.

## 3. Controller + routes

- [ ] 3.1 Create `lib/Controller/MemoryController.php` (`@NoAdminRequired`, `@NoCSRFRequired`): `memory(agentId)`, `userProfiles(agentId)`, `sessions(agentId)`, `consolidate(objectId)`, `recall(agentId, q)` — each loads RBAC-scoped and refuses cross-tenant access (404, no content leak).
- [ ] 3.2 Register the routes in `appinfo/routes.php` (`/api/agents/{agentId}/memory`, `/user-profiles`, `/sessions`, `/api/memory/{objectId}/consolidate`, `/api/agents/{agentId}/recall`) with explicit auth attributes.

## 4. UI

- [ ] 4.1 Add `src/api/memory.js` wrapping the memory/user-profile/session/consolidate/recall endpoints.
- [ ] 4.2 Add `src/views/AgentMemory.vue` (mirror `ApprovalInbox.vue`): agent picker → memory entries list, a char-budget bar, a `needsConsolidation` badge with a "Consolidate" action, and a sessions list; `NcEmptyContent` + loading states; every `NcSelect` carries `inputLabel` (ADR-004).
- [ ] 4.2 Register the Memory page in `src/manifest.json` (`route: /memory`, nav entry) + `src/registry.js` + `src/customComponents.js` (no bespoke router file).

## 5. Verify

- [ ] 5.1 Unit-test `MemoryService` the CI way (php:8.3-cli + stubs): append under/over budget (flag flips, nothing dropped), consolidate clears the flag, recall is org-scoped. Add OR stubs as needed.
- [ ] 5.2 Verify live on NC + OR: append entries until the budget flips `needsConsolidation`; consolidate clears it; a second-org user cannot read the memory (cross-tenant denied). Then Playwright-test the Memory view in the browser (entries render, budget bar, consolidate action, session list) with 0 console errors.

## Acceptance criteria

- `Memory`, `UserProfile`, `Session`, `SessionTurn` schemas exist as OpenRegister objects; entries are JSON arrays with an enforced character budget.
- Exceeding the budget sets `needsConsolidation` (a nudge) and never silently truncates.
- Cross-session recall uses OR `ObjectService` search (OR's vectorization stack), not a bespoke SQLite/FTS5 index, scoped to the caller's organisation.
- All memory objects are scoped by organisation/owner via OR; a cross-tenant read is denied with no content leak.
- The Memory view is reachable from the nav and renders an agent's memory, budget, consolidation flag, and sessions — verified live in the browser.

## Quality reminders

- SPDX tags in each PHP docblock; pass `composer phpcs` (lib scope) + PHPStan; run PHPUnit the CI way.
- Config-then-code: schemas are declarative (flat, no `if`/`then` — OR rejects them); enforcement/consolidation is the code path.
- Single write-path: all persistence via OR `ObjectService`; redaction-before-persist is not required (memory is user content, not an audit summary) but recall MUST stay tenant-scoped.
- No sed/awk/scripts on code — Edit tool only; `@spec` docblock tags referencing this change; i18n keys in English.
- Run-loop consumption (recordTurn/recall during an agent turn, acting on needsConsolidation) is an OR seam — do NOT stub an agent core here.
