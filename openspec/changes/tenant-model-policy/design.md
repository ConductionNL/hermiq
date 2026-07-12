# Design: tenant-model-policy

## Architecture Overview

Today, provider/model selection is two-layer: an instance-wide `hermiq.llm` config
(`LlmSettingsHandler`, `IAppConfig` key) picks a default `chatProvider` + per-provider config
block, and each `Agent` object may override `provider`/`model` (free text) per turn.
`ProviderFactory::createChatDriver()` is the single seam every run-time caller resolves a
driver through: `ResponseGenerationHandler::generateResponse()` (interactive chat) and
`ConversationManagementHandler` (title/summary background work) both call it, and
`ScheduleService`/`FlowAgentRunService` reach it indirectly via the same Engine call.

This change adds a third layer above both: a `ModelPolicy` OpenRegister object, one per
organisation (plus an org-less instance-wide fallback), that allowlists which
`{provider, model}` pairs the organisation's agents may resolve to. It is enforced at the
`ProviderFactory` seam — the one chokepoint every trigger path already shares — so no
per-trigger duplication of the check is needed.

```
Agent.provider/model (per-turn override, unchanged)
        │
        ▼
ProviderFactory::createChatDriver()
        │  NEW: resolve organisation → TenantModelPolicyService::effectivePolicyFor(org)
        │       → check (resolved provider, resolved model) against policy.allowed[]
        │       → violation: throw ModelPolicyViolationException (mirrors
        │         ProviderUnavailableException's shape/role)
        ▼
existing driver construction (ollama/openai/fireworks/nextcloud) — unchanged
```

`TenantModelPolicyService` mirrors `TenantControlService` exactly: `getForOrganisation()`
queries `register: hermiq, schema: modelpolicy` with `_rbac: false, _multitenancy: false` and
matches by `ObjectEntity.organisation`, because a policy read must work for a system-wide
schedule tick (which runs before any per-user RBAC context exists) just as
`loadEngagedOrganisations()` does for the kill-switch.

## Goals / Non-Goals

**Goals**
- One canonical place (`ModelPolicy` + `TenantModelPolicyService`) an organisation's allowed
  providers/models live, readable by both the UI (to filter choices) and the engine (to
  enforce them).
- Enforcement that covers every trigger path (schedule, Run-now, conversation, flow-listener)
  by construction, not by remembering to add a check in each caller.
- A defined fallback for organisations with no policy of their own (instance-admin default),
  so "no policy configured" is never equivalent to "unconstrained."
- A user-visible, audited refusal — never a silent substitution of a different model/provider.

**Non-Goals**
- Content-based / data-classification routing (routing an individual message based on what it
  contains) — out of scope per the proposal; `ModelPolicy` gates by provider+model only.
- Changing `ProviderFactory`'s driver construction logic itself, or adding a 5th driver.
- A per-skill or per-tool policy — this is scoped to chat provider/model only.

## Decisions

**New schema `ModelPolicy`, not a field on `TenantControl`.** `TenantControl` is a boolean
kill-switch (ADR-004 Art. 14 stop mechanism) — a different lifecycle and a different actor
(org-subadmin toggling engagement) than an allowlist an admin curates and a UI/engine both
read continuously. Folding an allowlist array into the kill-switch schema would overload one
object with two unrelated governance concerns. A sibling schema, following the exact same
per-organisation-object pattern, keeps each object single-purpose (matches the existing
`TenantControl`/`ModelPolicy` sibling relationship the brief anticipates).

**Enforce inside `ProviderFactory::createChatDriver()`, not inside `ScheduleService::dispatch()`.**
`dispatch()`'s two existing gates (kill-switch, human-approval) are schedule/Run-now-specific
— they run before an agent is even loaded. Model policy, by contrast, must also cover
interactive chat (`ResponseGenerationHandler`, no schedule involved) and flow-triggered runs
(`FlowAgentRunService`, also no schedule). Putting the check inside `createChatDriver()` means
every one of these callers is covered automatically, because they all already call it to get
a driver — there is no second place to remember. The trade-off is that `ProviderFactory` now
needs the calling agent's organisation threaded in (a new optional parameter), whereas today
it is a pure config-in/driver-out function; this is accepted as a small, justified widening of
one parameter list against the alternative of four separate call-site checks.

**Policy check happens AFTER model resolution (agent override applied), not before.**
`createChatDriver()` already computes the effective model as
`agentModel ?? providerConfig.chatModel`. The policy check runs against that *resolved* pair,
not against the agent's raw override alone — an agent with no explicit `model` override still
gets checked against whatever the instance config would resolve to, so a policy cannot be
bypassed by simply leaving the per-agent field blank.

