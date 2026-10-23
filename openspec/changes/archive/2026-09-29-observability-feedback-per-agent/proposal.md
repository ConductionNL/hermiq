---
kind: code
depends_on: []
---

# Proposal: observability-feedback-per-agent

## Summary

The agent page shows how people rated the agent's answers: how many thumbs up, how many thumbs down, and the share rated helpful, with the latest comments left on a thumbs down. The dashboard's per-agent breakdown shows the same counts beside each agent's runs.

## Why

One row of hermiq's capability matrix that is `building`, decided in the build-all pass of 2026-09-28.

| row | own rating | decision |
|---|---|---|
| `hermiq:ob-feedback-stats` | no, building | build: three competitors rate yes |

Competitor cells rated yes, quoted from the matrix evidence:

- Dify 1.17.1: "api/controllers/console/app/statistic.py:286 user-satisfaction-rate per app; agent Monitoring 'User Satisfaction Rate: Percentage of agent replies that users rated with a like'".
- Open WebUI v0.11.4: "backend/open_webui/routers/evaluations.py:222 GET /leaderboard (ELO per model from thumbs ratings), :268 per-model history, :399 feedback list".
- Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/analytics-drill-down-lists "per agent 'Total reactions' with thumbs up and down and comments".

The matrix note on the built half: "Feedback can be submitted (thumbs up/down in Chat) but there is no surface to see it aggregated per agent."

## What hermiq already has

- The Feedback schema: `messageId`, `conversationId`, `agentId`, `userId`, `type` (positive or negative), optional `comment` (`lib/Settings/hermiq_register.json` Feedback).
- `ChatController::sendFeedback()` (`lib/Controller/ChatController.php:628`) writes one Feedback per user and message.
- `AnalyticsController::index(agentId)` (`lib/Controller/AnalyticsController.php:78`) and `AnalyticsService::computeAnalytics()` (`lib/Service/AnalyticsService.php:154`) produce the agent page's stat tiles and the dashboard's `perAgent` rows, on one tenant boundary.
- The AgentDetail page in `src/manifest.json` has four `type: stat` tiles fed by `/api/analytics?agentId=@objectId`.

## What this change builds

1. `computeAnalytics()` gains a `feedback` block `{positive, negative, helpfulRate}` on the same tenant boundary and agent scope, and each `perAgent` row gains `positive` and `negative`.
2. A stat tile "Rated helpful" on the agent page, showing the share and the counts.
3. A custom widget "Latest low ratings" on the agent page listing the last ten thumbs-down comments with their date, linking to the session when the viewer may read it.
4. The dashboard's per-agent breakdown shows the two counts.

## Out of scope

- Ratings per model or a leaderboard across models.
- Changing how feedback is given in the chat.
