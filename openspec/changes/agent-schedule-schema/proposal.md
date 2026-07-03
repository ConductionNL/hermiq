---
kind: config
---

# Proposal: agent-schedule-schema

## Why

Hermiq's reason to exist is running an OpenRegister agent unattended — on a cron
expression, a fixed interval, or once at a future time. Before any dispatcher can
fire a scheduled agent, the data it fires on must exist: a declarative OpenRegister
`Schedule` schema that binds a schedule to an agent and holds its trigger, delivery,
and run-state fields. This change declares that schema and nothing else.

## What Changes

- Add a new declarative OpenRegister schema `Schedule` to the app's schema register
  `lib/Settings/hermiq_register.json` (OpenAPI 3.0.0 `components.schemas` format).
- The `Schedule` schema carries: `name`, `agentId` (reference to an OpenRegister
  `Agent`), `kind` (`once`|`interval`|`cron`), the trigger fields (`cronExpr`,
  `intervalMinutes`, `runAt`), `prompt`, `deliver` (`talk`|`notification`|`none`),
  `enabled`, `repeat` (`{times, completed}`), and the derived run-state fields
  (`nextRun`, `lastStatus`, `lastError`). Tenant scoping (`owner`/`organisation`)
  comes from OpenRegister `ObjectEntity` automatically.
- This is **config-only** — a JSON register patch imported via
  `ConfigurationService::importFromApp()` in the existing repair step. No PHP, no
  controller, no service, no new write path.

This is the **head of a two-change chain** (ADR-032 split of the mixed `agent-schedule`
feature):

1. **`agent-schedule-schema`** (this change, kind `config`) — declares the `Schedule`
   schema so schedule objects can be persisted and validated by OpenRegister.
2. **`agent-schedule-dispatcher`** (kind `code`, `depends_on: [agent-schedule-schema]`) —
   adds `Hermiq\Cron\ScheduleTask extends TimedJob` + `Hermiq\Service\ScheduleService`
   that polls due `Schedule` objects and fires the bound OpenRegister agent under the
   owner's identity, advancing run-state before the agent turn (at-most-once / crash
   safety) and delegating delivery.

Splitting the schema (declarative) from the dispatcher (imperative scheduled work)
keeps each change single-kind and lets the schema land and validate independently.

## Capabilities

### New Capabilities
- `agent-schedule-schema`: the declarative OpenRegister `Schedule` schema — its
  properties, enums, conditional-requirement rules (cron/interval/once), defaults,
  and reference to the OpenRegister `Agent` entity, plus tenant scoping via
  `ObjectEntity`.

### Modified Capabilities
<!-- None — the dispatcher behavior is specified in the downstream agent-schedule-dispatcher change. -->

## Impact

- **Config:** `lib/Settings/hermiq_register.json` gains a `Schedule` entry under
  `components.schemas`.
- **Data:** OpenRegister creates a magic table for `Schedule` objects in the `hermiq`
  register on import; existing schemas are untouched (union import, no regression).
- **No code, no API surface, no dependencies** added by this change. The downstream
  `agent-schedule-dispatcher` change consumes this schema and adds the runtime.
- **Downstream:** unblocks `agent-schedule-dispatcher`.
