## ADDED Requirements

### Requirement: Agent catalog is a dedicated nav app page

The frontend MUST render the agent catalog as a dedicated Nextcloud nav app page
that is the app's main route, listing the agents the caller may see with their
name, model, a schedule-attached indicator, and last-run status. Agents MUST be
read from OpenRegister's agents API (`GET /apps/openregister/api/agents`), which
applies organisation/RBAC filtering in the mapper layer; the schedule-attached
and last-run indicators MUST be derived from the `hermiq`/`schedule` OpenRegister
objects. The catalog MUST NOT be a dashboard-in-dashboard nesting.

#### Scenario: Open the agent catalog

- **WHEN** a user with access to several agents opens Hermiq
- **THEN** the main route MUST show those RBAC-scoped agents with, per row, the
  model, whether a schedule is attached, and the last-run status

#### Scenario: No agents yet

- **WHEN** the caller can see no agents
- **THEN** the catalog MUST show an empty-state (NcEmptyContent) with a call to
  action to create an agent, not a blank page or an error

### Requirement: Create and edit an agent via OpenRegister

The frontend MUST let a user create and edit an agent (name, model/provider,
prompt, enabled tools) through a modal that persists via the OpenRegister agents
API using the `createObjectStore` pattern — no bespoke Pinia store and no bespoke
backend CRUD. The enabled-tools picker MUST be populated from
`GET /apps/openregister/api/agents/tools`. Every `NcSelect` in the form MUST
carry an `inputLabel` for accessibility, and the modal MUST live in its own file
under `src/modals/`.

#### Scenario: Create an agent

- **WHEN** the user fills in name, selects a model, writes a prompt, and saves
- **THEN** the frontend MUST create the agent via the OpenRegister agents API and
  the new agent MUST appear in the catalog

#### Scenario: Edit an existing agent

- **WHEN** the user opens an agent, changes its prompt or enabled tools, and saves
- **THEN** the frontend MUST persist the update via OpenRegister and reflect the
  change without a full page reload

### Requirement: Agent detail attaches a schedule and runs the agent

The agent detail view MUST let a user attach or edit a `Schedule` for that agent
— exposing the schema fields `kind` (once|interval|cron), `cronExpr`,
`intervalMinutes`, `runAt`, `prompt`, `deliver`, `deliverTarget`, `enabled`, and
`repeat` — persisted as an OpenRegister `hermiq`/`schedule` object via
`createObjectStore`. The view MUST offer a **Run now** action that POSTs to a
thin Hermiq endpoint `POST /apps/hermiq/api/schedules/{id}/run`, which runs the
bound agent immediately by reusing `ScheduleService`'s existing dispatch
(run-one) path and is owner-guarded (returns 404 for a non-owner, exactly like
the run-history GET). Because OpenRegister's agent execution is a work-in-progress
that can return an error, the endpoint MUST still record the run (status `error`)
and the UI MUST handle and display that run error gracefully rather than breaking
the view.

#### Scenario: Attach a schedule to an agent

- **WHEN** the user opens the schedule modal, chooses `kind=cron`, enters a cron
  expression and a deliver target, and saves
- **THEN** the frontend MUST persist a `hermiq`/`schedule` object bound to that
  agent's id and show it as attached on the detail view

#### Scenario: Run now surfaces the result or an error

- **WHEN** the user clicks "Run now"
- **THEN** the frontend MUST POST to `/apps/hermiq/api/schedules/{id}/run` and
  show its result, and if OpenRegister returns a run error it MUST display that
  error state (kept in run history) without
  breaking the detail view

### Requirement: Agent detail shows run history

The agent detail view MUST show the run history for the agent's schedule by
reading `GET /apps/hermiq/api/schedules/{scheduleId}/runs`, listing each run with
its status and timing, newest-first, and an empty-state when there are no runs.

#### Scenario: View run history

- **WHEN** the user opens an agent that has a schedule with past runs
- **THEN** the detail view MUST list those runs newest-first with status and
  timing from the run-history endpoint

#### Scenario: Run history read fails

- **WHEN** the run-history request fails
- **THEN** the view MUST show a non-blocking error state, not a blank or broken
  detail view
