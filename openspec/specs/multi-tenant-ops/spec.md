# Multi-Tenant Ops Specification

**Status**: active (Hermiq surface live-verified; create-time hard quota reject + agent inventory are OpenRegister seams)

**Feature tier**: V2

**OpenSpec changes:** `multi-tenant-ops` — DONE: `TenantOpsService` (per-org quota status — schedules + agents-in-use vs. configurable limits; per-tenant EU AI Act audit export scoped to the caller's own objects from OR's hash-chained AuditTrail); `TenantOpsController` (`/api/tenant-ops/quota`, `/api/tenant-ops/audit-export`); `TenantOps` UI (quota cards + audit export download, capability-gated). Isolation is native (all objects carry OR organisation/owner/groups, RBAC-on reads — the org-scoped export is the proof). Local inference = agent Ollama config. Hard create-time quota reject + the authoritative agent inventory are OpenRegister seams (creation flows through OR's object API).

## Purpose

Adds MSP/organisation-level operational controls on top of Hermiq's multi-tenant foundation: per-org
agent and schedule quotas, strict per-tenant isolation across all object types, and sovereignty
features such as local Ollama inference and per-tenant EU AI Act audit export. Builds directly on
OpenRegister's `organisation`/`owner`/`groups` model rather than introducing a parallel tenancy layer.
## Requirements
### Requirement: Per-organisation agent and schedule quotas
The system MUST enforce a configurable maximum number of agents and active schedules per
organisation, rejecting creation attempts that would exceed the quota.

#### Scenario: An organisation attempts to exceed its agent quota
- GIVEN organisation A has a configured quota of 10 agents and currently has 10 agents
- WHEN a user in organisation A attempts to create an 11th agent
- THEN the system MUST reject the creation request
- AND the system MUST inform the user that the organisation's agent quota has been reached

### Requirement: Strict per-tenant isolation across all object types
The system MUST ensure every object type Hermiq introduces (agents, schedules, memory, skills,
approvals, runs, budgets) carries `organisation`/`owner`/`groups` fields, and MUST NOT include
another tenant's objects in any API response. Delegation MUST NOT be usable to reach across a
tenant boundary: an agent MUST NOT be able to delegate to a target agent belonging to a different
organisation, regardless of any configured `delegationAllowlist` entry.

#### Scenario: A cross-tenant list request is made
- **GIVEN** organisations A and B each have their own agents, schedules, memory, and budget
  objects
- **WHEN** a user in organisation A requests a list of any Hermiq object type, including budgets
- **THEN** the system MUST return only objects belonging to organisation A
- **AND** objects belonging to organisation B MUST NOT appear in the response

#### Scenario: An agent's allowlist names a target in a different organisation
- **GIVEN** agent A belongs to organisation A and its `delegationAllowlist` names agent B, which
  belongs to organisation B
- **WHEN** agent A attempts to delegate to agent B via `hermiq.delegateAgent`
- **THEN** the system MUST refuse the delegation
- **AND** agent B MUST NOT be invoked, regardless of the allowlist entry

### Requirement: Per-tenant sovereignty — local inference + AI-Act export
The system MUST allow each organisation to configure local-only inference (Ollama/Qwen) and
MUST provide a per-tenant export of AI Act-relevant audit records scoped strictly to that
organisation. The system MUST also allow each organisation to configure a `ModelPolicy` that
enforces which chat providers and models its agents may use, and MUST refuse — with a clear,
audited error — any agent run that would resolve to a provider or model outside that
organisation's effective policy, so that a sovereignty guarantee (e.g. "no data leaves this
instance") is a binding constraint rather than an unenforced configuration option.

#### Scenario: An org admin exports their AI Act audit trail
- **GIVEN** organisation A has run history recorded in OR `AuditTrail`
- **WHEN** an org admin of organisation A requests an AI Act audit export
- **THEN** the system MUST produce an export containing only organisation A's records
- **AND** the export MUST NOT require data to leave the local/self-hosted instance when local
  Ollama inference is configured

#### Scenario: An organisation enforces a no-external-cloud model policy
- **GIVEN** organisation A has configured a `ModelPolicy` allowing only the `ollama` provider
- **WHEN** any agent belonging to organisation A attempts to run using the `openai` or
  `fireworks` provider (whether via an agent's own override or the instance's configured
  provider)
- **THEN** the system MUST refuse the run before any request reaches an external provider
- **AND** the refusal MUST be recorded in the run's audit entry, giving the organisation
  verifiable proof — not just a configuration assertion — that no data left the local
  instance

### Requirement: Per-scope budget guardrails — soft threshold and hard cap
The system MUST allow an organisation admin to configure a `Budget` (scoped to an organisation or
to one agent, with a token limit and/or a EUR limit per a configurable period) and MUST enforce
two thresholds against that budget's current-period actual usage: a soft threshold that notifies
the organisation owner without blocking runs, and a hard cap that blocks NEW runs for that
budget's scope. The hard cap MUST NOT abort a run already in progress. Current-period usage MUST
be computed from the organisation's/agent's own `action='run'` AuditTrail entries — the same
source `run-analytics` aggregates — never from a separately maintained counter. A delegated
sub-agent run (`sub-agent-delegation`) MUST be gated by, and its usage MUST count toward, the SAME
budget the top-level run that ultimately triggered the delegation counts against — never a separate,
unaccounted budget scope.
<!-- Previous behavior: the hard cap/soft threshold applied to scheduled and flow/webhook-triggered
     runs only; delegated sub-agent runs did not exist. -->

#### Scenario: A soft threshold is crossed
- **GIVEN** a `Budget` for agent X with a token limit of 100,000 and an 80% soft threshold
- **WHEN** agent X's current-period recorded usage crosses 80,000 tokens
- **THEN** the system MUST send one notification to the organisation owner via the existing
  Talk/Notification delivery dialect
- **AND** agent X's schedules MUST continue to run normally

#### Scenario: A hard cap blocks a new run but never an in-flight one
- **GIVEN** a `Budget` for agent X with a token limit of 100,000, already at or above 100,000
  recorded tokens for the current period
- **WHEN** a schedule for agent X becomes due
- **THEN** the system MUST skip the run at the dispatch gate (recorded with a distinct
  budget-exhausted status) and MUST NOT invoke the agent
- **AND** any run of agent X already executing at the moment the cap is reached MUST be allowed
  to finish uninterrupted

#### Scenario: An organisation-scoped budget blocks all of that organisation's schedules
- **GIVEN** organisation A has an organisation-scoped `Budget` at its hard cap
- **WHEN** any schedule belonging to organisation A becomes due
- **THEN** the system MUST skip every one of organisation A's due schedules at the gate
- **AND** schedules belonging to any other organisation MUST be unaffected

#### Scenario: Budget enforcement applies identically to a webhook/event-triggered run
- **GIVEN** an agent whose organisation-scoped `Budget` is at its hard cap
- **WHEN** a webhook or platform event triggers a run for that agent (the `flow-agent-listener`
  path)
- **THEN** the system MUST apply the same hard-cap block as a scheduled tick would
- **AND** the block MUST be recorded via the same gate-skip audit convention

#### Scenario: A delegated sub-agent run counts against the parent's triggering budget
- **GIVEN** a scheduled run for agent A (organisation X) is in progress and organisation X has an
  organisation-scoped `Budget` with 90,000 of a 100,000 token limit already used this period
- **WHEN** agent A delegates a task to an allowed agent B, whose own sub-run consumes 15,000 tokens
- **THEN** the system MUST record agent B's sub-run usage so that organisation X's budget status
  reflects at least 105,000 used tokens for the period (over the hard cap)
- **AND** a subsequent NEW top-level run for organisation X MUST be blocked by that same hard cap

#### Scenario: A delegation is refused when the triggering budget is already exhausted
- **GIVEN** organisation X's budget is already at its hard cap
- **WHEN** an already-running agent in organisation X (started before the cap was reached) attempts
  to delegate a task via `hermiq.delegateAgent`
- **THEN** the system MUST refuse the delegation before invoking the target agent
- **AND** the already-running parent turn MUST be allowed to finish uninterrupted

### Requirement: Per-organisation retention period configuration
The system MUST let an org admin configure a retention period (in months) for the organisation's
governance records, MUST default new organisations to a value that meets EU AI Act Art. 12's
minimum (6 months), MUST reject any configured value below 6, and MUST surface the current value
in `TenantOps.vue` alongside the existing quota and audit-export sections.

#### Scenario: A new organisation has an Art. 12-compliant default retention period
- GIVEN an organisation with no retention period explicitly configured
- WHEN an org admin views the retention setting in Tenant ops
- THEN the system MUST show a default retention period of at least 6 months

#### Scenario: An org admin configures a longer retention period
- GIVEN an organisation's current retention period is the 6-month default
- WHEN an org admin sets the retention period to 12 months
- THEN the system MUST persist 12 months as that organisation's retention period
- AND subsequent views of Tenant ops for that organisation MUST show 12 months

#### Scenario: An org admin attempts to configure a non-compliant retention period
- GIVEN an organisation's current retention period is 6 months
- WHEN an org admin attempts to set the retention period to 3 months
- THEN the system MUST reject the change
- AND the organisation's retention period MUST remain unchanged at 6 months

## User Stories

- As an MSP operator, I want to set per-org agent and schedule quotas so that one tenant cannot exhaust shared infrastructure.
- As a tenant admin, I want absolute certainty that my organisation's agents, memory, and skills are invisible to other tenants so that I can trust the platform with sensitive data.
- As a sovereignty-conscious customer, I want to run inference entirely locally via Ollama so that no data leaves my infrastructure.
- As a compliance officer, I want a per-tenant AI Act audit export so that I can respond to regulator requests without manual data extraction.

## Acceptance Criteria

- [ ] Configurable per-organisation agent quota is enforced at creation time
- [ ] Configurable per-organisation schedule quota is enforced at creation time
- [ ] Every Hermiq object type carries organisation/owner/groups and is filtered accordingly on read
- [ ] Local Ollama/Qwen inference can be configured per organisation
- [ ] AI Act audit export is available per-tenant and excludes other tenants' data

## Notes

Depends on OpenRegister's `ObjectEntity` (owner/organisation/groups/version/locked/deleted) and
`AuditTrail` GDPR/DSAR endpoints, and on the existing LLPhant `OllamaChat` local-Qwen path. Related:
ADR-001 (Option C+ — multi-tenant via Nextcloud groups), ADR-004 (governance via OR AuditTrail).
Quota enforcement mechanism (hard block vs. soft warning) is an open question for the `planned` spec.
