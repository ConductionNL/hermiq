---
sidebar_position: 2
title: EU AI Act
description: Article-by-article — what Regulation (EU) 2024/1689 requires, how Hermiq is built to support it, and what stays your responsibility.
---

# EU AI Act

The **EU AI Act** (Regulation (EU) 2024/1689) is the EU's risk-based framework
for AI systems. It entered into force on 1 August 2024 and applies in stages —
prohibited practices from February 2025, obligations for general-purpose AI
models from August 2025, and most high-risk-system obligations from
2 August 2026.

## Who is who

The Act mostly regulates two roles: the **provider** who builds/trains an AI
system, and the **deployer** who uses one under their own authority
(Art. 3(3)–(4)). Hermiq is infrastructure: it does not train or ship a model
of its own — your organisation brings a model provider (a hosted API or a
local Ollama model) and builds agents on top of it. That makes **your
organisation the deployer** for the agents you run, with Hermiq built to help
you carry the deployer's obligations. Articles that fall on the *provider* of
the underlying model (Art. 9, 13, 15, and the general-purpose-model
obligations in Chapter V) are that provider's responsibility, not something
Hermiq's design can discharge for you. If it's unclear whether your specific
use also makes your organisation a provider (for example, if you package an
agent as a product for someone else), that determination needs your own
legal counsel.

## Article by article

| Article | What it requires | Hermiq's role |
|---|---|---|
| Art. 5 — Prohibited practices | Certain AI practices (social scoring, subliminal manipulation, etc.) may never be deployed, full stop | Enforced — unwaivable hard block |
| Art. 6 / Annex III — High-risk classification | Determine whether a system falls in a high-risk category | Not automated — a self-declared judgment call |
| Art. 9 — Risk management system | Identify, estimate, and mitigate risk across a system's lifecycle | Supports classification; mitigation is the model provider's duty |
| Art. 12 — Record-keeping | Automatic logging sufficient to trace operation and incidents | Built in — the `AuditTrail` |
| Art. 14 — Human oversight | A human who can understand, override, and stop the system | Built in — approval gate + kill switch |
| Art. 26 — Deployer obligations | The full operational checklist for whoever runs a high-risk system | Partially built in; several sub-duties are yours |
| Art. 49(3) / 71 — EU database registration | Public-body deployers of a high-risk system register it in the EU-level database | Not built — separate from, and not satisfied by, the Dutch Algoritmeregister |
| Art. 50 — Transparency | People must know they're talking to / seeing output from AI | Partially built in via Nextcloud Talk's bot identity |

### Art. 5 — Prohibited practices

**What it requires.** A short list of AI practices — social scoring, exploiting
vulnerabilities, subliminal/manipulative techniques that cause harm, and a few
others — that no provider or deployer may place into service or use, under
any circumstances. Unlike the high-risk tier, there is no paperwork or
sign-off that makes a prohibited practice legal.

**How Hermiq supports it.** An `AiFeature` classified `riskCategory:
unacceptable` can be catalogued (so you have a record that you considered and
rejected it) but its `enable` transition is refused unconditionally by
`AiFeatureDpoAckGuard` — no DPO acknowledgement, however senior, can switch it
on. This is checked before, and independently of, the ordinary DPO-ack lookup
that gates `high`-risk features.

**Your responsibility.** Classifying a feature as `unacceptable` in the first
place is still your call — Hermiq does not analyse what an agent does and
infer that it's a prohibited practice. If you build something that should be
`unacceptable` but leave it classified `high` or `limited`, the hard block
never engages.

### Art. 6 / Annex III — High-risk classification

**What it requires.** Determining whether a given AI system falls into one of
the Act's high-risk categories (Annex III: employment, education, essential
services, law enforcement, and others) — the trigger for most of the Act's
heavier obligations.

**How Hermiq supports it.** Nothing beyond the `riskCategory` field itself.
There is no built-in questionnaire or decision tree that maps "what does this
agent do" to an Annex III category.

**Your responsibility.** This classification is entirely yours to make, and
everything else on this page — the DPO gate, the audit export, the
Algoritmeregister publish gate — only engages correctly if you classify
honestly in the first place. When in doubt, treat a borderline case as
higher-risk, not lower.

### Art. 9 — Risk management system

**What it requires.** A continuous process to identify, estimate, and
mitigate risks to health, safety, or fundamental rights across a high-risk AI
system's lifecycle. This is formally a *provider* obligation — it targets
whoever builds the AI system, not whoever deploys it.

**How Hermiq supports it.** Hermiq can't run this process for a model it
didn't build, but it gives you the classification layer the rest of your
governance hangs off: every AI capability you register is an `AiFeature`
object with a `riskCategory` (`minimal` / `limited` / `high` / `unacceptable`)
mirroring the Act's own tiers, managed from the in-app **Settings** page's
**Algorithm register** tab, and a `high`-risk feature is blocked from being
switched on until your DPO has acknowledged it in writing. The compliance
dashboard's `model-policy-risk-control` check reflects this back per
organisation.

