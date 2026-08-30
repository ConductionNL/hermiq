# Tasks: agent-engine-port

## 1. Port the chat engine

- [ ] 1.1 Port `ChatService` (455 lines) + `Chat/*` handlers (`ResponseGenerationHandler`,
  `ConversationManagementHandler`, `ContextRetrievalHandler`, `ToolManagementHandler`,
  `MessageHistoryHandler`, `StreamYieldChannel` — ~2.8k lines total) from openregister HEAD into
  `lib/Service/Engine/*`, preserving the facade-delegates-to-handlers structure.
- [ ] 1.2 Re-point all reads/writes at `Agent`/`Conversation`/`Message`/`Feedback` OR objects
  (`agent-engine-schemas`) via `ObjectService`, replacing OR's QBMapper entity/mapper calls.
- [ ] 1.3 `ContextRetrievalHandler`'s RAG calls remain a data query against OR's existing vector-
  search surface — do not duplicate `VectorEmbeddings`/`VectorSearchHandler` inside Hermiq.

## 2. Port the LLM provider layer

- [ ] 2.1 Port the LLPhant provider layer + `llphant-ollama-think-keepalive.patch` +
  `llphant-ollama-usage-capture.patch` into `lib/Service/Llm/ProviderFactory` + a `hermiq.llm`
  `IAppConfig` key (mirrors `openregister.llm`'s `getValueString`/`setValueString` shape); re-verify
  both patches apply cleanly against Hermiq's resolved LLPhant version.
- [ ] 2.2 Add the 4th `nextcloud` driver: `OCP\TaskProcessing\IManager`-backed, non-streaming,
  `hasProviders()`-guarded (decidesk's 503-without-provider pattern); scope to background/
  non-interactive work only (titles, summaries) — LLPhant stays for SSE chat + embeddings.

## 3. Port the tool loop

- [ ] 3.1 Port `ToolManagementHandler` into `lib/Service/Engine/ToolLoop`, consuming
  `or-tool-registry-facade`'s `listTools()`/`invokeTool()` instead of constructing OR's
  `ToolRegistry`/`McpProviderBridge` instances directly.
- [ ] 3.2 Enforce the `Agent.tools` whitelist (empty = allow all) when calling
  `listTools()`, per the ADR-035 semantics `agent-engine-schemas` declared.

## 4. Mirror the routes

- [ ] 4.1 Mirror OR's ~30 routes across `Chat`/`ChatStream`/`ChatHealth`/`Conversation`/`Agents`
  controllers at `/apps/hermiq/api/{chat,conversations,agents}`, one route entry per existing OR
  route (route-for-route, not a re-design).
- [ ] 4.2 Preserve the SSE six-event envelope exactly (`token`/`tool_call`/`tool_result`/
  `heartbeat`/`final`/`error`, one terminal `final` or `error` per request) — hydra ADR-034
  Decision 6's contract is unchanged by this port.

## 5. Merge the SPA and retire the agents.js bypass

- [ ] 5.1 Merge OR's chat/agents SPA pages into Hermiq's existing SPA (already has
  `AgentCatalog.vue`/`AgentDetail.vue`/`AgentFormModal.vue`; gains Chat).
- [ ] 5.2 Rewrite `src/api/agents.js` onto `createObjectStore` against
  `/apps/hermiq/api/objects/hermiq/agent` (the file's own docblock rationale for bypassing it no
  longer holds once `Agent` is a plain OR object); keep `listTools()` as a bespoke call against the
  facade (not an object read).

## 6. Feature-flag the engine and pivot ScheduleService

- [ ] 6.1 Add an `IAppConfig` feature flag (e.g. `hermiq.engine.enabled`, default `false`) gating
  which engine `ScheduleService::runAgentAsOwner()` calls.
- [ ] 6.2 When the flag is on, `runAgentAsOwner()` calls the new in-app `Engine` facade against a
  hermiq-register `Conversation` instead of `OCA\OpenRegister\Service\ChatService` +
  `ConversationMapper::insert()`; when off (default), behavior is byte-for-byte unchanged from
  today. The new call MUST return the same `usage` (token/latency) shape so `lastRunUsage` /
  run-analytics does not silently lose data.

## 7. Prove parity before any default flips

- [ ] 7.1 Build and run the parity harness (plan §7.5): same `(agent, prompt)` through both engine
  paths; assert structural parity (tool-call sequence, RAG source count/shape, final message role,
  usage shape) plus a human-reviewed spot-check on a fixed prompt set. Automated semantic-similarity
  thresholding is `DEFERRED_QUESTIONS` item 2 — not required to merge this change.

## 8. Regression and compat

- [ ] 8.1 Re-run the full kill-switch/approval-gate regression suite (95 tests, from
  `human-approval-gate-enforcement`) with the feature flag ON — the gate lives in `ScheduleService`
  before either engine path is reached, but this MUST be proven, not assumed, given the EU AI Act
  Art. 14 stakes of a silent regression.
- [ ] 8.2 NC34 compat check on every new file: `\OCP\Server::get()` only (no `\OC::$server->
  getXxx()`); no second `<background-jobs>` block introduced in `info.xml`.

## 9. Quality gates

- [ ] 9.1 Run the full Hydra mechanical gate suite (spdx-headers, forbidden-patterns, stub-scan,
  route-auth, route-reachability, no-admin-idor, redundant-controller, spec-coverage, and gate-27
  no-phantom-cross-app-rpc — confirming the tool loop calls only the documented facade, not OR
  internals) before requesting review.
- [ ] 9.2 Composer audit + PHPCS/PHPMD/Psalm/PHPStan (`composer check:strict`) clean on all new
  `Engine`/`Llm` classes.

## Acceptance criteria

- The ported engine is fully wired, feature-flagged off by default; existing installs see zero
  behavior change at merge.
- With the flag on, `ScheduleService` runs entirely against hermiq-register objects with no call
  into OR's `ChatService`/QBMapper tables.
- The parity harness runs and its structural-diff checks pass on a documented prompt set.
- The 95-test kill-switch/approval regression suite passes with the flag on.
- SSE six-event envelope contract is bit-for-bit unchanged from OR's current implementation.

## Quality reminders

- `kind: code` — this spec was deliberately NOT split further per ADR-032 (design.md "Risks");
  consider `HYDRA_BUILDER_MAX_TURNS=400` / `budget:large` rather than re-chaining.
- No mock-based fixes — the parity harness must exercise real LLM calls (or a real recorded fixture
  set), not stubbed responses.
- Store pattern: `agents.js` migration must use `createObjectStore`, not a new bespoke Pinia store.
- Keep the declarative/imperative split honest — no new schema fields belong in this change; if one
  is needed, it belongs in a schema-kind spec, not bolted on here.
