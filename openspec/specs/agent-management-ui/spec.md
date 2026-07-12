# Agent Management UI Specification

**Status**: active (shipped to `main` v0.1.10; agent catalog + attach-schedule + Run-now + run-history live-verified)
**Standards**: WCAG 2.1 AA
**Feature tier**: MVP

**OpenSpec changes:**
- `openspec/changes/agent-management-ui/` — agent catalog + detail + schedule modals + run-now endpoint + run-history view (kind: code)

## Purpose

Give users a Nextcloud-native interface to manage agents and their schedules: browse an
agent catalog, create/configure an agent, attach schedules, trigger a run manually, and
review run history. This is the "+" in Option C+ — Hermiq owns the management surface while
the agents themselves live in OpenRegister.
## Requirements
### Requirement: Agent catalog [MVP]
The system MUST list the agents the user may see, showing name, model, whether a schedule is attached, and last-run status.

#### Scenario: Open the agent catalog
- GIVEN a user with access to several agents
- WHEN they open Hermiq
- THEN the system MUST show those agents (scoped by Nextcloud group/RBAC) with their schedule and last-run status

### Requirement: Create and configure an agent [MVP]
The system MUST let a user create an agent (name, model/provider, prompt, enabled tools) via
a create form, and MUST let a user edit an existing agent's configuration fields inline from
the agent detail view using a schema-driven, click-to-edit widget, persisting via
OpenRegister. Tenancy/noise fields (invited users, groups, views, private flag, quotas,
acting user, configuration, context refs, skill installs) MUST NOT be shown in the inline
config widget. The Provider and Model fields MUST be presented as selectable options drawn
from the caller's effective `ModelPolicy` rather than free text, and the system MUST reject
saving an agent whose provider/model falls outside that effective policy.

#### Scenario: Create an agent
- **GIVEN** the create form
- **WHEN** the user fills in name, selects a model, writes a prompt, and saves
- **THEN** the system MUST create the agent as an OpenRegister object owned by the user's organisation
@e2e exclude pre-existing behaviour (config-only create modal, unchanged by this change); live-verified during this change's verification (created an agent via the form → 201). Playwright coverage owned by the agent-management-ui base change.

#### Scenario: Edit a config field inline from the detail view
- **GIVEN** an agent detail view rendering the schema-driven config widget
- **WHEN** the user clicks a config field (e.g. name, prompt, temperature) and saves a new value
- **THEN** the system MUST persist the change via OpenRegister
- **AND** the detail view MUST reflect the saved value without opening the Edit modal
@e2e exclude live-verified: the schema-driven CnObjectDataWidget click-to-edit + whole-object PUT round-trip returned 200 and the cell reflected the saved value. Dedicated Playwright coverage deferred.

#### Scenario: Edit the tool allowlist inline from the detail view
- **GIVEN** an agent detail view rendering the schema-driven config widget
- **WHEN** the user opens the tools field
- **THEN** the system MUST present the current, dynamically-fetched tool catalog as selectable options
- **AND** selecting or deselecting tools and saving MUST persist the updated tool allowlist via OpenRegister
@e2e exclude live-verified end-to-end: opening the tools field rendered the dynamic /api/agents/tools catalog, selecting a tool and confirming issued the agent PUT (200), and the Tools cell displayed the saved tool. Dedicated Playwright coverage deferred.

#### Scenario: Model and Provider choices are filtered to the organisation's policy
- **GIVEN** the create/edit agent form for a user in an organisation whose effective
  `ModelPolicy` allows only `ollama` (any model)
- **WHEN** the user opens the Provider field
- **THEN** the system MUST offer only `ollama` as a selectable provider
- **AND** the Model field MUST be populated from the models available for the chosen provider
  under the effective policy, not a free-text input

#### Scenario: Saving an out-of-policy provider/model is rejected
- **GIVEN** an agent form pre-filled (e.g. via a raw API edit or a stale form state) with a
  provider/model combination outside the caller's effective policy
- **WHEN** the user attempts to save
- **THEN** the system MUST reject the save with a clear error naming the disallowed
  provider/model
- **AND** the agent's previously-saved (in-policy or unset) configuration MUST remain
  unchanged

### Requirement: Attach a schedule and run now [MVP]
From an agent's detail view the user MUST be able to add/edit a schedule (see `agent-schedule`) and trigger an immediate run.

#### Scenario: Run an agent manually
- GIVEN an agent detail view
- WHEN the user clicks "Run now"
- THEN the system MUST start a run under the user's identity and show its result and audit entry

