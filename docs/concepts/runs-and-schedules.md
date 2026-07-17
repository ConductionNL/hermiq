---
sidebar_position: 8
description: What a run is, what triggers one, and how a schedule makes an agent work without being asked.
---

# Runs & schedules

A **run** is one occasion of an agent doing its job. A **schedule** is what makes
runs happen without you asking.

This is the step that separates an agent from a chatbot. A chatbot works when you
type. An agent can work at 7am on Monday whether or not you are awake.

## What starts a run

Four things:

| Trigger | What it means |
|---|---|
| **Chat** | You talk to the agent and it answers. The run you can watch happen. |
| **Schedule** | A clock fires — "every weekday at 07:00". |
| **Flow** | Something happened elsewhere in Nextcloud and triggered the agent. |
| **Webhook** | An outside system called in. |

The agent does the same work regardless of which one started it. The difference
is only who pressed the button — and whether anyone is watching.

## What happens during a run

1. The agent assembles what it knows: its prompt, its [skills](./skills.md), its
   [context](./context.md), and its [memory](./memory.md).
2. If [RAG](./rag.md) is on, it looks up material relevant to the task.
3. It thinks, and may call [tools](./tools-and-mcp.md) to take action.
4. If it reaches something gated, it pauses for an
   **[approval](../approvals.md)** and waits for a human.
5. It delivers its answer.

Every run is written to the audit trail — what it did, which tools it called,
what it cost. That record is what makes an unattended agent something you can
account for afterwards rather than something you hope went well.

## Schedules

A schedule attaches to an agent and fires it on a rhythm — a cron expression, so
anything from "every hour" to "the first Monday of the month" is expressible.

Scheduled runs are where agents earn their keep: a morning briefing, an overnight
inbox triage, a weekly report. Nobody has to remember.

Because a scheduled run has no human watching it, two things matter more than
usual:

- **Delivery.** Output goes to a **Nextcloud Talk** conversation, so the work
  lands where your team already is — falling back to a notification if Talk is
  not installed. An unattended run that writes into the void is useless.
- **Gates.** A schedule can require an **[approval](../approvals.md)** before it
  dispatches, and an organisation-wide kill-switch can stop scheduled work
  entirely. If an agent is going to act while you sleep, you want a brake.

## Cost

Every run costs something — cloud models bill per token. Hermiq records token
usage and cost per run, and an administrator can set **budgets** and **cost
guardrails** per organisation, so a misbehaving schedule cannot quietly run up a
bill. Usage is visible on the dashboard.

This is a real consideration for scheduled agents specifically: a chat run costs
what one conversation costs, but an hourly schedule costs that *every hour,
forever*, whether or not anyone reads the output.

## Where to find it

The **Dashboard** shows run metrics — how many, success rate, latency, tokens.
An agent's own runs and their history are on its detail page. **Approvals**
holds anything waiting on a human.

## Related

- **[Agents](./agents.md)** — the thing that runs.
- **[Approvals](../approvals.md)** — pausing a run for a human decision.
- **[Incidents](../incidents.md)** — recording it when a run goes wrong.
