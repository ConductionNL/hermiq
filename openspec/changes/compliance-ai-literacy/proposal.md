---
kind: code
depends_on: []
---

# Proposal: compliance-ai-literacy

## Summary

Every person who works with agents gets a short course inside hermiq on working with AI: what an agent can and cannot do, why answers can be wrong, how to check sources, what not to type into a chat, and when to escalate. Each lesson takes a few minutes and ends with a check question. An organisation admin sees who completed it, can make it required before someone first uses an agent, and the EU AI Act article 4 control on the compliance dashboard reads its evidence from the completions.

## Why

One row of hermiq's capability matrix, compliance area, decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `hermiq:td-ai-literacy` | no | build: tender demand |

Demand, quoted from the matrix:

- tender https://www.tenderned.nl/aankondigingen/overzicht/415259, Gemeente Molenlanden zaaksysteem 2026 (2026-03-11), requirement 71423.

No competitor rates yes. Partial cells, quoted: Hermes Agent `hermes_cli/tips.py:1-3` "tips at CLI session start; tools/tour_tool.py:1-5 agent-led guided tours"; Dify "`web/i18n/en-US/common.json:203` Help, Learn Dify and ... step-by-step tour with hands-on lessons on workflows and agents". The matrix note: "A product tour teaches the app, not AI literacy."

## What hermiq already has

- A product walkthrough, "Getting started", seven steps on agents, skills, settings and flows, shown on first visit (`src/manifest.json:1650`, tour `hermiq:getting-started`), on the fleet's walkthrough engine (hydra ADR-043).
- Compliance controls computed from live data, never hand-set: `ComplianceService::computeControlStatus()` dispatches on `evidenceSource` to six seams (`lib/Service/ComplianceService.php:293-303`), seeded by `lib/Repair/SeedComplianceControls.php` (EU AI Act, ISO 42001, NIST AI RMF). No control covers article 4, AI literacy.
- An AI output notice in chat is absent; the Nextcloud Assistant shows one (matrix evidence for `co-transparency`).

## What this change builds

1. Six lessons on working with AI, in English and Dutch, as a page "Working with AI" and a walkthrough tour that points at the real chat, sources and approval inbox.
2. A check question per lesson and a recorded completion per person.
3. An organisation setting "Require the AI course before first use", enforced where a person starts a chat or a run.
4. An admin view of completion per person and group.
5. A seeded EU AI Act article 4 control whose status comes from completions.

## Out of scope

- A graded course with certificates. That is a learning platform's job; an organisation that runs learniq can link its own course from the page.
- Training content about a specific model or vendor. The lessons are about working with any AI agent.
