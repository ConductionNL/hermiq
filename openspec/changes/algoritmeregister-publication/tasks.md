# Tasks: algoritmeregister-publication

> Depends on `ai-feature-governance-register` (the `AiFeature` schema + DPO gate) landing first.

## 1. Algoritmekader metadata + publication state (register patch)

- [ ] 1.1 Extend the `AiFeature` schema in `lib/Settings/hermiq_register.json` with the optional Algoritmekader field group: `doel`, `wettelijkeGrondslag`, `impacttoetsen` (array of `{soort: enum IAMA|DPIA|FRAIA, referentie}`), `dataBronnen`, `menselijkeTussenkomst`, `verantwoordelijke` (`{organisatie, contact}`), `publicatiecategorie`; plus `algoritmeregisterStatus` (enum `niet-gepubliceerd`|`gepubliceerd`|`ingetrokken`, default `niet-gepubliceerd`) and `algoritmeregisterRef` (string). Keep the schema flat; bump the register `info.version`; re-parse the JSON after editing (Edit tool, not sed).
  - **spec_ref**: `specs/algoritmeregister-publication/spec.md#requirement-high-risk-ai-features-carry-algoritmekader-publication-metadata`
  - **acceptance_criteria**:
    - Cataloguing an `AiFeature` does NOT require the Algoritmekader fields (design-time governance unaffected)
    - All existing `AiFeature` objects remain valid (additive, union import, no regression)

## 2. Publish-readiness gate + mapping

- [ ] 2.1 Add a `lib/Service/AlgoritmekaderMapper.php` (SPDX docblock) that validates publish-readiness (riskCategory=`high`, lifecycle=`enabled`, DPO ack recorded, every mandatory Algoritmekader field present) returning the list of failing conditions, and maps a ready `AiFeature` to the Algoritmekader publication shape.
  - **spec_ref**: `specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features`
  - **acceptance_criteria**:
    - Refuses `minimal`/`limited` risk; refuses missing mandatory fields (naming them); refuses not-enabled / not-DPO-acknowledged — all fail-closed
    - Unit tests cover the readiness matrix

## 3. Publish/withdraw action delegated to OpenCatalogi

- [ ] 3.1 Add the `aifeature.publish-to-algoritmeregister` (+ withdraw) action to `lib/actions.seed.json` (default `["admin"]`).
- [ ] 3.2 Add `publishToAlgoritmeregister(string $id)` / `withdrawFromAlgoritmeregister(string $id)` to `lib/Controller/AiFeatureController.php`: `requireAction`, load RBAC-scoped (404 cross-tenant), run the readiness gate (422 with failing conditions on refusal), then hand the mapped publication to OpenCatalogi's publication surface resolved via the runtime integration seam (lazy; unavailable when OpenCatalogi absent — NOT a hard dependency, NO direct algoritmes.overheid.nl call). On success set `algoritmeregisterStatus=gepubliceerd` + store `algoritmeregisterRef`; withdraw sets `ingetrokken` and requests unpublication.
- [ ] 3.3 Register the routes in `appinfo/routes.php` with explicit auth, each resolving to an existing method (route-auth + route-reachability PASS).
  - **spec_ref**: `specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented`
  - **acceptance_criteria**:
    - No hard hermiq→OpenCatalogi coupling (integration seam / published-predicate, resolved at runtime); action unavailable + feature still internally governable when OpenCatalogi absent
    - hermiq opens NO connection to the national portal itself (gate-27 phantom-cross-app check passes)

## 4. Frontend status + actions

- [ ] 4.1 Extend `src/views/AiFeatureRegister.vue` with an `algoritmeregisterStatus` badge and admin-only publish/withdraw actions (disabled with an explained tooltip when the readiness gate would fail); read-only for non-admins; strings via `t()`; server data via `loadState`.
  - **spec_ref**: `specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented`
  - **acceptance_criteria**:
    - Publish action hidden when OpenCatalogi absent; disabled+explained when metadata incomplete

## 5. Verify

- [ ] 5.1 `openspec validate algoritmeregister-publication --strict` clean; PHPUnit for the mapper + controller green; no dangling refs; routes resolve.
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + unit tests green; delegation seam verified present/absent
