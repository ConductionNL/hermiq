---
sidebar_position: 7
description: Human-authored records of when an agent went wrong, linked to runs and included in the AI Act audit export.
---

# Incidents

An **incident** is a human-authored record of something that went wrong with an
agent — a bad output, an action that shouldn't have happened, a near-miss — and
what you did about it. Incidents live under **Compliance** in the Hermiq
navigation, and they become part of your organisation's EU AI Act audit trail.

## Why incidents exist

Approvals stop the wrong thing *before* it happens; incidents document it *after*
it happens. The EU AI Act (Article 12, record-keeping) expects operators of
autonomous systems to keep records of serious malfunctions and the response to
them. An incident is that record: not an automatic error log, but a considered,
human-written account that a Data Protection Officer or auditor can read.

## What an incident captures

| Field | What it records |
|---|---|
| **Description** | What happened, in plain language. |
| **Impact** | Who or what was affected, and how badly. |
| **Actions taken** | The response — what you did to contain and remediate it. |
| **Linked agent** | The agent involved (`linkedAgentId`). |
| **Linked runs** | The specific run(s) where it occurred (`linkedRunIds`), so an auditor can trace from the incident to the exact governed run records. |
| **Created by / at** | Who filed the incident and when. |

Because an incident links to real run IDs, it is not an isolated note — it
anchors into the same audit trail that already holds every agent run, tool
invocation, and [approval](./approvals.md) decision.

## How incidents fit the audit story

Hermiq keeps three layers of governance evidence, and incidents are the human
layer on top of the automatic ones:

1. **Run + tool-invocation records** — written automatically for every agent run
   (OpenRegister audit trail).
2. **Approval decisions** — who approved or rejected a gated action, and why.
3. **Incidents** — your written account of what went wrong and how you responded.

All three are included in the **EU AI Act audit export** (Compliance → *Export AI
Act audit trail*), so a single export hands an auditor the machine record *and*
the human narrative together.

## Where to find it

- **Compliance** (in the Hermiq navigation) — the *Incidents* section is where
  you open a new incident and review existing ones, alongside the retention
  setting and the audit export.
- **Retention** — the same Compliance page states how long your organisation
  keeps governance records (EU AI Act Art. 12 expects at least six months).

## Related

- **[Approvals](./approvals.md)** — the preventive counterpart: gate the risky
  action before it runs.
- **Compliance** — the audit export bundles runs, approval decisions, and
  incidents into one AI Act record.
