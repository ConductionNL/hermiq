# Dashboard

## What it is for

The dashboard answers one question: are my agents working? Open it first thing and you
should be able to tell in a glance whether last night went well.

It is the app's landing page, so it is what a new user sees before they know what
Hermiq does.

## What you see

Four numbers across the top, computed from the run audit trail rather than a separate
store:

- **Total runs**, over every agent you can see
- **Success rate**, the share of those runs that finished cleanly
- **Average latency**, how long a run takes
- **Tokens**, LLM usage where the provider reported it

Below them:

- **Running flows**, anything in flight right now
- **Runs by agent**, so a single misbehaving agent stands out from the rest
- **Schedules quota** and **Agents quota**, against your organisation's limits
- **Agents**, the most recent ones, as a table you can click through

## What the numbers count

Every figure is scoped to the agents you may see. A run belonging to another
organisation is never counted, and the boundary is the agent rather than the schedule,
because a flow-triggered run has no schedule.

Dry runs and replay previews are excluded. A preview must not move a real success rate.

Where the provider recorded no token usage, tokens read as unavailable rather than
zero. A confident zero is worse than an honest gap.

## API

- `GET /apps/hermiq/api/analytics` returns the metrics, optionally scoped with `agentId`

## Where to go next

The dashboard tells you that something went wrong. It does not tell you what. Open
[Runs](runs.md) and filter on the failed status.
