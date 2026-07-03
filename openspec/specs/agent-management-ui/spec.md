# Agent Management UI Specification

**Status**: planned
**Standards**: WCAG 2.1 AA
**Feature tier**: MVP

**OpenSpec changes:** _(none yet — run `/opsx-ff agent-management-ui`)_

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
The system MUST let a user create an agent (name, model/provider, prompt, enabled tools) and edit it, persisting via OpenRegister.

#### Scenario: Create an agent
- GIVEN the create form
- WHEN the user fills in name, selects a model, writes a prompt, and saves
- THEN the system MUST create the agent as an OpenRegister object owned by the user's organisation

### Requirement: Attach a schedule and run now [MVP]
From an agent's detail view the user MUST be able to add/edit a schedule (see `agent-schedule`) and trigger an immediate run.

#### Scenario: Run an agent manually
- GIVEN an agent detail view
- WHEN the user clicks "Run now"
- THEN the system MUST start a run under the user's identity and show its result and audit entry

### Requirement: Run history view [MVP]
Each agent's detail view MUST show its run history (see `run-audit-log`) with status, timing, and output/audit links.

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