**Your responsibility.** Choosing the right `riskCategory` for a use case,
and choosing a model provider whose own risk-management posture is adequate
for that use case, are judgment calls Hermiq cannot make for you.

### Art. 12 — Record-keeping

**What it requires.** High-risk AI systems must automatically log events
throughout operation, sufficient to identify risk situations and support
post-market monitoring.

**How Hermiq supports it.** Every run, LLM call, tool invocation, and
approval decision is written to OpenRegister's hash-chained, append-only
`AuditTrail` (each entry references the previous entry's hash, so a gap or
edit is detectable). The [Incidents](../incidents.md) page and
[Approvals](../approvals.md) page both describe pieces of this trail, and it
is exportable per-organisation as an audit pack (`TenantOpsService::exportAuditTrail()`).

**Your responsibility.** The Act expects logs to be kept "for a period
appropriate to the intended purpose" — at least six months unless other law
requires longer. Hermiq exports the trail on request; **you** decide your
retention policy and where the export is archived once it leaves the
instance.

### Art. 14 — Human oversight

**What it requires.** High-risk AI systems must be designed so a human can
understand their capabilities and limitations, detect anomalies, correctly
interpret outputs, decide not to use an output, and stop the system.

**How Hermiq supports it.** The [human approval gate](../approvals.md) pauses
a scheduled run, a flow-triggered run, a webhook-triggered run, or a
mid-conversation tool call until a person approves or rejects it — with
every decision (who, when, why) written to the audit trail. Any tool your
guardrail policy classifies `confirm` is routed through this gate regardless
of source. Separately, a per-organisation kill switch
(`TenantControlService`) stops a tenant's agents outright.

**Your responsibility.** Hermiq ships the mechanism; **you** decide, via
guardrail policy, which tools are consequential enough to require a human —
leaving a high-stakes tool ungated is a configuration choice, not a gap in
the software.

### Art. 26 — Deployer obligations

**What it requires.** The Act's operational checklist for whoever deploys a
high-risk AI system: use it per the provider's instructions, assign
competent human oversight, ensure input data is relevant to the intended
purpose, monitor for risks and report serious incidents, keep the
automatically generated logs, and — where applicable — notify affected
individuals and workers.

**How Hermiq supports it, sub-duty by sub-duty:**

| Sub-duty | Status |
|---|---|
| Assign human oversight | ✅ Approval gate + guardrail policy (Art. 14 above) |
| Keep the logs | ✅ `AuditTrail`, six-month-minimum retention exportable |
| Monitor for risks, record serious incidents | ✅ The [Incidents](../incidents.md) register, human-authored and linked to runs |
| Registering in the Dutch Algoritmeregister (national transparency register) | ✅ See [Dutch Algoritmeregister](./04-algoritmeregister.md) |
| Registering in the EU-level high-risk database (Art. 49(3)/71, public-body deployers) | ❌ Not built — a separate register from the Algoritmeregister above; not satisfied by publishing there |
| Inform natural persons subject to the system's output | ❌ Not automated — your responsibility |
| Inform workers/their representatives before workplace deployment | ❌ Not automated — your responsibility |
| Special rules for post-remote biometric identification | ❌ Hermiq ships no biometric-identification tooling; if you build one via a custom MCP tool, every Art. 26(9)–(10) duty is entirely yours |

**Your responsibility.** The unchecked rows above are organisational
processes, not settings. Hermiq's audit trail gives you the evidence to
carry them out and to prove you did — it does not carry them out for you.

### Art. 50 — Transparency

**What it requires.** People interacting with an AI system must be told
they're doing so, unless that's already obvious. AI-generated text on public
matters and synthetic audio/image/video content need disclosure too, with
narrower carve-outs for editorial review and artistic use.

**How Hermiq supports it.** When an agent talks in Nextcloud Talk (see
[Talking to an agent from Nextcloud Talk](../talk-chat-bridge.md)), it
participates as a registered Talk **bot**, not a disguised human account —
Talk's own UI marks it as a bot in every conversation. That satisfies the
"obvious to a reasonably well-informed person" carve-out in Art. 50(1)
without any extra disclosure step.

**Your responsibility.** If you relay an agent's output somewhere that bot
identity doesn't travel with it — forwarding it into an email as if written
by a person, publishing AI-generated text on public matters without human
review, or generating synthetic audio/image/video — the disclosure duty is
back on you. Hermiq does not watermark or label generated content once it
leaves Talk.

## Where to check your status

Everything on this page that's marked "built in" is reflected live on the
in-app **Settings** page's **Compliance** tab — see the [Compliance
dashboard](../compliance-dashboard.md) page for what it shows: a
per-framework coverage view (EU AI Act alongside ISO/IEC 42001 and NIST AI
RMF), the list of controls that aren't yet satisfied for your organisation, a
per-agent AI factsheet, and the auditor's-pack export. It computes every
control from your organisation's actual data — approvals, audit-trail
entries, `AiFeature` state — so it changes as your governance posture
changes, with nothing to manually mark "done."

