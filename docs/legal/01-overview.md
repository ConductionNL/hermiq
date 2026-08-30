---
sidebar_position: 1
title: Rules & regulations
description: Hermiq's design principles for EU and Dutch AI law, and a clear line between what the platform does for you and what remains your responsibility.
---

# Rules & regulations

AI software sits inside a growing body of law: the EU AI Act, the GDPR, and a
set of Dutch government rules on algorithm transparency, among others. This
section explains, law by law and article by article, what Hermiq is built to
do in support of each — and, just as importantly, what it does **not** do for
you.

:::danger Not legal advice
Nothing on this page or its subpages is legal advice. It describes **software
design decisions** — what Hermiq logs, gates, classifies, and exports — not a
legal opinion on your specific use case. Whether your organisation's use of
Hermiq is lawful depends on facts only you control: what data you feed an
agent, what decisions it's allowed to make, who it affects, and which
jurisdiction's rules apply. Talk to your own Data Protection Officer or legal
counsel before relying on any of this for a real compliance determination.
:::

## Design principles

Hermiq's governance model is built from a small number of load-bearing
decisions, applied consistently across the app rather than bolted onto each
feature separately:

- **One write path, one audit trail.** Every agent run, LLM call, tool
  invocation, and human decision goes through OpenRegister's `ObjectService`
  into a single, hash-chained, tamper-evident `AuditTrail` (each entry links
  to the previous one's hash — see [Approvals](../approvals.md) and
  [Incidents](../incidents.md)). There is no second, informal log an auditor
  could miss.
- **Redact before persist, not after.** Secrets and personal data are
  stripped from agent input/output by `RedactionService` **before** they
  reach the audit trail, because an append-only, hash-chained log can never
  un-write a value once it's in it.
- **Default-deny, explicit grant.** An agent can only call a tool it has been
  explicitly granted (see [Tool grants](../tool-grants.md)); a
  separately-configured guardrail policy decides which of those grants also
  require a human to `confirm` before the call runs.
- **A human in front of what matters, not everything.** The
  [approval gate](../approvals.md) pauses scheduled, flow-triggered,
  webhook-triggered, and tool-call actions your organisation has classified
  as needing sign-off, and an org-level kill switch can stop a tenant's
  agents immediately.
- **Design-time classification before runtime use.** A high-risk AI feature
  cannot be switched on until your organisation's DPO has explicitly
  acknowledged it (the `AiFeature` register) — oversight is a gate a feature
  has to pass, not a policy document nobody reads.
- **Delegate to the canonical register, don't reinvent it.** Where a
  capability already exists correctly elsewhere in the fleet — OpenRegister's
  GDPR processing register and data-subject-rights workflows, OpenCatalogi's
  publication pipeline for the Dutch Algoritmeregister — Hermiq calls it
  rather than building a second, divergent copy.

## Your own responsibility

Hermiq gives you the mechanism; it does not make the judgment calls the law
actually asks a human to make. In particular, **you** — not Hermiq — decide:

- **What an agent is for, and how risky that is.** Classifying an `AiFeature`
  correctly (`minimal` / `limited` / `high` / `unacceptable`) is a judgment
  about your specific use case; Hermiq enforces the DPO-acknowledgement gate
  once a feature is classified, but it cannot classify it for you.
- **The lawful basis and purpose for any personal data an agent touches.**
  The GDPR processing-register fields (purpose, legal basis, retention
  period) exist to be filled in accurately — an empty or wrong field is a
  compliance gap regardless of what the software makes possible.
- **Which tools require human confirmation.** The guardrail policy ships
  with reasonable defaults, not a determination of what's safe for *your*
  workflows.
- **Informing people affected by an agent's output**, notifying workers
  before an agent is deployed in a workplace context, and notifying a
  supervisory authority of a personal-data breach — these are organisational
  and legal processes. Hermiq's audit trail can supply the evidence; it
  cannot perform the notification for you.
- **Whether a given model provider is an appropriate processor** for the
  data you're about to send it, and whether your contract with that provider
  covers your obligations as a controller.

## Laws covered here

- **[EU AI Act](./02-eu-ai-act.md)** — Regulation (EU) 2024/1689: risk
  management, record-keeping, human oversight, deployer obligations,
  transparency.
- **[GDPR](./03-gdpr.md)** — Regulation (EU) 2016/679: lawfulness,
  accountability, data-subject rights, security, DPIAs, the DPO.
- **[Dutch Algoritmeregister](./04-algoritmeregister.md)** — the national
  transparency register for impactful government algorithms, and the
  Algoritmekader metadata standard behind it.

This is not an exhaustive survey of every rule that could touch an AI
deployment (sector-specific law, procurement rules, and your own internal
policy may add more). It covers the frameworks Hermiq has concrete, shipped
governance features for — so every claim on the following pages can point at
actual code, not intent.

For where these governance mechanisms actually live in the product, see the
[Compliance dashboard](../compliance-dashboard.md) page.
