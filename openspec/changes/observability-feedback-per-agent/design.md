# Design: observability-feedback-per-agent

Read at hermiq development c91e637a.

## Where it lives

- `lib/Service/AnalyticsService.php:154` `computeAnalytics()`: one Feedback query per call through ObjectService, filtered by `agentId` when scoped and by the same organisation boundary the run metrics use, counted by `type`. `helpfulRate` is positive divided by the total, null when there is no rating (the tile then reads "No ratings yet").
- New `AnalyticsService::latestLowRatings(agentId, limit)` and route `GET /api/analytics/agents/{agentId}/low-ratings`, guarded by `AgentAccessService` read access on the agent (the same guard `MemoryController::memory()` uses), because comments are text people wrote about an answer.
- `src/manifest.json` AgentDetail: one `type: stat` tile and one `type: custom` widget; the grid is re-closed so no cell stays empty (ADR-062).
- `src/components/widgets/LowRatingsWidget.vue` registered in the custom component registry.
- The dashboard breakdown widget reads the two new perAgent fields.

## Decisions

- D1. Aggregation happens in PHP over the Feedback objects, not in the browser, so the counts respect the tenant boundary the metrics already use.
- D2. Comments are shown without the author's name. The rating is about the answer, not the person.
- D3. Only thumbs-down comments are listed: those are the ones an owner acts on.
