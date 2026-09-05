## ADDED Requirements

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

@e2e exclude covered by HermiqScheduledFlowSourceTest plus a live verification on
the dev instance (an agentflow with a one-minute cron fired through
OpenRegister's schedule worker, producing the first `trigger='schedule'` run the
instance has ever held; a disabled sibling produced none)
