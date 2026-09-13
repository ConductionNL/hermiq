# OpenRegister Flow Consumer Specification

**Status**: retired

**Feature tier**: V1

**OpenSpec changes:** `hermiq-schedule-source` built this in `d000fd75` (#115).
`8408a75a` (#134, "delete hermiq's second flow engine; contribute nodes only")
then removed it, along with the whole `agentflow` object store.

> **RETIRED.** `HermiqFlowResolver`, `HermiqFlowResolverListener` and
> `tests/Unit/Flow/HermiqScheduledFlowSourceTest.php` were all deleted by
> `8408a75a`. Hermiq no longer runs a flow engine or answers a scheduler: flows
> are authored in OpenRegister's native flow store, and OpenRegister schedules
> them itself, so there is nothing left for hermiq to be a source of. The
> `agentflow` and `agentflowrun` schemas are retired too, and
> `lib/Repair/PruneRetiredAgentFlowSchemas.php` removes them from existing
> installs. What hermiq still contributes is nodes: `HermiqAgentNode`,
> `HermiqWorkloadNode` and `HermiqFlowNodeListener`. The requirement below is
> kept for history and describes code that is gone.

## Purpose

How Hermiq consumes OpenRegister's flow engine rather than running one of its own. An agentflow is
an OpenRegister flow document; Hermiq resolves it, and OpenRegister schedules it.

## Requirements
### Requirement: An agentflow can run on a schedule (REQ-OFC-010)

Hermiq's flow resolver SHALL also be a scheduled-flow source, reporting every
agentflow whose `trigger` is `schedule` to OpenRegister's scheduler with its
cron expression, its `enabled` flag and the user its runs are attributed to. The
`agentflow` schema SHALL declare a `cron` property so that expression survives a
save.

(Regression guard, two independent causes of one symptom: OpenRegister's
scheduler enumerated one hard-coded flow store and never asked the resolvers, so
an agentflow declaring a schedule was invisible to it; and `agentflow` had no
`cron` property, so the expression saying *when* was dropped on import. The
instance held zero runs with trigger `schedule` across 52,478 runs, while
`hydra-sequencer`, `hydra-dispatch` and `hydra-lock-reaper` all declared one —
the sequencer being the hydra pipeline's heartbeat.)

#### Scenario: A scheduled agentflow is offered to the scheduler

- **GIVEN** an enabled agentflow with trigger `schedule` and a cron
- **WHEN** OpenRegister's scheduler enumerates its sources
- **THEN** hermiq reports it, with its cron and owner

#### Scenario: Only schedules are offered

- **GIVEN** agentflows with triggers `schedule`, `object.created` and `manual`
- **WHEN** the scheduler enumerates
- **THEN** only the `schedule` one is reported

#### Scenario: A disabled agentflow is reported as disabled, not hidden

- **GIVEN** an agentflow with trigger `schedule` and `enabled` false
- **WHEN** the scheduler enumerates
- **THEN** it is reported with `enabled` false, and OpenRegister declines to run
  it

#### Scenario: A cron expression survives a save

- **GIVEN** an agentflow saved with a `cron` expression
- **WHEN** it is read back
- **THEN** the expression is present

@e2e exclude retired, and no longer implemented here. The PHPUnit class this
reason used to name was deleted by `8408a75a` with the resolver it tested, and
the live verification it describes was of a store that no longer exists. What
this app now asserts about the subject is the retirement itself:
`tests/Unit/Settings/AgentFlowRetirementTest.php` pins that neither schema
returns to `hermiq_register.json` and that the prune stays registered in
`info.xml`, and `tests/Unit/Repair/PruneRetiredAgentFlowSchemasTest.php` covers
the prune across five cases including a second run being a no-op.
