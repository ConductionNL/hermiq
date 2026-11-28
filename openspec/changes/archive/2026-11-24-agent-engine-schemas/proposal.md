---
kind: config
depends_on: []
chain:
  - agent-engine-schemas    # this spec (hermiq, config) — HEAD of the program chain
  - or-tool-registry-facade # openregister, code — cross-repo, narrated only (see design.md Appendix)
  - agent-engine-port       # hermiq, code — depends_on [agent-engine-schemas, or-tool-registry-facade]
  - agent-data-migration    # hermiq, code — depends_on [agent-engine-port]
  - chat-appid-flip         # nextcloud-vue, code — cross-repo, narrated only (see design.md Appendix)
  - or-chat-proxy-deprecation # openregister, code — depends_on [agent-engine-port], narrated only
---

# Proposal: agent-engine-schemas

## Why

Hermiq's ADR-001 was amended 2026-07-05: Hermiq now owns agent execution — the chat/tool-loop
orchestrator, the LLM provider layer, and conversations/messages/feedback — which today live in
OpenRegister (`ChatService`, `Chat/*` handlers, 4 QBMapper tables, ~30 routes). The paired hydra
ADR-034 ("Hermiq is the sole orchestrator") and ADR-035 (`IMcpToolProvider` ABI unchanged) amendments
land alongside it. Full design: `SPECTR-NEXTCLOUD-PLAN.md` §7 (migration design) and §8 (the
`nextcloud` TaskProcessing driver).

This is the **largest change in the Spectr program** (~11.6k LOC moving) and — per ADR-032 — MUST NOT
be a single `mixed` spec. It is split into a chain (below). This proposal is **spec 1: the config
head** — it declares the durable OR-object shape (`Agent`, `Conversation`, `Message`, `Feedback`
schemas in the `hermiq` register) that every downstream code spec in the chain depends on. It adds no
PHP, no controller, no service, no write path — schemas are data, and OpenRegister validates and
persists them (ADR-001 / ADR-031).

Today Hermiq already reads OR's agents directly and bypasses `createObjectStore` to do it —
`src/api/agents.js` documents this explicitly: *"OpenRegister agents are a first-class OR resource
... served at `/apps/openregister/api/agents` — NOT the generic
`/apps/openregister/api/objects/{register}/{schema}` path, so they cannot be read through
createObjectStore."* Declaring `Agent` as a plain schema in the `hermiq` register (this change) is
what makes that bespoke resource obsolete — once `Agent` is an OR object in Hermiq's own register,
`agents.js` can move onto the generic objects path and `createObjectStore`, closing a
long-standing store-pattern exception (see `feedback_store-pattern.md`). That migration is scoped to
`agent-engine-port` (spec 3), not here.

## Chain (the whole program — 5 changes across 3 repos)

```
 hermiq                          openregister                  nextcloud-vue
 ───────────────────────────     ──────────────────────────    ─────────────────────
 agent-engine-schemas (config)
   │  Agent/Conversation/
   │  Message/Feedback schemas
   │
   ├──────────────────────────►  or-tool-registry-facade (code)
   │                               small, additive read/invoke
   │                               facade over ToolRegistry +
   │                               McpProviderBridge (gate-27)
   │  depends_on: both ▼
   ▼
 agent-engine-port (code)
   Engine/*, Llm/ProviderFactory
   + hermiq.llm + `nextcloud`
   TaskProcessing driver,
   ToolLoop (consumes facade),
   routes mirror incl. SSE,
   chat SPA, api/agents.js
   onto createObjectStore
   │
   │                                                            ┌──────────────────────┐
   │                                                            │ chat-appid-flip (code)│
   │                                                            │  chatAppId config +   │
   │                                                            │  default flip (404    │
   │                                                            │  fix already merged   │
   │                                                            │  ncvue#83)            │
   │                                                            └──────────────────────┘
   ▼
 agent-data-migration (code)      or-chat-proxy-deprecation (code)
   repair step: OR tables →         depends_on: agent-engine-port
   hermiq-register objects          308/proxy compat window;
   (uuids/owner/org preserved;      later removal = SEPARATE
   agentId int-FK → uuid ref)       future change (not spec'd here)
```

