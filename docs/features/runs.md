# Runs

## What it is for

One list of what every agent actually did, newest first. When the dashboard shows the
success rate dropping, this is where you find out which runs failed and why.

## How to reach it

**Runs** in the navigation, between Approvals and Memory.

A link in a Talk delivery or a failure alert opens the same page, already narrowed to
the schedule that produced it. The page says so, and offers to show you everything.

## What you see

| Column | What it tells you |
|--------|-------------------|
| When | The time the run was recorded |
| Agent | Which agent ran, linked to its detail page |
| Status | How it ended |
| Trigger | Whether a schedule started it or a flow did |
| Duration | How long it took |
| Summary | What the run reported |

Filter by agent, by status, or both. The list pages fifty at a time and tells you the
total, so "1 to 50 of 340" means what it says.

## Why the Trigger column exists

Hermiq starts an agent two ways, and they record against different objects. A
scheduled run hangs on its schedule. A flow-triggered run hangs on whatever object the
flow was about, which usually lives in a different register entirely.

Before this page, no list could show you the second kind. The dashboard counted them
and every listing denied they existed. Naming the channel on each row keeps that
honest.

## What is not listed

Dry runs and replays. They are previews, and a preview that appears in the history is
indistinguishable from something that happened.

A run whose agent was deleted also disappears, because visibility is resolved through
the agent. That is a known gap in the OpenRegister read path, stated here rather than
hidden.

## API

- `GET /apps/hermiq/api/runs` lists runs, with `agentId`, `status`, `limit`, `offset`
- `GET /apps/hermiq/api/schedules/{scheduleId}/runs` lists one schedule's runs
- `GET /apps/hermiq/api/schedules/{scheduleId}/runs/{runId}/trace` returns the step timeline

## Where to go next

Found a failing agent? Open it from the Agent column and read its
[run history and memory](memory.md) in place.
