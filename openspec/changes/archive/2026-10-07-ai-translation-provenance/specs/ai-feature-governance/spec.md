# ai-feature-governance delta: ai-translation-provenance

## ADDED Requirements

### Requirement: The register records whether a feature's outputs are labelled as AI-made

The `AiFeature` schema SHALL carry `outputsLabelled` (boolean, default `false`) and `outputLabelling` (text) so a DPO can see, per feature, whether the people who read its outputs are told AI made them (EU AI Act Art. 50) and how. The `message-translation` feature SHALL be seeded with `outputsLabelled: true` and an `outputLabelling` text naming the provenance fields and the disclosure sentence. The seed step SHALL back-fill both fields on an existing `message-translation` row that lacks them, and SHALL leave a row that already has them untouched. The entity is a schema.org `SoftwareApplication`; `outputsLabelled` maps to a `PropertyValue`.

#### Scenario: A fresh install records that translations are labelled

- GIVEN no `message-translation` feature exists
- WHEN the `SeedMessageTranslationFeature` repair step runs
- THEN the created row MUST contain `outputsLabelled: true`
- AND a non-empty `outputLabelling` text

#### Scenario: A row seeded before this change is back-filled once

- GIVEN a `message-translation` row exists without `outputsLabelled`
- WHEN the repair step runs
- THEN the row MUST be saved with `outputsLabelled: true` and the `outputLabelling` text
- AND every other field of the row MUST keep its value, including `lifecycle`

#### Scenario: A row that already records labelling is left alone

- GIVEN a `message-translation` row with `outputsLabelled: true`
- WHEN the repair step runs
- THEN no save MUST happen

#### Scenario: A feature that says nothing about labelling reads as not labelled

- GIVEN an `AiFeature` row created without `outputsLabelled`
- WHEN the register is read
- THEN `outputsLabelled` MUST default to `false`
