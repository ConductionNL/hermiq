# Proposal: competitor-parity-2026-09

Round 4 discovery sweep of the dossiq competitor programme
(`procest/_round4/discovery/build-plan.md` in ConductionNL/market-intelligence,
written 2026-09-14). 36 systems read, 631 candidates kept, 70 capability clusters.
This is the hermiq umbrella for wave 3.

## Summary

**Decision D13 puts the assistant in hermiq.** dossiq declares which tools exist and
who may call them, anonymisation before a model reads belongs to filinq, and the run
record goes to openregister's audit trail. Cluster 46, "AI, and what it is allowed to
read", is hermiq's: nine candidates, three of them `must`, four driven passers.

This umbrella carries no requirement of its own. It records where the five changes
beside it came from, so a reader who finds one finds the rest.

## Why

D13 rejected two alternatives explicitly. dossiq shipping its own assistant costs a
second assistant in the fleet. Switching each AI capability on separately is not an
alternative at all: the sweep found it as a `must` in its own right, so it becomes a
requirement on whichever app wins, and hermiq won.

What hermiq already has matters as much as what it lacks, and each change below says
which. `tenant-model-policy` already constrains provider and model per organisation.
`ai-feature-governance` already gates a high-risk feature behind a DPO
acknowledgement. `run-audit-log` already records every run and every tool call on
openregister's tamper-evident chain. `agent-tool-governance` already holds per-agent
grants and the article 12 and 14 oversight surface. `woo-llm-anonymisation` already
runs a tool-free PII detection turn.

Four of the nine candidates land on gaps inside those capabilities rather than on
empty ground, which is why three of the five changes are M rather than L.

## The changes under it

| change | candidates | size |
|---|---|---|
| `a-provider-and-a-place-per-ai-feature` | C-integrations-38, C-configuration-75 | M |
| `what-the-model-reads-and-what-is-kept` | C-access-and-privacy-3, C-access-and-privacy-53 | M |
| `the-declared-tool-surface-and-the-prompt-library` | C-integrations-19, C-configuration-44 | M |
| `a-conversational-intake-that-files-for-the-citizen` | C-intake-9, C-communication-64, C-integrations-1 | L |
| `identical-reports-collapse-into-one` | C-intake-30 | M |

Cluster 46 holds the first nine. `C-intake-30` is not in it: the sweep puts it in
cluster 35, "intake routing, refusal and triage", owned by dossiq. Ruben's wave-3
instruction assigns the judgement half to hermiq, and the change below draws that line
explicitly: hermiq decides whether two reports are the same thing, dossiq decides what
happens next.

## The decisions these rest on

- **D13.** hermiq owns the assistant. dossiq declares tools and rights. Anonymisation
  before the model reads is filinq's. The run record goes to openregister's audit
  trail.
- **D6.** Promotion is relevance-led and every `must` enters. Four of the ten
  candidates here are `must`: an AI provider chosen per feature, anonymisation before
  a model reads a document, every AI run recorded and retained, and identical reports
  collapsed with a count.
- **D21.** A documented candidate is admitted and labelled. Five of the ten have **no
  driven passer at all**, which is a higher share than any other wave-3 cluster. Each
  requirement resting on one says so on its face.
- **D17.** The product serves a broad market including MKB, and a candidate rated
  `not` is not disqualified. None of hermiq's ten is in the `not` bucket.

## What is deliberately not here

The sweep's candidate `C-search-38`, a query and command language in the
organisation's own language, sits in cluster 65, "search quality", owned by
openregister. Parked question Q9.15 revived with four passers (nextcloud-deck,
request-tracker, itop, youtrack) under decision D5, and its note is that YouTrack's
language exists in the system language while every other query language in the corpus
is English. The build plan places it with openregister's search and facet layer, not
with hermiq, so no change here claims it.

## Affected projects

- `hermiq`: five changes, listed above.
- `dossiq`: declares which tools exist and who may call them, places the assistant
  surfaces, decides what to do with a collapsed report. Not changed here.
- `filinq`: owns the redaction that runs before a model reads a document. Not changed
  here.
- `openregister`: owns the audit trail every run is written to, and the search and
  command language. Not changed here.
