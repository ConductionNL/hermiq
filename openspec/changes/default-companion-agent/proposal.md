---
kind: code
depends_on: []
---

# Proposal: default-companion-agent

## Summary

The AI companion picks an agent for the user with a single, blunt rule:
`ChatStreamController::pickFallbackAgentForUser()` (line 558) returns the **first agent the user
can access** out of a `findAll(config: ['limit' => 20])` scan. There is no way for a user to say
which agent they want. This change adds a **per-user default agent** — a picker in personal
settings and a "Make my default" action on the agent detail page — and establishes a precedence
chain: **per-user → app-config → first-accessible**. It also makes the AI hexagon the default
avatar wherever an agent renders without an icon.

## Motivation

**The live failure this prevents:** the companion picked whichever agent came back first. That
agent's `model` was `qwen2.5`, while the configured provider was the Claude CLI.
`ProviderFactory` lets the **agent's** `model` override `anthropicConfig.chatModel` (lines
1880-1882: `$model = ($anthropicConfig['chatModel'] ?? 'claude-opus-4-8'); … $model =
$agentModel;`), so the runner executed `claude --model qwen2.5` → **exit 1, empty stderr, and the
UI spun forever**. The user had a perfectly good Claude agent; the picker just never chose it.
A per-user default is the direct fix: the user names the agent that works.

Beyond that one failure, "first accessible out of the first 20 rows" is not a product decision —
it is an accident of row order. Users with several agents get a non-deterministic companion that
can silently change when an agent is added.

Two supporting reasons:

- **The instance-wide default is not enough.** Open PR **hermiq#116** adds an app-config key
  `companion_agent_uuid` — an admin choosing one agent for everyone. That is the right default
  for an instance, and the wrong one for a person: on a multi-user instance one agent cannot suit
  every user's role or language. Per-user sits **above** it, not instead of it.
- **Agents without an icon render inconsistently.** `AgentFormModal` documents that an empty icon
  "clears back to the default agent icon", but no single default is applied across surfaces. The
  Conduction AI hexagon (`CnAiFloatingButton`, `cn-ai-floating-button__hex`) already signals "this
  is the AI" in the companion; reusing it as the agent fallback avatar makes agents recognisable.

## Affected Projects

- [ ] Project: `hermiq` — per-user default agent stored via NC user config; precedence resolution
  in `ChatStreamController`; a settings picker in `src/App.vue` above the "Talk delivery" section;
  a declarative "Make my default" `api-call` headerAction on the `AgentDetail` manifest page; a
  new controller endpoint to set/clear the preference.
- [ ] Project: `nextcloud-vue` — the AI hexagon is currently CSS local to `CnAiFloatingButton`
  (`position: fixed`, 52×60px). Reusing it as an agent avatar needs a shared, non-fixed hexagon
  surface. See design.md — this is the change's one cross-repo decision.

## Scope

### In Scope

