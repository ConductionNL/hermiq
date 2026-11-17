---
kind: code
---

# Proposal: algoritmeregister-publication

## Why

The `ai-feature-governance-register` change gives hermiq a **design-time inventory** of
the AI features the platform provides, each EU-AI-Act risk-classified (`minimal` |
`limited` | `high` | `unacceptable`) and DPO-gated. For a Dutch public body that is only
the *internal* half of the obligation. Since 2025 the Dutch government requires every
**impactful / high-risk** algorithm used by a public organisation to be published in the
national **Algoritmeregister** (algoritmes.overheid.nl), in the standard **Algoritmekader**
metadata format — transparency toward citizens, distinct from the internal AI Act
inventory. hermiq is positioned as the fleet's AI-governance home (scholiq and other apps
delegate their `AiFeature` records here), which makes it the natural single point from
which those high-risk features are published to the national register — today there is
**no Algoritmeregister coverage anywhere in hermiq** (`grep -ri algoritmeregister lib/`
is empty).

This is both a net-new capability (dimension 4, mandated + absent) and the transparency
counterpart to the internal governance register. It must NOT re-implement publication:
publishing to an external catalogue is a fleet abstraction that already routes through
**OpenCatalogi** (the fleet publication leaf) / OpenRegister's published-predicate, exactly
as WOO decision-publication does. hermiq contributes the Algoritmekader metadata + the
publish action; OpenCatalogi owns the outward publication surface.

## What Changes

- Extend the governance `AiFeature` schema (register patch on `hermiq_register.json`) with
  the **Algoritmekader** metadata group needed for a national-register entry, as an
  optional field block (only required to *publish*, not to catalogue): `doel` (purpose),
  `wettelijkeGrondslag` (legal basis), `impacttoetsen` (which of IAMA / DPIA / FRAIA were
  done, with references), `dataBronnen` (data sources / categories), `menselijkeTussenkomst`
  (human-oversight description, links to the existing Approval gate), `verantwoordelijke`
  (owning organisation + contact), `publicatiecategorie` (Algoritmekader category), and the
  publication-state fields `algoritmeregisterStatus` (`niet-gepubliceerd` | `gepubliceerd` |
  `ingetrokken`) + `algoritmeregisterRef` (the external register id/URL once published).
- Add a **publish-readiness gate**: only an `AiFeature` with `riskCategory` in
  {`high`} (impactful) **and** `lifecycle = enabled` **and** every mandatory Algoritmekader
  field present **and** the DPO acknowledgement recorded MAY be published. Publication of a
  `minimal`/`limited` feature is refused; publication without the mandatory metadata is
  refused (fail-closed, listing the missing fields).
- Add a **publish action** (`aifeature.publish-to-algoritmeregister`, admin-gated) on
  `AiFeatureController` that maps the record to an Algoritmekader-conformant publication
  and hands it to OpenCatalogi's publication path (via the existing integration-registry /
  published-predicate seam — resolved at runtime, **no hard hermiq→OpenCatalogi coupling**;
  when OpenCatalogi is absent the action is unavailable and the feature stays governable
  internally). Withdrawal (`intrekken`) sets `algoritmeregisterStatus = ingetrokken` and
  requests unpublication.
- Surface the Algoritmeregister status + publish/withdraw actions on the existing
  AI-feature register view (read-only badge for non-admins).

## Impact

- Affected: `lib/Settings/hermiq_register.json` (AiFeature metadata + publication fields;
  bump register `info.version`), `lib/Controller/AiFeatureController.php` (+publish/withdraw
  + readiness gate), `lib/actions.seed.json` (+`aifeature.publish-to-algoritmeregister`),
  `appinfo/routes.php` (+2 routes), `src/views/AiFeatureRegister.vue` (+status/actions),
  a mapping service to the Algoritmekader shape, and its unit tests.
- Depends on `ai-feature-governance-register` (the `AiFeature` schema + DPO gate this
  extends) landing first. The outward publication is delegated to OpenCatalogi; hermiq owns
  only the metadata + mapping + publish trigger.
- Out of scope: OpenCatalogi's rendering of the Algoritmeregister feed and any direct
  algoritmes.overheid.nl harvest contract (that is OpenCatalogi's DCAT/harvest domain).
