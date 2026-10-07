# Migration: ai-translation-provenance

## Current State

The `AiFeature` schema (`agentaifeature`, version 0.4.0, register 0.32.0) has no field for output labelling. A `message-translation` row may exist on instances that ran `message-translation-delegate`, without such a field.

## Target State

`AiFeature` 0.5.0 (register 0.33.0) carries two optional properties: `outputsLabelled` (boolean, default `false`) and `outputLabelling` (string). The `message-translation` row has `outputsLabelled: true` and an `outputLabelling` text.

## Migration Class

```
Version: none
File: none
Key operations:
- No Nextcloud migration class. The register import (ConfigurationService::importFromApp() in the existing repair step) adds the two properties to the schema.
- SeedMessageTranslationFeature back-fills the one row.
```

## Migration Steps

1. The register import updates the `agentaifeature` schema to 0.5.0 with the two optional properties.
2. `SeedMessageTranslationFeature` finds the `message-translation` row; when `outputsLabelled` is not `true`, it saves the existing object plus the two fields under the same uuid, `lifecycle` unchanged.

## Data Impact

One row at most per instance is written. No field is removed or renamed. Rows of other features read `outputsLabelled` as its default, `false`. Safe on live data.

## Rollback Procedure

Revert the PR. The extra fields stay in stored objects and are ignored by the older schema. To clear them, edit the `message-translation` row in the register.

## Validation

- `occ maintenance:repair` output contains "back-filled output labelling" or "already exists" for the message-translation step.
- `GET /apps/openregister/api/objects/hermiq/agentaifeature?slug=message-translation` returns `outputsLabelled: true`.