### Requirement: Run history view [MVP]
Each agent's detail view MUST show its run history (see `run-audit-log`) with status, timing, and
output/audit links, and MUST let the user expand any run to see its step timeline and download that
run's trace as a redacted JSON file.

#### Scenario: View an agent's run history
- GIVEN an agent detail view for an agent whose schedule has run
- WHEN the user views the Run history section
- THEN the system MUST list past runs with their status, timing, and output/audit links
- AND an agent with no runs MUST show an empty-state hint instead of an error

#### Scenario: View a run's step timeline
- GIVEN an agent detail view showing a completed run in the Run history section
- WHEN the user expands that run
- THEN the system MUST render its ordered step timeline (each step's type, name, duration, and
  outcome) fetched from the run-trace endpoint
- AND a run whose execution path did not record tool-call detail MUST show that plainly rather than
  appearing to have no tool activity

#### Scenario: Download a run's trace as JSON
- GIVEN an agent detail view showing a completed run in the Run history section
- WHEN the user chooses "Download trace (JSON)" for that run
- THEN the system MUST retrieve the run's full trace via the owner-scoped endpoint and save it as a
  local JSON file
- AND the downloaded content MUST be the same already-redacted data shown in the expanded timeline

### Requirement: Agent detail manages skills in place [MVP]
The agent detail view MUST list the agent's installed skills, let the user attach an
uninstalled catalog skill, and let the user detach an installed skill, without navigating
away from the detail page.

#### Scenario: Attach a skill from the detail page
- GIVEN an agent detail view and a `Skill` in `active` state not yet installed on this agent
- WHEN the user selects it in the attach picker
- THEN the system MUST install the skill onto the agent
- AND the detail view MUST show the skill in the installed list afterward
@e2e exclude attach reuses the existing installSkill endpoint; the detail Skills section (attach picker + installed list) was live-verified rendering, and the install path is covered by SkillServiceTest. A dedicated Playwright attach flow is deferred (needs a seeded catalog skill).

#### Scenario: Detach a skill from the detail page
- GIVEN an agent detail view showing an installed skill
- WHEN the user triggers the detach action for that skill
- THEN the system MUST remove the skill from the agent's installed skills
- AND the detail view MUST no longer show the skill in the installed list afterward
@e2e exclude detach is covered by SkillServiceTest (uninstallFromAgent removes from both installedOn and skillInstalls, plus idempotency); the detail Skills section was live-verified rendering. Playwright coverage deferred.

### Requirement: Agent detail manages memory in place [MVP]
The agent detail view MUST show the agent's memory (char-budget bar, entry list, add-fact
input, Consolidate action) using the same implementation as the standalone memory view, so
memory can be reviewed and updated without leaving the detail page.

#### Scenario: Add a memory fact from the detail page
- GIVEN an agent detail view with its Memory section visible
- WHEN the user submits a new fact via the add-fact input
- THEN the system MUST persist the fact to the agent's `Memory` object
- AND the fact MUST appear in the entry list without a full page navigation
@e2e exclude the Memory section reuses the unchanged MemoryController write path via the shared AgentMemoryPanel; the section was live-verified rendering (budget bar + add-fact input + entry list) on the detail page. Playwright write coverage deferred (live write was blocked by an unrelated needsDbUpgrade state on the shared instance).

## User Stories

- As an agent builder, I want a form to create and configure an agent so that I do not edit JSON by hand.
- As a user, I want to attach a schedule to an agent in one place so that setup is simple.
- As a user, I want a "Run now" button so that I can test an agent before scheduling it.
- As a user, I want to see past runs so that I know the agent is working.

## Acceptance Criteria

- [ ] An agent catalog lists agents scoped by NC group/RBAC with schedule + last-run status.
- [ ] Create/edit agent forms persist via OpenRegister (no bespoke store; Options API + createObjectStore).
- [ ] Agent detail lets the user add/edit a schedule and "Run now".
- [ ] Agent detail shows run history with status/timing and audit/output links.
- [ ] UI uses `@conduction/nextcloud-vue` components and meets WCAG 2.1 AA.

## Notes

- Frontend follows Conduction conventions: Vue 2.7 + Options API, `createObjectStore`, no
  custom Pinia stores; component logic that belongs in the shared lib lives in
  `@conduction/nextcloud-vue`.
- Consumes OpenRegister for all agent/schedule/run data (ADR-001).
- Related: `agent-schedule`, `run-audit-log`, `talk-delivery`; **ADR-001**.
