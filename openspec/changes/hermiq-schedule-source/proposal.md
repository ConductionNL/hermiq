# Proposal: hermiq-schedule-source

## Summary

Make hermiq's scheduled agentflows actually fire: answer OpenRegister's new
scheduled-flow enumeration, and give the `agentflow` schema the `cron` property
the expression needs to survive a save.

## Why

Two independent causes produced one symptom — a flow that ran correctly when
triggered and that nothing could trigger.

1. **OpenRegister's scheduler never asked hermiq.** It enumerated one hard-coded
   store (`flow_register`/`flow_schema`) rather than the resolver registry every
   other trigger family goes through. A hermiq agentflow with
   `trigger: schedule` was invisible to it — no error, no run, nothing. Fixed on
   the OpenRegister side; this is hermiq's answer to the enumeration.
2. **`agentflow` had no `cron` property.** OpenRegister drops undeclared
   properties on save, so the expression saying *when* never persisted. A flow
   could say it ran on a schedule and could not say which one.

Measured before the change: **zero** runs with `trigger='schedule'` out of
52,478. `hydra-sequencer`, `hydra-dispatch` and `hydra-lock-reaper` were all in
that state; the sequencer is the hydra pipeline's heartbeat.

## What Changes

- **`HermiqFlowResolver`** also implements `IScheduledFlowSource`, reporting
  agentflows whose trigger is `schedule` with their cron, `enabled` flag and
  owner (entity owner first, the flow's `owner` field as fallback — a scheduled
  run has no session, and a run with no owner cannot write, or#2158).
- **`agentflow` schema v0.1.3** gains the optional `cron` property; the register
  descriptor goes to v0.22.0. Optional, not required, no conditionals, so every
  existing AgentFlow stays valid.

## Blast radius

Hermiq reports only flows whose trigger is `schedule` — currently three, all
`enabled: false`. Disabled flows are reported with the flag rather than filtered
away, and OpenRegister makes the run/do-not-run decision, so enabling scheduling
does not start any hydra flow until someone deliberately enables it.

## Non-goals

Enabling the hydra flows. They stay `enabled: false`.
