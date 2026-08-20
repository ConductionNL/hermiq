---
kind: code
---

# Proposal: hermiq-mcp-adoption

## Summary

Adopt ADR-063 in Hermiq: declare the `x-openregister-mcp` dialect on a deliberately tiny,
**read-only** subset of Hermiq's own registers (`Agent`, `Schedule`, `Session`), migrate the
eight hand-written tools in `lib/Mcp/HermiqToolProvider.php` to `#[McpTool]`-annotated service
methods with **honest** hints/scope, and delete the now-empty provider class. Hermiq is the
fleet's agent platform, so its registers are also its **governance control plane** — this
change therefore refuses every write verb on every schema, and keeps the governance objects
(`Approval`, `AgentWebhook`, `GuardrailPolicy`, `ModelPolicy`, `TenantControl`, `Budget`,
`Skill`, `Memory`, `UserProfile`) off the agent tool surface **entirely**, in both directions.

## Motivation

Three concrete, verified-at-HEAD problems.

**1. Hermiq's own read tools are being default-denied right now.** `hermiq#57`
(`Engine/ToolGrantResolver`) made write/destructive classification **fail closed** for any
hint-less, non-3-segment tool id. All eight `hermiq.*` tools are 2-segment and declare **zero**
hints (`lib/Mcp/HermiqToolProvider.php` `TOOL_DESCRIPTORS`). So `hermiq.listFiles`,
`hermiq.readFile`, `hermiq.searchContacts`, `hermiq.listCalendarEvents`, `hermiq.listDeckBoards`,
`hermiq.searchTools` and `hermiq.recommendCourses` — **every one of them read-only or advisory** —
are today classified write/destructive, stripped by default-deny, and require an explicit grant.
Hermiq's flagship NC-native feature is over-blocked by Hermiq's own governance. Annotating the
tools honestly is the fix; `OpenRegister#369` already forwards descriptor hints through
`Tool/McpProviderBridge`, so declared hints reach the classifier.

**2. Hermiq has no declarative surface at all.** Zero of its 21 schemas carry
`x-openregister-mcp`, so `SchemaDerivedToolProvider` emits nothing for `hermiq`. A user on the
Agent Catalog page asking "which agents do I have?" gets nothing back from the app that *is* the
agent platform.

**3. There is a live draft proposing the exact opposite.** `openspec/changes/hermiq-domain-mcp-tools/`
(0/13 tasks, unimplemented) proposes hand-writing `hermiq.listAgents`, `hermiq.getAgentStatus`,
**`hermiq.listPendingApprovals`**, **`hermiq.runAgentNow`** and `hermiq.searchSkills` into the
provider, citing the older ADR-035. That change is superseded on both counts: ADR-063 forbids
hand-written CRUD, and `listPendingApprovals` + `runAgentNow` are precisely the
privilege-escalation surface this proposal refuses (see Risk 1). This change **supersedes and
withdraws** `hermiq-domain-mcp-tools`.

## Affected Projects

- [ ] Project: `hermiq` — dialect on 3 schemas (read-only); 8 provider tools → `#[McpTool]` on 3 services; `HermiqToolProvider` deleted; `IMcpScannableServices` opt-in added.

## Scope

### In Scope

- Declare `x-openregister-mcp` on **`Agent`, `Schedule`, `Session` only**, with **`search` + `get` only** (no `create`/`update`/`delete` anywhere).
- Move the 6 NC-native tools (`listFiles`, `readFile`, `searchContacts`, `listCalendarEvents`, `sendMail`, `listDeckBoards`) into `lib/Service/NcNativeToolService.php`, each `#[McpTool]`-annotated with honest hints and scope.
- Annotate `CourseRecommendationEngine::getOrRegenerate()` (`hermiq.recommendCourses`) and `ToolSearchService::search()` (`hermiq.searchTools`) with `#[McpTool]`, preserving both tool ids exactly.
- Delete `lib/Mcp/HermiqToolProvider.php` and its `IMcpToolProvider::hermiq` alias; add `lib/Mcp/HermiqScannableServices.php`.
- Withdraw the superseded `hermiq-domain-mcp-tools` change.

### Out of Scope

- **Any write verb, on any Hermiq schema.** Deliberately deferred — see Risk 1; re-opening this requires a fresh proposal with a threat model, not a dialect edit.
- The 18 schemas listed OFF in `design.md` — including `Approval`, which is deferred **indefinitely**, not "to a later change".
- Splitting `NcNativeToolService` into five per-capability services (deferred; see design.md Decision 3).
- Any change to OpenRegister.

## Approach

Chain 1 (dialect) for the three schemas a human plausibly asks about; chain 3 (`#[McpTool]`) for
the seven genuinely non-CRUD tools; nothing hand-written survives. Because
`AttributeToolScanner` builds ids as `{appId}.{name}` (`$name = $attribute->name ?? $method->getName()`),
passing `name: 'searchTools'` keeps the id `hermiq.searchTools` byte-identical — which matters,
because `Engine\FacadeToolInvoker::__call()` short-circuits that exact id before the facade.

## New Dependencies

None.

## Impact

