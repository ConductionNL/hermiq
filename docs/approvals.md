---
sidebar_position: 6
description: Human-in-the-loop gates that pause an agent until a person approves.
---

# Approvals

An **approval** is a human-in-the-loop gate. When an agent is about to do
something you have decided a person should sign off on, Hermiq pauses and creates
an **Approval** — the action does not run until someone approves it. Pending
approvals wait for you in the **Approvals** inbox (the *Approvals* item in the
Hermiq navigation).

## Why approvals exist

Autonomous agents run on their own — on a schedule, from a flow, from a webhook,
or by calling a tool mid-conversation. Most of that is routine. Some of it isn't:
sending an email, writing to a record, spending budget, invoking a high-blast-radius
tool. Approvals let you keep the automation while putting a person in front of the
actions that matter, so nothing irreversible happens unattended.

## What can require an approval

Hermiq can open an approval at four points, and in every case it guarantees
exactly **one** pending Approval per gated action (re-triggering never stacks
duplicates):

| Gate | When it fires |
|---|---|
| **Scheduled run** | A schedule configured to require approval will not dispatch its run until approved. |
| **Flow-triggered run** | A run started from a flow trigger pauses at the gate. |
| **Webhook run** | A run started from an inbound webhook pauses at the gate. |
| **Tool call** | A tool the agent wants to invoke mid-run pauses until approved — including any tool your **guardrail policy** classifies as `confirm`. The tool is not invoked until a matching approval exists. |

The `confirm` tool classification is set per organisation in **Settings →
Guardrail policy**, so an admin decides once which tools always need a human, and
every agent inherits it.

## The lifecycle of an approval

1. **Requested** — the agent reaches a gate; a `pending` Approval is created,
   capturing what wants to happen (the source, the agent, the prompt, and for a
   tool call the `toolId` and its arguments) and when it was requested.
2. **Decided** — a reviewer opens the Approvals inbox and **approves** or
   **rejects** it, optionally with a reason. Hermiq records who decided, when,
   and why.
3. **Consumed** — on approval, the paused action proceeds exactly once; the
   approval is marked consumed so it cannot be replayed. On rejection, the action
   never runs.

Every one of these steps is written to OpenRegister's audit trail, so an
approval decision is a permanent, attributable governance record — not a
transient UI state.

## Where to find it

- **Approvals inbox** — the *Approvals* navigation item lists everything waiting
  on you, with approve / reject actions.
- **Audit export** — approval decisions are part of the EU AI Act audit export
  (see [Incidents](./incidents.md) and Compliance), alongside the run records
  they gated.

## Related

- **Guardrail policy** (Settings) — classify a tool as `confirm` to route every
  call through the approval gate.
- **[Incidents](./incidents.md)** — when something does go wrong, record it as an
  incident linked to the agent and its runs.
