---
sidebar_position: 4
title: Dutch Algoritmeregister
description: The Algoritmekader metadata fields — what each captures, how Hermiq enforces it, and what stays your responsibility.
---

# Dutch Algoritmeregister

The **Algoritmeregister** (algoritmes.overheid.nl) is the Dutch national
transparency register for algorithms used by government bodies, built on the
**Algoritmekader** metadata standard. Publication has been Dutch cabinet
policy since 2022 for "impactful" algorithms, and is moving toward a
statutory footing in step with the EU AI Act's high-risk obligations and its
own EU-level database for high-risk systems used by public authorities
(Art. 49(3)) — the exact legal-mandate timeline is still evolving, so treat
this page as describing the metadata standard and Hermiq's support for it,
not a final statement of when publication becomes legally required for your
organisation.

This page doesn't map to numbered articles the way the AI Act and GDPR pages
do — the Algoritmekader is a metadata standard, not a statute. Instead, each
section below is one of the standard's required publication fields.

## How it fits together

Hermiq's `AiFeature` object can carry the Algoritmekader metadata as an
optional field group — optional at *cataloguing* time (design-time
governance doesn't require it), mandatory at *publish* time. Publication
itself is delegated to OpenCatalogi's existing publication pipeline rather
than Hermiq opening its own connection to algoritmes.overheid.nl — when
OpenCatalogi isn't installed, publishing is simply unavailable and the
feature stays fully governable internally.

## The publish gate

Hermiq refuses to publish an `AiFeature` unless **all** of the following
hold, and tells you exactly which one failed rather than publishing a
partial entry:

- `riskCategory` is `high` — `minimal`/`limited` features are out of scope
  for the national register and publishing them is refused.
- `lifecycle` is `enabled`.
- The DPO acknowledgement is recorded (the same gate described under
  [EU AI Act — Art. 9](./02-eu-ai-act.md)).
- Every mandatory field below is present.

:::warning Scope mismatch: "impactful" is broader than "high-risk"
Dutch cabinet policy asks for **impactful** algorithms to be published — a
broader, policy-defined category than the AI Act's Annex III **high-risk**
tier that Hermiq's `riskCategory` field mirrors. A feature you've honestly
classified `limited` under the AI Act could still be "impactful" enough
under Dutch policy to warrant publication, and Hermiq's publish gate will
refuse it (`riskCategory` isn't `high`) with no way around that from the UI.
If a feature seems impactful but isn't AI Act high-risk, don't rely on the
publish gate — check with your DPO whether it should be published anyway,
outside Hermiq's automated path.
:::

## The metadata fields

| Field | Captures |
|---|---|
| `doel` | The purpose of the algorithm |
| `wettelijkeGrondslag` | Its legal basis |
| `impacttoetsen` | Impact assessments performed (IAMA / DPIA / FRAIA) with references |
| `dataBronnen` | Data sources / categories used |
| `menselijkeTussenkomst` | A description of the human-oversight arrangement |
| `verantwoordelijke` | The responsible organisation and contact |
| `publicatiecategorie` | The Algoritmekader publication category |

**How Hermiq supports it.** Every field above is a real field on the
`AiFeature` schema, and the publish gate mechanically enforces that they're
all present before anything reaches the register — you cannot publish an
entry with a placeholder or a missing legal basis.

**Your responsibility.** Hermiq checks that the fields are *filled in*, not
that what you wrote is accurate — `wettelijkeGrondslag` naming the wrong
statute, or `menselijkeTussenkomst` describing oversight that doesn't
actually happen in practice, will pass the gate and still be wrong. The
content of every field is yours to get right.

## Publication state

An `AiFeature`'s `algoritmeregisterStatus` is `niet-gepubliceerd` (not
published) until you explicitly publish it, at which point it becomes
`gepubliceerd` and stores the register's external reference
(`algoritmeregisterRef`); withdrawing sets it to `ingetrokken` and requests
unpublication.

**Your responsibility.** *Deciding to publish* — and keeping the entry
current as the underlying agent changes — is a step your organisation takes.
An accurate but stale register entry is still a compliance gap; nothing
re-publishes automatically when you change how an agent works.

## Where to manage this

The Algoritmekader fields, the publish/withdraw actions, and each feature's
`algoritmeregisterStatus` all live on the in-app **Settings** page's
**Algorithm register** tab, alongside the rest of `AiFeature` governance —
there is no separate page for national-register publication.