Only the three **hermiq** changes (`agent-engine-schemas`, `agent-engine-port`,
`agent-data-migration`) are fully materialized as openspec artifacts in this repo. The two
**cross-repo** changes (`or-tool-registry-facade` in openregister, `chat-appid-flip` in
nextcloud-vue) and the follow-on **`or-chat-proxy-deprecation`** (openregister) are narrated as
design.md Appendix stubs (proposal-shaped prose, not full artifact sets) — the Hydra orchestrator
materializes their real `openspec/changes/` directories in their own repos when this program's build
sequence reaches them. `or-chat-proxy-deprecation`'s own *removal* (dropping OR's runtime code/routes/
tables entirely — plan §7.4 step 7) is explicitly a further, separate future change, not specified
here or in the appendix.

**Cross-repo `depends_on` note:** `agent-engine-port` depends on `or-tool-registry-facade`, which
lives in a different repo (openregister). Hydra's `depends_on` mechanism tracks issue numbers within
one dependency-check pass; until `or-tool-registry-facade`'s issue exists, reference it by slug (per
ADR-032 — "until issues exist, reference by spec slug; the planner translates slug → issue at
issue-creation time"). The orchestrator resolving cross-repo dependencies (as opposed to same-repo)
is itself new ground for Hydra's dependency checker — flagged as `DEFERRED_QUESTIONS` item 1 below.

## What Changes

- Add four new schemas to `lib/Settings/hermiq_register.json`: **`Agent`**, **`Conversation`**,
  **`Message`**, **`Feedback`** — modeled on OpenRegister's existing `Agent`/`Conversation`/
  `Message`/`Feedback` QBMapper entities (`openregister/lib/Db/{Agent,Conversation,Message,
  Feedback}.php`, verified at HEAD 2026-07-06), adapted for OR-object storage:
  - **Tenant scope is inherited, not declared.** `owner`/`organisation` are OMITTED as schema
    properties on all four (they come free from `ObjectEntity`, matching the `Approval`/
    `TenantControl` precedent in `human-approval-gate-schema`). OR's current QBMapper entities
    declare `owner`/`organisation` as their own columns; that's a QBMapper-era artifact this schema
    declaration deliberately does not carry forward.
  - **`Conversation.agentId` and `Message.conversationId` become uuid string refs**, not integer
    FKs — OR's current `Conversation.agentId` is `protected ?int $agentId` and `Message
    .conversationId` is `protected ?int $conversationId` (QBMapper auto-increment ids). Declaring
    them as `uuid` strings from day one in the hermiq register is what lets
    `agent-data-migration` turn the FK into a ref during the copy, per plan §7.4 step 4, rather than
    needing a second schema change later.
  - **No redundant soft-delete / timestamp fields.** OR's `Conversation` has its own
    `deletedAt`/`created`/`updated` columns; `ObjectEntity` already provides soft-delete
    (`deleted`) and `created`/`updated` natively (ADR-001) — the hermiq `Conversation` schema does
    not re-declare them.
  - `Message.context` (the AI Chat Companion `CnAiContext` snapshot, hydra ADR-034 Decision 5) is
    preserved as a JSON object property — required by the plan's "preserve ... `Message.context`"
    instruction.
- **Not migrated:** OR's fifth chat-adjacent table, `openregister_chat_history`
  (`Version002004000Date20251013000000.php`), is dead code — grep at HEAD finds zero references
  outside its own creation migration. The plan's "7 QBMapper tables" / `chat_history` count is
  corrected here to **4 live tables** (`agents`, `conversations`, `messages`, `feedback`); see
  design.md "Adaptations vs plan §7" for the full list of HEAD-verified corrections.

### MCP coverage

No MCP surface — schema-only change with no new user action; the register patch declares data,
adds no controller, service, or endpoint (ADR-035 Decision 2, answer 3).

## Impact

- Affected specs: NEW hermiq schemas capability (`agent-engine-schemas`).
- Affected code: `lib/Settings/hermiq_register.json` only. No PHP, no frontend, no routes.
- Downstream: unblocks `agent-engine-port` (consumes these schemas for read/write) and
  `agent-data-migration` (writes into these schemas).
