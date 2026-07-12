# Proposal: tenant-model-policy

## Summary

Adds a per-organisation model policy to Hermiq: an OpenRegister `ModelPolicy` object that
allowlists which of the four chat drivers (`openai`, `ollama`, `fireworks`, `nextcloud`) and
which model ids an organisation's agents may use, plus an optional org default model. The
agent create/edit UI filters model choices to the caller's org policy instead of accepting
free text, and the engine enforces the policy at run time regardless of trigger (schedule
tick, Run now, conversation, flow-listener) — an out-of-policy provider/model is a refused
run with a clear audit entry and a user-visible error, not a silent pass-through. An
instance-admin fallback policy applies to organisations that have not configured one of
their own.

## Motivation

Hermiq currently resolves the chat provider/model entirely from the instance-wide
`hermiq.llm` config (`LlmSettingsHandler`/`ProviderFactory`) plus a free-text per-agent
override (`Agent.provider`/`Agent.model`, rendered as plain `NcTextField`s in
`AgentFormModal.vue` — see `lib/Service/Llm/ProviderFactory.php` and
`src/modals/AgentFormModal.vue`). Any agent in any organisation can set `model` to any
string; `ProviderFactory::createChatDriver()` passes it straight to the LLPhant/Fireworks/
TaskProcessing client with no allowlist check. For a multi-tenant MSP deployment this means
one organisation cannot make a binding, auditable guarantee that its agents never send data
to a specific provider (e.g. "no US cloud, Ollama/local only") — the only enforcement today
is whatever an individual agent author happens to type into a text field.

This is the sovereignty core of Hermiq's value proposition for public-sector and
compliance-sensitive customers: research evidence (domain 265: model-provider abstraction,
per-tenant model policy, data-classification routing, "no US cloud" guarantee) and the
deployment plan (plan §8, `integration_openai`/`integration_ollama`) both call for a policy
layer above the existing instance-wide config. `multi-tenant-ops` already ships local Ollama
inference and a per-tenant AI Act audit export, but "local inference is *possible*" is not
the same guarantee as "local inference is *enforced*" — this change closes that gap.

## Affected Projects

