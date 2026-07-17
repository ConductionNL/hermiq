# Design: session-store-consolidation

## Architecture Overview

Hermiq's `hermiq` register declares four schemas for one user-facing concept:

| Register key | Live slug | Rows | Writers | Readers |
|---|---|---|---|---|
| `Conversation` | `conversation` (id 701) | **180** | `ChatStreamController::resolveConversation()`, `ChatController` | Chat UI, `/api/chat/*` |
| `Message` | `message` (id 700) | **289** | engine run loop | Chat UI |
| `Session` | `agentsession` (id 4347) | **0** | `MemoryService::startSession()` — *zero callers* | `listSessions()` → `MemoryController:182` |
| `SessionTurn` | `agentsessionturn` (id 4348) | **0** | `MemoryService::recordTurn()` — *zero callers* | `recallSessions()` → `MemoryController:252`, `HermiqToolProvider:857` |

The asymmetry is the whole story: **the empty store has live readers, the live store has no
reader on the memory seam.** Repointing the readers is a one-seam change:

```
BEFORE                                     AFTER
MemoryController::sessions()               MemoryController::sessions()
  └─ listSessions(agentId)                   └─ listSessions(agentId)
       └─ findMany(agentsession, …) → []          └─ findMany(conversation, …) → 180 rows

MemoryController::recall()  ┐              MemoryController::recall()  ┐
HermiqToolProvider:857      ┘                HermiqToolProvider:857    ┘
  └─ recallSessions(agentId, q)                └─ recallSessions(agentId, q)
       └─ findMany(agentsessionturn, …) → []        ├─ findMany(conversation, {agentId, @self.owner})
                                                    └─ findMany(message, {conversationId in …}, search: q)
```

`MemoryController` and `HermiqToolProvider` need **no call-site change**. Both already pass
`agentId` (+ `query`) and shape whatever `ObjectEntity`s return.

## The `agentId` join

`Message` has properties `conversationId`, `role`, `content`, `sources`, `context` — **no
`agentId`**. `SessionTurn` had one (`sessionId`, `agentId`, `role`, `content`, `createdAt`), so
today's `recallSessions()` filters `agentId` directly. The repointed path must resolve the
binding through `Conversation` (`title`, `userId`, `agentId`, `metadata`):

1. `findMany(schema: conversation, filters: ['agentId' => $agentId, '@self.owner' => $uid])`
2. Collect those conversation UUIDs.
3. `findMany(schema: message, filters: ['conversationId' => [...uuids], '@self.owner' => $uid], search: $query, limit: $limit)`

Two notes for the builder:
- **Empty conversation set ⇒ return `[]` immediately.** Do not issue the message query with an
  empty `conversationId` list — an empty `IN ()` is either a SQL error or, worse, a no-op filter
  that returns every user's messages. Guard explicitly.
- **Keep `@self.owner` on the message query too**, belt-and-braces, even though step 1 already
  constrained to the caller's conversations. `Conversation` also carries a `userId` property; the
  owner meta-filter is the authoritative one (`@self.owner`, NEVER `_owner` — the latter is
  silently ignored and returns unfiltered results, per `MemoryServiceTest`).
- If the caller's conversation list is large, chunk the `conversationId` list — OpenRegister/NC
  cap `IN ()` expression lists at 1000.

## API Design

No route changes. `GET /api/agents/{agentId}/sessions` and `GET /api/agents/{agentId}/recall`
keep their paths, verbs, auth posture and response envelope. Their **payloads** change because
the underlying objects change:

### `GET /api/agents/{agentId}/sessions`
**Response (after):** the caller's `Conversation` objects for that agent, shaped by
`MemoryController::shape()`. Fields available: `title`, `userId`, `agentId`, `metadata` + OR
object metadata. `Session`'s `startedAt`/`lastActivityAt` have **no `Conversation` equivalent as
schema properties** — consumers needing timestamps read OR's object `created`/`updated` metadata.

