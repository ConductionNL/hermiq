# Design: observability-compare-two-runs

Kind: code. Size M. A comparison service, one route, the Runs page, the agent run history widget, and a read of OpenRegister's flow run API. No schema changes.

## Context at development db6b74dc

- `lib/Service/ScheduleService.php:1150` `replayRun()`; `:1245-1274` `diffTrace()` comparing tool names by position and the summary text; `:1286-1295` `toolStepNames()`.
- `lib/Controller/RunHistoryController.php:158` `trace()`, `:206` `replay()`, both behind `loadOwnedSchedule()` with 404 for a non-owner.
- `lib/Service/RunHistoryService.php:163` `getRunTrace()`, reading `steps` from the audit entry's `changed` (`:197-200`).
- `lib/Service/AnalyticsService.php:106` `RUN_ACTIONS = 'run,agent-run'`; `:296-371` `listRuns()` limited to agents the caller may see, skipping dry runs; `:393-440` `toRunRow()` with `trigger` `schedule` or `flow`.
- `lib/Controller/AnalyticsController.php:119` `runs()`; `appinfo/routes.php:221` `/api/runs`.
- `src/views/Runs.vue:99-135` the run table; `:331` `listRuns()`.
- `src/widgets/AgentRunHistoryWidget.vue:110-128` replay button, `:238-256` replay result.
- openregister development at c53dd0685c (read only): `lib/Db/FlowRun.php:183-391` fields including `flowVersion`, `status`, `log`, `created`, `updated`; `appinfo/routes.php:2061-2074` `flowRun#index`, `flowRun#show`.

## D1. Any two runs the caller may see

`GET /api/runs/compare?left=<id>&right=<id>`, `#[NoAdminRequired]`. Both ids are audit entry uuids of action `run` or `agent-run`. Each must belong to an agent in the set `listRuns()` already computes for the caller; otherwise the answer is 404 for that side, the same as a missing run. The two runs may be of different agents; the view then says so at the top, "These runs are of different agents."

Rejected: extending `/api/schedules/{scheduleId}/runs/{runId}/trace` to take a second run. Its guard is ownership of one schedule, and a flow-triggered `agent-run` hangs on the triggering object, not a schedule, so it could never compare flow-triggered agent runs.

## D2. Steps are aligned, not only paired by position

`RunComparator::compare(stepsA, stepsB, summaryA, summaryB)` aligns the two tool name sequences with a longest common subsequence, so the result lists each step as `same`, `only-left`, `only-right` or `different-outcome`. Per step it carries name, outcome and duration from both sides. The run level carries status, duration, start time, trigger, agent version (the agent's audit version at run time, when recorded), provider and model (when the run recorded a provider disclosure), and whether the summaries differ.

`ScheduleService::diffTrace()` keeps its return shape for the existing replay screen and computes it from `RunComparator`, so positions and matches stay what the replay spec says, and there is one comparison.

Rejected: comparing by position only, as `diffTrace()` does. One extra "Search contacts" at the start marks every later step as changed, which hides the actual difference.

## D3. The Runs page selects two

Each row on `/runs` gets a checkbox. With two ticked, a "Compare" button appears; a third tick is refused with "Choose two runs to compare." The comparison opens in a detail view with the two runs in columns, the aligned steps in rows, and a summary line built from the comparison: "Same outcome. Run B called Read file once more and took 4.2 s longer." or "Different outcome. Run A succeeded, run B failed at Send email." On a narrow screen the columns stack.

The agent run history widget gets "Compare with…" on a run, which opens the same view with the second run to be chosen from that agent's runs.

## D4. Flow runs are read from OpenRegister, as the caller

On a flow's detail page the run history offers the same two-tick selection. The comparison reads both runs from OpenRegister's `GET /apps/openregister/api/flow-runs/{uuid}` in the browser, with the person's own session, so OpenRegister's organisation scoping decides what can be read (hydra ADR-022). The view lines up the node entries of each run's `log` by node id, with status and duration per node, and flags "These runs used different versions of the flow (4 and 5)." when `flowVersion` differs. A node that ran a hermiq agent links to that agent run, which can be compared with D1.

hermiq does not copy or store flow runs.

## Declarative versus imperative

No schema is added or changed. The comparison is a read over audit entries and flow runs, computed per request; nothing about it fits an `x-openregister-*` construct.

## Risks

- Old runs lack a provider disclosure or an agent version. Mitigation: the view shows "Not recorded for this run" for those fields rather than leaving them blank.
- A flow run log's shape changes in OpenRegister. Mitigation: the reader takes node id, status and timings only, and shows "This flow run could not be read" when they are missing.
- Summaries are redacted, so two runs that differ only in a redacted value compare as equal. The view says the comparison uses redacted summaries.
