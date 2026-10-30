---
kind: code
---

# Proposal: what-the-model-reads-and-what-is-kept

Round 4 discovery sweep, cluster 46 "AI, and what it is allowed to read"
(`procest/_round4/discovery/build-plan.md` in ConductionNL/market-intelligence,
2026-09-14). Owner hermiq on **decision D13**. Umbrella:
`competitor-parity-2026-09`.

## Summary

Two `must` candidates, one question: what did the model see, and for how long do we
keep the record. hermiq requires a document to have been redacted before a feature
reads it, and gives every AI run a retention that somebody chose.

## Why

The lane's clause on the first candidate is the sentence to keep: **"no citizen data
reaches the model" is the question every FG asks about an assistant, and it is
answerable as a capability rather than as a policy.** A policy is a promise. A
capability is a refusal.

Decision D13 assigns the redaction itself to filinq, which already has the client. So
hermiq's half is narrow and load-bearing: a feature may declare that it will not read
an unredacted document, and hermiq refuses when it has not been redacted. hermiq does
no redaction of its own.

The second candidate is the other end of the same run. The lane's clause: "an AVG
verwerkingsregister has to say what personal data a model was shown, for how long, and
a retention nobody set is a retention of forever". OpenProject holds the passer:
`AI::TextTransformRun` with a status, an input and events, and
`ai_text_transform_run_retention_seconds` enforced by
`app/workers/ai/text_transform_runs/cleanup_job.rb`. A retention setting **and** a job
that acts on it. The setting on its own is decoration.

hermiq already records. `run-audit-log` writes every run and every tool invocation to
openregister's tamper-evident `AuditTrail`, with organisation scoping and the GDPR
article 30 register. The sweep read dossiq `partial` on the candidate and noted
`lib/Controller/AiAuditExportController.php` exports an AI audit while "retention not
found". Same gap on this side: what is recorded is excellent, and nothing expires.

## The candidates, with their lane citations

| id | capability | relevance | driven passers | lane |
|---|---|---|---|---|
| C-access-and-privacy-3 | A document's personal data is removed before a model sees it. | must | none, decos-join documented | `access-and-privacy.tsv:42` |
| C-access-and-privacy-53 | Every AI run is recorded with its input and its outcome and deleted after a set period. | must | openproject | `access-and-privacy.tsv:56` |

`C-access-and-privacy-3` has **no driven passer**: the evidence is Decos Join's
documented `/zaaksysteem/joni-plus` page, admitted under **decision D21** and
labelled documented in the spec. That a `must` this important rests on a vendor claim
is itself worth recording: nobody in the driven set was measured doing it.

## What hermiq builds

- **A redaction requirement on a feature.** An `AiFeature` may declare
  `requiresRedaction`. A run of that feature that is handed a document reference must
  be given filinq's redaction outcome for it, and is refused otherwise.
- **The refusal is on the document, not on the feature.** A feature requiring
  redaction still runs on text nobody claims is a document. What it refuses is
  reading a document that has not been through filinq.
- **filinq does the redaction, and hermiq never guesses.** When filinq is absent,
  every feature requiring redaction is refused. hermiq must not fall back to its own
  PII detection and call the result redaction. Detection finds spans; redaction
  removes them, and they are not the same act.
- **A retention on every AI run.** A run entry carries the retention that applied when
  it was written, resolved from an instance default that an administrator sets, with a
  per-feature override.
- **A job that actually deletes.** A scheduled job removes run entries past their
  retention. The instance reports when it last ran and how many entries it removed,
  so a retention nobody enforces is visible rather than assumed.
- **What deletion means on a tamper-evident chain.** The run's payload is removed and
  the chain entry stays, carrying that the record existed, when, for which feature,
  and that it was deleted under retention. A chain with a hole is a broken chain.

## How dossiq consumes it

1. dossiq passes a document reference, and filinq's redaction outcome travels with it.
   dossiq performs no redaction and asserts none.
2. A refused run reaches dossiq as a refusal naming the feature and the missing
   redaction, so a handler reads why rather than a failure.
3. dossiq's own AI audit export keeps exporting. This change gives it an expiry it did
   not have, and does not change its shape.

## The existing specs this extends

- `woo-llm-anonymisation`, which already runs a tool-free PII **detection** turn with
  prompt-injection filtering active and no conversation persistence. This change
  leaves all five of its requirements intact and adds the boundary beside it:
  detection is not redaction, and a feature that requires redaction is not satisfied
  by a detection.
- `run-audit-log`, which already records every run and tool invocation. It gains a
  retention, an enforcing job and the deletion semantics for a hash chain.
- `ai-feature-governance`, whose `AiFeature` carries the two new declarations. Its DPO
  acknowledgement gate is untouched.

## Size and dependencies

**Size: M.** Two declarations, one refusal, one retention resolution, one job and one
careful deletion.

**Depends on:** filinq's redaction outcome being readable. Degrading without it is a
requirement below, not an accident.

## What this change does not do

- It does not redact. Decision D13 puts that in filinq, which already has the
  redaction client. A second redactor in the fleet is a second thing to be wrong.
- It does not weaken the audit chain. Nothing here deletes a chain entry, and nothing
  here rewrites a hash.
