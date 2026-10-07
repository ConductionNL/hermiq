# Design: what the model reads and what is kept

## D1. Detection is not redaction, and the distance matters

`woo-llm-anonymisation` gives hermiq `POST /api/assistant/detect-pii`, returning
spans with categories and confidences from one tool-free LLM turn. It is genuinely
useful and it is genuinely not redaction.

| | detect-pii | redaction |
|---|---|---|
| output | spans over the original text | a document with the spans gone |
| the model sees | the unredacted text, deliberately | nothing, it runs before |
| confidence | reported, and below 1 | not a property of the act |
| owner | hermiq | filinq, per D13 |

The dangerous move is treating a detection as evidence of redaction. It would let
hermiq mark a run as safe on the strength of a probabilistic span list produced by
sending the model exactly the text it was supposed not to see.

So the two are kept apart in the spec, explicitly, and a feature requiring redaction
is never satisfied by a detection.

## D2. Why the refusal attaches to the document, not to the feature

A feature that requires redaction still has to work. Summarising a handler's own note
involves no document, and refusing it would make the requirement unusable and
therefore unused.

The requirement is conditional on what the run is handed: when a document reference is
passed, filinq's redaction outcome for that reference must come with it. Text that
nobody claims is a document is not gated, and the boundary is the reference rather
than the feature.

## D3. Failing closed when filinq is absent

Two options when the redaction client cannot be resolved.

1. Fall back to hermiq's own detection and proceed. Tempting, because the endpoint
   exists. It is the D1 failure with extra steps.
2. Refuse every run of every feature requiring redaction, and say why.

Option 2. `ai-feature-governance` already establishes this posture: its DPO gate is
declarative on the transition and fails closed, on the argument that an imperative
check protects only the call site that remembers it. The same argument applies here.

A gemeente that has switched `requiresRedaction` on has made a statement. Quietly
downgrading it because an app is missing turns their statement into a preference.

## D4. A retention nobody set is forever, so there is always a default

OpenProject's shape is a setting plus a cleanup job. Copying only the setting is the
common failure, and it is worse than having neither: the screen says ninety days and
the rows are still there in year three.

So three parts, and all three are requirements:

- an instance default retention an administrator sets, with a per-feature override;
- the retention **copied onto the run** when it is written, so changing the default
  does not retroactively shorten or extend what was already promised;
- a job that removes expired payloads, and reports its last run and its count.

The third is the one that can be checked. "Show me the last cleanup run" is a question
an auditor asks and an instance can answer.

## D5. Deleting from a tamper-evident chain without breaking it

`run-audit-log` writes onto openregister's `AuditTrail`, a hash and `previousHash`
chain. Deleting an entry breaks every hash after it, which destroys the property the
chain exists for.

So retention removes the **payload**, not the entry. What stays: that a run happened,
when, for which feature, under which provider, and that its payload was deleted under
retention on a given date. What goes: the input text, the model output and anything
personal.

This satisfies both duties at once. Article 30 wants to know that processing happened
and of what kind. Storage limitation wants the personal data gone. A tombstone carries
the first and drops the second, and the chain stays whole.

## D6. The order of checks, again

`a-provider-and-a-place-per-ai-feature` specifies: resolve binding, narrow by policy,
check residency, call. Redaction joins that sequence **before** the call and after
residency, and for the same reason: a check that runs after the request has left has
recorded a breach, not prevented one.

Keeping both changes' checks in one ordered pre-call path means there is one place to
read to know what a run must pass, rather than two paths that drift.