- [x] Project: `hermiq` — new `ModelPolicy` OpenRegister schema; `TenantModelPolicyService`
      (mirrors `TenantControlService`'s per-organisation object pattern); enforcement in the
      `ProviderFactory`/`ResponseGenerationHandler`/`ConversationManagementHandler` seam;
      `AgentFormModal.vue` provider/model fields become policy-filtered `NcSelect`s; a
      `TenantModelPolicyController` + admin/org settings surface to manage the policy.

## Scope

### In Scope

- New `ModelPolicy` OpenRegister object (register `hermiq`, schema `modelpolicy`):
  per-organisation allowlist of `{provider, models[]}` pairs (of the 4 existing drivers) plus
  an optional `defaultModel` (`{provider, model}`).
- `TenantModelPolicyService`: reads the caller's organisation's `ModelPolicy` (mirrors
  `TenantControlService::getForOrganisation()` — matched by `ObjectEntity.organisation`,
  `_rbac: false, _multitenancy: false`, at most one policy per org); when none exists, reads
  an instance-wide fallback policy (a `ModelPolicy` with no organisation, or an `IAppConfig`
  default — decided in design.md).
- Run-time enforcement: before `ProviderFactory::createChatDriver()` resolves a driver for an
  agent turn, the resolved `(provider, model)` pair is checked against the calling agent's
  organisation policy. Out-of-policy resolution throws a clear, user-visible exception
  (mirroring `ProviderUnavailableException`'s existing role) and is recorded on the run's
  audit entry exactly as an existing gate skip is (`ScheduleService::recordGateSkip()`/
  `writeRunAudit()`), so scheduled, Run-now, flow-triggered, and interactive-chat runs are all
  covered by the same one enforcement point.
- Agent create/edit UI (`AgentFormModal.vue`): the free-text Provider/Model fields become
  `NcSelect` dropdowns populated from the caller's effective policy (org policy, or the
  instance fallback when the org has none); an agent cannot be saved with a provider/model
  outside the effective policy.
- Instance-admin fallback policy: when an organisation has no `ModelPolicy` of its own, its
  agents are constrained by an instance-wide default policy (configurable by an instance
  admin), not left unconstrained.
- New `tenant-model-policy` capability spec; `multi-tenant-ops` MODIFIED with a policy
  enforcement scenario; `agent-management-ui` MODIFIED with a filtered-model-picker scenario.

### Out of Scope

- Per-request/per-message data-classification routing (routing a single message to a
  different provider based on the content it contains) — the plan's "data-classification
  routing" is a possible future refinement of this same policy object, not delivered here.
  This change is allowlist-based (provider+model), not content-based.
- Changing the shape or number of chat drivers — the four drivers (`openai`, `ollama`,
  `fireworks`, `nextcloud`) are unchanged; this change only gates which of them an
  organisation's agents may select.
- Any cross-app RPC to another Conduction app for policy evaluation — the policy object and
  its enforcement live entirely inside Hermiq/OpenRegister.
- Retroactively fixing already-saved agents with an out-of-policy provider/model — enforcement
  applies at save time (UI) and at run time (engine); a pre-existing agent that predates a
  newly-tightened policy is only blocked the next time it tries to run, not silently edited.

## Approach

Mirror the existing `TenantControlService`/`TenantControl` pattern (a per-organisation
OpenRegister object read with `_rbac: false, _multitenancy: false` and matched by
`ObjectEntity.organisation`) for `ModelPolicy`. Add one enforcement call in the
`ProviderFactory` seam — the single chokepoint every run-time path (`ResponseGenerationHandler`,
`ConversationManagementHandler`) already funnels through to resolve a driver — so the policy
is checked exactly once per turn regardless of what triggered it. Reuse the existing
gate-skip/audit-entry machinery (`ScheduleService::recordGateSkip()`/`writeRunAudit()`) for
the schedule/Run-now path, and the existing `ProviderUnavailableException`-style user-visible
error for the interactive-chat path. The UI change follows `agent-capability-profile`'s
precedent of tightening an existing free-input field into a governed, fetched-from-the-server
option list (as that change did for `tools`).

## New Dependencies

None.

## Impact

- `lib/Settings/hermiq_register.json`: new `ModelPolicy` schema; register `info.version`
  bump to force re-import.
- `lib/Service/Llm/ProviderFactory.php`: new policy-check call ahead of driver resolution.
- New `lib/Service/TenantModelPolicyService.php`, `lib/Controller/TenantModelPolicyController.php`.
- `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Engine/ConversationManagementHandler.php`:
  thread the agent's organisation through to the policy check; catch/surface the violation.
- `lib/Service/ScheduleService.php`: schedule/Run-now paths get the violation recorded via the
  existing gate-skip/audit machinery (no new gate-ordering change needed if the violation
  simply surfaces as the existing `lastStatus='error'`/`lastError` path — confirmed in design.md).
- `src/modals/AgentFormModal.vue`, a new `src/store/` fetch for the effective policy, and a
  settings surface (org-level and instance-admin-level) to manage `ModelPolicy` objects.

## Cross-Project Dependencies

None. This is a self-contained Hermiq change; it reuses OpenRegister's existing
organisation/owner/groups multi-tenancy (already a Hermiq dependency) but adds no new
cross-app call.

## Risks

### Risk 1: A policy tightened after agents already exist silently breaks scheduled runs
**Severity:** Medium — **Mitigation:** the run-time refusal is loud, not silent: it produces
a `lastStatus='error'` with a specific `lastError` message ("model policy violation: ...")
and a per-run audit entry, exactly like any other agent-turn failure today — an org admin
sees it in the existing run-history/audit surfaces rather than a run just quietly using a
different model or provider.

### Risk 2: Enforcement point must cover every trigger path, not just the schedule tick
**Severity:** Medium — **Mitigation:** enforcing inside `ProviderFactory`/`createChatDriver()`
rather than inside `ScheduleService::dispatch()` (which is schedule/Run-now specific) means
conversation-driven chat and flow-triggered runs (`FlowAgentRunService`), which also resolve
a driver through the same factory, are covered by construction rather than needing a second,
easy-to-forget check.

### Risk 3: Organisations with no policy configured must not become either wide-open or fully blocked
**Severity:** Low — **Mitigation:** the instance-admin fallback policy (an org-less
`ModelPolicy`, or an equivalent `IAppConfig` default — finalised in design.md) gives every
organisation a defined, enforced default rather than leaving "no policy" ambiguous.

## Rollback Strategy

The enforcement call in `ProviderFactory` is a single, removable guard; reverting the commit
that adds it restores today's unconstrained behaviour. The `ModelPolicy` schema addition is
additive (no existing schema is modified in a breaking way) and can be left in place, unused,
without harming existing installs if the enforcement code is rolled back independently. No
data migration is introduced, so there is nothing to reverse-migrate.

## Open Questions

- Exact representation of the instance-wide fallback policy — an organisation-less
  `ModelPolicy` object (`ObjectEntity.organisation === ''`) versus a dedicated
  `IAppConfig`-backed default. Resolved in design.md.
- Whether a policy violation on the interactive-chat path (not schedule-driven) should also
  write an explicit `AuditTrail` entry beyond the existing conversation/message record, or
  whether the existing conversation error message is sufficient given ADR-004's "every run is
  audited" framing applies primarily to scheduled/governed runs. Resolved in design.md.
