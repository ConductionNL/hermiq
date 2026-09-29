---
kind: code
---

# Proposal: observability-compare-two-runs

## Summary

On the Runs page you can tick two runs and choose "Compare". hermiq shows them side by side: when each ran, how long it took, its outcome, the agent version and model, and the tool steps lined up so you see where the two went different ways. The two runs can be any two you may see, not only a replay against its original. You can also compare two runs of the same flow, node by node, read from OpenRegister's flow run records.

## Why

One row of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:dm-run-compare` | partial, built | build: the featureRequest demand row (dify#41864, a workflow run comparison view) asks for the missing half, two chosen runs side by side including flow runs |

Demand row:

- `dm-run-compare`: featureRequest https://github.com/langgenius/dify/issues/41864

No competitor rates this row yes. The two partial ones compare something else: Copilot Studio "evaluation runs compare side by side ("Comparison with"); flow runs open one at a time on the canvas, no two-run diff", and n8n "Comparison exists for evaluation runs and versions, not for two production runs."

## What hermiq already has

- A replay of one scheduled run as a dry run, diffed against the original by tool name per position and by output text (`lib/Controller/RunHistoryController.php:206`, `lib/Service/ScheduleService.php:1150` `replayRun()`, `:1245-1274` `diffTrace()`), shown in the agent's run history as "Replay" and "The replay produced the same outcome as the original run." (`src/widgets/AgentRunHistoryWidget.vue:110-128`, `:248`).
- The trace of one run, owner-scoped per schedule (`appinfo/routes.php:56-62`, `lib/Service/RunHistoryService.php:163`), read from the run's audit entry, which holds `steps` (`:197-200`).
- A cross-agent run list over the audit trail, actions `run` and `agent-run`, on the same tenant boundary as the dashboard numbers (`appinfo/routes.php:221`, `lib/Controller/AnalyticsController.php:119`, `lib/Service/AnalyticsService.php:106`, `:296-371`), shown on the Runs page as a table (`src/views/Runs.vue:99-135`).
- OpenRegister owns flow runs: a `FlowRun` with `flowId`, `flowVersion`, `status`, `log`, subjects and timings, read through `GET /api/flow-runs/{uuid}` (openregister `lib/Db/FlowRun.php:183-391`, `appinfo/routes.php:2061-2074`, development at c53dd0685c).

## What this change builds

1. `GET /api/runs/compare?left=<runId>&right=<runId>`: both runs' records and step timelines, and a comparison, for two runs the caller may see on the Runs page.
2. A comparison that lines the tool steps up by name rather than only by position, so an extra step in one run shows as an insertion instead of every later step showing as changed.
3. Selection on the Runs page, two at most, and a comparison view with the two runs in columns and a summary line such as "Same outcome. Run B called Read file once more and took 4.2 s longer."
4. Flow run comparison: pick two runs of one flow, read both from OpenRegister's flow run API, and line them up node by node with status and duration, flagging a different flow version.
5. The existing replay diff reuses the new comparison, so there is one way to compare steps.

## Out of scope

- Comparing outputs word by word. hermiq keeps a redacted summary of each run, not its full output, and compares those.
- Comparing tool arguments and results. The trace does not keep them (`run-trace-observability`; the `diffTrace()` docblock says so).
- A flow run comparison inside OpenRegister's own flow tooling. OpenRegister may want one; this change only reads its public flow run API.