### `GET /api/agents/{agentId}/recall?q=…`
**Response (after):** the caller's matching `Message` objects. `SessionTurn.sessionId` becomes
`Message.conversationId`; `role`/`content` carry over unchanged; `SessionTurn.createdAt` has no
`Message` property equivalent — same OR-metadata answer.

This payload shift is the one real compatibility break in the change, and it is confined to two
endpoints whose only consumers are `AgentSessions.vue` (deleted here) and the MCP tool (which
consumes `role`/`content`, both preserved).

## Database Changes

None. No tables, no columns, no OpenRegister schema definitions are touched by *this* change —
schema removal is deferred to `session-nav-schema-retirement` (kind: config). No migration class,
and none is needed: both retired schemas hold 0 rows.

## Nextcloud Integration

- Controllers: `lib/Controller/MemoryController.php` (unchanged signatures; `sessions()` line 182,
  `recall()` line 252)
- Services: `lib/Service/MemoryService.php` (the only file with logic changes)
- Mappers/Entities: none — all persistence via OpenRegister `ObjectService` (ADR-022)
- Events/Hooks: none
- MCP: `lib/Mcp/HermiqToolProvider.php` — `hermiq.recallMemory` (line 857); docblock at lines
  832-833 describes the `recallSessions()` SessionTurn substrate and must be corrected to name
  the message store.

## Security Considerations

This change touches a **cross-user disclosure boundary**, so it gets more than a line.

- **Fail-closed is load-bearing.** Both methods today return `[]` when
  `IUserSession::getUser()` is null, because recall full-text searches conversation *content*.
  The repointed methods MUST keep that guard verbatim. The controller 401s first; this is the
  belt-and-braces guard on the service seam itself.
- **`@self.owner`, not `userId`.** `Conversation` carries a `userId` property, which is tempting
  as a filter. Use `@self.owner` — it is OpenRegister's object-owner meta-filter and the one the
  existing code trusts. `_owner` is silently ignored and returns unfiltered results.
- **Actor identity, never stale authority (ADR-023).** `recallSessions()` has two callers and one
  of them is the agent itself, via MCP. Scoping to the acting user means an agent recalls only
  the run actor's own history. A scheduled/background run with no resolvable user recalls
  NOTHING. That is intentional and must survive this change — the repointed store contains far
  more data (289 real messages vs 0 turns), so a scoping bug that was previously unobservable
  (empty store ⇒ empty leak) becomes a live cross-tenant leak. **This is the single most
  important reason to test the null-user and wrong-owner paths.**
- No new endpoint, no new auth posture, no CSRF surface change.

## NL Design System

`Chat.vue` retitling only — no new components, no new tokens. Existing NC components and CSS
variables are untouched; no hardcoded colours introduced.

## File Structure

```
lib/
  Service/
    MemoryService.php          # MODIFIED: -startSession, -recordTurn, listSessions/recallSessions repointed
  Controller/
    MemoryController.php       # UNCHANGED (payload shifts, code does not)
  Mcp/
    HermiqToolProvider.php     # MODIFIED: docblock only (lines 832-833)
src/
  views/
    AgentSessions.vue          # DELETED
    Chat.vue                   # MODIFIED: wording
  api/
    memory.js                  # MODIFIED: -listSessions (line 77), -recall (line 100)
l10n/
  en.json                      # MODIFIED: wording
  nl.json                      # MODIFIED: wording
```

## Seed Data

**Not applicable — this change introduces no new schemas and no new entities.** (ADR-001/ADR-016
require seed data for every schema a change introduces or modifies; this change introduces none.)

The two schemas it *stops reading* (`agentsession` id 4347, `agentsessionturn` id 4348) hold **0
rows** on the reference instance and have never had a writer, so there is no seed data to retire
either. The store this change repoints *onto* is already populated with real traffic —
`conversation` (id 701) 180 rows, `message` (id 700) 289 rows — which is precisely why no seed
objects are needed: the capability is testable on this instance as-is.

Schema removal from `lib/Settings/hermiq_register.json` is scoped to
`session-nav-schema-retirement`.

## Declarative-vs-imperative decision

