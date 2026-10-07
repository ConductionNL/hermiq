---
kind: code
---

# Proposal: a-provider-and-a-place-per-ai-feature

Round 4 discovery sweep, cluster 46 "AI, and what it is allowed to read"
(`procest/_round4/discovery/build-plan.md` in ConductionNL/market-intelligence,
2026-09-14). Owner hermiq on **decision D13**. Umbrella:
`competitor-parity-2026-09`.

## Summary

An AI feature names the provider it runs on and the place that provider runs in. A
gemeente can put summarising on a local model and translation on a hosted one, and can
answer the question every functionaris gegevensbescherming asks first: which model saw
this case, and in which jurisdiction.

## Why

`C-integrations-38` is a **`must`**, and it is the sharpest of the three in cluster 46
because it is procurement-blocking rather than pleasant. The clause the lane recorded:
"a gemeente must be able to say which model saw the case and in which jurisdiction".

hermiq is close already, and the change is a join rather than a build.
`tenant-model-policy` constrains `{provider, models[]}` per organisation, with an
instance-admin fallback and run-time enforcement on every turn.
`ai-feature-governance` registers each `AiFeature` with an EU AI Act risk category and
a lifecycle gated on a DPO acknowledgement.

Two registers, and nothing joins them. A tenant policy allowing both Ollama and OpenAI
allows **every** feature to use either. That is exactly the granularity Zammad has and
we do not: it carries an AI provider connection and switches features separately
(`config/routes/ai_provider_connection.rb`, `ai_agent.rb`, `ai_text_tool.rb`,
`ai_vector_index.rb`, admin areas `AI::Provider` and `AI::Assistance`, permissions
`admin.ai_provider` and `admin.ai_assistance_ticket_summary`).

The second candidate is the deployment shape the same question implies. `Decos Join`
markets a split where the assistant runs in the vendor's cloud and the case system
stays on premise. The sweep's note is honest about it: "a deployment shape our matrix
cannot express". hermiq can express it cheaply, because a residency label on a
provider is one field and an answerable question.

## The candidates, with their lane citations

| id | capability | relevance | driven passers | lane |
|---|---|---|---|---|
| C-integrations-38 | The buyer chooses which AI provider is used and where it runs, per feature. | must | zammad | `integrations.tsv:34` |
| C-configuration-75 | The assistant runs in the vendor's cloud while the case system stays on premise. | could | none, decos-join documented | `configuration.tsv:111` |

`C-configuration-75` has **no driven passer**. The evidence is Decos Join's
documented `/zaaksysteem/joni-plus` page, admitted under **decision D21** and labelled
documented in the spec below. A vendor page is an upper bound, never a measurement.

## What hermiq builds

- **A provider binding on the feature.** `AiFeature` gains an optional provider and
  model. When set, runs of that feature use it. When unset, the feature falls back to
  the organisation's `ModelPolicy` default, which is what happens today.
- **The binding is checked against the policy, not instead of it.** A feature cannot
  name a provider the organisation's `ModelPolicy` forbids. The policy stays the
  ceiling and the feature binding narrows within it.
- **A residency label on a provider.** Each configured provider declares where it
  runs: `on-premise`, `eu` or `outside-eu`, plus a free-text location. The label is
  administered, not inferred from a hostname, because a hostname is not a
  jurisdiction.
- **A feature may require a residency.** An `AiFeature` may declare the residency it
  demands. A run that would send data to a provider outside it is refused before the
  call, not logged after it.
- **The answer, on the record.** Every run records the feature, the provider, the
  model and the residency in force at the time. "Which model saw this case, and
  where" becomes a read rather than an investigation.
- **A split deployment is a configuration, not a fork.** The on-premise instance
  keeps its cases and points a feature at a provider labelled `eu` or `outside-eu`.
  Nothing about the case system moves, and the residency label says what left.

## How dossiq consumes it

dossiq declares its AI features and nothing else. It does not choose a provider, does
not hold a residency and does not configure a model. When a run is refused on
residency, dossiq shows the refusal with the feature and the required residency
named, so a handler reads a reason rather than an error.

## The existing specs this extends

- `ai-feature-governance`, which owns the `AiFeature` register, the EU AI Act risk
  category and the DPO acknowledgement gate. The gate is untouched: a feature that
  names a provider is still disabled until the DPO signs for it.
- `tenant-model-policy`, which stays the ceiling. Its four requirements are unchanged,
  and this change adds nothing that can widen a policy.
- `run-audit-log`, which gains three fields on what it already records.

## Size and dependencies

**Size: M.** Three fields, one narrowing check, one refusal and one recorded answer.
Both registers exist.

**Depends on:** nothing in this umbrella.

## What this change does not do

It does not add a provider driver. The four supported chat drivers stay what
`tenant-model-policy` names. A residency label describes where an existing driver's
endpoint runs; it does not create a new one.
