---
kind: code
depends_on: []
---

# Proposal: reuse-scheduled-bias-evals

## Summary

An agent owner adds a ready-made bias test set to an agent and runs it on a schedule, for example monthly. The test set asks the same question in pairs that differ only in a person's name, gender, age, origin or disability, and a judge checks whether the answers treat both people the same. Every run is kept, a drop against the last run warns the owner, and the agent's factsheet and the compliance dashboard show the latest result and its date.

## Why

One row of hermiq's capability matrix, reuse area, decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `hermiq:td-bias-test` | partial, built | build: tender demand for the missing half, a bias rubric run on a schedule |

Demand, quoted from the matrix:

- tender https://www.tenderned.nl/aankondigingen/overzicht/415259, Gemeente Molenlanden zaaksysteem 2026 (2026-03-11), requirement 71423.

No competitor rates yes. Partial cells, quoted: Copilot Studio https://learn.microsoft.com/en-us/microsoft-copilot-studio/analytics-agent-evaluation-overview "evaluations include a 'hatefulness and unfairness' safety g[rader]"; n8n 2.40.7 "evaluations keep scored results per run (packages/cli/src/evaluation.ee/test-runs.controller.ee.ts)". The matrix note: "Results are kept, but a bias dataset and rubric must be written by hand and runs are started by hand."

## What hermiq already has

- Eval datasets and runs: `EvalDataset` (`name`, `description`, `cases`, `skillRefs`) and `EvalRun` (`passRate`, `regressionGateResult`, `previousPassRate`, `results` and more) in `lib/Settings/hermiq_register.json`; the `/evals` pages and `EvalRunPanelWidget` (`src/widgets/EvalRunPanelWidget.vue:424`, `src/api/evals.js:37`, `appinfo/routes.php:96`).
- `EvalRunService::run(dataset, agent, ...)` (`lib/Service/EvalRunService.php:248`) runs every case through the agent's real engine path, gated by the kill switch and budget like a schedule tick (spec `agent-evals`).
- `EvalScoringService::score()` (`lib/Service/EvalScoringService.php:89`) with `contains`, `notContains`, `jsonPathEquals` and an LLM-judge `rubric` (`:104`, `:248`) through the `ProviderFactory` chokepoint.
- No background job runs evals (`lib/BackgroundJob/` holds schedule, skill, Talk, webhook and retention jobs only); no bias or fairness content anywhere (`grep -ri 'bias|fairness' lib/ src/`).
- The per-agent AI factsheet and the compliance dashboard (`lib/Service/ComplianceService.php`, archived `compliance-control-packs`), whose design deferred bias scoring to a future model-monitoring change.

## What this change builds

1. A pair case type, `counterfactualPair`: two prompts that differ in one attribute, judged together on whether the answers differ in substance.
2. A seeded Dutch and English bias test set template with pairs over name, gender, age, origin and disability, in public-service situations.
3. Eval schedules: run a dataset against an agent on a cron, results kept as ordinary eval runs.
4. A warning to the owner when the pass rate falls below the regression threshold.
5. The latest bias run on the agent's factsheet, and a compliance evidence source for bias testing recency.

## Out of scope

- Fairness metrics over production traffic. hermiq does not label outcomes by protected characteristics and should not start.
- Declaring an agent free of bias. A test set shows how an agent behaved on these pairs, nothing more, and the page says so.
