---
kind: code
---

# Proposal: identical-reports-collapse-into-one

Round 4 discovery sweep, candidate `C-intake-30`, relevance **`must`**. The sweep
places it in cluster 35, "intake routing, refusal and triage", owned by dossiq
(`procest/_round4/discovery/build-plan.md` in ConductionNL/market-intelligence,
2026-09-14). Ruben's wave-3 instruction assigns the judgement half to hermiq under
**decision D13**. Umbrella: `competitor-parity-2026-09`.

## Summary

Two hundred meldingen about one street-wide power cut become one item with a count,
with the near-duplicates listed beside it. hermiq decides whether two reports describe
the same thing. dossiq decides what happens to them.

## Why

The lane's clause is the scene: **"two hundred meldingen about one straat-brede
storing, and dq 2.24 is one person filing twice, which is a different problem"**. That
sentence separates two things a reader will otherwise merge.

| | ledger row 2.24 | this candidate |
|---|---|---|
| the situation | one person files the same thing twice | two hundred people file one event |
| the test | same reporter, same subject | different reporters, one underlying cause |
| the answer | refuse or link the second | collapse into one, with a count |
| where it lives | dossiq, cluster 35 | the judgement here, the act in dossiq |

The sweep's own note says it: "2.24 detects a duplicate; this one collapses them and
counts." dossiq reads `no` on this candidate.

**Why the judgement is hermiq's.** "Identical" is easy and useless: two people never
type the same words. The useful test is "the same underlying event", and that is a
similarity judgement over free text, which is what decision D13 puts in hermiq. dossiq
holding it would mean a second model consumer in the fleet, and the shape D13 rejected.

**Why the act is dossiq's.** Whether a collapsed group becomes one case with two
hundred reporters, or two hundred cases linked to one parent, is a statutory question
about acknowledgement duties and archiving. hermiq has no business answering it.

## The candidate, with its lane citation

| id | capability | relevance | driven passers | lane |
|---|---|---|---|---|
| C-intake-30 | Identical incoming reports collapse into one item with a count, with the near-duplicates beside it. | must | none, jira-service-management documented | `intake.tsv:30` |

**No driven passer.** The evidence is Jira Service Management's documented alert
grouping: what alerts are, how they are created, configuring alert grouping, grouping
with Rovo, related alert groups, alerts by signal or noise, regular expressions for
filtering, and viewing similar alerts with past responders. Admitted under **decision
D21** and labelled documented in the spec. A `must` resting on a vendor page is worth
noticing, and the spec says so on its face.

## What hermiq builds

- **A similarity question with a group answer.** Given a new report and an open window
  of recent ones, hermiq answers which existing group it belongs to, or that it starts
  a new one, with a score.
- **Three bands, not two.** Above an upper threshold it is the same event. Below a
  lower one it is not. Between them it is a near-duplicate: attached to the group and
  flagged as uncertain, so a human sees it beside the group rather than buried in it
  or lost from it.
- **A group is a judgement, not a record of anything.** hermiq holds the grouping and
  the score. It creates no case, merges nothing and deletes nothing.
- **Reversal without loss.** A report pulled out of a group returns to standing alone,
  and the group's count moves. Nothing was destroyed to form a group, so nothing has
  to be recovered to undo one.
- **The reasons are readable.** A group carries why its members were grouped: the
  terms, the window and the score. "Why are these two hundred one thing" must be
  answerable to a citizen who disagrees.
- **A deterministic signal first, again.** Where the owning app supplies a
  deterministic key, such as the same address and the same category within an hour,
  hermiq uses it and records that it did. The model is for what the key does not
  catch.

## How dossiq consumes it

1. dossiq asks hermiq, per incoming report, which group it belongs to.
2. dossiq decides what a group means: one case with many reporters, or many cases
   under a parent. That decision stays statutory and stays in dossiq.
3. dossiq's `SentimentService` and its own duplicate detection for row 2.24 are
   untouched. One person filing twice is still dossiq's, and is a different test.
4. The acknowledgement duty under Awb 4:3a applies per request, whatever the grouping.
   Collapsing reports for a handler's view must not collapse two hundred
   acknowledgements into one, and the spec says so.

## The existing specs this extends

- `run-audit-log` records each similarity judgement as a run, so a grouping is
  auditable like any other model output.
- `ai-feature-governance` registers the grouping as its own feature with its own risk
  category.
- `agent-guardrails` applies: the input is text from the public.
- Nothing in `case-assistant-surface` changes. This is not a chat.

## Size and dependencies

**Size: M.** One similarity question, three bands, a group object, a reversal and a
reasons record.

**Depends on:** `what-the-model-reads-and-what-is-kept` for the retention on the text
being compared, and `a-conversational-intake-that-files-for-the-citizen` for the
deterministic-before-model rule this change reuses.

## What this change does not do

- It does not merge, close or delete anything. Grouping is additive and reversible.
- It does not answer row 2.24. One person filing twice is a different test, in dossiq.
- It does not suppress an acknowledgement. Two hundred people who wrote to the
  gemeente are owed two hundred confirmations of receipt.
