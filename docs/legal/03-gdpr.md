---
sidebar_position: 3
title: GDPR
description: Article-by-article — what the GDPR requires, how Hermiq (via OpenRegister) is built to support it, and what stays your responsibility.
---

# GDPR

The **GDPR** (Regulation (EU) 2016/679, in the Netherlands implemented
alongside the *Uitvoeringswet AVG*) governs any processing of personal data —
which an AI agent will touch the moment it reads a name, an email address, a
file with someone's details in it, or a chat message.

## Where this lives

Hermiq stores every object — agents, runs, memory, sessions — as an
OpenRegister object, so it inherits OpenRegister's GDPR subsystem rather than
implementing a second one: the processing-activity register, data-subject
request (DSAR) workflows, DPIA detection, and the audit trail are all
OpenRegister features Hermiq calls into, per the [design
principle](./01-overview.md#design-principles) of delegating to the
canonical register instead of reinventing it.

## Article by article

### Art. 5 & 6 — Principles and lawfulness

**What it requires.** Personal data must be processed for a specified,
explicit, legitimate purpose (purpose limitation), on one of the lawful
bases in Art. 6, and the controller must be able to demonstrate this
(accountability, Art. 5(2)).

**How Hermiq supports it.** Every processing activity recorded in
OpenRegister's *verwerkingsregister* carries mandatory `doel` (purpose) and
`grondslag` (Art. 6 legal basis) fields — there is no way to register a
processing activity without stating why it happens and on what basis.

**Your responsibility.** Hermiq enforces that the fields are *filled in* —
not that the purpose or basis you write down is actually correct for what
the agent does. That's a legal judgment about your own use case. If your
legal basis is consent, note that OpenRegister currently records a consent
field on the processing activity itself but has no full consent-lifecycle
service (capturing individual consent receipts, handling withdrawal) — treat
that as a partial, not a complete, answer to Art. 7.

### Art. 15, 16, 17, 20 — Data-subject rights (access, rectification, erasure, portability)

**What it requires.** A data subject can ask to see their data (Art. 15),
correct it (Art. 16), have it deleted (Art. 17), or receive it in a portable
format (Art. 20), each within a statutory deadline.

**How Hermiq supports it.** These are OpenRegister's DSAR workflows —
`DataSubjectRequestService`/`DsarService`, exposed via `DsarController` and
`DataSubjectRequestController` — which operate over the same `ObjectService`
CRUD path every Hermiq object already goes through, so an agent's memory or
session data is reachable by these workflows like any other personal data in
the instance. Response deadlines are tracked (`DataSubjectDeadline`) rather
than left to memory.

**Your responsibility.** Actually *acting* on an incoming request — deciding
what's in scope, who handles it, and responding within the deadline — is a
process your organisation runs; Hermiq surfaces the tooling and the clock,
not a person to operate it.

### Art. 22 — Automated individual decision-making

**What it requires.** A person generally has the right not to be subject to
a decision based solely on automated processing (including profiling) that
produces legal or similarly significant effects on them, unless an exception
applies.

**How Hermiq supports it.** The [human approval
gate](../approvals.md) is the mechanism that keeps a human in the loop before
a consequential agent action executes — the same control that satisfies
AI Act Art. 14 human oversight does double duty here, provided you've
actually gated the action.

**Your responsibility.** Whether a specific agent output counts as a
"decision" under Art. 22, and whether it's gated behind approval, is a
design choice about that agent — Hermiq doesn't infer which outputs are
significant enough to require it.

### Art. 25 — Data protection by design and by default

**What it requires.** Technical and organisational measures that implement
data-protection principles are built into processing from the start, not
added afterward.

**How Hermiq supports it.** `RedactionService` strips secrets and personal
data — API keys, auth headers, private keys, tokens, phone numbers, and
similar patterns — from agent input/output **before** it is written to the
audit trail, not after. Since the trail is append-only and hash-chained, a
value that never entered it can never leak from it later; this is enforced
at construction (the redaction toggle is fixed at startup so a running agent
can't disable it mid-flight).

**Your responsibility.** Redaction covers known secret/PII patterns; it is
not a substitute for deciding what data an agent should be given access to
in the first place.

### Art. 28 — Processor agreements

**What it requires.** Where a processor handles personal data on a
controller's behalf, the relationship must be governed by a contract (or
other binding legal act) setting out the processor's obligations.

**How Hermiq supports it.** Each processing-activity record carries a
`verwerker` (processor) field and a `verwerkersovereenkomst` (processor
agreement reference) field, so when an agent's processing involves a
third-party processor — most obviously the model provider it calls — that
relationship is recorded alongside the rest of the processing activity
rather than living in a separate document nobody links back to the agent.

**Your responsibility.** Hermiq records that you *have* an agreement
reference; it has no way to verify that a real, signed data-processing
agreement exists behind it, or that its terms actually cover what your agent
sends the provider. Checking that — and putting an agreement in place before
you point an agent at a new model provider — is yours.

### Art. 30 — Records of processing activities

**What it requires.** A controller must maintain a structured register of
its processing activities: purpose, legal basis, categories of data and data
subjects, recipients, retention period, and security measures, exportable
for a supervisory authority (in the Netherlands, the *Autoriteit
Persoonsgegevens*).

**How Hermiq supports it.** OpenRegister's *verwerkingsregister* is exactly
this register, modelled as a first-class OpenRegister schema (not a
side-document) with all of the above fields mandatory, and it exports as a
structured Art. 30 register.

**Your responsibility.** Creating a *verwerkingsactiviteit* entry for each
new agent capability that touches personal data is a step you take — Hermiq
doesn't auto-generate one just because an agent starts reading a file.

### Art. 32 — Security of processing

**What it requires.** Technical and organisational measures appropriate to
the risk, including the ability to ensure ongoing confidentiality and
integrity, and to restore availability after an incident.

**How Hermiq supports it.** The audit trail's hash chain gives you integrity
evidence (a broken chain is detectable), OpenRegister's RBAC restricts who
can read or write which objects, and each *verwerkingsactiviteit* records
its own `beveiligingsmaatregelen` (security measures) so the measures in
place for a given activity are documented, not assumed.

**Your responsibility.** Whether the measures recorded are actually
sufficient for the risk of a given processing activity is your assessment,
not a default the software supplies.

### Art. 33 & 34 — Breach notification

**What it requires.** A personal-data breach must be notified to the
supervisory authority within 72 hours where feasible, and to affected
individuals where the breach is likely to result in high risk to them.

**How Hermiq supports it.** Nothing automated. The audit trail and the
[Incidents](../incidents.md) register can supply the evidence you need to
investigate what happened and when — but detecting a breach, assessing its
risk, and notifying the authority and affected individuals within the
72-hour clock is a process Hermiq does not run for you.

**Your responsibility.** All of it — this is squarely an organisational
process, not a setting.

### Art. 44–49 — International transfers

**What it requires.** Personal data may only leave the EU/EEA for a country
without an adequacy decision if an appropriate safeguard is in place
(Standard Contractual Clauses, binding corporate rules, or a narrow
exception).

**How Hermiq supports it.** Nothing at the moment. Hermiq lets you point an
agent at any model provider — including one hosted outside the EU/EEA —
without checking where that provider processes the request or whether a
transfer safeguard is recorded. This is a real, currently-unaddressed gap in
Hermiq's own governance surface, not a "the law doesn't apply here" case.

**Your responsibility.** All of it, for now: knowing where each model
provider you use actually processes data, whether that's an international
transfer, and whether you have a valid safeguard for it. Prefer an EU/EEA-
hosted provider (or a local Ollama model, which never leaves your instance)
when this matters for your use case.

### Art. 35 — Data protection impact assessment (DPIA)

**What it requires.** A DPIA is required before processing likely to result
in high risk to individuals, assessing necessity, proportionality, and risk
mitigation.

**How Hermiq supports it.** OpenRegister runs a daily job
(`DsarDpiaDetectionJob`) that evaluates DSAR case patterns against a
configurable threshold and automatically flags `dpiaRequired = true` when a
group of cases crosses it — a fail-safe, one-way ratchet (a flag is never
auto-cleared) with its own audit entry recording the rule that fired. This
catches a class of "you should have done a DPIA and didn't notice" gaps
automatically.

**Your responsibility.** Detection is pattern-based and cannot see every
scenario that legally requires a DPIA — a new high-risk `AiFeature` should
prompt you to consider a DPIA *before* enabling it, not wait for an
automated flag after the fact. Carrying out the assessment itself is yours.

### Art. 37 — Designation of a Data Protection Officer

**What it requires.** Certain organisations must designate a DPO with real
independence and involvement in data-protection matters.

**How Hermiq supports it.** Hermiq cannot appoint your DPO — that's an
organisational decision outside any software's reach. Once you have one,
though, the `AiFeature` register gives them a concrete lever rather than an
advisory role: a `high`-risk AI feature is mechanically blocked from being
enabled until the DPO records an acknowledgement, and `PrivacyOfficerRecipientResolver`
routes relevant GDPR notifications to them.

**Your responsibility.** Designating the DPO, and making sure they actually
review what they're acknowledging rather than rubber-stamping it, is outside
what any gate can enforce.

## Where to check your status

The processing register, DSAR cases, and DPIA flags described above live in
OpenRegister's own admin surfaces; the parts Hermiq adds on top — the
`AiFeature` DPO-ack state and its evidence in the per-framework coverage
dashboard — are on the in-app **Settings** page's **Compliance** tab (see the
[EU AI Act](./02-eu-ai-act.md#where-to-check-your-status) page for what that
dashboard shows).