**Fallback policy = an organisation-less `ModelPolicy` object, not a separate `IAppConfig` key.**
Two options: (a) a dedicated `IAppConfig` JSON blob for the instance default (mirroring
`hermiq.llm`'s own storage), or (b) a `ModelPolicy` object whose `ObjectEntity.organisation`
is empty/absent, read by the same `TenantModelPolicyService::getForOrganisation()`-style query
when no org-specific match exists. (b) is chosen: it reuses one schema, one CRUD surface, one
settings UI pattern (a `ModelPolicy` "for this org" list plus one org-less "instance default"
entry an instance admin manages), rather than introducing a second, differently-shaped config
mechanism for what is conceptually the same object at a different scope. This mirrors how
`hermiq.llm` itself is already instance-wide with no org dimension — the fallback is simply
"the instance-wide `ModelPolicy`", already a familiar shape.

**A missing policy at either level means "constrained by the strictest defined level", not "open".**
Resolution order: (1) organisation's own `ModelPolicy` if one exists → use it exactly;
(2) else the org-less instance-default `ModelPolicy` if one exists → use it; (3) else — no
policy configured anywhere — fail CLOSED to the four drivers' currently-configured
`hermiq.llm` provider only (i.e., equivalent to "allow only what the instance admin already
turned on in Settings"), not fully open to all four drivers and arbitrary model strings. This
avoids a fresh install (no `ModelPolicy` ever created) becoming either broken (nothing works)
or silently unconstrained (defeats the entire feature the moment nobody has configured it
yet) — the existing `hermiq.llm.chatProvider` selection is already an intentional admin
choice, so treating it as the ceiling when no explicit policy exists is not a new trust
boundary, just a narrower default than "anything goes."

**Violation surfaces through the existing error/audit paths, not a new mechanism.**
- Schedule/Run-now path: `ModelPolicyViolationException` is caught by the same
  `try`/`catch (Throwable $e)` in `ScheduleService::runDue()` that already handles any agent-
  turn failure — `lastStatus='error'`, `lastError=$e->getMessage()`, written via the existing
  `writeRunAudit()`. No new gate/audit code path is introduced; the exception message is
  simply specific ("Model policy violation: organisation 'X' does not permit provider
  'fireworks' model 'llama-v3p1-8b-instruct'").
- Interactive chat path: `ResponseGenerationHandler` lets the exception propagate exactly as
  `ProviderUnavailableException` already does today (e.g. the existing `nextcloud`-for-chat
  guard) — the conversation surfaces a clear, generic-safe error message (ADR-005) to the
  user; no raw exception detail beyond the intended provider/model names is leaked.
- This reuses two mechanisms that already exist for two different reasons (schedule-failure
  audit, chat-error surfacing) rather than inventing a third.

## Risks / Trade-offs

- [Threading `organisation` into `ProviderFactory::createChatDriver()` touches its two call
  sites] → Both call sites (`ResponseGenerationHandler`, `ConversationManagementHandler`)
  already hold an `ObjectEntity $agent` with `getOrganisation()` available; the change is
  passing one already-available string through, not a new lookup.
- [A newly-tightened org policy can turn a previously-working schedule into an erroring one]
  → Accepted and intentional (see proposal Risk 1) — loud failure with a clear message is the
  correct behavior for a governance feature; the alternative (silently keep using the old
  model) would defeat the guarantee entirely.
- [Fail-closed-to-`hermiq.llm` default for "no policy anywhere" could surprise an instance that
  never adopts this feature] → Scoped narrowly: it only changes behavior for organisations
  whose agents were already relying on the per-agent `model`/`provider` override diverging
  from the instance's configured `chatProvider` — which was already an unenforced, arbitrary
  free-text field with no guarantee of working (an invalid model string already errors deep
  inside the LLPhant/Fireworks client today, just later and with a worse message).

## Migration Plan

- Additive schema (`ModelPolicy`); register `info.version` bump (0.9.1 → 0.10.0) triggers
  OpenRegister re-import on next boot, same as every prior schema addition in this app
  (`agent-capability-profile`'s 0.7.0 → 0.8.0 precedent).
- No seed `ModelPolicy` objects are required for existing installs to keep working: the
  "no policy anywhere" fail-closed-to-`hermiq.llm` behavior (see Decisions) is the implicit
  policy until an admin creates an explicit one.
- Rollback: reverting the `ProviderFactory` enforcement commit restores unconstrained
  behavior; the additive schema can be left in place harmlessly (see proposal's Rollback
  Strategy).
- Seed data for a fresh install/demo: one instance-default `ModelPolicy` (organisation-less)
  permitting all four drivers with no model restriction (`models: []` meaning "any model for
  this provider" — see spec), so a clean install behaves exactly as today until an admin
  narrows it; plus one example organisation-scoped `ModelPolicy` restricting to
  `ollama`-only, demonstrating the sovereignty use case out of the box.

### Schema: `modelpolicy`

| Field | Instance default (seed 1) | Org sample — sovereignty (seed 2) |
|-------|---------------------------|-------------------------------------|
| `allowed[0].provider` | `openai` | `ollama` |
| `allowed[0].models` | `[]` (any) | `[]` (any) |
| `allowed[1].provider` | `ollama` | *(none — single-provider policy)* |
| `allowed[1].models` | `[]` | |
| `allowed[2].provider` | `fireworks` | |
| `allowed[2].models` | `[]` | |
| `allowed[3].provider` | `nextcloud` | |
| `allowed[3].models` | `[]` | |
| `defaultModel.provider` | `null` | `ollama` |
| `defaultModel.model` | `null` | `qwen2.5` |
| `@self.organisation` | *(empty — instance default)* | seeded sample org |

**Related items per object:** none (a `ModelPolicy` is a standalone governance object, no
Files/Notes/Tasks/Contacts links).

## API Design

### `GET /api/model-policy/effective`
Returns the caller's effective policy (their org's own `ModelPolicy` if one exists, else the
instance default), for the agent form to populate its provider/model dropdowns.

**Request:** none (identity from session).

**Response:**
```json
{
  "source": "organisation",
  "allowed": [
    { "provider": "ollama", "models": [] }
  ],
  "defaultModel": { "provider": "ollama", "model": "qwen2.5" }
}
```

### `GET /api/model-policy` (admin/org-subadmin)
Lists the caller-visible `ModelPolicy` objects (their org's, plus the instance default when
the caller is an instance admin).

### `PUT /api/model-policy/{uuid}` (admin/org-subadmin)
Updates a `ModelPolicy`'s `allowed`/`defaultModel`. Same authorization split as
`TenantControlController`: an org-subadmin may only write their own organisation's policy; an
instance admin may additionally write the org-less instance default.

**Request:**
```json
{ "allowed": [{ "provider": "ollama", "models": ["qwen2.5", "llama3"] }], "defaultModel": { "provider": "ollama", "model": "qwen2.5" } }
```
**Response:** the persisted `ModelPolicy` object, same shape as the GET.

## Nextcloud Integration

- Controllers: new `TenantModelPolicyController` (`#[NoAdminRequired]` for the `effective`
  read; org-subadmin/instance-admin guard on writes, mirroring `TenantControlController`).
- Services: new `TenantModelPolicyService`; `ProviderFactory` gains the enforcement call;
  `ResponseGenerationHandler`/`ConversationManagementHandler` thread `organisation` through.
- Mappers/Entities: none new — `ModelPolicy` is an OpenRegister `ObjectEntity`, same as
  `TenantControl`.
- Events/Hooks: none.

## Security Considerations

- Reads (`getForOrganisation`) run with `_rbac: false, _multitenancy: false` for the same
  reason `TenantControlService`/`ScheduleService`'s kill-switch read does: the schedule tick
  and the engine's per-turn resolution run outside a per-request user session and must see
  every organisation's policy to pick the right one — this is a read-only, system-scoped
  query identical in shape to the existing precedent, not a new privilege escalation surface.
- Writes are RBAC-scoped: an org-subadmin may only write their own organisation's
  `ModelPolicy` (matched by `ObjectEntity.organisation`, same `@self.organisation` pinning
  `TenantControlService::toggle()` already uses to prevent writing into the wrong org); only
  an instance admin may write the org-less instance-default policy.
- The violation error message names the rejected provider/model (useful for the admin/user to
  fix their agent config) but never includes API keys or other `hermiq.llm` config values
  (ADR-005 — generic-safe client errors, detailed server-side logging).

## NL Design System

The new model-picker `NcSelect`s in `AgentFormModal.vue` follow the same pattern
`agent-capability-profile`'s tool allowlist selector already established: `NcSelect` with
`input-label`, fetched options, `multiple: false` for provider/model (single choice, unlike
the tools multi-select). No new component classes or bespoke styling.

## File Structure

```
lib/
  Settings/hermiq_register.json        (+ ModelPolicy schema, version bump)
  Service/
    TenantModelPolicyService.php       (new)
    Llm/ProviderFactory.php            (+ policy check ahead of driver construction)
    Llm/ModelPolicyViolationException.php (new)
    Engine/ResponseGenerationHandler.php  (thread organisation through)
    Engine/ConversationManagementHandler.php (thread organisation through)
  Controller/
    TenantModelPolicyController.php    (new)
  AppInfo/routes.php                   (+ 3 routes)
src/
  modals/AgentFormModal.vue            (provider/model → policy-filtered NcSelect)
  views/ (or Settings surface)         (ModelPolicy admin/org-subadmin management UI)
```

## Trade-offs

Considered enforcing policy purely client-side (UI only lets you pick allowed options, no
server-side check). Rejected: OpenRegister object writes and direct API calls bypass the
Vue form entirely, and the sovereignty guarantee this change exists to make ("no data to US
clouds") is worthless if it can be defeated by a raw API call — server-side enforcement in
`ProviderFactory` is the actual guarantee; the UI filtering is a UX convenience on top of it,
not a substitute for it.
