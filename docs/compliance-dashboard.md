---
sidebar_position: 13
title: Compliance dashboard
description: The Settings page's Compliance tab — per-framework coverage, the gap list, the per-agent AI factsheet, and the auditor's-pack export.
---

# Compliance dashboard

The in-app **Settings** page's **Compliance** tab turns the governance
mechanisms described elsewhere in these docs — [approvals](./approvals.md),
[incidents](./incidents.md), the audit trail, the `AiFeature` risk register —
into a single, always-current view of where your organisation stands.

## What it shows

A seeded control catalogue spans three frameworks: the **EU AI Act**
(Art. 12, 14, 26), **ISO/IEC 42001** (a representative set of AI management
system clauses), and the **NIST AI RMF** (one control per
GOVERN/MAP/MEASURE/MANAGE function). Every control carries a short,
source-cited description and a link to the original regulation or standard
text.

For each control, the dashboard shows one of three computed states:

| State | Meaning |
|---|---|
| `satisfied` | The underlying governance data supports this control today |
| `partial` | Some but not all of the expected evidence exists |
| `unevidenced` | No supporting data exists yet for your organisation |

Nothing here is a checkbox a person ticks. Every control's status is
**computed at read time** from your organisation's actual data — audit-trail
entries, approval decisions, the kill-switch state, `ModelPolicy`, access
reviews, and `AiFeature` DPO-acknowledgements — dispatched by the control's
`evidenceSource`. There is no UI or API affordance to set a control's status
directly, and nothing to remember to update: completing an agent run that
writes an audit-trail entry, or a reviewer approving a pending request,
changes the relevant control's status on its own the next time the dashboard
loads.

## The per-agent AI factsheet

Opening an agent and choosing **View compliance factsheet** assembles a
read-only summary live from existing data — no separate factsheet object is
stored: the agent's purpose, provider, model, and tool allowlist; its linked
`AiFeature` risk classification and DPO-acknowledgement state; its approval
decision history; any linked incidents; and its last access-review
timestamp.

## The auditor's-pack export

The Compliance tab's export button downloads a single JSON payload combining
the unmodified audit-trail export (the same one described under
[EU AI Act — Art. 12](./legal/02-eu-ai-act.md)) with the per-control coverage
data above — one file to hand to an auditor rather than several.

## Who can see it

The dashboard and export are restricted to callers holding
`compliance.view-dashboard` / `compliance.export-pack`; the factsheet is
restricted to an agent's owner/acting user or a caller holding
`compliance.view-factsheet`, and refuses an unauthorized request with a
plain 404 rather than confirming the agent even exists.

## What this isn't

A `satisfied` control means the evidence Hermiq can compute is present — not
a legal sign-off that your organisation is compliant with that framework.
See [Rules & regulations](./legal/01-overview.md) for what stays your
judgment call on top of what this dashboard measures.