- `lib/Settings/hermiq_register.json` — dialect blocks on 3 of 21 schemas.
- `lib/Mcp/HermiqToolProvider.php` — **deleted** (539 lines); logic relocated, not dropped.
- `lib/Service/NcNativeToolService.php` — **new**; receives the 6 NC-native tool bodies and their IDOR guards verbatim.
- `lib/Service/CourseRecommendationEngine.php`, `lib/Service/ToolSearchService.php` — `#[McpTool]` attributes added.
- `lib/AppInfo/Application.php` — provider alias removed, `IMcpScannableServices::hermiq` alias added.
- `tests/` — `HermiqToolProviderTest` retargeted at the new services.

## Cross-Project Dependencies

Depends on OpenRegister at `origin/development` for `Mcp/Attribute/McpTool`, `Mcp/IMcpScannableServices`,
`Mcp/BuiltIn/SchemaDerivedToolProvider`, and the `#369` hint-forwarding in `Tool/McpProviderBridge`. All
present and verified at HEAD.

## Risks

### Risk 1: An agent with write access to Hermiq's own registers can escalate its own privileges

**Severity:** High — **Mitigation:** Refuse all write verbs on all schemas, and keep the
governance objects off the surface entirely (both verbs). The escalation is not hypothetical and
the chain is short:

- `Agent` carries `tools`, `prompt`, `actingUser`, `groups`, `isPrivate`. One `hermiq.agent.update`
  call lets an agent **grant itself tools** and **rewrite its own system prompt**. That is a total
  compromise of the grant model in a single tool call.
- `Approval` carries `status`, `toolId`, `toolArguments`. An agent that can update an `Approval`
  **approves its own pending action**, which is the entire point of `human-approval-gate`. Even
  reading the approval queue is refused: it is the control plane for the agent's own governance,
  a human uses the Approval Inbox UI rather than an LLM to work it, and leaving it off costs
  nothing while closing a whole class of future mistakes.
- `Schedule` carries `prompt`, `requiresApproval`, `deliverTarget`. `hermiq.schedule.create` is
  **persistence** (an agent scheduling itself to run unattended); `hermiq.schedule.update` lets it
  set `requiresApproval: false` — disabling its own approval gate — and re-point `deliverTarget`
  for scheduled exfiltration.
- `GuardrailPolicy.inputFilters` / `outputFilters` **are the blocklist**; reading them tells an
  agent exactly what to evade. `TenantControl.engaged` is the tenant kill-switch. `AgentWebhook`
  carries `secretHash` + `secretPrefix` — credential material.
- `Skill` carries `body`, `files`, `state`, `quarantineReason` — a Skill is **executable
  instruction content**. A write lets an agent author its own new instructions and clear its own
  `state: quarantined`.
- `Memory.entries` / `UserProfile.entries` — a write here is **memory poisoning**: persistent
  injection that survives the session.

This matters more in Hermiq than anywhere else in the fleet, because Hermiq's *own* retained tools
(`readFile`, `searchContacts`, `listCalendarEvents`) ingest attacker-influenceable content, and its
retained `sendMail` is an outbound channel. Read-injection and write-escalation would otherwise sit
in the same agent.

### Risk 2: Transcript reads become an exfiltration payload

**Severity:** High — **Mitigation:** Split headers from bodies. `Session` (title, startedAt,
lastActivityAt, agentId) is ON; `SessionTurn`, `Conversation`, `Message` (raw `content`) are OFF.
Enabling `hermiq.message.search` next to the retained `hermiq.sendMail` would hand a
prompt-injected agent a two-call "read every past conversation → email it out" chain. The dialect
has **no field projection** — a `get` returns the whole object — so "read the headers, not the
bodies" can only be expressed by leaving the body-bearing schemas off.

### Risk 3: `recommendCourses` is not actually read-only

**Severity:** Medium — **Mitigation:** Annotate it honestly rather than conveniently.
`CourseRecommendationEngine::getOrRegenerate()` **persists** a regenerated `CourseRecommendation`
when the cached one is stale, so it gets `readOnlyHint: false`, `scope: 'update'`. The honest
consequence is that it now fails closed and needs an explicit grant. If the fleet wants it
ungated, the correct fix is to split a pure-read `getCached()` — not to mislabel the write. See
Open Questions.

### Risk 4: Deleting the provider silently unregisters `hermiq.searchTools`

**Severity:** Medium — **Mitigation:** `searchTools` must stay enumerable through the *same*
registration path so grants and whitelists keep applying to it (`agent-tool-governance` design.md
§2). Moving it to `#[McpTool(name: 'searchTools')]` on `ToolSearchService` preserves both the id
and the registration path; `FacadeToolInvoker`'s short-circuit keys on the id and is unaffected. A
task asserts the id explicitly.

## Rollback Strategy

Two independent halves. Revert the JSON hunk to drop the dialect (`SchemaDerivedToolProvider`
emits nothing for an app with no opted-in schema — no code change needed). Revert the PHP commit
to restore `HermiqToolProvider` and its alias; the service classes are additive and inert without
the `IMcpScannableServices` alias.

## Open Questions

- Hermiq carries **two parallel chat-history models** — `Session`/`SessionTurn` and
  `Conversation`/`Message`. Which is canonical? This change exposes `Session` only and leaves the
  question open.
- Does `Agent.configuration` still hold provider credentials post-`hermiq#43` (credential-broker
  migration)? If any key material can land in that blob, `Agent` must drop to OFF as well. A task
  verifies this before the dialect ships.
- Should `recommendCourses` be split into a pure-read `getCached()` so it can be granted as a read?