- Persist a per-user default agent UUID (NC user config, app `hermiq`).
- Resolve the companion agent by precedence: **per-user → app-config (`companion_agent_uuid`,
  hermiq#116) → first-accessible (existing `pickFallbackAgentForUser()`)**.
- Access-check the per-user default on every read. A stored UUID is a *preference*, never an
  authorization — if the user has lost access, fall through to the next tier.
- A personal-settings picker rendered in `src/App.vue`'s `#user-settings` slot, **above the
  "Talk delivery" section** (current order: About Hermiq `#about` → Talk delivery
  `#talk-delivery` → Setup `#setup`, admin-only → Credentials `#credentials`).
- A "Make my default" action on the `AgentDetail` page, declared as an `api-call` headerAction.
- The AI hexagon as the default agent icon/avatar wherever an agent renders without one.

### Out of Scope

- **Implementing or modifying hermiq#116.** This change consumes its app-config key as the middle
  precedence tier. It must degrade gracefully if #116 has not landed — see Risk 1.
- **Fixing the `claude --model qwen2.5` failure itself.** A per-user default lets the user *avoid*
  the broken agent; it does not validate that an agent's `model` is compatible with its provider,
  and it does not fix the empty-stderr/infinite-spin symptom. Both are real and deliberately
  deferred — see design.md and DEFERRED_QUESTIONS.
- **Per-agent or per-conversation overrides.** One default per user.
- **An admin UI for `companion_agent_uuid`.** That belongs with #116.

## Approach

Three seams:

1. **Resolution.** A single method resolves the companion agent by precedence and access-checks
   the result at each tier. `ChatStreamController` line 224 calls it instead of calling
   `pickFallbackAgentForUser()` directly. `pickFallbackAgentForUser()` survives unchanged as the
   last tier.
2. **Persistence + endpoint.** NC user config via `IConfig`/`IUserConfig` — no schema, no
   OpenRegister object. A small controller endpoint sets and clears it, and is what the settings
   picker and the headerAction both call.
3. **UI.** A settings section in `src/App.vue`; a declarative `api-call` headerAction on
   `AgentDetail` (no new Vue page); a hexagon avatar fallback.

## New Dependencies

None in `hermiq`. A shared hexagon avatar may require a new component export from
`@conduction/nextcloud-vue` — a version bump of an existing dependency, not a new one.

## Impact

- `lib/Controller/ChatStreamController.php` — line 224 call site; new precedence resolver;
  `pickFallbackAgentForUser()` (line 558) unchanged, demoted to last tier.
- New/extended controller + route for setting and clearing the preference.
- `src/App.vue` — a new `NcAppSettingsSection` above `#talk-delivery`.
- `src/manifest.json` — one new `api-call` entry in `AgentDetail`'s `config.headerActions`
  (currently `edit-agent`, `version-history`, `view-factsheet`).
- `l10n/en.json`, `l10n/nl.json` — new strings.
- Agent avatar surfaces — the hexagon fallback.

## Cross-Project Dependencies

- **hermiq#116 (open PR, same repo)** — provides the `companion_agent_uuid` app-config key. **Not
  merged into this worktree:** `grep -rn "companion_agent_uuid" lib/ src/` returns nothing at
  HEAD. This change must tolerate its absence.
- **`nextcloud-vue`** — for a reusable hexagon avatar, if that route is chosen.

## Risks

### Risk 1: hermiq#116 has not landed, so the middle precedence tier does not exist
**Severity:** High — **Mitigation:** verified against HEAD in this worktree: `companion_agent_uuid`
appears nowhere in `lib/` or `src/`; `pickFallbackAgentForUser()` is first-accessible only, with no
app-config read. The resolver MUST treat the app-config tier as *optional*: read the key, and if it
is absent or empty, fall straight through to first-accessible. Both orders of merge then work, and
neither PR blocks the other. Do **not** implement the key here — that is #116's job.

### Risk 2: A stored default UUID is treated as authorization
**Severity:** High — **Mitigation:** the preference is user-writable data naming an object id —
a textbook IDOR vector if trusted. The resolver MUST run `canUserAccessAgent()` on the stored
UUID on **every** read, exactly as the first-accessible tier already does, and fall through on
failure. A user who stores an agent they cannot access, or loses access later, gets the next
tier — never that agent.

### Risk 3: The default points at a deleted agent
**Severity:** Medium — **Mitigation:** falling through on a failed lookup covers this (a missing
agent cannot pass the access check). The stale key MAY be cleared opportunistically, but
correctness MUST NOT depend on cleanup.

### Risk 4: The hexagon is copy-pasted into hermiq instead of shared
**Severity:** Medium — **Mitigation:** the hexagon carries a Conduction **brand rule** — pointy-top,
point-up, never rotated, never flat-top, all six sides equal only at a √3:2 width:height ratio
(52×60px), Cobalt fill. Duplicating that CSS into hermiq guarantees drift from the brand rule.
Vue logic and shared visual contracts live in `nextcloud-vue`. See design.md.

### Risk 5: The headerAction is added as a body widget and silently loses its actions
**Severity:** Low — **Mitigation:** the manifest renderer's body-widget branch bypasses
`CnDetailPage` and drops `config.headerActions` entirely. `AgentDetail` is `type: detail` with a
working `headerActions` array today, so adding a fourth entry is safe — but it MUST be added to
`config.headerActions`, and the action MUST be verified rendering in a live browser, not by
grepping the bundle.

## Rollback Strategy

Revert the commit. The preference lives in NC user config, so a revert leaves orphaned user-config
rows — harmless (nothing reads them) and forward-compatible if the change is re-applied. No schema
and no OpenRegister object is created, so there is no register re-import to undo and no data to
migrate. With the change reverted, the companion falls back to today's behaviour: app-config if
hermiq#116 has landed, otherwise first-accessible.

## Capabilities

### New Capabilities

- `default-companion-agent`: a per-user default agent preference, its precedence chain over the
  instance-wide default and the first-accessible fallback, and its access-checking contract.

### Modified Capabilities

- `agent-management-ui`: the agent detail page gains a "Make my default" header action, and agents
  rendered without an icon fall back to the Conduction AI hexagon avatar.
- `inapp-settings-section`: personal settings gain a default-agent picker above the Talk delivery
  section.