**Not applicable in the ADR-031 sense.** This change touches no lifecycle/status workflow, no
aggregations, no derived fields, no notifications, no relations, and no widgets. It repoints two
read methods and deletes two dead write methods. No declarative register dialect is added or
modified, so there is no imperative-vs-declarative choice to make.

One adjacent warning for the builder: do **not** reach for a declarative relation to express the
`Conversation` → `Message` link. `Message.conversationId` is a plain string property today, and
turning it into an OR relation is a schema change — out of scope, and it would drag this
`kind: code` change into `mixed` territory, which ADR-032 forbids.

## Trade-offs

### Rejected: rename the `conversation` schema slug to `session`

**Hard blocker, not a preference.** The slug `session` **already exists and is owned by
`scholiq`** (schema id 1286). OpenRegister enforces `UNIQUE(organisation, slug)`
case-SENSITIVELY but resolves slugs case-INsensitively, so a hermiq `session` schema on a shared
instance is a cross-app slug collision — the exact failure mode recorded in
`reference_or-cross-app-schema-slug-collision.md`. The `agentsession`/`agentsessionturn` slugs
in the live register are themselves evidence of this: the register declares the keys `Session`
and `SessionTurn`, and the deployed slugs came out prefixed.

**Therefore no schema slug is renamed by any change in this chain. "Session" is a UI/terminology
word only.** The API routes (`/api/chat/*`) and the register keys keep their current names.

### Rejected: implement the missing `startSession`/`recordTurn` callers

The alternative reading of "the session store is empty" is "wire up its writers". Rejected: the
`conversation`/`message` store already performs exactly that job and holds the real data.
Writing both would fork persistence and give two answers to "what did we discuss?" — the
duplicate-persistence anti-pattern ADR-003 itself argues against ("no duplicate persistence
layer; one write path through `ObjectService`"). Deleting the unreferenced writers is the
smaller, safer move.

### Rejected: keep the endpoints reading the empty store and just delete the UI

This would leave `hermiq.recallMemory` permanently unable to recall a conversation, which is the
single highest-value defect this change fixes. The MCP tool is not reached through the deleted
page.

### Deviation from ADR-003 — and a proposed amendment

**ADR-003 (`Status: proposed`) explicitly mandates the schema set `Memory, UserProfile, Session,
SessionTurn, Skill, SkillSource`. This change retires `Session` and `SessionTurn`, deviating from
that decision.** Recorded deliberately:

- ADR-003 is **`proposed`, not `accepted`** — no accepted decision is violated.
- Its session half was **never implemented**. The schemas were imported and the readers built,
  but `startSession`/`recordTurn` were written and never called. The ADR's premise — that Hermes'
  SQLite/FTS5 session store should be re-homed as `Session`/`SessionTurn` OpenRegister objects —
  was satisfied *in a different shape*: the `conversation`/`message` store carries it.
- The ADR's actual goals (searchable, versioned, RBAC-scoped, auditable, org-shareable; one write
  path through `ObjectService`) are **better served** by consolidating on the store that has the
  data than by a second, empty pair of schemas.

**Proposed amendment to ADR-003:** strike `Session` and `SessionTurn` from the mandated schema
list and add a Consequences note that cross-session recall reads the `Conversation`/`Message`
store. The Memory/UserProfile/Skill/SkillSource half of ADR-003 is unaffected and stays as-is.
Amending the ADR is not in this change's scope (`kind: code`); it is called out here so the
reviewer can decide whether to amend ADR-003 before or alongside this chain.

### Not conflated: ADR-024

**ADR-024 (`Status: accepted`) is a different path and is untouched by this change.** It owns
`ContextAssembler` (`lib/Service/Engine/ContextAssembler.php`) — the budgeted preamble built from
a `Context` object's `files` / `objectQueries` / `viewRefs` / `documents`. The retrieval path this
chain's sibling change (`session-context-performance`) touches is
`ContextRetrievalHandler::searchKeywordOnly()`, a **separate** unscoped-search seam. Same word,
different code. Neither this change nor ADR-024 constrains the other.
